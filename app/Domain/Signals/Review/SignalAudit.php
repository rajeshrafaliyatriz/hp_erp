<?php

namespace App\Domain\Signals\Review;

use App\Services\Events\EventCatalogue;
use App\Services\Events\EventRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writes the g2g_event row for a decision on a signal (the platform's append-only store; AuditLogProjector projects it into g2g_audit_log).
 *
 * Unlike GtmAudit, which records AFTER the change commits and only logs a failure, this runs INSIDE the transaction that writes the history row
 * and the signal's own columns, and lets every failure through: if the event cannot be written, nothing is saved. The one error it absorbs is
 * the unique (tenant, idempotency_key) index refusing a repeat, which is what the key is for; the event already stored is returned.
 *
 * The key is `{type}:{tenant}:{opportunity}:{review row}`: one decision, one event, whatever retries.
 */
final class SignalAudit
{
    /** @var array<string, string> history decision => event type (the screens call follow_up "Contacted") */
    public const TYPES = [
        'reviewed' => 'signals.opportunity.reviewed',
        'dismissed' => 'signals.opportunity.dismissed',
        'follow_up' => 'signals.opportunity.contacted',
        'reopened' => 'signals.opportunity.reopened',
        'promoted' => 'signals.opportunity.promoted',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return int the event id, or the existing one when this decision's key was already used
     */
    public static function record(string $type, int $tenantId, int $opportunityId, ?int $userId, array $payload, int $reviewId): int
    {
        if (! isset(EventCatalogue::SHIPPED[$type])) {
            Log::warning('Signals event type is not registered in EventCatalogue', ['type' => $type]);
        }

        $key = "{$type}:{$tenantId}:{$opportunityId}:{$reviewId}";

        try {
            return app(EventRecorder::class)->record($type, $tenantId, 'g2g_company_opportunities', $opportunityId, $userId, $payload, null, $key);
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
