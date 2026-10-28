<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Products\ProductSignalRules;
use App\Domain\Signals\Run\RunAccounting;
use App\Domain\Signals\Run\VerifyEnvironmentGuard;
use App\Domain\Signals\Sources\SourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests: no database, no provider, no application boot. Fixtures only.
 */
class SignalIntelligenceOverhaulTest extends TestCase
{
    // ── regression: the zero-qualified runs ─────────────────────────────────────

    /** A summary longer than varchar(255) used to make the run's final save fail, so the run showed "Did not complete" instead of its real outcome. */
    public function test_long_stage_message_is_clamped_to_the_column_width(): void
    {
        $run = new ResearchRun();
        $run->stage_message = str_repeat('é', 600);

        $this->assertSame(255, mb_strlen($run->getAttributes()['stage_message']));
        $run->stage_message = null;
        $this->assertNull($run->getAttributes()['stage_message']);
    }

    public function test_zero_qualified_run_explains_itself_from_tracked_counters_only(): void
    {
        $diag = ['planned' => ['a', 'b', 'c'], 'executed' => ['a', 'b', 'c'], 'failed' => [], 'skipped' => [], 'returned' => 54, 'dropped_old' => 2,
            'sources_screened_out' => 15, 'pages_fetched' => 8, 'pages_failed' => 1, 'pages_blocked_by_robots' => 6,
            'import' => ['accepted' => 0, 'updated' => 0, 'duplicate' => 0, 'rejected_by_gate' => 0]];
        $s = RunAccounting::build($diag, ['source_off_topic' => 22], ['sources' => 25, 'candidates' => 0]);

        $this->assertSame(0, $s['outcome']['qualified_total']);
        $codes = array_column($s['zero_reasons'], 'code');
        $this->assertContains('source_off_topic', $codes);
        $counts = array_column($s['zero_reasons'], 'count');
        $sorted = $counts;
        rsort($sorted);
        $this->assertSame($sorted, $counts, 'the biggest tracked reason comes first');
        $this->assertContains('no_candidate_proposed', $codes);
        $this->assertContains('pages_unreadable', $codes);
        $this->assertNotContains('rejected_by_gate', $codes, 'a reason with count 0 is never invented');
        $this->assertSame(54, $s['results']['returned']);
    }

    public function test_counts_keep_new_updated_and_unchanged_apart(): void
    {
        $s = RunAccounting::build(['import' => ['accepted' => 2, 'updated' => 1, 'duplicate' => 3, 'rejected_by_gate' => 1]], [], ['sources' => 10, 'candidates' => 7]);

        $this->assertSame(['new' => 2, 'updated' => 1, 'unchanged_existing' => 3, 'qualified_total' => 6], $s['outcome']);
        $this->assertSame([], $s['zero_reasons']);
        $this->assertSame(1, $s['candidates']['rejected_by_gate']);
    }

    public function test_empty_search_results_are_not_confused_with_a_clean_run(): void
    {
        $s = RunAccounting::build(['planned' => ['a'], 'executed' => ['a'], 'returned' => 0], [], ['sources' => 0, 'candidates' => 0]);

        $this->assertSame('no_search_results', $s['zero_reasons'][0]['code']);
        $this->assertStringContainsString('Widen', $s['next_action']);
    }

    public function test_all_searches_failing_is_failed_not_success(): void
    {
        $this->assertSame('failed', RunAccounting::status(5, 5, false, false, false));
        $this->assertSame('partial', RunAccounting::status(5, 5, false, false, true), 'a direct source can still carry a run');
        $this->assertSame('partial', RunAccounting::status(5, 2, false, false, false));
        $this->assertSame('partial', RunAccounting::status(5, 0, true, false, false), 'a school site that could not be read');
        $this->assertSame('partial', RunAccounting::status(5, 0, false, true, false), 'stopped by the time budget');
        $this->assertSame('success', RunAccounting::status(5, 0, false, false, false));

        $s = RunAccounting::build(['planned' => ['a', 'b'], 'executed' => ['a', 'b'], 'failed' => ['a' => 'http_429', 'b' => 'http_429']], [], ['sources' => 0, 'candidates' => 0]);
        $this->assertSame('all_searches_failed', $s['zero_reasons'][0]['code']);
        $this->assertSame(['search' => ['http_429' => 2]], $s['errors_by_stage']);
    }

    public function test_provider_error_kinds_are_reported_by_stage(): void
    {
        $s = RunAccounting::build(['planned' => ['a', 'b', 'c'], 'executed' => ['a', 'b', 'c'], 'failed' => ['b' => 'ConnectionException', 'c' => 'http_429'], 'returned' => 6], [], ['sources' => 6, 'candidates' => 1]);

        $this->assertSame(1, $s['queries']['succeeded']);
        $this->assertSame(2, $s['queries']['failed']);
        $this->assertSame(['ConnectionException' => 1, 'http_429' => 1], $s['errors_by_stage']['search']);
    }

    // ── source registry ──────────────────────────────────────────────────────

    private function registry(): SourceRegistry
    {
        return new SourceRegistry(include __DIR__ . '/../../../config/signals_sources.php');
    }

    public function test_registry_has_at_least_eighty_unique_candidates_and_none_is_falsely_operational(): void
    {
        $c = $this->registry()->counts();

        $this->assertGreaterThanOrEqual(80, $c['candidates']);
        $this->assertSame(0, $c['verified_operational']);
        $this->assertSame(0, $this->registry()->operational() === [] ? 0 : 1, 'unverified candidates are never returned as operational');
        $this->assertSame($c['candidates'], $c['pending_verification'] + $c['needs_url']);
    }

    public function test_registry_removes_duplicate_addresses_and_keeps_existing_metadata(): void
    {
        $r = new SourceRegistry(['candidates' => [
            ['CBSE', 'https://www.cbse.gov.in/', 'india', 'board', true, 'core', 'circular', 'official_page'],
            ['CBSE again', 'http://cbse.gov.in', 'india', 'board', true, 'core', 'circular', 'official_page'],
            ['Category only', null, 'state', 'directory', false, 'core', 'directory', 'search_only'],
            ['Category only', null, 'state', 'directory', false, 'core', 'directory', 'search_only'],
        ]]);

        $this->assertSame(2, $r->counts()['candidates']);
        $this->assertSame('CBSE', $r->all()[0]['name']);
        $this->assertSame('needs_url', $r->all()[1]['status']);
    }

    public function test_only_a_recorded_verification_makes_a_source_operational(): void
    {
        $id = (new SourceRegistry(['candidates' => [['A', 'https://a.example/x', 'india', 'news', false, 'core', 'news', 'news_search']]]))->all()[0]['id'];
        $r = new SourceRegistry([
            'verified' => [$id => ['verified_at' => '2026-10-24', 'http_status' => 200, 'machine_readable' => true]],
            'candidates' => [['A', 'https://a.example/x', 'india', 'news', false, 'core', 'news', 'news_search']],
        ]);

        $this->assertSame('verified_operational', $r->all()[0]['status']);
        $this->assertCount(1, $r->operational());
    }

    public function test_every_candidate_names_valid_products_and_a_region(): void
    {
        $valid = ['core', 'learn', 'ccb', 'insight', 'compass'];
        foreach ($this->registry()->all() as $s) {
            $this->assertNotSame([], $s['products'], $s['name']);
            $this->assertSame([], array_diff($s['products'], $valid), $s['name']);
            $this->assertContains($s['region'], ['gujarat', 'state', 'india', 'international'], $s['name']);
        }
        foreach ($valid as $p) {
            $this->assertNotSame([], $this->registry()->forProduct($p), $p);
        }
    }

    // ── product rules ────────────────────────────────────────────────────────

    public function test_procurement_for_a_capability_qualifies(): void
    {
        $a = ProductSignalRules::assess(['url' => 'https://t.example/1', 'text' => 'Tender for development of a school management system and admission management software.']);

        $this->assertSame('qualified', $a['tier']);
        $this->assertSame(['core'], $a['products']);
    }

    public function test_a_new_school_alone_is_only_a_review_candidate(): void
    {
        $a = ProductSignalRules::assess(['url' => 'https://saras.example/1', 'text' => 'Swarrnim International School is a newly affiliated school.']);

        $this->assertSame('review', $a['tier']);
    }

    public function test_association_mention_is_informational_and_unrelated_text_is_rejected(): void
    {
        $this->assertSame('informational', ProductSignalRules::assess(['url' => 'https://x.example', 'text' => 'Principal attended the conference on career guidance.', 'source_kind' => 'event'])['tier']);
        $this->assertSame('rejected', ProductSignalRules::assess(['url' => 'https://x.example', 'text' => 'Cricket match results from the district.'])['tier']);
        $this->assertSame('rejected', ProductSignalRules::assess(['url' => '', 'text' => 'Tender for school ERP.'])['tier'], 'no source address, no signal');
    }

    public function test_each_capability_is_matched_and_multi_product_evidence_lists_all(): void
    {
        $cases = [
            'learn' => 'Bids invited for a learning management system for government schools. Tender no 5.',
            'ccb' => 'Tender for teacher training platform and teacher assessment system.',
            'insight' => 'RFP for school analytics and a school performance dashboard.',
            'compass' => 'Tender for a career guidance platform for Class 9 students.',
        ];
        foreach ($cases as $product => $text) {
            $a = ProductSignalRules::assess(['url' => 'https://t.example', 'text' => $text]);
            $this->assertSame('qualified', $a['tier'], $product);
            $this->assertContains($product, $a['products'], $product);
        }
        $multi = ProductSignalRules::assess(['url' => 'https://t.example', 'text' => 'Tender for school ERP and a learning management system.']);
        $this->assertEqualsCanonicalizing(['core', 'learn'], $multi['products']);
    }

    // ── environment preflight ───────────────────────────────────────────────

    public function test_preflight_accepts_only_the_exact_verification_environment(): void
    {
        VerifyEnvironmentGuard::assert(['appEnv' => 'verify', 'dbHost' => '127.0.0.1', 'dbName' => 'g2g_signals_verify']);
        $this->addToAssertionCount(1);
    }

    /** @dataProvider badEnvironments */
    public function test_preflight_aborts_on_anything_else(array $env): void
    {
        $this->expectException(\RuntimeException::class);
        VerifyEnvironmentGuard::assert($env);
    }

    public static function badEnvironments(): array
    {
        $ok = ['appEnv' => 'verify', 'dbHost' => '127.0.0.1', 'dbName' => 'g2g_signals_verify'];

        return [
            'wrong env' => [array_merge($ok, ['appEnv' => 'local'])],
            'testing env' => [array_merge($ok, ['appEnv' => 'testing'])],
            'localhost is not 127.0.0.1' => [array_merge($ok, ['dbHost' => 'localhost'])],
            'remote host' => [array_merge($ok, ['dbHost' => '202.47.117.220'])],
            'other database' => [array_merge($ok, ['dbName' => 'hp_erp'])],
            'case variant' => [array_merge($ok, ['dbName' => 'G2G_Signals_Verify'])],
            'whitespace' => [array_merge($ok, ['dbHost' => '127.0.0.1 '])],
            'missing host' => [['appEnv' => 'verify', 'dbName' => 'g2g_signals_verify']],
            'empty env' => [array_merge($ok, ['appEnv' => ''])],
            'overridden' => [array_merge($ok, ['dbConnectionOverrides' => ['DB2_HOST']])],
        ];
    }
}
