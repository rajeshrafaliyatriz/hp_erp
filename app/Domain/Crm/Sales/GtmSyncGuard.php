<?php

namespace App\Domain\Crm\Sales;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Makes the CRM opportunity <-> GTM deal sync bookkeeping, never part of saving (defect D17, slice 11).
 *
 * OpportunityGtmSyncBridge keeps crm_opportunities and gtm_deals in step. It used to run inline after a save with no try/catch, so any failure inside it (a GTM
 * column a host is behind on, a constraint, a lock) turned a saved record into a 500 after the data was already written. Every call site now goes through here:
 *
 *   - Throwable is caught (not only exceptions: a TypeError or an Error from an old host's missing class must not reach the person either);
 *   - the failure is logged once per attempt with the direction, the organisation, the record id and the error, and counted for the day;
 *   - nothing is rethrown, and the caller carries on exactly as if the sync had succeeded: the row and its history are already written;
 *   - a database transaction the failed sync left open is rolled back to the level it found, and the log says so. (The six call sites run outside any
 *     transaction of their own, so this is a guarantee for a future caller that does call it inside one, not a case seen today.)
 *
 * The repair is `php artisan opportunities:resync-gtm` (a dry run unless --apply), which re-runs the same sync for the records that are out of step.
 *
 * The bridge is resolved from the container so that a test can bind a bridge that throws; in production the container makes the plain class.
 */
final class GtmSyncGuard
{
    public const FORWARD = 'opportunity_to_gtm';

    public const REVERSE = 'gtm_to_opportunity';

    /** Sync one CRM opportunity into its GTM deal. Returns the deal id, or null when it was not synced (no such row, or the sync failed). Never throws. */
    public static function opportunityToGtm(int $tenantId, int $opportunityId, ?int $actorId = null): ?int
    {
        return self::run(self::FORWARD, $tenantId, $opportunityId, static function (OpportunityGtmSyncBridge $bridge) use ($tenantId, $opportunityId, $actorId) {
            return $bridge::syncOpportunityToGtm($tenantId, $opportunityId, $actorId);
        });
    }

    /** Sync one GTM deal into its CRM opportunity. Returns the opportunity id, or null when it was not synced. Never throws. */
    public static function dealToOpportunity(int $tenantId, int $dealId, ?int $actorId = null): ?int
    {
        return self::run(self::REVERSE, $tenantId, $dealId, static function (OpportunityGtmSyncBridge $bridge) use ($tenantId, $dealId, $actorId) {
            return $bridge::syncGtmDealToOpportunity($tenantId, $dealId, $actorId);
        });
    }

    /** How many syncs failed today for one organisation, as far as this server's cache remembers (it resets with the cache; the repair command's dry run is the exact measure). */
    public static function failuresToday(int $tenantId): int
    {
        try {
            return (int) Cache::get(self::counterKey($tenantId), 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @param callable(OpportunityGtmSyncBridge): ?int $call */
    private static function run(string $direction, int $tenantId, int $recordId, callable $call): ?int
    {
        $level = self::level();

        try {
            $result = $call(app(OpportunityGtmSyncBridge::class));

            return $result === null ? null : (int) $result;
        } catch (\Throwable $e) {
            $restored = self::restoreTransactionLevel($level);
            self::report($direction, $tenantId, $recordId, $e, $restored);

            return null;
        }
    }

    private static function level(): int
    {
        try {
            return DB::transactionLevel();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Rolls back any transaction the failed sync opened and did not close, down to the level it started at. Returns how many it rolled back. */
    private static function restoreTransactionLevel(int $level): int
    {
        $rolledBack = 0;

        try {
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
                $rolledBack++;
            }
        } catch (\Throwable) {
            // Whatever state the connection is in, the person's save must still answer.
        }

        return $rolledBack;
    }

    private static function report(string $direction, int $tenantId, int $recordId, \Throwable $e, int $rolledBack): void
    {
        try {
            Log::warning('CRM/GTM sync failed (' . $direction . '). The record was saved; run "php artisan opportunities:resync-gtm" to repair it.', [
                'direction' => $direction,
                'sub_institute_id' => $tenantId,
                ($direction === self::FORWARD ? 'opportunity_id' : 'deal_id') => $recordId,
                'error' => $e->getMessage(),
                'exception' => $e::class,
                'at' => basename($e->getFile()) . ':' . $e->getLine(),
                'transactions_rolled_back' => $rolledBack,
            ]);
        } catch (\Throwable) {
            // Even logging must not reach the caller.
        }

        try {
            Cache::add(self::counterKey($tenantId), 0, now()->addDays(8));
            Cache::increment(self::counterKey($tenantId));
        } catch (\Throwable) {
            // The count is a convenience; losing it is better than failing a save.
        }
    }

    private static function counterKey(int $tenantId): string
    {
        return 'crm:gtm-sync-failures:' . $tenantId . ':' . now()->format('Ymd');
    }
}
