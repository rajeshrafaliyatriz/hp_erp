<?php

namespace App\Domain\AI\Conversation;

use App\Domain\AI\Support\AiAuditLogger;
use App\Domain\AI\Workspace\PageContextResolver;
use App\Domain\AI\Workspace\PageSnapshot;
use App\Domain\AI\Support\AiModelClient;
use App\Domain\AI\Support\AiNotConfiguredException;
use App\Services\Ai\AiPolicyResolver;
use App\Services\Ai\AiRequestScope;
use Throwable;

/**
 * One question in, one answer out, with everything in between recorded.
 *
 * This is G2G's counterpart to LMS K-12's ask pipeline, and deliberately a much
 * smaller thing. LMS runs a fifteen-stage lifecycle — intent classification, entity
 * resolution, tool selection, governance, evidence collection. That machinery exists
 * there because it has the tool registry and the ontology to drive it. Reproducing
 * the *shape* of it here without those would produce fifteen stages of which twelve
 * were pass-throughs, which is harder to read than four honest ones and no more
 * capable.
 *
 * So the four steps that do carry weight:
 *
 *   1. Resume or open the conversation, scoped to the caller's organisation.
 *   2. Build a grounded system prompt from that organisation's real figures.
 *   3. Call the centrally-configured provider, replaying the recent transcript.
 *   4. Persist both turns and audit the exchange.
 *
 * A FAILED ANSWER IS STILL A TURN
 *
 * When the provider refuses or the credential is missing, the user's question is
 * already stored and an assistant turn is written carrying the error. That is the
 * behaviour worth insisting on: a transcript that silently drops its failures shows
 * a user asking three questions and getting two answers with no sign of the third,
 * and makes "did it break, or did I imagine asking" unanswerable.
 *
 * EVERY EXCHANGE IS AUDITED, INCLUDING THE REFUSALS
 *
 * AI Audit exists to answer what the system did. An assistant is the capability most
 * likely to be asked that about, so the audit row is written on both paths — with the
 * outcome, the model and the token counts, and never the message text, which is in
 * the transcript and does not need a second copy in a different table.
 */
final class AskPipeline
{
    public function __construct(
        private readonly ConversationStore $conversations,
        private readonly OrganisationContext $context,
        private readonly AiModelClient $models,
        private readonly AiAuditLogger $audit,
        private readonly ModuleGrounding $grounding,
        private readonly AiPolicyResolver $policies,
        private readonly PageContextResolver $pages,
    ) {
    }

    /**
     * The AI module this capability resolves its provider through.
     *
     * Named here rather than passed in: which configuration a conversation runs on is
     * a property of the capability, not of the caller, and letting a caller choose
     * would let it route around whatever an administrator configured.
     */
    private const MODULE = 'conversational_ai';

    /**
     * Ask a question.
     *
     * @return array<string, mixed> The answer, the conversation, and what produced it.
     */
    public function ask(
        AiRequestScope $scope,
        string $sessionKey,
        string $message,
        ?string $moduleKey = null,
        ?int $menuId = null,
        ?array $pageData = null,
        ?string $organisationName = null
    ): array {
        $institute = $scope->selectedInstituteId;

        $conversation = $this->conversations->resume(
            $sessionKey,
            $institute,
            $scope->userId,
            $moduleKey,
            $scope->clientId
        );

        // The transcript BEFORE this question is added, or the model would be handed
        // the question twice — once as history and once as the prompt.
        $history = $this->conversations->contextMessages((int) $conversation->id, $institute);

        $this->conversations->addTurn((int) $conversation->id, $institute, 'user', $message);
        $this->conversations->titleFromFirstMessage((int) $conversation->id, $message);

        // The module the assistant was opened in. Null is the organisation-wide assistant;
        // a key that names no registered module never reaches here (the controller refuses it).
        $module = $moduleKey === null ? null : $this->grounding->module($moduleKey, $institute);

        // POLICY, BEFORE ANY MODEL IS CALLED. The narrowest assignment wins (module, then the
        // whole organisation). A refusal is a turn and an audit row, like any other failure.
        $decision = $this->policies->resolve($institute, [
            'operation' => 'ai_request',
            'module_id' => $module === null ? null : (int) $module->id,
        ]);

        if (! $decision['allowed']) {
            $this->recordModuleActivity($scope, $module, 'denied', (string) $decision['message'], (int) $conversation->id);

            return $this->recordRefusal($scope, $conversation, $decision);
        }

        $organisation = $organisationName ?: 'this organisation';

        if ($module !== null) {
            // Module-scoped grounding REPLACES the organisation-wide briefing: that one carries
            // every other module's figures.
            // The page counts only if it really sits under this module: a menu id from another
            // module is ignored, never trusted into the prompt.
            $page = $menuId === null ? null : $this->pageUnder($scope, $menuId, (string) $module->module_key);

            $briefing = $this->grounding->briefing($scope, (string) $module->module_key, $page['module_key'] ?? null);
            // What is on the screen counts only for a page that really sits under this module.
            $snapshot = $page === null ? null : PageSnapshot::clean($pageData);
            $screen = $snapshot === null ? null : PageSnapshot::describe($snapshot);

            $system = $this->context->moduleSystemPrompt((string) $module->label, $briefing, $organisation, $page, $screen);
        } else {
            $briefing = $this->context->briefing($institute);
            $system = $this->context->systemPrompt($briefing, $organisation);
        }

        $messages = array_merge(
            [[
                'role' => 'system',
                'content' => $system,
            ]],
            $history,
            [['role' => 'user', 'content' => $message]]
        );

        try {
            $completion = $this->models->complete(
                self::MODULE,
                $messages,
                // Low but not zero. A factual assistant should not be inventive, and
                // exactly zero makes it repeat itself verbatim when asked a question
                // twice, which reads as a broken screen rather than a consistent one.
                ['temperature' => 0.2],
                $institute,
                // The screen's module, so a model chosen on that module's AI Stack
                // Models tab is the one that answers here.
                $moduleKey
            );
        } catch (AiNotConfiguredException $exception) {
            $this->recordModuleActivity($scope, $module, 'failed', $exception->getMessage(), (int) $conversation->id);

            return $this->recordFailure($scope, $conversation, $exception, configured: false);
        } catch (Throwable $exception) {
            $this->recordModuleActivity($scope, $module, 'failed', $exception->getMessage(), (int) $conversation->id);

            return $this->recordFailure($scope, $conversation, $exception, configured: true);
        }

        $answer = $completion->text !== ''
            ? $completion->text
            // A 200 with no content. Real, and it happens on a truncated first token;
            // saying so beats storing an empty assistant turn the UI renders as blank.
            : 'The model returned an empty answer. Try rephrasing the question.';

        $this->conversations->addTurn(
            (int) $conversation->id,
            $institute,
            'assistant',
            $answer,
            $completion->toArray()
        );

        $this->audit->record('ai.conversation.answered', $scope, [
            'related_type' => 'ai_conversations',
            'related_id' => (int) $conversation->id,
            'message' => sprintf(
                'Answered in conversation %d using %s/%s.',
                $conversation->id,
                $completion->provider,
                $completion->model ?? 'provider default'
            ),
            // Counts and model, never the message text. The transcript already holds
            // what was said, and a second copy in the audit table would double the
            // places an organisation's data has to be protected.
            'payload' => $completion->toArray(),
        ]);

        $this->recordModuleActivity(
            $scope,
            $module,
            'completed',
            'Answered a question in the module chat.',
            (int) $conversation->id,
            $completion->toArray()['latency_ms'] ?? null
        );

        return [
            'conversation_id' => (int) $conversation->id,
            'session_key' => $sessionKey,
            'answer' => $answer,
            'grounded' => $briefing !== null,
            'module_key' => $module === null ? null : (string) $module->module_key,
            'truncated' => $completion->wasTruncated(),
            'usage' => $completion->toArray(),
        ];
    }

    /**
     * The page the chat is opened on, when it belongs to this module's menu tree.
     *
     * @return array{title:string, breadcrumb:array<int,string>, module_key:string}|null
     */
    private function pageUnder(AiRequestScope $scope, int $menuId, string $moduleKey): ?array
    {
        $resolved = $this->pages->resolve($scope, $menuId);

        if ($resolved['page'] === null || $resolved['module'] === null || $resolved['module']['root_key'] !== $moduleKey) {
            return null;
        }

        return [
            'title' => $resolved['page']['title'],
            'breadcrumb' => $resolved['page']['breadcrumb'],
            'module_key' => $resolved['module']['key'],
        ];
    }

    /**
     * One row in the module's Activity ledger (`module.<key>.chat`), so the AI Stack's
     * Activity tab shows chat the same way it shows every other operation. Only for a chat
     * opened inside a registered module; the organisation-wide assistant has no module ledger.
     * The question text is not copied - it is in the transcript.
     */
    private function recordModuleActivity(
        AiRequestScope $scope,
        ?object $module,
        string $status,
        string $message,
        int $conversationId,
        ?int $durationMs = null
    ): void {
        if ($module === null) {
            return;
        }

        $key = (string) $module->module_key;

        $this->audit->record("module.{$key}.chat", $scope, [
            'related_type' => 'ai_conversations',
            'related_id' => $conversationId,
            'outcome' => match ($status) {
                'completed' => 'success',
                'denied' => 'rejected',
                default => 'failure',
            },
            'message' => $message,
            'payload' => [
                'module' => $key,
                'operation' => 'chat',
                'operation_label' => 'Chat question',
                'capability' => 'conversational',
                'status' => $status,
                'reference' => 'conversation-' . $conversationId,
                'duration_ms' => $durationMs,
                // G2G's chat does not consult a knowledge graph; null is "not measured".
                'knowledge_graph_used' => null,
            ],
        ]);
    }

    /**
     * A question the resolved policy does not permit.
     *
     * Recorded like a failure - the question is already a turn, so an assistant turn carries
     * the reason and the audit row names the policy - but reported as a refusal, so the
     * screen shows the rule rather than "try again".
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     */
    private function recordRefusal(AiRequestScope $scope, object $conversation, array $decision): array
    {
        $institute = $scope->selectedInstituteId;
        $reason = (string) $decision['message'];

        $this->conversations->addTurn(
            (int) $conversation->id,
            $institute,
            'assistant',
            '',
            ['error' => $reason]
        );

        $this->audit->recordRejection($reason, $scope, [
            'related_type' => 'ai_conversations',
            'related_id' => (int) $conversation->id,
            'payload' => ['policy_id' => $decision['policy_id'], 'refused_by' => 'policy'],
        ]);

        return [
            'conversation_id' => (int) $conversation->id,
            'session_key' => (string) $conversation->session_key,
            'answer' => null,
            'error' => $reason,
            'configured' => true,
            'refused' => true,
            'policy_id' => $decision['policy_id'],
        ];
    }

    /**
     * Store the failure as a turn and audit it.
     *
     * `configured: false` separates "nobody has set this up" from "the provider
     * broke". The first is a normal state with a known fix and is recorded as a
     * refusal; the second is a fault. Logging both as failures would put a brand-new
     * organisation's first question in the same bucket as a provider outage.
     *
     * @return array<string, mixed>
     */
    private function recordFailure(
        AiRequestScope $scope,
        object $conversation,
        Throwable $exception,
        bool $configured
    ): array {
        $institute = $scope->selectedInstituteId;

        $this->conversations->addTurn(
            (int) $conversation->id,
            $institute,
            'assistant',
            '',
            ['error' => $exception->getMessage()]
        );

        $configured
            ? $this->audit->record('ai.conversation.failed', $scope, [
                'related_type' => 'ai_conversations',
                'related_id' => (int) $conversation->id,
                'outcome' => 'failure',
                'message' => $exception->getMessage(),
            ])
            : $this->audit->recordRejection($exception->getMessage(), $scope, [
                'related_type' => 'ai_conversations',
                'related_id' => (int) $conversation->id,
            ]);

        return [
            'conversation_id' => (int) $conversation->id,
            'session_key' => (string) $conversation->session_key,
            'answer' => null,
            'error' => $exception->getMessage(),
            // Lets the screen distinguish "go and add a credential" from "try again".
            'configured' => $configured,
        ];
    }
}
