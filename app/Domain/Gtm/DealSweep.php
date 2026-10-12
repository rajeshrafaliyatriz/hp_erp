<?php

namespace App\Domain\Gtm;

use Illuminate\Support\Facades\DB;

/**
 * Announces deal health flags as events so a workflow can react ("deal has no next step -> remind the owner").
 * Run by the daily scheduler pass. The idempotency key is (deal, flag, ISO week): a flag that stays raised is
 * announced once a week, never every day, and a repeat run in the same week announces nothing.
 */
final class DealSweep
{
    /** @return int events newly recorded */
    public function run(int $tenant): int
    {
        $open = GtmDeal::where('sub_institute_id', $tenant)->whereIn('stage', GtmDeal::OPEN_STAGES)->get();
        if ($open->isEmpty()) {
            return 0;
        }
        $new = 0;
        $week = now()->format('o-W');
        foreach (app(DealHealth::class)->assess($tenant, $open) as $dealId => $h) {
            foreach ($h['flags'] as $flag) {
                $key = "deal-flag:{$dealId}:{$flag}:{$week}";
                $exists = DB::table('g2g_event')->where('sub_institute_id', $tenant)->where('idempotency_key', $key)->exists();
                GtmAudit::record('gtm.deal.health_flagged', $tenant, 'gtm_deals', (int) $dealId, null, ['flag' => $flag, 'label' => DealHealth::FLAGS[$flag] ?? $flag], ['source' => 'sweep'], $key);
                $exists ?: $new++;
            }
        }

        return $new;
    }
}
