<?php

namespace App\Domain\Facts;

use App\Services\Events\EventCatalogue;
use App\Services\Events\EventRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writes the g2g_event rows of the facts ledger (the platform's append-only store; AuditLogProjector projects them into g2g_audit_log).
 *
 *   fact.proposed   a fact was stored, waiting for a person   key fact.proposed:{tenant}:{fact}
 *   fact.decided    a person accepted or dismissed it          key fact.decided:{tenant}:{fact}
 *
 * A fact is proposed once and decided once (its status moves from proposed to applied or dismissed a single time), so one key per fact and kind never loses
 * an event. Replacing an older applied fact (superseded) is part of the NEW fact's decided event (`superseded` in its payload), not an event of its own: the
 * older fact's key is already taken by its own decision.
 *
 * IDEMPOTENCY IS A UNIQUE INDEX, NOT THIS CLASS. g2g_event has a unique (tenant, idempotency_key): a key coarser than the thing it names loses the second
 * event without a word, so every key carries the id of the fact. A repeat of the SAME event (a retry) is the one error absorbed: the stored event is
 * returned. Any other failure is let through, so a decision and its event are saved together or not at all (callers run this inside the transaction that
 * writes the state change). Same design as App\Domain\AgentGateway\AgentEvents.
 */
final class FactEvents
{
    public const PROPOSED = 'fact.proposed';

    public const DECIDED = 'fact.decided';

    /** @param array<string, mixed> $payload */
    public function proposed(int $tenantId, int $factId, ?int $userId, array $payload): int
    {
        return $this->record(self::PROPOSED, $tenantId, $factId, $userId, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function decided(int $tenantId, int $factId, ?int $userId, array $payload): int
    {
        return $this->record(self::DECIDED, $tenantId, $factId, $userId, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function record(string $type, int $tenantId, int $factId, ?int $userId, array $payload): int
    {
        if (! isset(EventCatalogue::SHIPPED[$type])) {
            Log::warning('Facts event type is not registered in EventCatalogue', ['type' => $type]);
        }
        $key = "{$type}:{$tenantId}:{$factId}";

        try {
            return app(EventRecorder::class)->record($type, $tenantId, 'g2g_entity_facts', $factId, $userId, $payload, null, $key);
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
