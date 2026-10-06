<?php

namespace App\Services\Events;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONLY way anything is written to g2g_event.
 *
 * `05-data-flow-contracts.md` §1: the event store is the single source of record,
 * append-only, written in the same transaction as the state change it describes.
 *
 * TWO THINGS THIS CLASS GUARANTEES, because they are the reason item 6 was
 * sequenced after G-SEC-12:
 *
 *   - `actor_id` is passed in from the caller's RESOLVED IDENTITY. It is never
 *     read from a request here, and callers that have no resolved identity pass
 *     null - which means SYSTEM, a real value, not "unknown".
 *   - `sub_institute_id` is REQUIRED and never defaulted. This is the one table
 *     that will hold every tenant's history, so it is the worst possible place
 *     for a missing tenant to recur (G-DATA-08).
 *
 * No UPDATE, no DELETE. A mistake is corrected by a compensating event.
 */
class EventRecorder
{
    /**
     * Append one event and return its id.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>|null  $metadata
     */
    public function record(
        string $type,
        int $subInstituteId,
        string $entityType,
        ?int $entityId,
        ?int $actorId,
        array $payload,
        ?array $metadata = null,
        ?string $idempotencyKey = null,
        ?string $correlationId = null,
        ?string $causationId = null,
        ?int $actingForId = null,
        ?string $occurredAt = null
    ): int {
        if ($subInstituteId <= 0) {
            // Refused loudly. An event with no tenant is unattributable history,
            // and a store that accepts one cannot be trusted to answer
            // "what happened in this organisation?".
            throw new \InvalidArgumentException('An event requires a tenant.');
        }

        $now = now()->format('Y-m-d H:i:s.v');

        return (int) DB::table('g2g_event')->insertGetId([
            'event_uuid'       => (string) Str::uuid(),
            'type'             => $type,
            'sub_institute_id' => $subInstituteId,
            'entity_type'      => $entityType,
            'entity_id'        => $entityId,
            'actor_id'         => $actorId,
            'acting_for_id'    => $actingForId,
            'payload'          => json_encode($payload),
            'metadata'         => $metadata === null ? null : json_encode($metadata),
            'correlation_id'   => $correlationId,
            'causation_id'     => $causationId,
            'idempotency_key'  => $idempotencyKey,
            // occurred_at is when the fact happened; recorded_at is when we heard
            // about it. Imports and offline actions differ, and conflating them
            // silently reorders history.
            'occurred_at'      => $occurredAt ?? $now,
            'recorded_at'      => $now,
        ]);
    }
}
