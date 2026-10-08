<?php

namespace App\Domain\AI\Lifecycle;

use App\Domain\AI\Conversation\ConversationStore;
use App\Domain\AI\Support\AiAuditLogger;
use App\Services\Ai\AiRequestScope;

/**
 * One chat turn through the twelve-stage lifecycle.
 *
 * Counterpart of AskPipeline for when `ai.lifecycle.enabled` is on; the response keeps every
 * field AskPipeline returns (so existing callers are unaffected) and adds the trace, the
 * evidence, recommendations, and any proposed action or generated report.
 *
 * Everything is recorded: the assistant turn carries the trace; the audit ledger gets the answer
 * (or refusal/failure) and a lifecycle row with the stage statuses; the module's Activity tab
 * gets a `module.<key>.chat` row. Message text is never copied into audit - the transcript holds it.
 */
final class LifecycleAskService
{
    public function __construct(
        private readonly LifecyclePipeline $pipeline,
        private readonly ConversationStore $conversations,
        private readonly AiAuditLogger $audit,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $availableActions
     * @return array<string, mixed>
     */
    public function ask(
        AiRequestScope $scope,
        string $sessionKey,
        string $message,
        ?string $moduleKey,
        ?int $menuId,
        ?array $pageData,
        string $organisation,
        array $availableActions = [],
        ?string $aiStackTab = null
    ): array {
        $context = new LifecycleContext($scope, $sessionKey, $message, $moduleKey, $menuId, $pageData, $organisation, $this->cleanActions($availableActions), $aiStackTab);
        $trace = $this->pipeline->run($context);

        $this->finalise($context);

        $institute = $scope->selectedInstituteId;
        $conversationId = (int) ($context->conversation->id ?? 0);
        $usage = $context->usage ?? [];

        if ($conversationId > 0) {
            $this->conversations->addTurn(
                $conversationId,
                $institute,
                'assistant',
                (string) ($context->answer ?? ''),
                array_merge($usage, [
                    'error' => $context->answer === null ? $context->error : null,
                    'trace' => ['stages' => $trace, 'intent' => $context->intent, 'evidence' => $context->evidence],
                ])
            );
        }

        $this->record($context, $trace, $conversationId);

        return [
            'conversation_id' => $conversationId,
            'session_key' => (string) ($context->conversation->session_key ?? $sessionKey),
            'answer' => $context->answer,
            'error' => $context->answer === null ? $context->error : null,
            'configured' => $context->configured,
            'refused' => $context->refusal !== null,
            'policy_id' => $context->refusal['policy_id'] ?? null,
            'grounded' => $context->briefing !== null || $context->evidence !== [],
            'module_key' => $context->module === null ? null : (string) $context->module->module_key,
            'truncated' => $context->truncated,
            'usage' => $usage,
            // Lifecycle additions.
            'intent' => $context->intent,
            'trace' => $trace,
            'evidence' => array_map(fn ($e) => $this->publicEvidence($e), $context->evidence),
            'recommendations' => $context->recommendations,
            'proposed_action' => $context->proposedAction === null ? null : $context->proposedAction + ['requires_approval' => $context->requiresApproval],
            'report' => $context->report,
            'report_suggestions' => $context->reportSuggestions,
            'template_suggestions' => $context->templateSuggestions,
        ];
    }

    /**
     * Deterministic replies for turns the model does not write, so an action or a report always
     * has a sentence beside it, and a failed model call still says what evidence was found.
     */
    private function finalise(LifecycleContext $context): void
    {
        if ($context->answer !== null) {
            return;
        }

        if ($context->proposedAction !== null) {
            $label = $context->proposedAction['label'];
            $context->answer = $context->requiresApproval
                ? "I've opened \"{$label}\". Review it and send it for approval - nothing is saved until an administrator approves it."
                : "I've opened \"{$label}\". Review it and confirm - nothing is saved until you do.";

            return;
        }

        if ($context->intent === 'report' && $context->refusal === null) {
            $context->answer = $context->report !== null
                ? sprintf('I generated "%s" from %d row%s of this module\'s data. You can open, print or share it below.', $context->report['title'] ?? 'the report', $context->report['row_count'] ?? 0, ($context->report['row_count'] ?? 0) === 1 ? '' : 's')
                : ($context->reportNote ?? "I couldn't find a report template or data source in this module that matches that request.")
                    . ($context->reportSuggestions === [] ? '' : ' These report types are available:');
        }
    }

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    private function publicEvidence(array $evidence): array
    {
        return [
            'id' => $evidence['id'],
            'label' => $evidence['label'],
            'source' => $evidence['source'],
            'module' => $evidence['module'],
            'total' => $evidence['total'],
            'truncated' => $evidence['truncated'],
            'personal' => $evidence['personal'],
            'columns' => $evidence['columns'],
            'as_of' => $evidence['as_of'],
        ];
    }

    /**
     * Keep only well-formed action metadata. Phrases and labels are used for matching and display
     * only; they carry no data and cannot widen what any action may do.
     *
     * @param  array<int, mixed>  $actions
     * @return array<int, array{key:string,label:string,description:string,phrases:array<int,string>}>
     */
    private function cleanActions(array $actions): array
    {
        $clean = [];

        foreach (array_slice($actions, 0, 30) as $action) {
            if (! is_array($action) || ! is_string($action['key'] ?? null) || $action['key'] === '') {
                continue;
            }

            $clean[] = [
                'key' => mb_substr($action['key'], 0, 80),
                'label' => mb_substr((string) ($action['label'] ?? $action['key']), 0, 120),
                'description' => mb_substr((string) ($action['description'] ?? ''), 0, 300),
                'phrases' => array_values(array_map(fn ($p) => mb_substr((string) $p, 0, 80), array_slice((array) ($action['phrases'] ?? []), 0, 12))),
            ];
        }

        return $clean;
    }

    /** @param array<int, array<string, mixed>> $trace */
    private function record(LifecycleContext $context, array $trace, int $conversationId): void
    {
        $scope = $context->scope;
        $statuses = array_column($trace, 'status', 'key');
        $reasoning = $statuses[StageKey::Reasoning->value] ?? null;

        if ($context->refusal !== null) {
            $this->audit->recordRejection((string) $context->error, $scope, [
                'related_type' => 'ai_conversations',
                'related_id' => $conversationId,
                'payload' => ['policy_id' => $context->refusal['policy_id'], 'refused_by' => 'policy'],
            ]);
        } elseif ($context->answer !== null && $context->usage !== null) {
            $this->audit->record('ai.conversation.answered', $scope, [
                'related_type' => 'ai_conversations',
                'related_id' => $conversationId,
                'message' => sprintf('Answered in conversation %d.', $conversationId),
                'payload' => $context->usage,
            ]);
        } elseif ($reasoning === StageStatus::Failed->value) {
            $context->configured
                ? $this->audit->record('ai.conversation.failed', $scope, [
                    'related_type' => 'ai_conversations', 'related_id' => $conversationId,
                    'outcome' => 'failure', 'message' => (string) $context->error,
                ])
                : $this->audit->recordRejection((string) $context->error, $scope, [
                    'related_type' => 'ai_conversations', 'related_id' => $conversationId,
                ]);
        }

        // The lifecycle ledger row: which intent, what each stage did. Counts and statuses only.
        $this->audit->record('ai.lifecycle.completed', $scope, [
            'related_type' => 'ai_conversations',
            'related_id' => $conversationId,
            'message' => sprintf('Lifecycle finished for conversation %d (%s).', $conversationId, $context->intent),
            'payload' => [
                'intent' => $context->intent,
                'stages' => $statuses,
                'evidence' => count($context->evidence),
                'proposed_action' => $context->proposedAction['key'] ?? null,
                'report_id' => $context->report['id'] ?? null,
            ],
        ]);

        if ($context->module === null) {
            return;
        }

        $key = (string) $context->module->module_key;
        $failed = $context->refusal !== null || ($context->answer === null);

        $this->audit->record("module.{$key}.chat", $scope, [
            'related_type' => 'ai_conversations',
            'related_id' => $conversationId,
            'outcome' => $context->refusal !== null ? 'rejected' : ($failed ? 'failure' : 'success'),
            'message' => $context->refusal !== null ? (string) $context->error : ($failed ? (string) $context->error : 'Answered a question in the module chat.'),
            'payload' => [
                'module' => $key,
                'operation' => 'chat',
                'operation_label' => 'Chat ' . $context->intent,
                'capability' => 'conversational',
                'status' => $context->refusal !== null ? 'denied' : ($failed ? 'failed' : 'completed'),
                'reference' => 'conversation-' . $conversationId,
                'duration_ms' => $context->usage['latency_ms'] ?? null,
                'knowledge_graph_used' => null,
                'intent' => $context->intent,
                'evidence' => count($context->evidence),
            ],
        ]);
    }
}
