<?php

namespace App\Console\Commands;

use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Opportunities\SearchProviderFactory;
use App\Domain\Signals\SignalRunner;
use App\Support\ColumnExists;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Is this environment ready to run Signals? A READ-ONLY readiness report: it reads the database schema, the migrations table and the
 * configuration, and prints PASS / WARN / FAIL per check. It calls no search or AI provider, writes nothing, starts nothing and never prints a
 * key or password (providers are reported only as configured / not configured). Safe on any environment, including production.
 *
 *   php artisan signals:preflight
 *   php artisan signals:preflight --tenant=6 --origin=https://g2g.example.com
 */
class SignalsPreflight extends Command
{
    protected $signature = 'signals:preflight {--tenant= : also report this organisation\'s products, AI configuration and data} {--origin= : a frontend origin to check against the CORS allow-list}';

    protected $description = 'Read-only readiness report: migrations, columns, queue, providers, storage, CORS and manual-only research. No provider is called.';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    private const MIGRATIONS = [
        '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables',
        '2026_10_13_100000_create_g2g_target_accounts_table',
        '2026_10_14_100000_create_g2g_icp_profiles_tables',
        '2026_11_29_090000_add_assessment_to_g2g_company_opportunities',
        '2026_11_29_100000_add_date_provenance_to_g2g_research_sources',
        '2026_11_29_110000_add_modified_date_to_g2g_research_sources',
        '2026_12_01_090000_add_product_key_to_signals_runs_and_sources',
    ];

    private const TABLES = [
        'g2g_company_opportunities', 'g2g_companies', 'g2g_research_runs', 'g2g_research_sources', 'g2g_ingestion_sources', 'g2g_ingestion_analyses',
        'g2g_ingestion_findings', 'g2g_target_accounts', 'g2g_icp_profiles', 'g2g_icp_profile_versions', 'g2g_product_offers', 'g2g_partners',
        'g2g_opportunity_events', 'g2g_signal_scan_log', 'g2g_signal_import_rejections', 'jobs', 'failed_jobs',
    ];

    private const COLUMNS = [
        ['g2g_company_opportunities', 'assessment'], ['g2g_company_opportunities', 'buying_intent'], ['g2g_company_opportunities', 'business_fit'],
        ['g2g_research_sources', 'date_provenance'], ['g2g_research_sources', 'date_confidence'], ['g2g_research_sources', 'modified_at'], ['g2g_research_sources', 'modified_provenance'],
        ['g2g_research_runs', 'product_key'], ['g2g_research_runs', 'icp_profile_id'], ['g2g_ingestion_sources', 'product_key'],
    ];

    public function handle(): int
    {
        $this->database();
        $this->schema();
        $this->queue();
        $this->providers();
        $this->manualOnly();
        $this->storage();
        $this->cors();
        if ($this->option('tenant') !== null) {
            $this->tenant((int) $this->option('tenant'));
        }

        $width = max(array_map(fn ($r) => strlen($r[1]), $this->rows));
        foreach ($this->rows as [$status, $name, $detail]) {
            $line = sprintf('%-4s  %-' . $width . 's  %s', $status, $name, $detail);
            match ($status) { 'FAIL' => $this->error($line), 'WARN' => $this->warn($line), default => $this->line($line) };
        }
        $fails = count(array_filter($this->rows, fn ($r) => $r[0] === 'FAIL'));
        $warns = count(array_filter($this->rows, fn ($r) => $r[0] === 'WARN'));
        $this->newLine();
        $this->line("{$fails} failing, {$warns} warning(s). Nothing was written and no provider was called.");

        return $fails > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function add(string $status, string $name, string $detail = ''): void
    {
        $this->rows[] = [$status, $name, $detail];
    }

    private function database(): void
    {
        $driver = DB::connection()->getDriverName();
        $env = (string) config('app.env');
        $this->add('PASS', 'environment', "APP_ENV={$env}, driver={$driver}, database=" . DB::connection()->getDatabaseName() . ', host=' . (string) config('database.connections.' . config('database.default') . '.host', 'n/a'));
        try {
            $version = in_array($driver, ['mysql', 'mariadb'], true) ? (string) (DB::selectOne('select version() as v')->v ?? '') : $driver;
            $this->add('PASS', 'database server', $version);
            // The two calls MariaDB 10.1 cannot answer through Laravel's own query: both must work here.
            $ok = ColumnExists::has('g2g_research_runs', 'status') || ! Schema::hasTable('g2g_research_runs');
            $this->add($ok ? 'PASS' : 'WARN', 'column checks (SHOW COLUMNS)', $ok ? 'answered' : 'g2g_research_runs.status not found');
            try {
                Schema::hasColumn('g2g_research_runs', 'status');
                $this->add('PASS', 'Schema::hasColumn()', 'works on this server');
            } catch (\Throwable $e) {
                $this->add('WARN', 'Schema::hasColumn()', 'fails on this server (MariaDB < 10.2 without the legacy grammar). Signals code avoids it; other modules may not.');
            }
        } catch (\Throwable $e) {
            $this->add('FAIL', 'database', 'cannot be queried: ' . mb_substr($e->getMessage(), 0, 120));
        }
    }

    private function schema(): void
    {
        try {
            $ran = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
            $pending = array_values(array_diff(self::MIGRATIONS, $ran));
            $this->add($pending === [] ? 'PASS' : 'FAIL', 'migrations', $pending === [] ? count(self::MIGRATIONS) . ' Signals migrations applied' : 'not applied: ' . implode(', ', $pending));
            $missingTables = array_values(array_filter(self::TABLES, fn ($t) => ! Schema::hasTable($t)));
            $this->add($missingTables === [] ? 'PASS' : 'FAIL', 'tables', $missingTables === [] ? count(self::TABLES) . ' present' : 'missing: ' . implode(', ', $missingTables));
            $missingCols = array_values(array_map(fn ($c) => "{$c[0]}.{$c[1]}", array_filter(self::COLUMNS, fn ($c) => Schema::hasTable($c[0]) && ! ColumnExists::has($c[0], $c[1]))));
            $this->add($missingCols === [] ? 'PASS' : 'FAIL', 'columns', $missingCols === [] ? count(self::COLUMNS) . ' required columns present' : 'missing: ' . implode(', ', $missingCols));
        } catch (\Throwable $e) {
            $this->add('FAIL', 'schema', 'cannot be read: ' . mb_substr($e->getMessage(), 0, 120));
        }
    }

    private function queue(): void
    {
        $conn = (string) config('queue.default');
        $queue = (string) config('signals.queue', 'signals');
        if ($conn === 'sync') {
            $this->add('WARN', 'queue', "QUEUE_CONNECTION=sync: Generate Signals would run inside the web request (slow, and it can time out). Use 'database'.");

            return;
        }
        $retry = (int) config("queue.connections.{$conn}.retry_after", 0);
        $this->add($retry > 900 ? 'PASS' : 'FAIL', 'queue retry_after', "{$retry}s (must exceed the 900s job timeout, or a long run is started twice)");
        try {
            $waiting = Schema::hasTable('jobs') ? DB::table('jobs')->where('queue', $queue)->count() : 0;
            $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('queue', $queue)->count() : 0;
            $this->add($failed > 0 ? 'WARN' : 'PASS', 'queue backlog', "{$waiting} waiting on '{$queue}', {$failed} failed. A worker must run: php artisan queue:work --queue={$queue},default --tries=1 --timeout=900");
        } catch (\Throwable) {
            $this->add('WARN', 'queue backlog', 'could not be read');
        }
        $this->add(function_exists('exec') && ! in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true) ? 'PASS' : 'WARN', 'on-demand worker start',
            'the web request starts a short-lived worker: ' . (function_exists('exec') ? 'exec() is available' : 'exec() is disabled, so a standing queue worker is REQUIRED'));
    }

    private function providers(): void
    {
        $search = SearchProviderFactory::make();
        $this->add($search !== null && $search->isConfigured() ? 'PASS' : 'WARN', 'search provider', $search !== null && $search->isConfigured() ? 'configured (driver ' . $search->name() . ')' : 'not configured: Generate Signals will refuse with a clear message');
        if ($this->option('tenant') === null) {
            $this->add('PASS', 'AI provider', 'pass --tenant to check an organisation\'s AI configuration');
        }
    }

    private function manualOnly(): void
    {
        $on = (bool) config('signals.scheduled_research', false);
        $this->add($on ? 'WARN' : 'PASS', 'scheduled research', $on ? 'ON (SIGNALS_SCHEDULED_RESEARCH=true): research will start by itself and spend quota' : 'off: research starts only when a person presses Generate Signals');
        $this->add('PASS', 'scheduled products', implode(', ', (array) config('signals.products', [])) . ' (used only if scheduling is switched on)');
    }

    private function storage(): void
    {
        $disk = (string) config('signals.ingestion.disk', 'local');
        $private = ! in_array($disk, ['public', 's3-public'], true);
        $this->add($private ? 'PASS' : 'FAIL', 'ingestion storage', "disk '{$disk}'" . ($private ? ' (private)' : ' is PUBLIC: uploaded documents would be downloadable'));
        $root = (string) config("filesystems.disks.{$disk}.root", storage_path('app'));
        $this->add(is_dir($root) && is_writable($root) ? 'PASS' : 'FAIL', 'storage writable', $root . (is_writable($root) ? '' : ' is not writable by this user'));
        $maxKb = (int) config('signals.ingestion.max_upload_kb');
        $php = min($this->bytes((string) ini_get('upload_max_filesize')), $this->bytes((string) ini_get('post_max_size')));
        $this->add($php >= $maxKb * 1024 ? 'PASS' : 'WARN', 'upload limits', "app allows {$maxKb} KB; PHP allows " . intdiv($php, 1024) . ' KB (upload_max_filesize / post_max_size). The web server may set a lower limit.');
    }

    private function cors(): void
    {
        $origins = (array) config('cors.allowed_origins', []);
        $this->add('PASS', 'CORS allow-list', count($origins) . ' origin(s) + ' . count((array) config('cors.allowed_origins_patterns', [])) . ' pattern(s); credentials ' . (config('cors.supports_credentials') ? 'on' : 'off'));
        if (($origin = (string) $this->option('origin')) !== '') {
            $allowed = in_array($origin, $origins, true) || array_filter((array) config('cors.allowed_origins_patterns', []), fn ($p) => @preg_match($p, $origin) === 1) !== [];
            $this->add($allowed ? 'PASS' : 'FAIL', "CORS for {$origin}", $allowed ? 'allowed' : 'NOT in config/cors.php: the browser will block every request from it');
        }
    }

    private function tenant(int $tenant): void
    {
        try {
            $this->add(app(SignalRunner::class)->aiConfigured($tenant) ? 'PASS' : 'WARN', "AI provider (tenant {$tenant})", app(SignalRunner::class)->aiConfigured($tenant) ? 'configured' : 'not configured: runs refuse with a clear message');
            foreach ((new ProductCatalog())->listFor($tenant) as $p) {
                $opps = Schema::hasTable('g2g_company_opportunities')
                    ? DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenant)->where('business_fit', $p['business_fit'])->count() : 0;
                $this->add($p['ready'] ? 'PASS' : 'WARN', "product {$p['key']}", ($p['ready'] ? 'ready' : $p['state']) . ", {$p['target_accounts']} target accounts, {$opps} opportunities" . ($p['ready'] ? '' : ' | ' . ($p['missing'][0] ?? '')));
            }
            $unassigned = Schema::hasTable('g2g_company_opportunities') ? DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenant)->whereNull('business_fit')->count() : 0;
            $this->add('PASS', 'unassigned opportunities', "{$unassigned} (older records with no product; they are never assigned to a product automatically)");
        } catch (\Throwable $e) {
            $this->add('FAIL', "tenant {$tenant}", mb_substr($e->getMessage(), 0, 120));
        }
    }

    private function bytes(string $v): int
    {
        $v = trim($v);
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) { 'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n };
    }
}
