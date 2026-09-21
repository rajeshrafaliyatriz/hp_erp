<?php

namespace App\Domain\AI\Conversation;

use App\Domain\AI\Support\AiAuditLogger;
use App\Domain\AI\Support\AiModelClient;
use App\Domain\AI\Support\AiNotConfiguredException;
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

        $briefing = $this->context->briefing($institute);

        $messages = array_merge(
            [[
                'role' => 'system',
                'content' => $this->context->systemPrompt(
                    $briefing,
                    $organisationName ?: 'this organisation'
                ),
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
                $institute
            );
        } catch (AiNotConfiguredException $exception) {
            return $this->recordFailure($scope, $conversation, $exception, configured: false);
        } catch (Throwable $exception) {
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

        return [
            'conversation_id' => (int) $conversation->id,
            'session_key' => $sessionKey,
            'answer' => $answer,
            'grounded' => $briefing !== null,
            'truncated' => $completion->wasTruncated(),
            'usage' => $completion->toArray(),
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
