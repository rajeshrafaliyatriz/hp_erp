<?php

namespace App\Domain\Gtm;

use App\Services\Events\EventRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Every consequential GTM write leaves a g2g_event row (the platform's append-only store), so GTM
 * history is queryable in the same place as everything else and is projected into g2g_audit_log
 * by the existing AuditLogProjector.
 *
 * Recording must never fail the action it describes: the event is written AFTER the change
 * commits and a failure is logged, not thrown. An unregistered type is logged loudly (it would
 * not be in EventCatalogue) but still recorded - losing history is worse than an untidy name.
 *
 * `$metadata['source'] = 'workflow'` marks an event caused by a workflow action. The workflow engine
 * ignores those, which is what stops a workflow from triggering itself.
 */
final class GtmAudit
{
    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>|null  $metadata
     * @return int|null the event id, or the existing one when the idempotency key was already used
     */
    public static function record(string $type, int $tenantId, string $entityType, ?int $entityId, ?int $actorId, array $payload = [], ?array $metadata = null, ?string $idempotencyKey = null): ?int
    {
        if (! GtmEvents::exists($type)) {
            Log::warning('GTM event type is not registered in GtmEvents/EventCatalogue', ['type' => $type]);
        }

        try {
            return app(EventRecorder::class)->record($type, $tenantId, $entityType, $entityId, $actorId, $payload, $metadata, $idempotencyKey);
        } catch (QueryException $e) {
            // The unique (tenant, idempotency_key) index refused a repeat: that is the point of the key.
            if ($idempotencyKey !== null) {
                return DB::table('g2g_event')->where('sub_institute_id', $tenantId)->where('idempotency_key', $idempotencyKey)->value('id');
            }
            Log::warning('GTM audit event not recorded', ['type' => $type, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::warning('GTM audit event not recorded', ['type' => $type, 'error' => $e->getMessage()]);
        }

        return null;
    }
}
