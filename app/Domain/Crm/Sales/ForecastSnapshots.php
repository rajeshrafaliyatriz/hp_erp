<?php

namespace App\Domain\Crm\Sales;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The daily record of the CRM pipeline, per owner (slice 11, task 6; table g2g_forecast_snapshots; the command is opportunities:forecast-snapshot). It keeps
 * what the pipeline WAS on a day, so that a change in the forecast can be explained later (what moved is in crm_opportunity_stage_history, which task 3 made
 * complete: every path that changes a stage, an amount, a probability or a closing date leaves a row).
 *
 * The arithmetic follows the GTM forecast's own rules (App\Domain\Gtm\Forecast\ForecastMath), so the two never disagree about what a number means:
 *   - money is added in whole minor units (amount x 100) and only within one currency; nothing here can add USD to INR;
 *   - an opportunity without an amount or a currency cannot be summed: it is COUNTED, never given a placeholder value;
 *   - the weighted figure uses the probability the opportunity itself carries, and an opportunity without one is left out of it (and counted), never given 0 or a
 *     default; with no probability at all the figure is NULL;
 *   - categories are cumulative: open = everything not omitted, best case = best case + commit, commit = commit only; an opportunity with no category is "pipeline".
 *
 * "Open" is the opportunity pipeline's own meaning: not Closed Won and not Closed Lost (OpportunityEvents::isOpen); a deleted opportunity is not in it.
 * A snapshot is "as of now": it is stored under TODAY's date and a second run on the same day replaces that day's rows with the same numbers (it never adds).
 * Every read and write carries the organisation.
 */
final class ForecastSnapshots
{
    /** The currency of an opportunity that records none (or one that is not a three-letter code): ISO 4217's "no currency involved". */
    public const NO_CURRENCY = 'XXX';

    public const TOTAL = 'total';

    public const OWNER = 'owner';

    private const CHUNK = 500;

    /** @return list<int> the organisations that have opportunities */
    public function tenants(): array
    {
        return DB::table('crm_opportunities')->where('sub_institute_id', '>', 0)->distinct()->orderBy('sub_institute_id')->pluck('sub_institute_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * What the pipeline is now, for one organisation: one row per scope, owner and currency. Reads only.
     *
     * @return list<array<string, mixed>>
     */
    public function compute(int $tenantId): array
    {
        $agg = [];

        DB::table('crm_opportunities')->where('sub_institute_id', $tenantId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('sales_stage')->orWhereNotIn('sales_stage', ['Closed Won', 'Closed Lost']))
            ->orderBy('id')->chunk(self::CHUNK, function ($opportunities) use (&$agg) {
                foreach ($opportunities as $o) {
                    // A stage the picklist spells differently still counts as closed (case and spaces do not matter).
                    if (! OpportunityEvents::isOpen($o->sales_stage)) {
                        continue;
                    }
                    $currency = self::currency($o->currency);
                    $owner = (int) ($o->assigned_to ?? 0);
                    foreach ([[self::TOTAL, 0], [self::OWNER, $owner]] as [$scope, $ownerId]) {
                        $row = &$agg[$scope . '|' . $ownerId . '|' . $currency];
                        $row ??= ['scope' => $scope, 'owner_user_id' => $ownerId, 'currency' => $currency, 'open_count' => 0, 'priced_count' => 0, 'omitted_count' => 0,
                            'open' => 0, 'best' => 0, 'commit' => 0, 'weighted' => 0, 'weighted_count' => 0];
                        self::add($row, $o, $currency);
                        unset($row);
                    }
                }
            });

        ksort($agg);
        $rows = [];
        foreach ($agg as $r) {
            $rows[] = [
                'scope' => $r['scope'], 'owner_user_id' => $r['owner_user_id'], 'currency' => $r['currency'],
                'open_count' => $r['open_count'], 'priced_count' => $r['priced_count'], 'omitted_count' => $r['omitted_count'],
                'open_value' => $r['open'] / 100, 'best_case_value' => $r['best'] / 100, 'commit_value' => $r['commit'] / 100,
                'weighted_value' => $r['weighted_count'] > 0 ? $r['weighted'] / 100 : null, 'weighted_count' => $r['weighted_count'],
            ];
        }

        return $rows;
    }

    /**
     * Stores one organisation's rows for a day, replacing that day's rows of that organisation (a second run on the same day gives the same rows, never more).
     * Returns the number of rows stored.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function store(int $tenantId, string $date, array $rows): int
    {
        return DB::transaction(function () use ($tenantId, $date, $rows) {
            DB::table('g2g_forecast_snapshots')->where('sub_institute_id', $tenantId)->where('snapshot_date', $date)->delete();
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('g2g_forecast_snapshots')->insert(array_map(fn ($r) => $r + ['sub_institute_id' => $tenantId, 'snapshot_date' => $date, 'created_at' => now()], $chunk));
            }

            return count($rows);
        });
    }

    /**
     * @return array{rows: int, open: int, written: int}
     *
     * @throws ForecastSnapshotsBlocked when this connection lacks the table
     */
    public function run(int $tenantId, bool $apply): array
    {
        if (! Schema::hasTable('g2g_forecast_snapshots')) {
            throw new ForecastSnapshotsBlocked('g2g_forecast_snapshots is not on this connection: run migration 2026_12_11_130100 first.');
        }

        $rows = $this->compute($tenantId);
        $totals = array_filter($rows, fn ($r) => $r['scope'] === self::TOTAL);
        $written = $apply ? $this->store($tenantId, now()->toDateString(), $rows) : 0;

        return ['rows' => count($rows), 'open' => (int) array_sum(array_column($totals, 'open_count')), 'written' => $written, 'totals' => array_values($totals)];
    }

    /**
     * The stored rows of one organisation, oldest day first.
     *
     * @return array{items: list<array<string, mixed>>, first_date: ?string, last_date: ?string}
     */
    public function read(int $tenantId, ?string $from = null, ?string $to = null, ?string $scope = null, ?int $ownerId = null, ?string $currency = null): array
    {
        $query = DB::table('g2g_forecast_snapshots')->where('sub_institute_id', $tenantId);
        if ($from !== null) {
            $query->where('snapshot_date', '>=', $from);
        }
        if ($to !== null) {
            $query->where('snapshot_date', '<=', $to);
        }
        if ($scope !== null) {
            $query->where('scope', $scope);
        }
        if ($ownerId !== null) {
            $query->where('owner_user_id', $ownerId);
        }
        if ($currency !== null) {
            $query->where('currency', strtoupper($currency));
        }

        $items = $query->orderBy('snapshot_date')->orderBy('scope', 'desc')->orderBy('owner_user_id')->orderBy('currency')->limit(5000)->get()->map(fn ($r) => [
            'date' => substr((string) $r->snapshot_date, 0, 10), 'scope' => $r->scope, 'owner_user_id' => (int) $r->owner_user_id, 'currency' => $r->currency,
            'open_count' => (int) $r->open_count, 'priced_count' => (int) $r->priced_count, 'omitted_count' => (int) $r->omitted_count,
            'open_value' => (float) $r->open_value, 'best_case_value' => (float) $r->best_case_value, 'commit_value' => (float) $r->commit_value,
            'weighted_value' => $r->weighted_value === null ? null : (float) $r->weighted_value, 'weighted_count' => (int) $r->weighted_count,
        ])->all();

        return ['items' => $items, 'first_date' => $items === [] ? null : $items[0]['date'], 'last_date' => $items === [] ? null : $items[count($items) - 1]['date']];
    }

    /** @param array<string, mixed> $row */
    private static function add(array &$row, object $o, string $currency): void
    {
        $category = self::category($o->forecast_category ?? null);
        if ($category === 'omitted') {
            $row['omitted_count']++;

            return;
        }

        $row['open_count']++;
        if ($o->amount === null || $o->amount === '' || $currency === self::NO_CURRENCY) {
            return;   // counted, never given a value
        }

        $cents = (int) round(((float) $o->amount) * 100);
        $row['priced_count']++;
        $row['open'] += $cents;
        if (in_array($category, ['best_case', 'commit'], true)) {
            $row['best'] += $cents;
        }
        if ($category === 'commit') {
            $row['commit'] += $cents;
        }
        if ($o->probability !== null && $o->probability !== '') {
            $row['weighted'] += (int) round($cents * ((float) $o->probability) / 100);
            $row['weighted_count']++;
        }
    }

    /** "Best Case" -> best_case; an opportunity with no (or an unknown) category is in the pipeline. */
    private static function category(?string $value): string
    {
        $c = str_replace([' ', '-'], '_', mb_strtolower(trim((string) $value)));

        return in_array($c, ['best_case', 'commit', 'omitted'], true) ? $c : 'pipeline';
    }

    private static function currency(?string $value): string
    {
        $c = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{3}$/', $c) === 1 ? $c : self::NO_CURRENCY;
    }
}
