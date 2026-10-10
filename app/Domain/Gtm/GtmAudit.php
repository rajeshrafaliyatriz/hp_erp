<?php

namespace App\Domain\Gtm;

use App\Services\Events\EventRecorder;
use Illuminate\Support\Facades\Log;

/**
 * Every consequential GTM write leaves a g2g_event row (the platform's append-only
 * store), so GTM history is queryable in the same place as everything else rather
 * than in a table only this feature reads.
 *
 * Recording must never fail the action it describes: the event is written AFTER the
 * change commits and a failure is logged, not thrown.
 */
final class GtmAudit
{
    /** @param array<string,mixed> $payload */
    public static function record(string $type, int $tenantId, string $entityType, ?int $entityId, ?int $actorId, array $payload = []): void
    {
        try {
            app(EventRecorder::class)->record($type, $tenantId, $entityType, $entityId, $actorId, $payload);
        } catch (\Throwable $e) {
            Log::warning('GTM audit event not recorded', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }
}
