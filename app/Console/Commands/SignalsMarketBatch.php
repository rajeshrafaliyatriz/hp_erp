<?php

namespace App\Console\Commands;

use App\Domain\ProductMaster\ProductResolver;
use App\Support\ColumnExists;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Carries a CHOSEN set of researched signals, with their evidence, from one environment (where they were generated) to another (your live server),
 * as ordinary stored rows, so the normal Signals screens show them. Nothing is generated, rewritten or sent to a provider.
 *
 *   export    (read-only on the database)  write a batch file for the signal ids you name, and verify its counts and checksum
 *   plan      (read-only)                  dry run against THIS database for --tenant: what would be created, collisions, what cannot be kept
 *   import    (guarded)                    create the rows; needs SIGNALS_MARKET_BATCH_ENABLED=true, --tenant and --confirm=IMPORT-MARKET-BATCH
 *   rollback  (guarded)                    delete exactly the rows import created (from its manifest); needs --confirm=ROLLBACK-MARKET-BATCH
 *
 * Nothing existing is ever overwritten or deleted: a signal whose fingerprint already exists is skipped and reported, existing organisations are
 * reused by name, and rollback removes only the ids written to the manifest. Every imported signal carries `import_source = market-batch:<batch>`
 * so it can always be told apart and found again. Local ids that differ between environments (ICP profile, Product Master, user ids) are not copied:
 * the Product Master link is looked up again by product key on the target.
 */
class SignalsMarketBatch extends Command
{
    protected $signature = 'signals:market-batch {action : export|plan|import|rollback}
        {--tenant= : the organisation to read from (export) or write to (plan, import, rollback)}
        {--batch= : a short name for this batch, letters, digits and dashes (required)}
        {--signals= : export only: comma-separated signal ids to put in the batch}
        {--file= : batch file path (default database/signal-batches/<batch>.json)}
        {--confirm= : required phrase for import / rollback}';

    protected $description = 'Export, plan, import and roll back a chosen batch of researched signals (with their evidence) between environments.';

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');
        if ($tenant <= 0) {
            return $this->stop('Pass --tenant=<sub_institute_id> explicitly.');
        }
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{2,60}$/', (string) $this->option('batch'))) {
            return $this->stop('Pass --batch=<name> (3 to 61 letters, digits or dashes).');
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

    private function batch(): string
    {
        return (string) $this->option('batch');
    }

    private function marker(): string
    {
        return 'market-batch:' . $this->batch();
    }

    private function path(): string
    {
        return $this->option('file') ?: base_path('database/signal-batches/' . $this->batch() . '.json');
    }

    private function manifestPath(int $tenant): string
    {
        return storage_path("app/market-batch-manifest-{$tenant}-" . $this->batch() . '.json');
    }

    // ── export ───────────────────────────────────────────────────────────────

    private function export(int $tenant): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $this->option('signals'))))));
        if ($ids === []) {
            return $this->stop('Pass --signals=<id,id,...> (the signals to put in the batch).');
        }
        $opps = DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenant)->whereIn('id', $ids)->where('is_sample', false)->orderBy('id')->get();
        if ($opps->count() !== count($ids)) {
            return $this->stop('Found ' . $opps->count() . ' of ' . count($ids) . ' signals for this tenant (sample rows are never exported). Nothing was written.');
        }
        foreach ($opps as $o) {
            if (trim((string) $o->source_url) === '') {
                return $this->stop("Signal {$o->id} has no source link: a batch only carries signals with evidence. Nothing was written.");
            }
        }

        $runIds = $opps->pluck('research_run_id')->filter()->unique()->values()->all();
        $companyIds = $opps->pluck('company_id')->unique()->values()->all();
        $rows = fn ($q) => $q->get()->map(fn ($r) => (array) $r)->all();
        $data = [
            'runs' => $rows(DB::table('g2g_research_runs')->whereIn('id', $runIds)->orderBy('id')),
            'companies' => $rows(DB::table('g2g_companies')->whereIn('id', $companyIds)->orderBy('id')),
            'opportunities' => $opps->map(fn ($r) => (array) $r)->all(),
            'events' => $rows(DB::table('g2g_opportunity_events')->whereIn('opportunity_id', $ids)->orderBy('id')),
            'matches' => $rows(DB::table('g2g_opportunity_matches')->where('signal_type', 'opportunity')->whereIn('signal_id', $ids)->orderBy('id')),
            'sources' => $rows(DB::table('g2g_research_sources')->whereIn('research_run_id', $runIds)->orderBy('id')),
        ];
        $companies = collect($data['companies'])->keyBy('id');
        $summary = $opps->map(function ($o) use ($companies) {
            $a = json_decode((string) $o->assessment, true) ?: [];

            return [
                'id' => (int) $o->id, 'lane' => $o->product_key . '/' . $o->customer_model, 'organisation' => $companies[$o->company_id]['name'] ?? null, 'evidence_link' => $o->source_url,
                'grade' => $a['signal_grade']['grade'] ?? null, 'evidence_level' => $a['evidence_maturity']['level'] ?? null, 'label' => $o->qualification,
            ];
        })->all();

        $fixture = [
            'meta' => [
                'purpose' => 'A chosen batch of researched signals with their evidence links. Do not edit by hand.', 'batch' => $this->batch(), 'marker' => $this->marker(),
                'source_tenant' => $tenant, 'exported_at' => now()->toIso8601String(), 'counts' => array_map('count', $data), 'signals' => $summary,
                'sha256' => hash('sha256', json_encode($data)),
            ],
            'data' => $data,
        ];
        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info('Batch written: ' . $this->path());
        $this->table(['table', 'rows'], collect($fixture['meta']['counts'])->map(fn ($c, $t) => [$t, $c])->values()->all());

        return self::SUCCESS;
    }

    // ── plan / import ────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function load(): ?array
    {
        if (! is_file($this->path())) {
            $this->error('Batch file not found: ' . $this->path());

            return null;
        }
        $f = json_decode((string) file_get_contents($this->path()), true);
        if (! is_array($f) || ($f['meta']['sha256'] ?? null) !== hash('sha256', json_encode($f['data'] ?? [])) || ($f['meta']['batch'] ?? null) !== $this->batch()) {
            $this->error('The batch file is damaged, was edited, or belongs to a different batch (checksum or name mismatch). Re-export it.');

            return null;
        }

        return $f;
    }

    private function plan(int $tenant, bool $write): int
    {
        $f = $this->load();
        if ($f === null) {
            return self::FAILURE;
        }
        $d = $f['data'];

        if ($write) {
            if (! config('signals.market_batch.enabled')) {
                return $this->stop('Import is switched off. Set SIGNALS_MARKET_BATCH_ENABLED=true for the import, then back to false afterwards.');
            }
            if ($this->option('confirm') !== 'IMPORT-MARKET-BATCH') {
                return $this->stop('Pass --confirm=IMPORT-MARKET-BATCH to write.');
            }
            if (is_file($this->manifestPath($tenant))) {
                return $this->stop('This batch already has an import manifest for this organisation. Roll it back first.');
            }
        }
        $this->line(($write ? 'IMPORT' : 'DRY RUN (nothing is written)') . " into {$this->laravel->environment()} database '" . DB::connection()->getDatabaseName() . "', tenant {$tenant}, batch " . $this->batch());

        $required = [
            ['g2g_research_runs', 'product_key'], ['g2g_company_opportunities', 'business_fit'], ['g2g_company_opportunities', 'is_sample'], ['g2g_company_opportunities', 'expires_at'],
            ['g2g_company_opportunities', 'assessment'], ['g2g_company_opportunities', 'product_key'], ['g2g_company_opportunities', 'customer_model'], ['g2g_company_opportunities', 'source_url'],
        ];
        foreach (['g2g_research_runs', 'g2g_companies', 'g2g_company_opportunities', 'g2g_opportunity_events', 'g2g_opportunity_matches', 'g2g_research_sources'] as $table) {
            if (! Schema::hasTable($table)) {
                return $this->stop("Table {$table} does not exist here. Nothing was written.");
            }
        }
        $blocked = [];
        foreach ($required as [$t, $c]) {
            if (! ColumnExists::has($t, $c)) {
                $blocked[] = "{$t}.{$c} is missing here: run the pending migrations first.";
            }
        }
        foreach ($blocked as $b) {
            $this->error($b);
        }
        if ($blocked !== []) {
            return $this->stop('The target schema is not ready. Nothing was written.');
        }

        $unsupported = [];
        foreach (['g2g_research_runs' => 'runs', 'g2g_companies' => 'companies', 'g2g_company_opportunities' => 'opportunities', 'g2g_opportunity_events' => 'events', 'g2g_opportunity_matches' => 'matches', 'g2g_research_sources' => 'sources'] as $table => $key) {
            $missing = array_values(array_diff(array_keys($d[$key][0] ?? []), ColumnExists::all($table)));
            if ($missing !== []) {
                $unsupported[$table] = $missing;
            }
        }

        $existingPrints = DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenant)->whereIn('fingerprint', array_column($d['opportunities'], 'fingerprint'))->pluck('id', 'fingerprint')->all();
        $already = DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenant)->where('import_source', $this->marker())->count();
        $skipped = [];
        $importable = [];
        foreach ($d['opportunities'] as $o) {
            isset($existingPrints[$o['fingerprint']]) ? $skipped[] = "signal {$o['id']} collides with existing signal {$existingPrints[$o['fingerprint']]}" : $importable[] = $o;
        }
        $importableIds = array_column($importable, 'id');
        $neededRuns = array_unique(array_filter(array_column($importable, 'research_run_id')));
        $neededCompanies = array_unique(array_column($importable, 'company_id'));
        $d['runs'] = array_values(array_filter($d['runs'], fn ($r) => in_array($r['id'], $neededRuns, true)));
        $d['companies'] = array_values(array_filter($d['companies'], fn ($c) => in_array($c['id'], $neededCompanies, true)));
        $d['events'] = array_values(array_filter($d['events'], fn ($e) => in_array($e['opportunity_id'], $importableIds, true)));
        $d['matches'] = array_values(array_filter($d['matches'], fn ($m) => in_array($m['signal_id'], $importableIds, true)));
        $d['sources'] = array_values(array_filter($d['sources'], fn ($x) => in_array($x['research_run_id'], $neededRuns, true)));

        $reuse = [];
        $create = [];
        foreach ($d['companies'] as $c) {
            $existing = DB::table('g2g_companies')->where('sub_institute_id', $tenant)->where('normalized_name', $c['normalized_name'])->value('id');
            $existing ? $reuse[$c['id']] = (int) $existing : $create[] = $c['id'];
        }
        $offerIds = array_values(array_unique(array_filter(array_column($d['matches'], 'offer_id'))));
        $liveOffers = $offerIds === [] || ! Schema::hasTable('g2g_product_offers') ? [] : DB::table('g2g_product_offers')->where('sub_institute_id', $tenant)->whereIn('offer_id', $offerIds)->pluck('offer_id')->all();
        $matchesKept = count(array_filter($d['matches'], fn ($m) => in_array($m['offer_id'], $liveOffers, true)));
        $resolver = Schema::hasTable('g2g_product_master') ? new ProductResolver() : null;

        $this->table(['lane', 'organisation', 'grade', 'evidence', 'label', 'evidence link'], array_map(fn ($s) => [$s['lane'], mb_substr((string) $s['organisation'], 0, 34), $s['grade'], $s['evidence_level'], $s['label'], mb_substr((string) $s['evidence_link'], 0, 70)], $f['meta']['signals']));
        $this->table(['item', 'would create', 'note'], [
            ['research runs (only those that produced these signals)', count($d['runs']), 'new rows'],
            ['organisations', count($create), count($reuse) . ' already exist and are reused by name'],
            ['signals', count($importable), count($skipped) . ' skipped as collisions; marker ' . $this->marker()],
            ['signal events', count($d['events']), 'timeline entries'],
            ['offer matches', $matchesKept, (count($d['matches']) - $matchesKept) . ' skipped: that offer id does not exist in this tenant'],
            ['research sources (evidence panel)', count($d['sources']), 'sources of the runs above'],
        ]);
        $this->line('Product Master link: ' . ($resolver ? 'looked up again by product key on this database' : 'no Product Master table here: left empty'));
        $this->line('Already imported earlier (rows carrying this marker): ' . $already);
        foreach ($skipped as $s) {
            $this->warn('Collision, skipped: ' . $s);
        }
        foreach ($unsupported as $t => $cols) {
            $this->warn("Not representable in {$t}: " . implode(', ', $cols) . ' (left out; content otherwise unchanged)');
        }
        $this->line('Not copied: ICP profile ids, user ids (reviewed_by), ingestion source link, offer ids that do not exist here.');

        if (! $write) {
            $this->info('Dry run complete. Nothing was written.');

            return self::SUCCESS;
        }
        if ($already > 0) {
            return $this->stop('Signals with this marker already exist. Roll back first.');
        }

        $manifest = ['tenant' => $tenant, 'batch' => $this->batch(), 'marker' => $this->marker(), 'created_at' => now()->toIso8601String(), 'runs' => [], 'companies' => [], 'opportunities' => [], 'events' => [], 'matches' => [], 'sources' => []];
        DB::transaction(function () use ($d, $tenant, $reuse, $existingPrints, $liveOffers, $resolver, &$manifest) {
            $runMap = [];
            $companyMap = $reuse;
            $oppMap = [];
            $cols = fn (string $t) => array_flip(ColumnExists::all($t));
            $clean = fn (array $row, string $table, array $override) => array_intersect_key(array_merge($row, $override), $cols($table));
            $master = fn (?string $productKey) => $resolver && $productKey ? $resolver->product($tenant, $productKey)?->id : null;

            foreach ($d['runs'] as $r) {
                $new = DB::table('g2g_research_runs')->insertGetId($clean($r, 'g2g_research_runs', ['id' => null, 'sub_institute_id' => $tenant, 'triggered_by' => null, 'icp_profile_id' => null, 'product_master_id' => $master($r['product_key'] ?? null)]));
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
                    continue;   // never duplicate or overwrite an existing signal
                }
                $new = DB::table('g2g_company_opportunities')->insertGetId($clean($o, 'g2g_company_opportunities', [
                    'id' => null, 'sub_institute_id' => $tenant, 'company_id' => $companyMap[$o['company_id']] ?? null, 'research_run_id' => $runMap[$o['research_run_id']] ?? null,
                    'ingestion_source_id' => null, 'reviewed_by' => null, 'relevant_offer_id' => null, 'icp_profile_id' => null, 'product_master_id' => $master($o['product_key'] ?? null),
                    'import_source' => $this->marker(), 'is_sample' => 0,
                ]));
                $oppMap[$o['id']] = $new;
                $manifest['opportunities'][] = $new;
            }
            foreach ($d['events'] as $e) {
                if (isset($oppMap[$e['opportunity_id']])) {
                    $manifest['events'][] = DB::table('g2g_opportunity_events')->insertGetId($clean($e, 'g2g_opportunity_events', ['id' => null, 'sub_institute_id' => $tenant, 'opportunity_id' => $oppMap[$e['opportunity_id']], 'scan_log_id' => null]));
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
        if ($this->option('confirm') !== 'ROLLBACK-MARKET-BATCH') {
            return $this->stop('Pass --confirm=ROLLBACK-MARKET-BATCH to delete what the import created.');
        }
        $p = $this->manifestPath($tenant);
        if (! is_file($p)) {
            return $this->stop('No manifest for this tenant and batch: nothing to roll back.');
        }
        $m = json_decode((string) file_get_contents($p), true);
        if (($m['tenant'] ?? null) !== $tenant || ($m['marker'] ?? null) !== $this->marker()) {
            return $this->stop('The manifest does not match this tenant or batch.');
        }

        DB::transaction(function () use ($m, $tenant) {
            // Only ids the import wrote, only inside this tenant; signals must still carry the marker.
            DB::table('g2g_research_sources')->where('sub_institute_id', $tenant)->whereIn('id', $m['sources'])->delete();
            DB::table('g2g_opportunity_matches')->where('sub_institute_id', $tenant)->whereIn('id', $m['matches'])->delete();
            DB::table('g2g_opportunity_events')->where('sub_institute_id', $tenant)->whereIn('id', $m['events'])->delete();
            DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenant)->where('import_source', $this->marker())->whereIn('id', $m['opportunities'])->delete();
            DB::table('g2g_research_runs')->where('sub_institute_id', $tenant)->whereIn('id', $m['runs'])->delete();
            DB::table('g2g_companies')->where('sub_institute_id', $tenant)->whereIn('id', $m['companies'])->delete();
        });
        File::move($p, $p . '.rolled-back-' . now()->format('YmdHis'));
        $this->info('Rolled back: the batch rows were removed. Existing data was not touched.');

        return self::SUCCESS;
    }
}
