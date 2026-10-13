<?php

namespace App\Domain\Gtm;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deal health flags. Pure rules over recorded facts - no model is involved, so a flag is
 * something that can be checked against the database, never an opinion. The AI agents are
 * given these flags and coach on them; they do not decide them.
 *
 * Only OPEN deals are assessed. Thresholds come from config/gtm.php.
 */
final class DealHealth
{
    public const FLAGS = [
        'no_next_step' => 'No next step is recorded',
        'next_step_overdue' => 'The next step is past its due date',
        'no_recent_activity' => 'No activity has been logged recently',
        'no_activity_ever' => 'No activity has ever been logged',
        'close_date_passed' => 'The expected close date has passed',
        'no_close_date' => 'No expected close date is set',
        'stuck_in_stage' => 'The deal has sat in this stage for a long time',
        'no_decision_maker' => 'No economic buyer or decision maker is recorded for the account',
    ];

    /**
     * @param  iterable<GtmDeal>  $deals  all from one tenant
     * @return array<int, array{flags: list<string>, days_since_activity: ?int, days_in_stage: ?int, last_activity_at: ?string}> keyed by deal id
     */
    public function assess(int $tenant, iterable $deals, ?Carbon $now = null): array
    {
        $now ??= now();
        $deals = collect($deals)->filter(fn ($d) => $d->isOpen())->values();
        if ($deals->isEmpty()) {
            return [];
        }
        $ids = $deals->pluck('id')->all();
        $accountIds = $deals->pluck('account_id')->unique()->all();

        // Activity counts toward a DEAL when logged against the deal or, failing that, the account.
        $lastByDeal = DB::table('gtm_activities')->where('sub_institute_id', $tenant)->whereIn('deal_id', $ids)->where('type', '!=', 'system')
            ->selectRaw('deal_id, MAX(occurred_at) m')->groupBy('deal_id')->pluck('m', 'deal_id');
        $lastByAccount = DB::table('gtm_activities')->where('sub_institute_id', $tenant)->whereIn('account_id', $accountIds)->where('type', '!=', 'system')
            ->selectRaw('account_id, MAX(occurred_at) m')->groupBy('account_id')->pluck('m', 'account_id');
        $deciders = DB::table('gtm_contacts')->where('sub_institute_id', $tenant)->whereIn('account_id', $accountIds)->whereNull('deleted_at')
            ->whereIn('role_in_deal', ['economic_buyer', 'decision_maker'])->distinct()->pluck('account_id')->flip();

        $quiet = (int) config('gtm.deals.quiet_after_days', 14);
        $stuck = (int) config('gtm.deals.stuck_in_stage_days', 30);
        $out = [];

        foreach ($deals as $d) {
            $last = collect([$lastByDeal[$d->id] ?? null, $lastByAccount[$d->account_id] ?? null])->filter()->max();
            $sinceActivity = $last ? (int) Carbon::parse($last)->diffInDays($now) : null;
            $inStage = $d->stage_changed_at ? (int) $d->stage_changed_at->diffInDays($now) : ($d->created_at ? (int) $d->created_at->diffInDays($now) : null);

            $flags = [];
            if (trim((string) $d->next_step) === '') {
                $flags[] = 'no_next_step';
            } elseif ($d->next_step_due && $d->next_step_due->lt($now->copy()->startOfDay())) {
                $flags[] = 'next_step_overdue';
            }
            if ($last === null) {
                $flags[] = 'no_activity_ever';
            } elseif ($sinceActivity >= $quiet) {
                $flags[] = 'no_recent_activity';
            }
            if ($d->expected_close_date === null) {
                $flags[] = 'no_close_date';
            } elseif ($d->expected_close_date->lt($now->copy()->startOfDay())) {
                $flags[] = 'close_date_passed';
            }
            if ($inStage !== null && $inStage >= $stuck) {
                $flags[] = 'stuck_in_stage';
            }
            if (! isset($deciders[$d->account_id])) {
                $flags[] = 'no_decision_maker';
            }

            $out[$d->id] = ['flags' => $flags, 'days_since_activity' => $sinceActivity, 'days_in_stage' => $inStage, 'last_activity_at' => $last ? (string) $last : null];
        }

        return $out;
    }
}
