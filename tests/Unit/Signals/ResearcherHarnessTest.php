<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Opportunities\OpportunityResearcher;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Opportunities\WebSearchProvider;
use App\Domain\Signals\Run\RunAccounting;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Exercises the REAL OpportunityResearcher::gather(), finish() and stageStats() with fakes. No Laravel application is booted: a bare container holds
 * an in-memory config, an array cache and a logger that records. No database, no provider, no network.
 *
 * Not covered here (it needs a database): run() itself, i.e. the AI call, the importer, and the order in which the stages run.
 */
class ResearcherHarnessTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: array}> */
    public static array $log = [];

    protected function setUp(): void
    {
        self::$log = [];
        $container = new Container();
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $container->instance('config', new ConfigRepository([
            'signals' => [
                'timezone' => 'Asia/Kolkata', 'run_budget_seconds' => 600,
                'web_search' => ['min_interval_ms' => 0],
                'discovery' => ['query_cache_hours' => 4],
                'opportunities' => ['results_per_query' => 6, 'max_sources' => 40],
            ],
        ]));
        $store = new CacheRepository(new ArrayStore());
        $container->instance('cache', new class($store) {
            public function __construct(private CacheRepository $store)
            {
            }

            public function store(): CacheRepository
            {
                return $this->store;
            }
        });
        // Eloquent asks a connection only for its date format when casting; the PDO factory throws, so any real connection attempt fails the test.
        $tripwire = new \Illuminate\Database\Connection(function () {
            throw new \LogicException('A database connection was attempted in an isolated test.');
        });
        ResearchRun::setConnectionResolver(new class($tripwire) implements \Illuminate\Database\ConnectionResolverInterface {
            public function __construct(private \Illuminate\Database\Connection $c)
            {
            }

            public function connection($name = null)
            {
                return $this->c;
            }

            public function getDefaultConnection()
            {
                return 'tripwire';
            }

            public function setDefaultConnection($name)
            {
            }
        });
        $container->instance('log', new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ResearcherHarnessTest::$log[] = [(string) $level, (string) $message, $context];
            }
        });
    }

    protected function tearDown(): void
    {
        ResearchRun::unsetConnectionResolver();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function researcher(bool $statsColumn = true): HarnessResearcher
    {
        $r = (new \ReflectionClass(HarnessResearcher::class))->newInstanceWithoutConstructor();
        $r->statsColumn = $statsColumn;
        $this->set($r, 'startedAt', microtime(true));

        return $r;
    }

    private function set(object $o, string $prop, mixed $value): void
    {
        $p = new \ReflectionProperty(OpportunityResearcher::class, $prop);
        $p->setAccessible(true);
        $p->setValue($o, $value);
    }

    private function call(object $o, string $method, array $args = []): mixed
    {
        $m = new \ReflectionMethod(OpportunityResearcher::class, $method);
        $m->setAccessible(true);

        return $m->invokeArgs($o, $args);
    }

    private function diag(object $o): array
    {
        return $o->diagnostics();
    }

    private function search(array $script): FakeSearch
    {
        return new FakeSearch($script);
    }

    private function hit(string $url = 'https://tenders.example/school-erp-1'): array
    {
        return ['url' => $url, 'title' => 'School ERP tender', 'excerpt' => 'Tender for school management system', 'published_at' => now()->subDays(2)->toDateString()];
    }

    private function queries(): array
    {
        return [['id' => 'q1', 'q' => 'school erp tender', 'source' => 'tender', 'days' => 60]];
    }

    private function fakeRun(string $id = '5'): FakeRun
    {
        $run = new FakeRun();
        $run->forceFill(['id' => (int) $id, 'sub_institute_id' => 6, 'status' => 'running']);

        return $run;
    }

    private function logged(string $event): array
    {
        return array_values(array_filter(self::$log, fn ($l) => $l[1] === $event));
    }

    // ── searching ────────────────────────────────────────────────────────────

    public function test_empty_results_are_retried_and_only_a_success_is_remembered(): void
    {
        $search = $this->search([[], [$this->hit()]]);

        $first = $this->researcher();
        [$sources1] = $this->call($first, 'gather', [$search, $this->queries(), 30, 6]);
        $this->assertSame([], $sources1, 'run 1: nothing found');
        $this->assertSame(1, $search->calls);

        $second = $this->researcher();
        [$sources2] = $this->call($second, 'gather', [$search, $this->queries(), 30, 6]);
        $this->assertSame(2, $search->calls, 'run 2: the empty search was NOT cached, so it ran again');
        $this->assertCount(1, $sources2);

        $third = $this->researcher();
        $this->call($third, 'gather', [$search, $this->queries(), 30, 6]);
        $this->assertSame(2, $search->calls, 'run 3: the successful search is skipped');
        $this->assertSame(['q1' => 'searched_within_cache_window'], $this->diag($third)['skipped']);
    }

    public function test_a_failed_search_does_not_block_the_next_run(): void
    {
        $search = $this->search([new \RuntimeException('Tavily returned HTTP 500'), [$this->hit()]]);

        $this->call($this->researcher(), 'gather', [$search, $this->queries(), 30, 6]);
        [$sources] = $this->call($this->researcher(), 'gather', [$search, $this->queries(), 30, 6]);

        $this->assertSame(2, $search->calls);
        $this->assertCount(1, $sources);
    }

    public function test_all_searches_failing_is_a_failed_run_with_the_real_reason(): void
    {
        $search = $this->search([new \RuntimeException('Tavily returned HTTP 500'), new \RuntimeException('Tavily returned HTTP 500')]);
        $queries = [['id' => 'q1', 'q' => 'one', 'days' => 30], ['id' => 'q2', 'q' => 'two', 'days' => 30]];
        $r = $this->researcher();

        [$sources, $ran, $failed, $last] = $this->call($r, 'gather', [$search, $queries, 30, 6]);

        $this->assertSame([], $sources);
        $this->assertSame([2, 2], [$ran, $failed]);
        $this->assertSame('failed', RunAccounting::status($ran, $failed, false, false, false));
        $this->assertSame('partial', RunAccounting::status($ran, $failed, false, false, true), 'only a school-website source can carry it');
        $this->assertSame(['q1' => 'http_500', 'q2' => 'http_500'], $this->diag($r)['failed']);
        $stats = $this->call($r, 'stageStats', [0, 0]);
        $this->assertSame('all_searches_failed', $stats['zero_reasons'][0]['code']);
    }

    public function test_one_failing_search_makes_the_run_partial_not_successful(): void
    {
        $search = $this->search([[$this->hit()], new \RuntimeException('Tavily returned HTTP 500')]);
        $queries = [['id' => 'q1', 'q' => 'one', 'days' => 30], ['id' => 'q2', 'q' => 'two', 'days' => 30]];
        $r = $this->researcher();

        [$sources, $ran, $failed] = $this->call($r, 'gather', [$search, $queries, 30, 6]);

        $this->assertCount(1, $sources);
        $this->assertSame('partial', RunAccounting::status($ran, $failed, false, false, false));
        $this->assertSame(1, $this->call($r, 'stageStats', [1, 0])['queries']['failed']);
    }

    public function test_results_older_than_the_window_are_counted_as_dropped_not_silently_lost(): void
    {
        $old = $this->hit('https://old.example/a');
        $old['published_at'] = now()->subDays(400)->toDateString();
        $r = $this->researcher();

        [$sources] = $this->call($r, 'gather', [$this->search([[$old]]), $this->queries(), 30, 6]);

        $this->assertSame([], $sources);
        $stats = $this->call($r, 'stageStats', [0, 0]);
        $this->assertSame(1, $stats['results']['dropped_too_old']);
        $this->assertContains('results_too_old', array_column($stats['zero_reasons'], 'code'));
    }

    // ── closing the run ──────────────────────────────────────────────────────

    public function test_a_completed_run_with_zero_qualified_is_success_with_its_reasons_saved(): void
    {
        $r = $this->researcher();
        $this->call($r, 'gather', [$this->search([[$this->hit()]]), $this->queries(), 30, 6]);
        $run = $this->fakeRun();

        $this->call($r, 'finish', [$run, microtime(true), 'success', ['stage' => 'completed', 'stage_message' => 'Done: no school showed a clear reason to buy.',
            'stage_stats' => $this->call($r, 'stageStats', [1, 0])]]);

        $saved = $run->saves[0];
        $this->assertSame('success', $saved['status']);
        $this->assertSame('completed', $saved['stage']);
        $this->assertArrayHasKey('stage_stats', $saved);
        $this->assertSame(0, json_decode($saved['stage_stats'], true)['outcome']['qualified_total']);
        $this->assertNotSame([], json_decode($saved['stage_stats'], true)['zero_reasons']);
    }

    public function test_a_run_with_qualified_signals_stores_new_updated_and_unchanged_apart(): void
    {
        $r = $this->researcher();
        $this->set($r, 'diag', ['planned' => ['q1'], 'executed' => ['q1'], 'skipped' => [], 'failed' => [], 'returned' => 5, 'dropped_old' => 0, 'pages_fetched' => 2, 'pages_failed' => 0,
            'pages_blocked_by_robots' => 0, 'sources_screened_out' => 0, 'import' => ['accepted' => 2, 'updated' => 1, 'duplicate' => 1, 'rejected_by_gate' => 0]]);
        $run = $this->fakeRun();

        $this->call($r, 'finish', [$run, microtime(true), 'success', ['stage_stats' => $this->call($r, 'stageStats', [5, 4])]]);

        $outcome = json_decode($run->saves[0]['stage_stats'], true)['outcome'];
        $this->assertSame(['new' => 2, 'updated' => 1, 'unchanged_existing' => 1, 'qualified_total' => 4], $outcome);
    }

    public function test_a_summary_over_the_column_limit_is_saved_clamped_and_logged_in_full(): void
    {
        $run = $this->fakeRun();

        $this->call($this->researcher(), 'finish', [$run, microtime(true), 'success', ['stage_message' => str_repeat('é', 600)]]);

        $this->assertSame(255, mb_strlen($run->saves[0]['stage_message']));
        $this->assertSame(600, mb_strlen($this->logged('signals.stage_message_truncated')[0][2]['full_message']));
        $this->assertSame('success', $run->saves[0]['status'], 'a long message does not change the outcome');
    }

    public function test_a_failed_final_save_never_leaves_the_run_successful(): void
    {
        $r = $this->researcher();
        $run = $this->fakeRun();
        $run->failWith = new \RuntimeException('SQLSTATE[22001]: Data too long');

        $result = $this->call($r, 'finish', [$run, microtime(true), 'success', ['stage' => 'completed']]);

        $this->assertSame([], $run->saves, 'the full save did not happen');
        $this->assertSame('failed', $result->status, 'the run object says failed, not success');
        $this->assertSame('run_summary_not_saved', $result->error_code);
        $this->assertSame('run_summary_not_saved', $r->minimalClosed[0][0], 'the minimal close was written');
        $this->assertNotEmpty($this->logged('signals.run_summary_not_saved'));
        $this->assertStringContainsString('SQLSTATE[22001]', $this->logged('signals.run_summary_not_saved')[0][2]['message'], 'the real error is in the log');
        $this->assertNotEmpty($this->logged('signals.run_closed_as_failed'));
    }

    public function test_when_even_the_minimal_close_fails_the_error_is_raised_not_swallowed(): void
    {
        $r = $this->researcher();
        $r->minimalCloseFails = true;
        $run = $this->fakeRun();
        $run->failWith = new \RuntimeException('first');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('first');
        $this->call($r, 'finish', [$run, microtime(true), 'success', []]);
    }

    public function test_missing_stage_stats_column_is_reported_and_the_rest_still_saves(): void
    {
        $r = $this->researcher(statsColumn: false);
        $run = $this->fakeRun();

        $this->call($r, 'finish', [$run, microtime(true), 'success', ['stage' => 'completed', 'stage_stats' => ['x' => 1]]]);

        $this->assertArrayNotHasKey('stage_stats', $run->saves[0]);
        $this->assertSame('success', $run->saves[0]['status']);
        $this->assertNotEmpty($this->logged('signals.stage_stats_not_persisted'), 'it says the breakdown was not stored');
    }

    public function test_closing_the_same_run_twice_gives_the_same_result(): void
    {
        $r = $this->researcher();
        $run = $this->fakeRun();
        $args = [$run, microtime(true), 'success', ['stage' => 'completed', 'stage_message' => 'Done.']];

        $this->call($r, 'finish', $args);
        $this->call($r, 'finish', $args);

        foreach (['status', 'stage', 'stage_message', 'error_code'] as $k) {
            $this->assertSame($run->saves[0][$k], $run->saves[1][$k], $k);
        }
    }

    // ── status rule consistency ──────────────────────────────────────────────

    public function test_no_inline_copy_of_the_status_rule_remains_in_the_researcher(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../../app/Domain/Signals/Opportunities/OpportunityResearcher.php');

        $this->assertSame(3, substr_count($src, 'RunAccounting::status('), 'all-failed, empty-source and final all use the one rule');
        $this->assertStringNotContainsString("? 'partial' : 'success'", $src);
        $this->assertStringNotContainsString('$queriesFailed === $queriesRun &&', $src);
    }
}

class HarnessResearcher extends OpportunityResearcher
{
    public bool $statsColumn = true;
    public bool $minimalCloseFails = false;
    /** @var list<array{0: string, 1: string}> */
    public array $minimalClosed = [];

    protected function statsColumnExists(): bool
    {
        return $this->statsColumn;
    }

    protected function closeRunMinimal(ResearchRun $run, string $code, string $message, int $durationMs): void
    {
        if ($this->minimalCloseFails) {
            throw new \RuntimeException('minimal close failed');
        }
        $this->minimalClosed[] = [$code, $message];
    }
}

class FakeRun extends ResearchRun
{
    /** @var list<array<string, mixed>> */
    public array $saves = [];
    public ?\Throwable $failWith = null;

    public function save(array $options = []): bool
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->saves[] = $this->getAttributes();

        return true;
    }
}

class FakeSearch implements WebSearchProvider
{
    public int $calls = 0;

    /** @param list<array|\Throwable> $script one entry per call: results, or an exception to throw */
    public function __construct(private array $script)
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function search(string $query, int $recencyDays, int $limit, array $options = []): array
    {
        $step = $this->script[$this->calls] ?? [];
        $this->calls++;
        if ($step instanceof \Throwable) {
            throw $step;
        }

        return $step;
    }
}
