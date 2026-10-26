<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Products\ProductSignalRules;
use App\Domain\Signals\Run\RunAccounting;
use App\Domain\Signals\Run\RunFinalizer;
use App\Domain\Signals\Run\VerifyEnvironmentGuard;
use App\Domain\Signals\Sources\SourceRegistry;
use App\Domain\Signals\Support\SearchCache;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests: no application boot, no database, no provider, no network. Source-text assertions guard the wiring in files whose real execution
 * would need a database (the researcher, the commands); they prove the code is connected, not that a run succeeds.
 */
class SignalWiringTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../' . $relative);
    }

    // ── search cache: written only after a successful retrieval ───────────────

    public function test_a_search_is_remembered_only_after_it_returned_results(): void
    {
        $cache = new SearchCache(new Repository(new ArrayStore()), 4);

        $this->assertFalse($cache->seen(6, 'school erp tender'), 'unseen before it runs');
        $this->assertFalse($cache->remember(6, 'school erp tender', []), 'an empty result is not remembered');
        $this->assertFalse($cache->seen(6, 'school erp tender'), 'so an empty or failed search is retried next run');

        $this->assertTrue($cache->remember(6, 'school erp tender', [['url' => 'https://a.example']]));
        $this->assertTrue($cache->seen(6, 'school erp tender'));
        $this->assertFalse($cache->seen(1, 'school erp tender'), 'per organisation');
        $this->assertFalse($cache->seen(6, 'another query'));
    }

    public function test_a_failed_search_never_poisons_the_cache(): void
    {
        $cache = new SearchCache(new Repository(new ArrayStore()), 4);
        // A failed search never reaches remember(); the key must still be free.
        $this->assertFalse($cache->seen(6, 'q'));
        $this->assertFalse($cache->seen(6, 'q'));
    }

    public function test_cache_is_off_when_hours_are_zero(): void
    {
        $cache = new SearchCache(new Repository(new ArrayStore()), 0);

        $this->assertFalse($cache->remember(6, 'q', [['url' => 'https://a.example']]));
        $this->assertFalse($cache->seen(6, 'q'));
    }

    public function test_the_researcher_uses_the_search_cache_and_not_the_old_pre_search_write(): void
    {
        $src = $this->source('app/Domain/Signals/Opportunities/OpportunityResearcher.php');

        $this->assertStringContainsString('$searchCache->seen(', $src);
        $this->assertStringContainsString('$searchCache->remember(', $src);
        $this->assertStringNotContainsString('Cache::add(\'signals:q:', $src, 'the cache must not be written before the search runs');
    }

    // ── run closing: persistence failure is explicit ─────────────────────────

    public function test_a_failed_summary_save_closes_the_run_as_failed_and_logs_the_real_error(): void
    {
        $logged = [];
        $closed = null;
        $result = RunFinalizer::persist(
            function () { throw new \RuntimeException('SQLSTATE[22001]: Data too long for column ' . str_repeat('x', 600)); },
            function (string $code, string $message) use (&$closed) { $closed = [$code, $message]; },
            function (string $event, array $ctx) use (&$logged) { $logged[] = [$event, $ctx]; },
        );

        $this->assertSame('closed_as_failed', $result);
        $this->assertSame('run_summary_not_saved', $closed[0]);
        $this->assertStringNotContainsString('SQLSTATE', $closed[1], 'the user-facing message does not echo the database error');
        $this->assertSame('signals.run_summary_not_saved', $logged[0][0]);
        $this->assertStringContainsString('SQLSTATE[22001]', $logged[0][1]['message'], 'the real error is kept in the internal log');
        $this->assertLessThanOrEqual(400, mb_strlen($logged[0][1]['message']), 'and bounded');
    }

    public function test_when_even_the_fallback_fails_the_original_error_is_thrown(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('first');
        RunFinalizer::persist(
            function () { throw new \RuntimeException('first'); },
            function () { throw new \RuntimeException('second'); },
            fn () => null,
        );
    }

    public function test_a_successful_save_is_reported_as_saved_and_skips_the_fallback(): void
    {
        $called = false;

        $this->assertSame('saved', RunFinalizer::persist(fn () => true, function () use (&$called) { $called = true; }, fn () => null));
        $this->assertFalse($called);
    }

    public function test_missing_stage_stats_column_is_reported_not_silently_ignored(): void
    {
        [$attrs, $dropped] = RunFinalizer::withoutMissingColumns(['stage' => 'completed', 'stage_stats' => ['a' => 1]], false);
        $this->assertSame(['stage' => 'completed'], $attrs);
        $this->assertSame(['stage_stats'], $dropped);

        [$attrs, $dropped] = RunFinalizer::withoutMissingColumns(['stage' => 'completed', 'stage_stats' => ['a' => 1]], true);
        $this->assertArrayHasKey('stage_stats', $attrs);
        $this->assertSame([], $dropped);
    }

    public function test_long_messages_are_flagged_so_the_full_text_is_logged(): void
    {
        $this->assertTrue(RunFinalizer::willTruncate(str_repeat('a', 256)));
        $this->assertFalse(RunFinalizer::willTruncate(str_repeat('a', 255)));
        $this->assertFalse(RunFinalizer::willTruncate(null));
    }

    public function test_the_researcher_closes_runs_through_the_finalizer_and_the_status_rule(): void
    {
        $src = $this->source('app/Domain/Signals/Opportunities/OpportunityResearcher.php');

        $this->assertStringContainsString('RunFinalizer::persist(', $src);
        $this->assertStringContainsString('RunFinalizer::withoutMissingColumns(', $src);
        $this->assertSame(3, substr_count($src, 'RunAccounting::status('), 'all-failed, empty-source and final status use the shared rule');
        $this->assertStringContainsString("'stage_stats' => \$this->stageStats(", $src);
    }

    // ── accounting: serialization and consistency ─────────────────────────────

    private function sampleStats(): array
    {
        return RunAccounting::build(
            ['planned' => ['a', 'b'], 'executed' => ['a', 'b'], 'failed' => ['b' => 'http_429'], 'returned' => 9, 'dropped_old' => 1, 'sources_screened_out' => 2,
                'pages_fetched' => 3, 'pages_failed' => 1, 'import' => ['accepted' => 1, 'updated' => 1, 'duplicate' => 2, 'rejected_by_gate' => 1]],
            ['source_off_topic' => 2],
            ['sources' => 6, 'candidates' => 5],
        );
    }

    public function test_accounting_survives_json_storage_unchanged(): void
    {
        $s = $this->sampleStats();

        $this->assertSame($s, json_decode((string) json_encode($s), true));
    }

    public function test_repeated_runs_over_the_same_counters_give_the_same_accounting(): void
    {
        $this->assertSame($this->sampleStats(), $this->sampleStats());
    }

    public function test_qualified_total_matches_the_run_row_formula_the_screens_use(): void
    {
        $o = $this->sampleStats()['outcome'];

        $this->assertSame($o['new'] + $o['updated'] + $o['unchanged_existing'], $o['qualified_total']);
        $this->assertSame(1 + 1 + 2, $o['qualified_total'], 'researcher: accepted + updated + duplicate');
    }

    public function test_every_backend_stats_key_is_declared_in_the_frontend_type(): void
    {
        $ts = __DIR__ . '/../../../../g2gf/services/signals/opportunities.ts';
        if (! is_file($ts)) {
            $this->markTestSkipped('The frontend is not next to the backend here.');
        }
        $type = (string) file_get_contents($ts);
        $keys = [];
        $walk = function (array $a) use (&$walk, &$keys) {
            foreach ($a as $k => $v) {
                if (is_string($k) && ! in_array($k, ['http_429'], true)) {
                    $keys[] = $k;
                }
                if (is_array($v) && $k !== 'errors_by_stage' && $k !== 'zero_reasons') {
                    $walk($v);
                }
            }
        };
        $walk($this->sampleStats());
        foreach (array_unique($keys) as $k) {
            $this->assertStringContainsString($k, $type, "frontend RunStageStats lacks '{$k}'");
        }
    }

    // ── environment guard ────────────────────────────────────────────────────

    private function conn(array $extra = []): array
    {
        return ['mysql' => array_merge(['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'g2g_signals_verify'], $extra),
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'], 'db2' => ['driver' => 'mysql', 'host' => '10.0.0.9', 'database' => 'x']];
    }

    public function test_effective_values_pass_for_the_exact_verification_setup(): void
    {
        VerifyEnvironmentGuard::assert(VerifyEnvironmentGuard::effective('verify', 'mysql', $this->conn()));
        $this->addToAssertionCount(1);
    }

    /** @dataProvider rejectedSetups */
    public function test_effective_values_are_rejected_for_every_other_setup(string $env, string $default, array $connections, array $envVars): void
    {
        $this->expectException(\RuntimeException::class);
        VerifyEnvironmentGuard::assert(VerifyEnvironmentGuard::effective($env, $default, $connections, $envVars));
    }

    public static function rejectedSetups(): array
    {
        $base = ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'g2g_signals_verify'];
        $with = fn (array $x) => ['mysql' => array_merge($base, $x)];

        return [
            'wrong env' => ['local', 'mysql', $with([]), []],
            'wrong host' => ['verify', 'mysql', $with(['host' => 'localhost']), []],
            'remote host' => ['verify', 'mysql', $with(['host' => '202.47.117.220']), []],
            'wrong database' => ['verify', 'mysql', $with(['database' => 'hp_erp']), []],
            'missing database' => ['verify', 'mysql', ['mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1']], []],
            'sqlite fallback as default' => ['verify', 'sqlite', ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']] + $with([]), []],
            'alternate connection as default' => ['verify', 'db2', ['db2' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'g2g_signals_verify']], []],
            'connection url override' => ['verify', 'mysql', $with(['url' => 'mysql://u@remote/hp_erp']), []],
            'read/write split' => ['verify', 'mysql', $with(['read' => ['host' => ['10.0.0.1']]]), []],
            'DB_URL env override' => ['verify', 'mysql', $with([]), ['DB_URL' => 'mysql://u@remote/hp_erp']],
            'unknown default' => ['verify', 'nope', $with([]), []],
        ];
    }

    /** @dataProvider rejectedExtras */
    public function test_socket_framework_env_and_ancillary_backends_are_rejected(array $conn, array $extra): void
    {
        $this->expectException(\RuntimeException::class);
        VerifyEnvironmentGuard::assert(VerifyEnvironmentGuard::effective('verify', 'mysql', $this->conn($conn), [], $extra));
    }

    public static function rejectedExtras(): array
    {
        return [
            'unix socket ignores the host' => [['unix_socket' => '/var/run/mysqld/mysqld.sock'], []],
            '--env disagrees with APP_ENV' => [[], ['frameworkEnv' => 'local']],
            'redis cache' => [[], ['cacheStore' => 'redis']],
            'cache on another connection' => [[], ['cacheStore' => 'database', 'cacheConnection' => 'db2']],
            'queue on another connection' => [[], ['queueDefault' => 'database', 'queueConnection' => 'db2']],
            'redis queue' => [[], ['queueDefault' => 'redis']],
            'session on another connection' => [[], ['sessionDriver' => 'database', 'sessionConnection' => 'db2']],
        ];
    }

    public function test_array_cache_sync_queue_and_default_connection_backends_are_accepted(): void
    {
        VerifyEnvironmentGuard::assert(VerifyEnvironmentGuard::effective('verify', 'mysql', $this->conn(['unix_socket' => '']), [], [
            'frameworkEnv' => 'verify', 'cacheStore' => 'array', 'cacheConnection' => null, 'queueDefault' => 'sync', 'queueConnection' => null,
            'sessionDriver' => 'array', 'sessionConnection' => null,
        ]));
        VerifyEnvironmentGuard::assert(VerifyEnvironmentGuard::effective('verify', 'mysql', $this->conn(), [], [
            'cacheStore' => 'database', 'cacheConnection' => 'mysql', 'queueDefault' => 'database', 'queueConnection' => '',
        ]));
        $this->addToAssertionCount(1);
    }

    public function test_rejection_happens_before_the_connect_callback_is_ever_called(): void
    {
        $connections = 0;
        $connect = function () use (&$connections) { $connections++; };

        foreach ([['appEnv' => 'local', 'dbHost' => '127.0.0.1', 'dbName' => 'g2g_signals_verify'],
            ['appEnv' => 'verify', 'dbHost' => '202.47.117.220', 'dbName' => 'hp_erp'], []] as $bad) {
            try {
                VerifyEnvironmentGuard::guarded($bad, $connect);
                $this->fail('the guard must throw');
            } catch (\RuntimeException) {
            }
        }
        $this->assertSame(0, $connections, 'no connection attempt on any rejection');

        VerifyEnvironmentGuard::guarded(['appEnv' => 'verify', 'dbHost' => '127.0.0.1', 'dbName' => 'g2g_signals_verify'], $connect);
        $this->assertSame(1, $connections);
    }

    public function test_the_research_command_checks_the_guard_before_anything_else(): void
    {
        $src = $this->source('app/Console/Commands/RunOpportunityResearch.php');
        $guard = strpos($src, 'SignalsVerifyPreflight::check()');

        $this->assertNotFalse($guard);
        $this->assertLessThan(strpos($src, 'Cache::forever'), $guard, 'before the first cache (database) write');
        $this->assertLessThan(strpos($src, 'app(OpportunityResearcher::class)'), $guard, 'before the researcher is resolved');
        $this->assertStringNotContainsString('handle(OpportunityResearcher', $src, 'no constructor-injected researcher is built before handle() runs');
        $this->assertStringContainsString('{--verify', $src);
    }

    // ── source registry compatibility ─────────────────────────────────────────

    public function test_the_registry_leaves_the_existing_searches_untouched_and_activates_nothing(): void
    {
        $registry = new SourceRegistry(include __DIR__ . '/../../../config/signals_sources.php');

        $this->assertSame([], $registry->operational());
        foreach ($registry->all() as $s) {
            $this->assertContains($s['status'], [SourceRegistry::PENDING, SourceRegistry::NEEDS_URL]);
        }
        $icp = $this->source('config/signals_icp.php');
        $this->assertStringContainsString("'id' => 'k12-tender-sms'", $icp, 'the existing catalogue is still there');
        $this->assertStringNotContainsString('signals_sources', $icp, 'and does not depend on the new inventory');
        $this->assertStringNotContainsString('SourceRegistry', $this->source('app/Domain/Signals/Opportunities/OpportunityResearcher.php'), 'no retrieval path reads unverified candidates');
    }

    // ── product rules in the real data shape ──────────────────────────────────

    public function test_real_card_shapes_get_the_right_tier(): void
    {
        $newSchool = ProductSignalRules::assess(['url' => 'https://saras.cbse.gov.in/saras/AffiliatedList/AfflicationDetails/430631',
            'text' => 'Swarrnim International School in Gujarat is a newly founded CBSE senior secondary school. Its affiliation runs from April 2026 to March 2031. newly affiliated']);
        $tender = ProductSignalRules::assess(['url' => 'https://www.tenderdetail.com/Indian-Tenders/TenderNotice/57971430/x',
            'text' => 'Tender for Development & Implementation Of School Admission Management & ERP Software, closing 16 Nov 2026.']);
        $campus = ProductSignalRules::assess(['url' => 'https://in.linkedin.com/company/ris-hq', 'text' => 'Rahul International School has opened admissions across four campuses. new campus']);

        $this->assertSame('review', $newSchool['tier'], 'a new school is never "qualified" by itself');
        $this->assertSame('qualified', $tender['tier']);
        $this->assertContains($campus['tier'], ['review', 'informational']);
        $this->assertNotSame('qualified', $campus['tier']);
    }

    public function test_the_capability_result_is_an_annotation_and_never_a_rejection_reason(): void
    {
        $src = $this->source('app/Domain/Signals/Opportunities/OpportunityResearcher.php');

        $this->assertStringContainsString("\$assessment['capability_signal'] = ProductSignalRules::assess(", $src);
        $this->assertDoesNotMatchRegularExpression('/capability_signal[^;]*reject\(/', $src);
        $this->assertSame(1, substr_count($src, 'ProductSignalRules::assess('), 'used once, as an annotation');
    }
}
