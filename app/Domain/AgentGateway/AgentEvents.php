<?php

namespace App\Domain\AgentGateway;

use App\Services\Events\EventCatalogue;
use App\Services\Events\EventRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writes the g2g_event rows of the agent (the platform's append-only store; AuditLogProjector projects them into g2g_audit_log).
 *
 *   agent.run.finished       a run ended (any status)           key agent.run.finished:{tenant}:{run}
 *   agent.proposal.decided   a person accepted or dismissed it   key agent.proposal.decided:{tenant}:{proposal}
 *   agent.action.applied     an action was carried out           key agent.action.applied:{tenant}:{proposal}
 *   agent.autonomy.changed   an administrator changed a level    key agent.autonomy.changed:{tenant}:{audit row of that change}
 *                            or the kill switch
 *
 * IDEMPOTENCY IS A UNIQUE INDEX, NOT THIS CLASS. g2g_event has a unique (tenant, idempotency_key): a key that is coarser than the thing it names loses the
 * second event without a word, so every key carries the id of the run or proposal it is about. A repeat of the SAME event (a retry) is the one error
 * absorbed: the event already stored is returned. Any other failure is let through, so the decision and its event are saved together or not at all
 * (callers run it inside the transaction that writes the state change).
 */
final class AgentEvents
{
    public const RUN_FINISHED = 'agent.run.finished';

    public const PROPOSAL_DECIDED = 'agent.proposal.decided';

    public const ACTION_APPLIED = 'agent.action.applied';

    public const AUTONOMY_CHANGED = 'agent.autonomy.changed';

    /** @param array<string, mixed> $payload */
    public function runFinished(int $tenantId, int $runId, ?int $userId, string $correlationId, array $payload): int
    {
        return $this->record(self::RUN_FINISHED, $tenantId, 'g2g_agent_runs', $runId, $userId, $payload, $correlationId);
    }

    /** @param array<string, mixed> $payload */
    public function proposalDecided(int $tenantId, int $proposalId, ?int $userId, array $payload): int
    {
        return $this->record(self::PROPOSAL_DECIDED, $tenantId, 'g2g_agent_proposals', $proposalId, $userId, $payload, null);
    }

    /** @param array<string, mixed> $payload */
    public function actionApplied(int $tenantId, int $proposalId, ?int $userId, array $payload): int
    {
        return $this->record(self::ACTION_APPLIED, $tenantId, 'g2g_agent_proposals', $proposalId, $userId, $payload, null);
    }

    /**
     * A person changed an autonomy level or the kill switch (S7b). A setting is changed MANY times (up, down, up again), so the row of the setting is not a
     * key: the key carries `$changeId`, the id of the audit row this very change wrote, which no other change has. `$entityId` is the id of the setting's
     * row (the row that was written, or the one that was removed when a level went back to its default).
     *
     * @param array<string, mixed> $payload
     */
    public function autonomyChanged(int $tenantId, int $entityId, int $changeId, ?int $userId, array $payload): int
    {
        return $this->record(self::AUTONOMY_CHANGED, $tenantId, 'g2g_agent_autonomy', $entityId, $userId, $payload, null, self::AUTONOMY_CHANGED . ":{$tenantId}:{$changeId}");
    }

    /** @param array<string, mixed> $payload */
    private function record(string $type, int $tenantId, string $entityType, int $entityId, ?int $userId, array $payload, ?string $correlationId, ?string $key = null): int
    {
        if (! isset(EventCatalogue::SHIPPED[$type])) {
            Log::warning('Agent event type is not registered in EventCatalogue', ['type' => $type]);
        }
        $key ??= "{$type}:{$tenantId}:{$entityId}";

        try {
            return app(EventRecorder::class)->record($type, $tenantId, $entityType, $entityId, $userId, $payload, null, $key, $correlationId);
        } catch (UniqueConstraintViolationException $e) {
            $existing = DB::table('g2g_event')->where('sub_institute_id', $tenantId)->where('idempotency_key', $key)->value('id');
            // A unique index other than the idempotency key refused it: that is a real failure, not a repeat.
            if ($existing === null) {
                throw $e;
            }

            return (int) $existing;
        }
    }
}
