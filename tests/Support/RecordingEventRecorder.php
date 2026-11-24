<?php

namespace Tests\Support;

use App\Services\Events\EventRecorder;

/**
 * An event recorder that keeps what it was asked to write instead of writing it (S15 follow-up, 24 Nov 2026): the delete and move tests read the history lines a change
 * leaves without an event store. A test binds it with `$this->app->instance(EventRecorder::class, $recorder)` and reads `$recorder->lines`.
 */
final class RecordingEventRecorder extends EventRecorder
{
    /** @var list<array{type: string, tenant: int, entityType: string, entityId: ?int, actor: ?int, payload: array<string, mixed>}> */
    public array $lines = [];

    /** @var list<int> the database transaction level at the moment of each write: 0 means the line was written AFTER the commit */
    public array $levels = [];

    /** Set to a message to make every write fail, as an event store that is down would. */
    public ?string $failWith = null;

    public function record(string $type, int $subInstituteId, string $entityType, ?int $entityId, ?int $actorId, array $payload, ?array $metadata = null, ?string $idempotencyKey = null, ?string $correlationId = null, ?string $causationId = null, ?int $actingForId = null, ?string $occurredAt = null): int
    {
        if ($this->failWith !== null) {
            throw new \RuntimeException($this->failWith);
        }
        $this->levels[] = \Illuminate\Support\Facades\DB::transactionLevel();
        $this->lines[] = ['type' => $type, 'tenant' => $subInstituteId, 'entityType' => $entityType, 'entityId' => $entityId, 'actor' => $actorId, 'payload' => $payload];

        return count($this->lines);
    }

    /** @return list<array{type: string, tenant: int, entityType: string, entityId: ?int, actor: ?int, payload: array<string, mixed>}> the lines of one type */
    public function of(string $type): array
    {
        return array_values(array_filter($this->lines, fn (array $l) => $l['type'] === $type));
    }
}
