<?php

namespace App\Domain\Crm\Sales;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs what a failed sync left behind (defect D17, slice 11; the command is opportunities:resync-gtm). Once the sync became bookkeeping (GtmSyncGuard) a
 * failure no longer stops a save, so the record and its counterpart can differ. This finds them and runs the SAME sync again, through the same guard.
 *
 * It repairs only what the sync itself would have written, and nothing more:
 *
 *   unlinked        an opportunity with no GTM deal (the sync failed when it was made, or the opportunity came from the CSV import, a converted lead or a signal,
 *                   which never ran it). The sync makes the deal and links it.
 *   stale           a linked deal that differs from what the sync would write from the opportunity, AND the opportunity was saved at or after the deal's last
 *                   change. The sync updates the deal. If the DEAL is the newer one, somebody edited it in GTM after the opportunity was last saved: it is left
 *                   alone and counted ("deal newer"), because pushing the opportunity over it would undo that edit.
 *   deal removed    the linked deal no longer exists (it was deleted on purpose): left alone and counted. The next edit of the opportunity would make a new one.
 *   deals without   (only with includeDeals) a GTM deal that has no opportunity: the reverse sync makes one. It never updates an opportunity that exists.
 *
 * "Differs" is judged with the sync's own expressions (an empty amount on the opportunity does not clear the deal's amount, and so on), so a pair the healthy
 * sync would leave as it is is NOT reported, and after a repair the same check finds nothing: a second run changes nothing.
 *
 * Every read and write carries the organisation. Each repair is one call to the guard (atomic inside the sync, never throws); one that fails is counted and the
 * next carries on, so the run is not one big transaction.
 */
final class GtmResync
{
    private const CHUNK = 200;

    /** @return list<int> the organisations that have opportunities */
    public function tenants(): array
    {
        return DB::table('crm_opportunities')->where('sub_institute_id', '>', 0)->distinct()->orderBy('sub_institute_id')->pluck('sub_institute_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * @return array<string, mixed> counts of what was (or would be) repaired, and why the rest was left
     *
     * @throws GtmResyncBlocked when this connection lacks a table the sync needs
     */
    public function run(int $tenantId, bool $apply, bool $includeDeals = false): array
    {
        foreach (['crm_opportunities', 'gtm_deals', 'gtm_accounts'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new GtmResyncBlocked("{$table} is not on this connection: there is nothing to sync.");
            }
        }

        $r = [
            'opportunities' => 0, 'in_step' => 0,
            'unlinked' => 0, 'stale' => 0, 'deal_newer' => 0, 'deal_removed' => 0,
            'deals_without_opportunity' => 0,
            'repaired' => 0, 'failed' => 0, 'deals_repaired' => 0, 'deals_failed' => 0,
        ];

        $forward = [];
        DB::table('crm_opportunities')->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->orderBy('id')->chunk(self::CHUNK, function ($opportunities) use (&$r, &$forward, $tenantId) {
            $linked = $opportunities->pluck('converted_from_gtm_deal_id')->filter()->unique()->values()->all();
            $deals = $linked === [] ? collect() : DB::table('gtm_deals')->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->whereIn('id', $linked)->get()->keyBy('id');

            foreach ($opportunities as $opp) {
                $r['opportunities']++;
                if (! $opp->converted_from_gtm_deal_id) {
                    $r['unlinked']++;
                    $forward[] = (int) $opp->id;

                    continue;
                }
                $deal = $deals->get($opp->converted_from_gtm_deal_id);
                if ($deal === null) {
                    $r['deal_removed']++;

                    continue;
                }
                if (! self::differs($opp, $deal)) {
                    $r['in_step']++;

                    continue;
                }
                if (self::dealIsNewer($opp, $deal)) {
                    $r['deal_newer']++;

                    continue;
                }
                $r['stale']++;
                $forward[] = (int) $opp->id;
            }
        });

        $orphans = [];
        if ($includeDeals) {
            $orphans = DB::table('gtm_deals')->where('sub_institute_id', $tenantId)->whereNull('deleted_at')
                ->whereNotExists(function ($q) use ($tenantId) {
                    $q->select(DB::raw(1))->from('crm_opportunities')->whereColumn('crm_opportunities.converted_from_gtm_deal_id', 'gtm_deals.id')->where('crm_opportunities.sub_institute_id', $tenantId);
                })->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
            $r['deals_without_opportunity'] = count($orphans);
        }

        if ($apply) {
            foreach ($forward as $opportunityId) {
                GtmSyncGuard::opportunityToGtm($tenantId, $opportunityId) === null ? $r['failed']++ : $r['repaired']++;
            }
            foreach ($orphans as $dealId) {
                GtmSyncGuard::dealToOpportunity($tenantId, $dealId) === null ? $r['deals_failed']++ : $r['deals_repaired']++;
            }
        }

        return $r;
    }

    /**
     * Whether the sync would change the deal, by the sync's own expressions (OpportunityGtmSyncBridge::syncOpportunityToGtm, the "already linked" branch).
     *
     * @param  object  $opp  a crm_opportunities row
     * @param  object  $deal  a gtm_deals row
     */
    public static function differs(object $opp, object $deal): bool
    {
        $stage = OpportunityGtmSyncBridge::mapSalesStageToGtmStage($opp->sales_stage);
        $closing = in_array($stage, ['won', 'lost'], true);
        $amount = $opp->amount ?: $deal->amount;
        $currency = $opp->currency ?: ($deal->currency ?: 'INR');
        $closingDate = $opp->closing_date ? substr((string) $opp->closing_date, 0, 10) : null;
        $expectedClose = $closingDate ?: ($deal->expected_close_date ? substr((string) $deal->expected_close_date, 0, 10) : null);
        $reason = $opp->close_reason ?: $deal->close_reason;

        return (string) $deal->name !== (string) $opp->name
            || ! self::sameMoney($deal->amount, $amount)
            || (string) $deal->currency !== (string) $currency
            || (string) $deal->stage !== $stage
            || ($deal->expected_close_date ? substr((string) $deal->expected_close_date, 0, 10) : null) !== $expectedClose
            || (string) $deal->close_reason !== (string) $reason
            || ($closing && $deal->closed_at === null)
            || (! $closing && $deal->closed_at !== null);
    }

    /** The deal was changed after the opportunity was last saved. Equal moments count as "the opportunity is the source". */
    public static function dealIsNewer(object $opp, object $deal): bool
    {
        $o = $opp->updated_at ? strtotime((string) $opp->updated_at) : 0;
        $d = $deal->updated_at ? strtotime((string) $deal->updated_at) : 0;

        return $d > $o;
    }

    /** @param mixed $a @param mixed $b */
    private static function sameMoney($a, $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return abs((float) $a - (float) $b) < 0.005;
    }
}
