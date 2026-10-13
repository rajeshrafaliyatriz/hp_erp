<?php

namespace App\Console\Commands;

use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\SearchProviderFactory;
use App\Domain\Signals\Support\SignalsAiModule;
use App\Domain\Signals\Support\StructuredAi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tells you WHY company research fails on a given server, step by step, in plain words.
 *
 * The UI deliberately shows only a safe sentence ("Signal generation failed"). This command is for the
 * person running the server: it tests each thing a run depends on and prints the real reason a step
 * fails. It writes nothing (it makes one tiny web search and one tiny AI call) and never prints a key.
 *
 *   php artisan signals:diagnose --tenant=6
 */
class DiagnoseSignals extends Command
{
    protected $signature = 'signals:diagnose {--tenant= : sub_institute_id to test (default: the first with a product profile)}
        {--all : also list the overview of every organisation that has a product profile or an ICP}';

    protected $description = 'Test every step company research depends on and print the real reason any step fails.';

    private int $failures = 0;

    /** Columns research reads or writes that older databases are most likely to lack. */
    private const NEEDED = [
        'g2g_research_runs' => ['stage', 'stage_message', 'opportunities_rejected'],
        'g2g_research_sources' => ['research_run_id', 'cited'],
        'g2g_companies' => ['type', 'state', 'industry', 'location'],
        'g2g_company_aliases' => ['normalized_alias'],
        'g2g_company_opportunities' => ['expires_at', 'is_sample', 'claim_level', 'fetch_level', 'score_total', 'entry_point', 'what_we_could_sell', 'likely_problem', 'soft_marketing_angle', 'trigger_summary', 'urgency', 'import_source', 'pipeline_status'],
        'g2g_opportunity_events' => ['changes'],
        'g2g_opportunity_matches' => ['match_score', 'readiness_at_match'],
        'g2g_signal_scan_log' => ['records_accepted'],
        'g2g_signal_import_rejections' => ['reason_code'],
        'g2g_product_offers' => ['offer_id', 'readiness_confirmed'],
        'jobs' => ['queue'],
    ];

    public function handle(): int
    {
        $tenant = $this->option('tenant') !== null
            ? (int) $this->option('tenant')
            : (int) (ProductProfile::query()->value('sub_institute_id') ?? 0);

        $this->line("Signals research diagnosis (tenant {$tenant})");
        $this->line(str_repeat('-', 60));

        $this->step('PHP version is 8.1 or newer (the research code needs it)', function () {
            if (version_compare(PHP_VERSION, '8.1.0', '<')) {
                throw new \RuntimeException('This server runs PHP ' . PHP_VERSION . '. Upgrade to 8.1+.');
            }

            return 'PHP ' . PHP_VERSION;
        });

        $this->step('Database connection', function () {
            DB::select('select 1');

            return DB::connection()->getDatabaseName() . ' on ' . config('database.connections.' . config('database.default') . '.host');
        });

        $this->step('Database has every table and column research needs', function () {
            $missing = [];
            foreach (self::NEEDED as $table => $columns) {
                if (! Schema::hasTable($table)) {
                    $missing[] = "table {$table}";

                    continue;
                }
                // SHOW COLUMNS, not Schema::getColumnListing(): the latter selects information_schema.generation_expression,
                // which MariaDB 10.1 does not have.
                $have = array_map(fn ($row) => strtolower($row->Field), DB::select("SHOW COLUMNS FROM `{$table}`"));
                foreach ($columns as $c) {
                    if (! in_array($c, $have, true)) {
                        $missing[] = "{$table}.{$c}";
                    }
                }
            }
            if ($missing !== []) {
                throw new \RuntimeException('MISSING: ' . implode(', ', $missing) . '. Run signals-live-db-migration.sql (or php artisan migrate) on this database.');
            }

            return 'complete';
        });

        $this->step('Every model research saves works on this database version', function () {
            // Reproduces the MariaDB 10.1 failure ("Unknown column generation_expression") without writing anything:
            // fill() is what triggers Eloquent's column introspection, and it needs no insert.
            $broken = [];
            foreach ([
                \App\Domain\Signals\Market\ScanLog::class, \App\Domain\Signals\Market\OpportunityEvent::class,
                \App\Domain\Signals\Market\ImportRejection::class, \App\Domain\Signals\Market\CompanyAlias::class,
                \App\Domain\Signals\Opportunities\Company::class, \App\Domain\Signals\Opportunities\CompanyOpportunity::class,
                \App\Domain\Signals\Opportunities\ResearchRun::class, \App\Domain\Signals\Opportunities\ResearchSource::class,
                \App\Domain\Portfolio\OpportunityMatch::class, \App\Domain\Portfolio\ProductOffer::class,
            ] as $class) {
                try {
                    (new $class())->fill(['sub_institute_id' => 1]);
                } catch (\Throwable $e) {
                    $broken[] = class_basename($class) . ' (' . mb_substr($e->getMessage(), 0, 90) . ')';
                }
            }
            if ($broken !== []) {
                throw new \RuntimeException('These cannot be saved here: ' . implode('; ', $broken) . '. Add the SkipsGuardableColumnCheck trait to each.');
            }

            return 'all can be saved';
        });

        $this->step('Product profile exists and is complete', function () use ($tenant) {
            $p = ProductProfile::where('sub_institute_id', $tenant)->first();
            if (! $p) {
                throw new \RuntimeException("No product profile for tenant {$tenant}. Note: the tenant is the organisation id (sub_institute_id), NOT the department id in the page address. Run without --tenant to auto-pick one, or fill in Product profile on the Signals screen.");
            }
            $c = app(\App\Domain\Signals\Opportunities\ProductProfileService::class)->completeness($p);
            if (! $c['complete']) {
                throw new \RuntimeException('Profile incomplete, missing: ' . implode(', ', (array) $c['missing']));
            }

            return 'complete';
        });

        $this->step('Web search (Tavily): one real search', function () {
            $search = SearchProviderFactory::make();
            if ($search === null || ! $search->isConfigured()) {
                throw new \RuntimeException('No search provider configured. Set SIGNALS_SEARCH_DRIVER=tavily and TAVILY_API_KEY in .env, then php artisan config:clear.');
            }
            $r = $search->search('company announces expansion', 7, 1);

            return $search->name() . ' answered with ' . count($r) . ' result(s)';
        });

        $this->step('AI provider: one tiny real call', function () use ($tenant) {
            $cfg = app(\App\Domain\AI\Configuration\AiConfigurationResolver::class)->resolve(app(SignalsAiModule::class)->key($tenant), $tenant);
            if (! $cfg->hasKey()) {
                throw new \RuntimeException("No API key for provider '{$cfg->provider}'. Set it in .env or in AI Providers.");
            }
            $out = app(StructuredAi::class)->json($tenant, [
                ['role' => 'system', 'content' => 'Reply with one JSON object only: {"ok":true}'],
                ['role' => 'user', 'content' => 'ping'],
            ], 50);

            return "{$cfg->provider} / {$cfg->model} answered";
        });

        $this->step('Queue worker can pick up jobs', function () {
            $conn = (string) config('queue.default');
            if ($conn === 'sync') {
                return 'queue is "sync": runs inside the web request (no worker needed, but slow)';
            }
            $waiting = DB::table('jobs')->where('queue', config('signals.queue', 'signals'))->count();
            $retry = (int) config('queue.connections.' . $conn . '.retry_after', 0);
            if ($retry > 0 && $retry < 900) {
                throw new \RuntimeException("queue retry_after is {$retry}s but a run can take 2+ minutes and the job timeout is 900s: raise retry_after to 960 (config/queue.php) or runs get marked failed.");
            }

            return "queue '{$conn}', {$waiting} job(s) waiting on '" . config('signals.queue', 'signals') . "'. A worker must be running: php artisan queue:work --queue=signals --tries=1 --timeout=900";
        });

        $this->step('No stale lock is blocking this tenant', function () use ($tenant) {
            $lock = Cache::lock("signals:research:{$tenant}", 5);
            if (! $lock->get()) {
                throw new \RuntimeException('A previous run left a lock behind (it blocks new runs for up to 30 minutes). Clear with: php artisan tinker --execute="Cache::lock(\'signals:research:' . $tenant . '\')->forceRelease();"');
            }
            $lock->release();

            return 'free';
        });

        $this->line(str_repeat('-', 60));
        $this->line('Tenant overview (will research actually run?)');
        $overview = app(\App\Domain\Signals\Icp\SignalsTenantOverview::class);
        $ids = $this->option('all') ? $overview->tenants() : ($tenant > 0 ? [$tenant] : []);
        foreach ($ids as $id) {
            try {
                foreach ($overview->lines($overview->forTenant((int) $id)) as $line) {
                    $this->line($line);
                }
            } catch (\Throwable $e) {
                $this->line("Organisation {$id}: could not be summarised (" . mb_substr($e->getMessage(), 0, 120) . ')');
            }
        }
        if ($ids === []) {
            $this->line('  (no organisation to show: give --tenant, or --all)');
        }
        $this->line(str_repeat('-', 60));
        if ($this->failures === 0) {
            $this->info('All steps passed. If a run still fails, send the newest "signals.research_failed" line from storage/logs/laravel.log.');
        } else {
            $this->error($this->failures . ' step(s) failed. Fix the first FAIL above, then run this command again.');
        }

        return $this->failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function step(string $label, callable $test): void
    {
        try {
            $note = (string) $test();
            $this->line("  <info>PASS</info>  {$label}" . ($note !== '' ? "  ({$note})" : ''));
        } catch (\Throwable $e) {
            $this->failures++;
            $this->line("  <error>FAIL</error>  {$label}");
            $this->line('        ' . mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 600));
        }
    }
}
