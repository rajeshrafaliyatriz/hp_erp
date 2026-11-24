<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Market\EvidenceMaturity;
use App\Domain\Signals\Market\MarketImporter;
use App\Domain\Signals\Market\QualificationGate;
use App\Domain\Signals\Market\SignalGrade;
use App\Domain\Signals\Opportunities\OpportunityResearcher;
use App\Domain\Signals\Opportunities\WebSearchProvider;
use App\Domain\Signals\Run\RunAccounting;
use App\Domain\Signals\SignalRunner;
use App\Domain\Signals\Support\StructuredAi;
use App\Domain\Signals\Support\UnsafeUrlException;
use Tests\TestCase;

require_once __DIR__ . '/EvidenceDepthFetchTest.php';   // defines StubFetcher

/** The optional second-source verification step. A fake search provider and a stub fetcher: no network, no database. */
class VerificationRunTest extends TestCase
{
    private const EVENT = 'Greenfield Public School announced construction of a new science laboratory block for the next academic session.';

    protected function setUp(): void
    {
        parent::setUp();
        config(['signals.verification' => ['enabled' => true, 'max_searches' => 4, 'max_pages' => 6, 'max_candidates' => 3],
            'signals.opportunities.default_recency_days' => 60, 'signals.opportunities.results_per_query' => 6, 'signals.fetch.skip_hosts' => ['facebook.com', 'linkedin.com', 'instagram.com', 'x.com'],
            'signals.run_budget_seconds' => 0]);
    }

    public function test_the_flag_is_off_by_default_with_the_agreed_caps(): void
    {
        $cfg = require __DIR__ . '/../../../config/signals.php';
        $this->assertFalse($cfg['verification']['enabled']);
        $this->assertSame(4, $cfg['verification']['max_searches']);
        $this->assertSame(6, $cfg['verification']['max_pages']);
        $this->assertSame(3, $cfg['verification']['max_candidates']);
    }

    public function test_flag_off_makes_no_search_no_fetch_and_records_nothing(): void
    {
        config(['signals.verification.enabled' => false]);
        $search = new FakeVerifySearch($this->results());
        $f = new StubFetcher($this->pages());
        $r = $this->researcher($f);
        $in = [$this->candidate()];

        $this->assertSame($in, $this->verify($r, $in, $search));
        $this->assertSame([], $search->queries);
        $this->assertSame([], $f->pageCalls);
        $this->assertArrayNotHasKey('verification', $r->diagnostics());
        $this->assertArrayNotHasKey('verification', RunAccounting::build($r->diagnostics(), [], ['sources' => 0, 'candidates' => 0, 'considered' => 0]));
    }

    public function test_run_calls_it_only_behind_the_flag_and_without_a_named_company_scan(): void
    {
        $src = file_get_contents(__DIR__ . '/../../../app/Domain/Signals/Opportunities/OpportunityResearcher.php');
        $this->assertMatchesRegularExpression('/if \(\$company === null && \$custom === null && \$candidates !== \[\] && \(bool\) config\(\'signals\.verification\.enabled\', false\)\) \{\s+try \{\s+\$candidates = \$this->verifyCandidates\(/', $src);
        $this->assertLessThan(strpos($src, '$this->importer->import('), strpos($src, '$this->verifyCandidates('), 'verification happens before the import');
    }

    public function test_a_corroborating_page_upgrades_the_annotations_and_records_before_and_after(): void
    {
        $search = new FakeVerifySearch($this->results());
        $f = new StubFetcher($this->pages());
        $r = $this->researcher($f);
        $in = [$this->candidate(reasoning: 'A new lab block signals growth in enrolment and a need for modern systems.')];

        $out = $this->verify($r, $in, $search);
        $a = $out[0]['assessment'];

        $this->assertCount(2, $search->queries, 'at most two queries per candidate');
        $this->assertSame('E2', $a['evidence_maturity']['level']);
        $this->assertTrue($a['evidence_maturity']['quote_verified']);
        $this->assertSame(2, $a['evidence_maturity']['independent_domains']);
        $this->assertSame('ready', $a['signal_grade']['grade']);
        $this->assertSame('web_page', $a['signal_grade']['source_policy']);
        $v = $a['verification'];
        $this->assertTrue($v['attempted']);
        $this->assertTrue($v['confirmed']);
        $this->assertSame(['level' => 'E0', 'grade' => 'verify'], $v['before']);
        $this->assertSame(['level' => 'E2', 'grade' => 'ready'], $v['after']);
        $this->assertSame($search->queries, $v['queries']);
        $this->assertSame([['url' => 'https://www.cityherald.org/lab', 'host' => 'cityherald.org']], $v['corroborating']);
        $last = end($a['evidence_items']);
        $this->assertSame('https://www.cityherald.org/lab', $last['url']);
        $this->assertNotNull($last['passage'], 'a passage only because the page was read');
        $this->assertSame(['candidates' => 1, 'searches' => 2, 'pages' => 1, 'confirmed' => 1, 'upgraded_to_ready' => 1, 'failed' => 0, 'blocked_by_robots' => 0], $r->diagnostics()['verification']);
        $this->assertSame(1, $r->diagnostics()['fetch_by_host']['cityherald.org']['read']);
    }

    public function test_without_reasoning_the_level_is_e1_and_the_grade_is_still_ready(): void
    {
        $out = $this->verify($this->researcher(new StubFetcher($this->pages())), [$this->candidate()], new FakeVerifySearch($this->results()));
        $this->assertSame('E1', $out[0]['assessment']['evidence_maturity']['level']);
        $this->assertSame('ready', $out[0]['assessment']['signal_grade']['grade']);
    }

    public function test_the_grade_counts_in_the_run_stats_follow_the_upgrade(): void
    {
        $r = $this->researcher(new StubFetcher($this->pages()));
        $this->setProp($r, 'diag', ['grades' => ['verify' => 1]] + $r->diagnostics());
        $this->verify($r, [$this->candidate()], new FakeVerifySearch($this->results()));

        $stats = RunAccounting::build($r->diagnostics(), [], ['sources' => 0, 'candidates' => 1, 'considered' => 1]);
        $this->assertSame(['ready' => 1, 'verify' => 0], $stats['signal_grades']);
        $this->assertSame(1, $stats['verification']['upgraded_to_ready']);
    }

    public function test_claim_level_qualification_and_confidence_are_byte_identical(): void
    {
        $in = [$this->candidate(reasoning: 'A new lab block signals growth in enrolment and a need for modern systems.')];
        $out = $this->verify($this->researcher(new StubFetcher($this->pages())), $in, new FakeVerifySearch($this->results()));

        $strip = function (array $c) {
            unset($c['assessment']);

            return json_encode($c);
        };
        $this->assertSame($strip($in[0]), $strip($out[0]), 'every key outside the assessment is untouched');
        $keep = fn (array $a) => json_encode(array_diff_key($a, array_flip(['evidence_maturity', 'signal_grade', 'evidence_items', 'verification'])));
        $this->assertSame($keep($in[0]['assessment']), $keep($out[0]['assessment']), 'fit, evidence, region... in the assessment are untouched');
        $this->assertSame(json_encode($in[0]['assessment']['fit']), json_encode($out[0]['assessment']['fit']));
    }

    public function test_a_page_from_the_same_domain_or_a_social_url_never_corroborates(): void
    {
        $search = new FakeVerifySearch([
            ['url' => 'https://www.example.com/same-domain', 'title' => 'x', 'excerpt' => '', 'published_at' => null],
            ['url' => 'https://www.facebook.com/p/1', 'title' => 'x', 'excerpt' => '', 'published_at' => null],
            ['url' => 'https://www.linkedin.com/posts/1', 'title' => 'x', 'excerpt' => '', 'published_at' => null],
        ]);
        $f = new StubFetcher(['https://www.example.com/same-domain' => $this->html($this->good()), 'https://www.facebook.com/p/1' => $this->html($this->good()), 'https://www.linkedin.com/posts/1' => $this->html($this->good())]);
        $r = $this->researcher($f);
        $out = $this->verify($r, [$this->candidate()], $search);

        $this->assertSame([], $f->pageCalls, 'neither the original domain nor a social page is even fetched');
        $this->assertSame('verify', $out[0]['assessment']['signal_grade']['grade']);
        $this->assertSame('E0', $out[0]['assessment']['evidence_maturity']['level']);
        $this->assertFalse($out[0]['assessment']['verification']['confirmed']);
        $this->assertSame(['level' => 'E0', 'grade' => 'verify'], $out[0]['assessment']['verification']['after']);
    }

    public function test_a_page_missing_the_organisation_or_the_event_words_does_not_corroborate(): void
    {
        $f = new StubFetcher([
            'https://www.cityherald.org/lab' => $this->html('A school in the city has begun construction of a science laboratory block for the academic session.'),
            'https://www.districtnews.net/lab' => $this->html('Greenfield Public School won a chess trophy and held a sports day.'),
        ]);
        $out = $this->verify($this->researcher($f), [$this->candidate()], new FakeVerifySearch([
            ['url' => 'https://www.cityherald.org/lab', 'title' => '', 'excerpt' => '', 'published_at' => null],
            ['url' => 'https://www.districtnews.net/lab', 'title' => '', 'excerpt' => '', 'published_at' => null],
        ]));

        $this->assertCount(2, $f->pageCalls, 'both were read');
        $this->assertFalse($out[0]['assessment']['verification']['confirmed']);
        $this->assertSame('verify', $out[0]['assessment']['signal_grade']['grade']);
        $this->assertSame('E0', $out[0]['assessment']['evidence_maturity']['level']);
    }

    public function test_a_robots_refused_and_a_failing_page_are_recorded_and_the_run_goes_on(): void
    {
        $search = new FakeVerifySearch([
            ['url' => 'https://www.blocked.org/lab', 'title' => '', 'excerpt' => '', 'published_at' => null],
            ['url' => 'https://www.broken.net/lab', 'title' => '', 'excerpt' => '', 'published_at' => null],
            ['url' => 'https://www.cityherald.org/lab', 'title' => '', 'excerpt' => '', 'published_at' => null],
        ]);
        $f = new StubFetcher(['https://www.broken.net/lab' => new UnsafeUrlException('x', 'http_status', 403), 'https://www.cityherald.org/lab' => $this->html($this->good())], robotsDeny: ['www.blocked.org']);
        $r = $this->researcher($f);
        $out = $this->verify($r, [$this->candidate()], $search);

        $this->assertNotContains('https://www.blocked.org/lab', $f->pageRequests(), 'robots.txt is respected');
        $d = $r->diagnostics();
        $this->assertSame(1, $d['fetch_by_host']['blocked.org']['failed:robots_disallowed']);
        $this->assertSame(1, $d['fetch_by_host']['broken.net']['failed:http_403']);
        $this->assertSame(1, $d['verification']['blocked_by_robots']);
        $this->assertSame(1, $d['verification']['failed']);
        $this->assertTrue($out[0]['assessment']['verification']['confirmed'], 'the third page still corroborates');
    }

    public function test_a_failing_search_is_recorded_and_never_fatal(): void
    {
        $search = new FakeVerifySearch($this->results(), failAll: true);
        $r = $this->researcher(new StubFetcher($this->pages()));
        $out = $this->verify($r, [$this->candidate()], $search);

        $this->assertCount(2, $search->queries);
        $this->assertSame(2, $r->diagnostics()['verification']['failed']);
        $this->assertFalse($out[0]['assessment']['verification']['confirmed']);
        $this->assertSame('verify', $out[0]['assessment']['signal_grade']['grade']);
    }

    public function test_the_search_cap_is_a_total_per_run(): void
    {
        $search = new FakeVerifySearch([]);
        $r = $this->researcher(new StubFetcher([]));
        $this->verify($r, [$this->candidate('One School'), $this->candidate('Two School'), $this->candidate('Three School')], $search);

        $this->assertCount(4, $search->queries, '3 candidates x 2 queries would be 6; the cap is 4');
        $this->assertSame(4, $r->diagnostics()['verification']['searches']);
        $this->assertSame(3, $r->diagnostics()['verification']['candidates']);
    }

    public function test_the_page_cap_is_a_total_per_run(): void
    {
        config(['signals.verification.max_pages' => 2]);
        $res = [];
        $pages = [];
        foreach (['a', 'b', 'c', 'd'] as $h) {
            $res[] = ['url' => "https://www.site-{$h}.org/x", 'title' => '', 'excerpt' => '', 'published_at' => null];
            $pages["https://www.site-{$h}.org/x"] = $this->html('nothing relevant here at all');
        }
        $f = new StubFetcher($pages);
        $r = $this->researcher($f);
        $this->verify($r, [$this->candidate('One School'), $this->candidate('Two School')], new FakeVerifySearch($res));

        $this->assertCount(2, $f->pageRequests());
        $this->assertSame(2, $r->diagnostics()['verification']['pages']);
    }

    public function test_at_most_max_candidates_are_verified_highest_fit_first(): void
    {
        config(['signals.verification.max_candidates' => 1, 'signals.verification.max_searches' => 10]);
        $search = new FakeVerifySearch([]);
        $in = [$this->candidate('Low School', fit: 40), $this->candidate('High School', fit: 90), $this->candidate('Mid School', fit: 60)];
        $out = $this->verify($this->researcher(new StubFetcher([])), $in, $search);

        $this->assertArrayNotHasKey('verification', $out[0]['assessment']);
        $this->assertArrayHasKey('verification', $out[1]['assessment']);
        $this->assertArrayNotHasKey('verification', $out[2]['assessment']);
        $this->assertStringContainsString('High School', $search->queries[0]);
    }

    public function test_a_lead_or_a_reject_is_never_verified(): void
    {
        $search = new FakeVerifySearch($this->results());
        $f = new StubFetcher($this->pages());
        $r = $this->researcher($f);
        $lead = $this->candidate('Lead School', grade: 'lead');
        $reject = $this->candidate('Reject School', grade: 'reject');
        $ready = $this->candidate('Ready School', grade: 'ready');
        $out = $this->verify($r, [$lead, $reject, $ready], $search);

        $this->assertSame([$lead, $reject, $ready], $out);
        $this->assertSame([], $search->queries);
        $this->assertSame([], $f->pageCalls);
        $this->assertArrayNotHasKey('verification', $r->diagnostics());
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function good(): string
    {
        return 'Greenfield Public School has begun construction of a science laboratory block, ready for the academic session in June.';
    }

    private function html(string $text): string
    {
        return '<html><head><title>News</title></head><body><p>' . $text . '</p></body></html>';
    }

    /** @return list<array{url: string, title: string, excerpt: string, published_at: ?string}> */
    private function results(): array
    {
        return [
            ['url' => 'https://www.cityherald.org/lab', 'title' => 'Greenfield expands', 'excerpt' => '', 'published_at' => null],
            ['url' => 'https://www.example.com/same', 'title' => 'Same site', 'excerpt' => '', 'published_at' => null],
        ];
    }

    /** @return array<string, string> */
    private function pages(): array
    {
        return ['https://www.cityherald.org/lab' => $this->html($this->good()), 'https://www.example.com/same' => $this->html($this->good())];
    }

    /** A candidate as candidate() builds it: graded verify (E0, snippet only) unless told otherwise. */
    private function candidate(string $org = 'Greenfield Public School', string $grade = 'verify', int $fit = 70, ?string $reasoning = null): array
    {
        $today = now()->toDateString();
        $gradeInput = [
            'organisation' => $org, 'kind' => null, 'source_url' => 'https://news.example.com/story/1', 'source_host_policy' => 'web_page',
            'evidence_level' => 'E0', 'quote_verified' => false, 'event_date' => $today, 'source_published_at' => $today, 'deadline' => null,
            'today' => $today, 'recency_days' => 60, 'trigger_matched' => true, 'region_status' => 'inside', 'disqualifier_hit' => false,
            'is_opinion_or_research' => false, 'independent_domains' => 1,
        ];
        $graded = SignalGrade::grade($gradeInput);
        $this->assertSame('verify', $graded['grade'], 'the fixture itself is a verify candidate');
        $graded = ['grade' => $grade] + $graded;
        $maturity = EvidenceMaturity::assess(['fetch_level' => 'search_snippet', 'quote_verified' => false, 'primary' => false, 'corroborating_domains' => 1, 'inference_reasoning' => $reasoning]);
        $candidate = [
            'buyer' => ['name' => $org, 'type' => 'school', 'website' => null],
            'trigger_type' => 'expansion', 'what_happened' => str_replace('Greenfield Public School', $org, self::EVENT),
            'source_url' => 'https://news.example.com/story/1', 'source_excerpt' => 'A new science laboratory block is under construction.',
            'claim_level' => 'hypothesis', 'qualification' => 'qualified', 'confidence' => 0.62, 'priority' => 'medium', 'fetch_level' => 'search_snippet',
            'evidence' => ['quote_verified' => false, 'corroborating_domains' => 1, 'inference_reasoning' => $reasoning],
            'assessment' => [
                'fit' => ['total' => $fit, 'band' => 'good'], 'region' => ['status' => 'confirmed'], 'evidence' => ['quote_verified' => false],
                'evidence_maturity' => $maturity + ['independent_sources' => 1], 'evidence_items' => [['url' => 'https://news.example.com/story/1', 'snippet' => 'x', 'passage' => null]],
                'signal_grade' => $graded + ['source_policy' => 'web_page'],
            ],
        ];
        $this->inputs[$org . '|https://news.example.com/story/1'] = ['grade' => $gradeInput, 'primary' => false, 'reasoning' => $reasoning];

        return $candidate;
    }

    /** @var array<string, array<string, mixed>> */
    private array $inputs = [];

    private function verify(OpportunityResearcher $r, array $candidates, WebSearchProvider $search): array
    {
        $keyed = [];
        foreach ($this->inputs as $k => $v) {
            $keyed[mb_strtolower($k)] = $v;
        }
        $this->setProp($r, 'verifyInputs', $keyed);

        return (new \ReflectionMethod($r, 'verifyCandidates'))->invokeArgs($r, [$candidates, $search, 1]);
    }

    private function researcher(StubFetcher $f): OpportunityResearcher
    {
        $gate = (new \ReflectionClass(QualificationGate::class))->newInstanceWithoutConstructor();
        $r = new OpportunityResearcher($this->createMock(StructuredAi::class), $this->createMock(SignalRunner::class), $f, $this->createMock(MarketImporter::class), $gate);
        $this->setProp($r, 'diag', (new \ReflectionMethod($r, 'freshDiagnostics'))->invoke($r));
        $this->setProp($r, 'startedAt', microtime(true));
        $this->inputs = $this->inputs;

        return $r;
    }

    private function setProp(object $o, string $prop, mixed $value): void
    {
        (new \ReflectionProperty($o, $prop))->setValue($o, $value);
    }
}

class FakeVerifySearch implements WebSearchProvider
{
    /** @var list<string> */
    public array $queries = [];

    public function __construct(private readonly array $results, private readonly bool $failAll = false)
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
        $this->queries[] = $query;
        if ($this->failAll) {
            throw new \RuntimeException('HTTP 500');
        }

        return $this->results;
    }
}
