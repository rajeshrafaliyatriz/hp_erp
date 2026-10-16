<?php

namespace App\Console\Commands;

use App\Support\ColumnExists;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * TEMPORARY demonstration aid: carry the exact signals of two local research days (13 and 14 Oct 2026) to another environment, as ordinary stored
 * rows, so the normal Research date dropdown and signal cards show them. Nothing is generated, rewritten or sent to a provider.
 *
 *   export    (local only, read-only)  write the fixture file from this database and verify its counts
 *   plan      (read-only)              dry run against THIS database for --tenant: what would be created, collisions, what cannot be kept exactly
 *   import    (guarded)                create the rows; needs SIGNALS_DEMO_FIXTURE_ENABLED=true, --tenant and --confirm=IMPORT-DEMO-FIXTURE
 *   rollback  (guarded)                delete exactly the rows import created (from its manifest); needs --confirm=ROLLBACK-DEMO-FIXTURE
 *
 * Nothing existing is ever overwritten or deleted: a signal whose fingerprint already exists is skipped and reported, existing organisations are
 * reused by name, and rollback removes only the ids written to the manifest.
 */
class SignalsDemoFixture extends Command
{
    protected $signature = 'signals:demo-fixture {action : export|plan|import|rollback}
        {--tenant= : the organisation to read from (export) or write to (plan, import, rollback)}
        {--file= : fixture path (default database/fixtures/demo_signals_2026-10-13_14.json)}
        {--confirm= : required phrase for import / rollback}';
    protected $description = 'Temporary: copy the local 13 and 14 Oct 2026 signals exactly into another environment (export, plan, import, rollback).';

    private const DATES = ['2026-10-13' => 11, '2026-10-14' => 5];   // the expected number of signals per day in the local data
    private const FIT = 'scholar';                                    // business_fit of ScholarClone
    private const MARKER = 'demo_fixture_oct13_14_2026';

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');
        if ($tenant <= 0) {
            $this->error('Pass --tenant=<sub_institute_id> explicitly.');

            return self::FAILURE;
        }

        return match ((string) $this->argument('action')) {
            'export' => $this->export($tenant),
            'plan' => $this->plan($tenant, false),
            'import' => $this->plan($tenant, true),
            'rollback' => $this->rollback($tenant),
            default => $this->stop('Unknown action. Use export, plan, import or rollback.'),
        };
    }

    private function stop(string $m): int
    {
        $this->error($m);

        return self::FAILURE;
    }

    private function path(): string
    {
        return $this->option('file') ?: base_path('database/fixtures/demo_signals_2026-10-13_14.json');
    }

    private function manifestPath(int $tenant): string
    {
        return storage_path("app/demo-fixture-manifest-{$tenant}.json");
    }

    /** @return array<string, mixed> */
    private function row(object $r): array
    {
        return (array) $r;
    }

    // ── export ───────────────────────────────────────────────────────────────

    private function export(int $tenant): int
    {
        if (! app()->environment('local')) {
            return $this->stop('Export reads the local data and only runs when APP_ENV=local.');
        }

        $opps = DB::table('g2g_company_opportunities as o')
            ->join('g2g_research_runs as r', 'r.id', '=', 'o.research_run_id')
            ->where('o.sub_institute_id', $tenant)->where('r.sub_institute_id', $tenant)
            ->whereIn(DB::raw('date(r.report_date)'), array_keys(self::DATES))->whereIn('r.status', ['success', 'partial'])
            ->where('o.business_fit', self::FIT)->where('o.is_sample', false)
            ->orderBy('o.id')->select('o.*', DB::raw('date(r.report_date) as __date'))->get();

        $perDate = [];
        foreach ($opps as $o) {
            $perDate[$o->__date] = ($perDate[$o->__date] ?? 0) + 1;
        }
        ksort($perDate);
        foreach (self::DATES as $d => $expected) {
            if (($perDate[$d] ?? 0) !== $expected) {
                return $this->stop("Local data has " . ($perDate[$d] ?? 0) . " signals for {$d}, expected {$expected}. Nothing was written.");
            }
        }

        $ids = $opps->pluck('id')->all();
        $runIds = $opps->pluck('research_run_id')->unique()->values()->all();
        $companyIds = $opps->pluck('company_id')->unique()->values()->all();
        $dateOf = $opps->pluck('__date', 'id');

        $strip = function (object $o) {
            $a = (array) $o;
            unset($a['__date']);

            return $a;
        };

        $data = [
            'runs' => DB::table('g2g_research_runs')->whereIn('id', $runIds)->orderBy('id')->get()->map(fn ($r) => $this->row($r))->all(),
            'companies' => DB::table('g2g_companies')->whereIn('id', $companyIds)->orderBy('id')->get()->map(fn ($r) => $this->row($r))->all(),
            'opportunities' => $opps->map($strip)->all(),
            'events' => DB::table('g2g_opportunity_events')->whereIn('opportunity_id', $ids)->orderBy('id')->get()->map(fn ($r) => $this->row($r))->all(),
            'matches' => DB::table('g2g_opportunity_matches')->where('signal_type', 'opportunity')->whereIn('signal_id', $ids)->orderBy('id')->get()->map(fn ($r) => $this->row($r))->all(),
            'sources' => DB::table('g2g_research_sources')->whereIn('research_run_id', $runIds)->orderBy('id')->get()->map(fn ($r) => $this->row($r))->all(),
        ];
        $fixture = [
            'meta' => [
                'purpose' => 'TEMPORARY demonstration copy of local research days. Do not edit by hand.',
                'source_tenant' => $tenant, 'source_product' => 'scholarclone', 'business_fit' => self::FIT, 'exported_at' => now()->toIso8601String(),
                'signals_per_date' => $perDate, 'date_of_signal' => $dateOf->all(),
                'counts' => array_map('count', $data), 'sha256' => hash('sha256', json_encode($data)),
            ],
            'data' => $data,
        ];

        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info('Fixture written: ' . $this->path());
        $this->table(['table', 'rows'], collect($fixture['meta']['counts'])->map(fn ($c, $t) => [$t, $c])->values()->all());
        $this->line('Signals per date: ' . json_encode($perDate));

        return self::SUCCESS;
    }

    // ── plan / import ────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function load(): ?array
    {
        if (! is_file($this->path())) {
            $this->error('Fixture file not found: ' . $this->path());

            return null;
        }
        $f = json_decode((string) file_get_contents($this->path()), true);
        if (! is_array($f) || ($f['meta']['sha256'] ?? null) !== hash('sha256', json_encode($f['data'] ?? []))) {
            $this->error('The fixture file is damaged or was edited (checksum mismatch). Re-export it.');

            return null;
        }

        return $f;
    }

    /** @param list<array<string,mixed>> $rows @return list<string> columns of the fixture that the target table does not have */
    private function missingColumns(string $table, array $rows): array
    {
        $have = ColumnExists::all($table);

        return array_values(array_diff(array_keys($rows[0] ?? []), $have));
    }

    private function plan(int $tenant, bool $write): int
    {
        $f = $this->load();
        if ($f === null) {
            return self::FAILURE;
        }
        $d = $f['data'];

        if ($write) {
            if (! config('signals.demo_fixture.enabled')) {
                return $this->stop('Import is switched off. Set SIGNALS_DEMO_FIXTURE_ENABLED=true for the demonstration, then back to false afterwards.');
            }
            if ($this->option('confirm') !== 'IMPORT-DEMO-FIXTURE') {
                return $this->stop('Pass --confirm=IMPORT-DEMO-FIXTURE to write.');
            }
            if (is_file($this->manifestPath($tenant))) {
                return $this->stop('This organisation already has an import manifest. Roll it back first.');
            }
        }

        $this->line(($write ? 'IMPORT' : 'DRY RUN (nothing is written)') . " into {$this->laravel->environment()} database '" . DB::connection()->getDatabaseName() . "', tenant {$tenant}");
        if (! DB::table('g2g_research_runs')->where('sub_institute_id', $tenant)->exists() && ! DB::table('g2g_companies')->where('sub_institute_id', $tenant)->exists()) {
            $this->warn('This tenant has no research runs or companies yet. Check the tenant id.');
        }

        // Columns the target cannot hold: reported, never silently dropped into different content.
        $unsupported = [];
        foreach (['g2g_research_runs' => 'runs', 'g2g_companies' => 'companies', 'g2g_company_opportunities' => 'opportunities', 'g2g_opportunity_events' => 'events', 'g2g_opportunity_matches' => 'matches', 'g2g_research_sources' => 'sources'] as $table => $key) {
            if (! Schema::hasTable($table)) {
                return $this->stop("Table {$table} does not exist here. Nothing was written.");
            }
            $missing = $this->missingColumns($table, $d[$key]);
            if ($missing !== []) {
                $unsupported[$table] = $missing;
            }
        }

        // The screen cannot show these days without these columns, whatever is imported: stop and name the migration instead of writing rows that
        // would never appear (or breaking the date list). The rest are reported above and only affect how much of a card is filled in.
        $required = [
            ['g2g_research_runs', 'product_key', '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', 'the Research date list filters runs by product'],
            ['g2g_research_runs', 'stage', '2026_10_01_130000_add_stage_to_g2g_research_runs', 'run summary'],
            ['g2g_company_opportunities', 'business_fit', '2026_10_08_100000_extend_g2g_signals_for_market_import', 'the product filter reads it'],
            ['g2g_company_opportunities', 'is_sample', '2026_10_08_100000_extend_g2g_signals_for_market_import', 'sample rows are hidden by it'],
            ['g2g_company_opportunities', 'expires_at', '2026_10_08_100000_extend_g2g_signals_for_market_import', 'expiry filter'],
        ];
        $blocked = [];
        foreach ($required as [$table, $column, $migration, $why]) {
            if (! ColumnExists::has($table, $column)) {
                $blocked[] = "{$table}.{$column} is missing ({$why}). Run migration {$migration} first.";
            }
        }
        foreach (['g2g_company_opportunities.assessment' => '2026_11_29_090000_add_assessment_to_g2g_company_opportunities', 'g2g_company_opportunities.buying_intent' => '2026_11_29_090000_add_assessment_to_g2g_company_opportunities',
            'g2g_research_sources.date_provenance' => '2026_11_29_100000_add_date_provenance_to_g2g_research_sources', 'g2g_research_sources.modified_at' => '2026_11_29_110000_add_modified_date_to_g2g_research_sources'] as $col => $migration) {
            [$t, $c] = explode('.', $col);
            if (! ColumnExists::has($t, $c)) {
                $this->warn("{$col} is missing: cards will lack that part (buying-intent line and reasoning, or source date provenance). Migration {$migration} adds it.");
            }
        }
        foreach ($blocked as $b) {
            $this->error($b);
        }
        if ($blocked !== []) {
            return $this->stop('The target schema is not ready. Nothing was written.');
        }

        // Signals: skip a fingerprint that already exists; skip all if this fixture was imported before.
        $already = DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenant)->where('import_source', self::MARKER)->count();
        $existingPrints = DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenant)->whereIn('fingerprint', array_column($d['opportunities'], 'fingerprint'))->pluck('id', 'fingerprint')->all();
        $perDate = [];
        $skipped = [];
        foreach ($d['opportunities'] as $o) {
            $day = $f['meta']['date_of_signal'][$o['id']] ?? '?';
            if (isset($existingPrints[$o['fingerprint']])) {
                $skipped[] = "signal {$o['id']} ({$day}) collides with existing live signal {$existingPrints[$o['fingerprint']]}";
                continue;
            }
            $perDate[$day] = ($perDate[$day] ?? 0) + 1;
        }
        ksort($perDate);

        // Only what the importable signals need is created: a signal that collides brings nothing with it.
        $importableIds = array_column(array_filter($d['opportunities'], fn ($o) => ! isset($existingPrints[$o['fingerprint']])), 'id');
        $neededRuns = array_unique(array_column(array_filter($d['opportunities'], fn ($o) => in_array($o['id'], $importableIds, true)), 'research_run_id'));
        $neededCompanies = array_unique(array_column(array_filter($d['opportunities'], fn ($o) => in_array($o['id'], $importableIds, true)), 'company_id'));
        $d['runs'] = array_values(array_filter($d['runs'], fn ($r) => in_array($r['id'], $neededRuns, true)));
        $d['companies'] = array_values(array_filter($d['companies'], fn ($c) => in_array($c['id'], $neededCompanies, true)));
        $d['events'] = array_values(array_filter($d['events'], fn ($e) => in_array($e['opportunity_id'], $importableIds, true)));
        $d['matches'] = array_values(array_filter($d['matches'], fn ($m) => in_array($m['signal_id'], $importableIds, true)));
        $d['sources'] = array_values(array_filter($d['sources'], fn ($x) => in_array($x['research_run_id'], $neededRuns, true)));

        // Organisations: reuse by normalised name (never overwrite), create the rest.
        $reuse = [];
        $create = [];
        foreach ($d['companies'] as $c) {
            $existing = DB::table('g2g_companies')->where('sub_institute_id', $tenant)->where('normalized_name', $c['normalized_name'])->value('id');
            $existing ? $reuse[$c['id']] = (int) $existing : $create[] = $c['id'];
        }

        $offerIds = array_values(array_unique(array_filter(array_column($d['matches'], 'offer_id'))));
        $liveOffers = $offerIds === [] ? [] : DB::table('g2g_product_offers')->where('sub_institute_id', $tenant)->whereIn('offer_id', $offerIds)->pluck('offer_id')->all();
        $matchesKept = count(array_filter($d['matches'], fn ($m) => in_array($m['offer_id'], $liveOffers, true)));
        $liveRunsOnDates = DB::table('g2g_research_runs')->where('sub_institute_id', $tenant)->whereIn(DB::raw('date(report_date)'), array_keys(self::DATES))->count();

        $this->table(['item', 'would create', 'note'], [
            ['research runs (only the ones that produced these signals)', count($d['runs']), 'new rows; live runs on these two dates today: ' . $liveRunsOnDates],
            ['organisations', count($create), count($reuse) . ' already exist and are reused by name'],
            ['signals', array_sum($perDate), 'per date: ' . json_encode($perDate) . '; expected ' . json_encode(self::DATES)],
            ['signal events', count($d['events']), 'timeline entries'],
            ['offer matches', $matchesKept, (count($d['matches']) - $matchesKept) . ' skipped: that offer id does not exist in this tenant'],
            ['research sources (evidence panel)', count($d['sources']), 'sources of the runs above'],
        ]);
        $this->line('Already imported earlier (marker rows): ' . $already);
        foreach ($skipped as $s) {
            $this->warn('Collision, skipped: ' . $s);
        }
        foreach ($unsupported as $t => $cols) {
            $this->warn("Not representable in {$t}: " . implode(', ', $cols) . ' (left out; content otherwise unchanged)');
        }
        $this->line('Not carried: local user ids (reviewed_by), ingestion source link, local offer ids that do not exist here, target-account/CRM details (the live account list supplies those if the organisation is linked there).');

        if (! $write) {
            $this->info('Dry run complete. Nothing was written.');

            return self::SUCCESS;
        }
        if ($already > 0) {
            return $this->stop('Signals with this marker already exist. Roll back first.');
        }

        $manifest = ['tenant' => $tenant, 'marker' => self::MARKER, 'created_at' => now()->toIso8601String(), 'runs' => [], 'companies' => [], 'opportunities' => [], 'events' => [], 'matches' => [], 'sources' => []];
        DB::transaction(function () use ($d, $tenant, $reuse, $skipped, $existingPrints, $liveOffers, &$manifest) {
            $runMap = [];
            $companyMap = $reuse;
            $oppMap = [];
            $cols = fn (string $t) => array_flip(ColumnExists::all($t));
            $clean = fn (array $row, string $table, array $override) => array_intersect_key(array_merge($row, $override), $cols($table));

            foreach ($d['runs'] as $r) {
                $new = DB::table('g2g_research_runs')->insertGetId($clean($r, 'g2g_research_runs', ['id' => null, 'sub_institute_id' => $tenant, 'triggered_by' => null]));
                $runMap[$r['id']] = $new;
                $manifest['runs'][] = $new;
            }
            foreach ($d['companies'] as $c) {
                if (isset($companyMap[$c['id']])) {
                    continue;
                }
                $new = DB::table('g2g_companies')->insertGetId($clean($c, 'g2g_companies', ['id' => null, 'sub_institute_id' => $tenant]));
                $companyMap[$c['id']] = $new;
                $manifest['companies'][] = $new;
            }
            foreach ($d['opportunities'] as $o) {
                if (isset($existingPrints[$o['fingerprint']])) {
                    continue;   // never duplicate or overwrite an existing live signal
                }
                $new = DB::table('g2g_company_opportunities')->insertGetId($clean($o, 'g2g_company_opportunities', [
                    'id' => null, 'sub_institute_id' => $tenant, 'company_id' => $companyMap[$o['company_id']] ?? null, 'research_run_id' => $runMap[$o['research_run_id']] ?? null,
                    'ingestion_source_id' => null, 'reviewed_by' => null, 'relevant_offer_id' => null, 'import_source' => self::MARKER, 'is_sample' => 0,
                ]));
                $oppMap[$o['id']] = $new;
                $manifest['opportunities'][] = $new;
            }
            foreach ($d['events'] as $e) {
                if (isset($oppMap[$e['opportunity_id']])) {
                    $manifest['events'][] = DB::table('g2g_opportunity_events')->insertGetId($clean($e, 'g2g_opportunity_events', ['id' => null, 'sub_institute_id' => $tenant, 'opportunity_id' => $oppMap[$e['opportunity_id']]]));
                }
            }
            foreach ($d['matches'] as $m) {
                if (isset($oppMap[$m['signal_id']]) && in_array($m['offer_id'], $liveOffers, true)) {
                    $manifest['matches'][] = DB::table('g2g_opportunity_matches')->insertGetId($clean($m, 'g2g_opportunity_matches', ['id' => null, 'sub_institute_id' => $tenant, 'signal_id' => $oppMap[$m['signal_id']], 'reviewed_by' => null]));
                }
            }
            foreach ($d['sources'] as $s) {
                if (isset($runMap[$s['research_run_id']])) {
                    $manifest['sources'][] = DB::table('g2g_research_sources')->insertGetId($clean($s, 'g2g_research_sources', ['id' => null, 'sub_institute_id' => $tenant, 'research_run_id' => $runMap[$s['research_run_id']]]));
                }
            }
        });
        File::put($this->manifestPath($tenant), json_encode($manifest, JSON_PRETTY_PRINT));
        $this->info('Imported. Manifest: ' . $this->manifestPath($tenant) . '. Counts: ' . json_encode(array_map('count', array_filter($manifest, 'is_array'))));

        return self::SUCCESS;
    }

    // ── rollback ─────────────────────────────────────────────────────────────

    private function rollback(int $tenant): int
    {
        if ($this->option('confirm') !== 'ROLLBACK-DEMO-FIXTURE') {
            return $this->stop('Pass --confirm=ROLLBACK-DEMO-FIXTURE to delete what the import created.');
        }
        $p = $this->manifestPath($tenant);
        if (! is_file($p)) {
            return $this->stop('No manifest for this tenant: nothing to roll back.');
        }
        $m = json_decode((string) file_get_contents($p), true);
        if (($m['tenant'] ?? null) !== $tenant || ($m['marker'] ?? null) !== self::MARKER) {
            return $this->stop('The manifest does not match this tenant.');
        }

        DB::transaction(function () use ($m, $tenant) {
            // Only ids the import wrote, and only inside this tenant; signals must still carry the marker.
            DB::table('g2g_research_sources')->where('sub_institute_id', $tenant)->whereIn('id', $m['sources'])->delete();
            DB::table('g2g_opportunity_matches')->where('sub_institute_id', $tenant)->whereIn('id', $m['matches'])->delete();
            DB::table('g2g_opportunity_events')->where('sub_institute_id', $tenant)->whereIn('id', $m['events'])->delete();
            DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenant)->where('import_source', self::MARKER)->whereIn('id', $m['opportunities'])->delete();
            DB::table('g2g_research_runs')->where('sub_institute_id', $tenant)->whereIn('id', $m['runs'])->delete();
            DB::table('g2g_companies')->where('sub_institute_id', $tenant)->whereIn('id', $m['companies'])->delete();
        });
        File::move($p, $p . '.rolled-back-' . now()->format('YmdHis'));
        $this->info('Rolled back: the demonstration rows were removed. Existing live data was not touched.');

        return self::SUCCESS;
    }
}
