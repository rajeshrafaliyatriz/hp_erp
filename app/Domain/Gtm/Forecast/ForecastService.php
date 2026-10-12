<?php

namespace App\Domain\Gtm\Forecast;

use App\Domain\Gtm\GtmDeal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the forecast screen's data from real deal records, and keeps the daily snapshots that make
 * accuracy measurable later.
 *
 * CALCULATED vs ACTUAL. `forecast` is arithmetic over open deals and configured probabilities - a
 * projection. `actuals` is what really closed (deals with a closed_at in the window). They are returned
 * in separate blocks and never merged, and `accuracy` appears only when snapshots taken BEFORE a period
 * started exist to compare with what was then won.
 */
final class ForecastService
{
    /** @return array<string, array{probability: ?float, category: string}> */
    public function settings(int $tenant): array
    {
        $rows = DB::table('gtm_stage_settings')->where('sub_institute_id', $tenant)->get()->keyBy('stage');
        $out = [];
        foreach (GtmDeal::OPEN_STAGES as $stage) {
            $r = $rows[$stage] ?? null;
            $out[$stage] = ['probability' => $r && $r->probability !== null ? (float) $r->probability : null, 'category' => $r->category ?? 'pipeline', 'configured' => $r !== null];
        }

        return $out;
    }

    /**
     * Win rate of deals that ever reached each open stage and have since closed. A SUGGESTION shown beside
     * the probability an administrator sets; never applied automatically, and absent below the sample size.
     *
     * @return array<string, array{available: bool, win_rate: ?float, sample: int, minimum: int}>
     */
    public function historicalWinRates(int $tenant): array
    {
        $min = (int) config('gtm.forecast.min_history_deals', 20);
        $out = [];
        foreach (GtmDeal::OPEN_STAGES as $stage) {
            $ids = DB::table('gtm_deal_stage_history')->where('sub_institute_id', $tenant)->where('to_stage', $stage)->distinct()->pluck('deal_id');
            $closed = $ids->isEmpty() ? collect() : GtmDeal::withTrashed()->where('sub_institute_id', $tenant)->whereIn('id', $ids)->whereIn('stage', ['won', 'lost'])->pluck('stage');
            $n = $closed->count();
            $out[$stage] = ['available' => $n >= $min, 'win_rate' => $n >= $min ? round($closed->filter(fn ($s) => $s === 'won')->count() / $n * 100, 1) : null, 'sample' => $n, 'minimum' => $min];
        }

        return $out;
    }

    /**
     * @param  array{from?: ?string, to?: ?string, owner_user_id?: ?int, stage?: ?string, department_id?: ?int, currency?: ?string}  $f
     * @return array<string, mixed>
     */
    public function build(int $tenant, array $f): array
    {
        $from = ! empty($f['from']) ? Carbon::parse($f['from'])->startOfDay() : now()->startOfMonth();
        $to = ! empty($f['to']) ? Carbon::parse($f['to'])->endOfDay() : now()->addMonths(5)->endOfMonth();
        $settings = $this->settings($tenant);

        $q = GtmDeal::query()->where('gtm_deals.sub_institute_id', $tenant)->whereIn('gtm_deals.stage', GtmDeal::OPEN_STAGES);
        $this->scope($q, $tenant, $f);
        $deals = $q->get(['gtm_deals.id', 'gtm_deals.stage', 'gtm_deals.amount', 'gtm_deals.currency', 'gtm_deals.expected_close_date', 'gtm_deals.forecast_category'])
            ->map(fn ($d) => ['id' => $d->id, 'stage' => $d->stage, 'amount' => $d->amount, 'currency' => $d->currency, 'expected_close_date' => $d->expected_close_date?->toDateString(), 'forecast_category' => $d->forecast_category])->all();

        $calc = ForecastMath::summarise($deals, $settings, $from, $to);
        if (! empty($f['currency'])) {
            $calc['periods'] = array_values(array_filter($calc['periods'], fn ($p) => $p['currency'] === $f['currency']));
            $calc['totals_by_currency'] = array_values(array_filter($calc['totals_by_currency'], fn ($p) => $p['currency'] === $f['currency']));
        }

        return [
            'basis' => 'calculated from open deals and the probabilities your administrators configured; not a prediction of results',
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()] + array_intersect_key($f, array_flip(['owner_user_id', 'stage', 'department_id', 'currency'])),
            'probabilities_configured' => $calc['weighted_available'],
            'settings' => $settings,
            'forecast' => $calc,
            'actuals' => $this->actuals($tenant, $f, $from, $to),
            'accuracy' => $this->accuracy($tenant, $f['currency'] ?? null),
        ];
    }

    /** What actually closed in the window (by closed_at), per currency. Real results, not projections. @return array<string, mixed> */
    public function actuals(int $tenant, array $f, Carbon $from, Carbon $to): array
    {
        $q = GtmDeal::query()->where('gtm_deals.sub_institute_id', $tenant)->whereIn('gtm_deals.stage', ['won', 'lost'])->whereBetween('gtm_deals.closed_at', [$from, $to]);
        $this->scope($q, $tenant, $f + ['stage' => null]);
        $rows = $q->get(['gtm_deals.stage', 'gtm_deals.amount', 'gtm_deals.currency']);
        $by = function (string $stage) use ($rows) {
            $out = [];
            foreach ($rows->where('stage', $stage) as $d) {
                if ($d->amount !== null && $d->currency) {
                    $out[$d->currency] = ($out[$d->currency] ?? 0) + (int) round((float) $d->amount * 100);
                }
            }

            return array_map(fn ($c) => $c / 100, $out);
        };

        return ['won' => ['count' => $rows->where('stage', 'won')->count(), 'value_by_currency' => $by('won')], 'lost' => ['count' => $rows->where('stage', 'lost')->count(), 'value_by_currency' => $by('lost')]];
    }

    /**
     * Compare the last snapshot taken BEFORE each finished month with what was won in it. Only months
     * that have such a snapshot appear; with none, say so.
     *
     * @return array<string, mixed>
     */
    public function accuracy(int $tenant, ?string $currency): array
    {
        $snaps = DB::table('gtm_forecast_snapshots')->where('sub_institute_id', $tenant)->when($currency, fn ($q) => $q->where('currency', $currency))->get();
        if ($snaps->isEmpty()) {
            return ['available' => false, 'reason' => 'No forecast snapshots exist yet. They are saved daily, so accuracy can be shown once a month that had a snapshot has closed.', 'periods' => []];
        }
        $rows = [];
        foreach ($snaps->groupBy(fn ($s) => $s->period.'|'.$s->currency) as $group) {
            $first = $group->first();
            $start = Carbon::createFromFormat('Y-m-d', $first->period.'-01')->startOfDay();
            if ($start->copy()->endOfMonth()->gte(now())) {
                continue; // the month has not finished
            }
            $before = $group->filter(fn ($s) => Carbon::parse($s->snapshot_date)->lt($start))->sortByDesc('snapshot_date')->first();
            if (! $before) {
                continue;
            }
            $won = GtmDeal::query()->where('sub_institute_id', $tenant)->where('stage', 'won')->where('currency', $first->currency)
                ->whereBetween('closed_at', [$start, $start->copy()->endOfMonth()])->sum('amount');
            $rows[] = [
                'period' => $first->period, 'currency' => $first->currency, 'snapshot_date' => (string) $before->snapshot_date,
                'forecast_commit' => (float) $before->commit_value, 'forecast_weighted' => $before->weighted_value !== null ? (float) $before->weighted_value : null,
                'actual_won' => (float) $won,
                'commit_attainment_pct' => (float) $before->commit_value > 0 ? round($won / (float) $before->commit_value * 100, 1) : null,
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['period'], $b['period']));

        return $rows === []
            ? ['available' => false, 'reason' => 'Snapshots exist, but no finished month has one taken before it began yet.', 'periods' => []]
            : ['available' => true, 'reason' => null, 'periods' => $rows];
    }

    /** Store today's forecast per close month and currency, for one tenant. Idempotent for the day. */
    public function snapshot(int $tenant): int
    {
        $calc = $this->build($tenant, ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->addMonths(11)->endOfMonth()->toDateString()]);
        $written = 0;
        foreach ($calc['forecast']['periods'] as $p) {
            DB::table('gtm_forecast_snapshots')->updateOrInsert(
                ['sub_institute_id' => $tenant, 'snapshot_date' => now()->toDateString(), 'period' => $p['period'], 'currency' => $p['currency']],
                ['deal_count' => $p['deal_count'], 'pipeline_value' => $p['pipeline'], 'best_case_value' => $p['best_case'], 'commit_value' => $p['commit'], 'weighted_value' => $p['weighted'], 'created_at' => now()]
            );
            $written++;
        }

        return $written;
    }

    /** Owner / team / stage filters shared by the forecast and its actuals. */
    private function scope($q, int $tenant, array $f): void
    {
        if (! empty($f['owner_user_id'])) {
            $q->where('gtm_deals.owner_user_id', (int) $f['owner_user_id']);
        }
        if (! empty($f['stage'])) {
            $q->where('gtm_deals.stage', $f['stage']);
        }
        if (! empty($f['department_id'])) {
            // "Team" = the department of the deal's owner, from the tenant's own user records.
            $q->whereIn('gtm_deals.owner_user_id', DB::table('tbluser')->where('sub_institute_id', $tenant)->where('department_id', (int) $f['department_id'])->select('id'));
        }
    }
}
