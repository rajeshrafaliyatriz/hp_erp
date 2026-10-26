<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Opportunities\OpportunityResearcher;
use App\Domain\Signals\Run\RunAccounting;
use App\Domain\Signals\Run\RunBudget;
use App\Domain\Signals\Scholar\SourceScreen;
use App\Domain\Signals\Sources\SourceRegistry;
use App\Domain\Signals\Sources\SourceSelector;
use App\Domain\Signals\Support\FetchFailure;
use App\Domain\Signals\Support\HostClasses;
use App\Domain\Signals\Support\RobotsPolicy;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Domain\Signals\Support\UnsafeUrlException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * Source-quality changes. Pure: no database, no network, no provider. The page fetcher is a fake that records the URLs it was asked for; the real
 * SafeUrlFetcher is exercised only on paths that stop before any request (URL validation, the run's request ceiling). Tests named "source text"
 * inspect code that cannot run here and prove wiring only.
 */
class SourceQualityTest extends TestCase
{
    protected function setUp(): void
    {
        $app = new Container();
        Container::setInstance($app);
        $app->instance('config', new Repository(['signals' => [
            'run_budget_seconds' => 600, 'timezone' => 'Asia/Kolkata', 'opportunities' => ['fetch_pages' => 8],
            'ingestion' => ['user_agent' => 'G2GSignalsBot/1.0'],
            'fetch' => ['skip_hosts' => HostClasses::UNREADABLE_SOCIAL],
            'card' => ['evidence' => ['primary_host_suffixes' => ['.gov.in', '.nic.in']]],
        ]]));
        $app->instance('date', new DateFactory());
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        RunBudget::disarm();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }

    private function source(string $relative): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../../../' . $relative));
    }

    // ── 1. host matching ───────────────────────────────────────────────────────────

    public function test_exact_and_subdomain_hosts_match_and_nothing_else_does(): void
    {
        foreach (['https://reddit.com/r/x', 'https://www.reddit.com/r/x', 'https://old.reddit.com/r/x', 'HTTPS://WWW.REDDIT.COM/', 'https://reddit.com:443/x', 'https://reddit.com./x',
            'https://m.facebook.com/p', 'https://x.com/user/status/1', 'https://www.instagram.com/p/abc', 'https://in.quora.com/q', 'https://mobile.twitter.com/a'] as $url) {
            $this->assertTrue(HostClasses::isUnreadableSocial($url), $url);
        }
        foreach (['https://notreddit.com/x', 'https://reddit.com.evil.example/x', 'https://fedex.com/', 'https://box.com/f', 'https://mix.com/', 'https://max.com.au/',
            'https://example.com/?u=https://reddit.com/x', 'https://example.com/facebook.com', 'https://user@example.com/', 'https://linkedin.com/in/x', 'not a url', '', '/relative/reddit.com'] as $url) {
            $this->assertFalse(HostClasses::isUnreadableSocial($url), $url);
        }
        $this->assertTrue(HostClasses::inDomains('https://sub.example.org/p', ['EXAMPLE.org']));
        $this->assertFalse(HostClasses::inDomains('https://example.org/p', ['sub.example.org']), 'a parent domain is not a match for its own subdomain entry');
        $this->assertSame('example.org', HostClasses::host('https://www.Example.org./p'));
    }

    // ── 1 + 2. social snippets kept, page fetch skipped, failures categorised (real enrich()) ───

    private function researcher(SafeUrlFetcher $fetcher): OpportunityResearcher
    {
        $r = (new \ReflectionClass(OpportunityResearcher::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($fetcher) {
            $this->fetcher = $fetcher;
            $this->startedAt = microtime(true);
        }, $r, OpportunityResearcher::class)();

        return $r;
    }

    private function enrich(OpportunityResearcher $r, array $sources): array
    {
        $m = new \ReflectionMethod($r, 'enrich');
        $m->setAccessible(true);

        return $m->invoke($r, $sources);
    }

    private function fakeFetcher(): object
    {
        return new class extends SafeUrlFetcher {
            /** @var list<string> */
            public array $asked = [];

            public function __construct()
            {
            }

            public function fetch(string $url, ?int $maxBytes = null, ?int $timeout = null, bool $verifyTls = true, array $extraTypes = []): array
            {
                $this->asked[] = $url;
                $host = (string) parse_url($url, PHP_URL_HOST);
                if (str_ends_with($url, '/robots.txt')) {
                    return $this->page($host === 'blocked.example' ? "User-agent: *\nDisallow: /" : "User-agent: *\nAllow: /");
                }

                return match ($host) {
                    'forbidden.example' => throw new UnsafeUrlException('The page returned HTTP 403.', 'http_status', 403),
                    'slow.example' => throw new UnsafeUrlException('The page could not be retrieved.', 'timeout'),
                    'pdf.example' => throw new UnsafeUrlException('Only HTML or plain-text pages can be ingested.', 'non_html'),
                    default => $this->page('<html><body><p>' . str_repeat('The school announced a new campus with admissions open for the coming session. ', 12) . '</p></body></html>'),
                };
            }

            private function page(string $body): array
            {
                return ['url' => '', 'status' => 200, 'content_type' => 'text/html', 'body' => $body, 'truncated' => false, 'last_modified' => null];
            }
        };
    }

    private function sources(): array
    {
        $mk = fn (string $id, string $url) => ['id' => $id, 'url' => $url, 'title' => 'Title ' . $id, 'published_at' => '2026-10-20', 'text' => 'snippet of ' . $id,
            'page_fetched' => false, 'retrieved_at' => '2026-10-26T00:00:00+00:00'];

        return [
            $mk('S1', 'https://www.instagram.com/p/abc'), $mk('S2', 'https://old.reddit.com/r/schools/x'), $mk('S3', 'https://notreddit.com/news/a'),
            $mk('S4', 'https://blocked.example/p'), $mk('S5', 'https://forbidden.example/p'), $mk('S6', 'https://slow.example/p'), $mk('S7', 'https://pdf.example/p'),
            $mk('S8', 'https://www.facebook.com/school/posts/1'),
        ];
    }

    public function test_social_hosts_keep_their_snippet_and_are_never_requested(): void
    {
        $fetcher = $this->fakeFetcher();
        $r = $this->researcher($fetcher);
        $out = $this->enrich($r, $this->sources());
        $by = array_column($out, null, 'id');

        foreach (['S1', 'S2', 'S8'] as $id) {
            $this->assertSame('snippet of ' . $id, $by[$id]['text'], "{$id}: the search snippet is kept for the model");
            $this->assertFalse($by[$id]['page_fetched'], "{$id}: not read");
        }
        $asked = implode(' ', $fetcher->asked);
        foreach (['instagram.com', 'reddit.com', 'facebook.com'] as $host) {
            $this->assertStringNotContainsString($host, str_replace('notreddit.com', '', $asked), "no request, not even robots.txt, to {$host}");
        }
        $this->assertStringContainsString('notreddit.com', $asked, 'a look-alike host is a normal page and is fetched');
        $this->assertTrue($by['S3']['page_fetched']);
    }

    public function test_skips_failures_and_reads_are_counted_apart_and_add_up(): void
    {
        $r = $this->researcher($this->fakeFetcher());
        $this->enrich($r, $this->sources());
        $d = $r->diagnostics();

        $this->assertSame(1, $d['pages_fetched'], 'only notreddit.com was read');
        $this->assertSame(['social_unreadable' => 3], $d['pages_skipped']);
        $this->assertSame(1, $d['pages_blocked_by_robots']);
        $this->assertSame(3, $d['pages_failed'], 'forbidden, slow and non-html; skipped pages are not failures');
        $failures = $d['page_failures'];
        ksort($failures);
        $this->assertSame(['http_403' => 1, 'non_html' => 1, 'timeout' => 1], $failures);

        $p = RunAccounting::build($d, [], ['sources' => 8, 'candidates' => 0])['pages'];
        $this->assertSame(1, $p['fetched']);
        $this->assertSame(4, $p['failed'], 'failed keeps its old meaning: failures plus robots refusals');
        $this->assertSame(3, $p['skipped']);
        $this->assertSame(['social_unreadable' => 3], $p['skipped_by_reason']);
        $this->assertSame(['http_403' => 1, 'non_html' => 1, 'robots_disallowed' => 1, 'timeout' => 1], $p['failed_by_category']);
        $this->assertSame($p['failed'], array_sum($p['failed_by_category']), 'the categories add up to failed');
        $this->assertSame(['third_party' => 3], $p['failed_by_host_class']);
    }

    public function test_a_run_that_recorded_no_categories_reports_them_as_unavailable_not_zero(): void
    {
        $p = RunAccounting::build(['pages_fetched' => 2, 'pages_failed' => 1], [], ['sources' => 3, 'candidates' => 0])['pages'];

        $this->assertSame(['fetched' => 2, 'failed' => 1, 'blocked_by_robots' => 0, 'skipped' => null, 'skipped_by_reason' => null, 'failed_by_category' => null, 'failed_by_host_class' => null], $p);
    }

    public function test_the_social_list_comes_from_config_and_is_not_hardcoded_in_the_researcher(): void
    {
        $this->assertStringContainsString("config('signals.fetch.skip_hosts'", $this->source('app/Domain/Signals/Opportunities/OpportunityResearcher.php'));
        $cfg = $this->source('config/signals.php');
        $this->assertStringContainsString("'skip_hosts' => ['instagram.com', 'facebook.com', 'x.com', 'twitter.com', 'reddit.com', 'quora.com']", $cfg);
        $this->assertStringNotContainsString("'linkedin.com', 'x.com'", $cfg, 'LinkedIn pages are still read');
    }

    // ── 2. failure categories (real classes) ───────────────────────────────────────

    public function test_exceptions_map_to_a_fixed_category_without_using_their_message(): void
    {
        $this->assertSame('http_403', FetchFailure::category(new UnsafeUrlException('secret-token-in-message', 'http_status', 403)));
        $this->assertSame('http_429', FetchFailure::category(new UnsafeUrlException('x', 'http_status', 429)));
        $this->assertSame('http_404', FetchFailure::category(new UnsafeUrlException('x', 'http_status', 404)));
        $this->assertSame('http_4xx', FetchFailure::category(new UnsafeUrlException('x', 'http_status', 418)));
        $this->assertSame('http_5xx', FetchFailure::category(new UnsafeUrlException('x', 'http_status', 503)));
        $this->assertSame('non_html', FetchFailure::category(new UnsafeUrlException('x', 'non_html')));
        $this->assertSame('budget', FetchFailure::category(new UnsafeUrlException('x', 'budget')));
        $this->assertSame('other', FetchFailure::category(new UnsafeUrlException('x', 'made-up-category')));
        $this->assertSame('other', FetchFailure::category(new UnsafeUrlException('legacy caller, message only')));
        $this->assertSame('other', FetchFailure::category(new \RuntimeException('https://x.example/?token=abc')));
        $this->assertSame('timeout', FetchFailure::transport(new \RuntimeException('cURL error 28: Operation timed out')));
        $this->assertSame('dns', FetchFailure::transport(new \RuntimeException('cURL error 6: Could not resolve host')));
        $this->assertSame('connection', FetchFailure::transport(new \RuntimeException('cURL error 7: Failed to connect')));
    }

    public function test_the_real_validator_labels_its_refusals(): void
    {
        $f = new SafeUrlFetcher(fn (string $host) => match ($host) {
            'nowhere.example' => [], 'private.example' => ['10.0.0.5'], default => ['93.184.216.34'],
        });
        $cat = function (string $url) use ($f): string {
            try {
                $f->validate($url);
            } catch (UnsafeUrlException $e) {
                return FetchFailure::category($e);
            }

            return 'accepted';
        };

        $this->assertSame('invalid_url', $cat('not a url'));
        $this->assertSame('blocked_url', $cat('ftp://example.org/x'));
        $this->assertSame('blocked_url', $cat('https://user:pw@example.org/x'));
        $this->assertSame('blocked_url', $cat('https://example.org:8443/x'));
        $this->assertSame('blocked_address', $cat('https://localhost/x'));
        $this->assertSame('blocked_address', $cat('https://private.example/x'));
        $this->assertSame('dns', $cat('https://nowhere.example/x'));
        $this->assertSame('accepted', $cat('https://school.example/x'));
    }

    public function test_the_real_fetcher_refuses_at_the_request_ceiling_before_any_request_and_robots_does_not_swallow_it(): void
    {
        $f = new SafeUrlFetcher(fn () => ['93.184.216.34']);
        RunBudget::arm([RunBudget::PAGE => 0]);

        try {
            $f->fetch('https://school.example/p');
            $this->fail('the request was allowed');
        } catch (UnsafeUrlException $e) {
            $this->assertSame('budget', FetchFailure::category($e));
        }
        try {
            (new RobotsPolicy($f))->allows('https://school.example/p');
            $this->fail('a budget refusal was read as "no robots.txt, so allowed"');
        } catch (UnsafeUrlException $e) {
            $this->assertSame('budget', $e->category);
        }
        RunBudget::disarm();
        // Without a budget, an unreachable robots.txt still means "allowed" (unchanged behaviour); the fake below fails the same way a network error would.
        $broken = new class extends SafeUrlFetcher {
            public function __construct()
            {
            }

            public function fetch(string $url, ?int $maxBytes = null, ?int $timeout = null, bool $verifyTls = true, array $extraTypes = []): array
            {
                throw new UnsafeUrlException('x', 'timeout');
            }
        };
        $this->assertTrue((new RobotsPolicy($broken))->allows('https://school.example/p'));
    }

    // ── 3. Gujarat-first queries ───────────────────────────────────────────────────

    private function queries(): array
    {
        $cfg = include __DIR__ . '/../../../config/signals_icp.php';

        return array_column($cfg['scholar_k12']['queries'], null, 'id');
    }

    public function test_every_previous_query_is_still_present_and_only_two_were_added(): void
    {
        $old = ['k12-tender-sms', 'k12-tender-class', 'k12-li-ask-erp', 'k12-li-ask-lms', 'k12-forum-ask', 'k12-li-job-erp', 'k12-board-job-erp', 'k12-li-job-academic', 'k12-job-erp',
            'k12-li-campus', 'k12-news-campus-gj', 'k12-news-campus-mh', 'k12-affiliation', 'k12-li-leader', 'k12-news-leader-gj', 'k12-news-leader-mh', 'k12-sem-multicampus',
            'k12-sem-excel', 'k12-sem-parent-comm', 'k12-sem-teacher-dev', 'k12-sem-career', 'k12-sem-expansion', 'k12-sem-competitor', 'k12-pain-system', 'k12-news-fee-rules'];
        $q = $this->queries();

        $this->assertSame([], array_diff($old, array_keys($q)), 'no query was removed');
        $this->assertSame(['k12-gu-news-campus', 'k12-gu-news-digital'], array_values(array_diff(array_keys($q), $old)));
        $this->assertCount(27, $q, 'the catalogue grew by two, and the per-run slot count (9) is unchanged');
    }

    public function test_gujarat_aimed_queries_name_gujarat_cities_and_no_longer_say_maharashtra(): void
    {
        $q = $this->queries();
        foreach (['k12-li-ask-lms', 'k12-li-job-erp', 'k12-board-job-erp', 'k12-li-job-academic', 'k12-job-erp', 'k12-li-campus', 'k12-li-leader', 'k12-sem-multicampus',
            'k12-sem-parent-comm', 'k12-sem-teacher-dev', 'k12-pain-system', 'k12-sem-expansion'] as $id) {
            $this->assertStringNotContainsString('Maharashtra', $q[$id]['q'], $id);
            $this->assertMatchesRegularExpression('/Gujarat OR (Ahmedabad|Gandhinagar)/', $q[$id]['q'], $id);
        }
        $this->assertStringContainsString('Gandhinagar OR Bhavnagar OR Jamnagar OR Anand OR Mehsana OR Vapi OR Bharuch', $q['k12-news-campus-gj']['q']);
        $this->assertStringContainsString('Rajkot', $q['k12-news-leader-gj']['q']);
    }

    public function test_all_eleven_requested_cities_appear_and_queries_stay_short(): void
    {
        $all = implode(' ', array_column($this->queries(), 'q'));
        foreach (['Ahmedabad', 'Surat', 'Vadodara', 'Rajkot', 'Gandhinagar', 'Bhavnagar', 'Jamnagar', 'Anand', 'Mehsana', 'Vapi', 'Bharuch'] as $city) {
            $this->assertStringContainsString($city, $all, $city);
        }
        foreach ($this->queries() as $id => $x) {
            $this->assertLessThanOrEqual(300, mb_strlen($x['q']), "{$id}: search APIs cap the query length");
        }
    }

    public function test_non_gujarat_coverage_is_kept_where_it_has_its_own_purpose(): void
    {
        $q = $this->queries();

        $this->assertStringContainsString('Maharashtra', $q['k12-tender-class']['q'], 'the Maharashtra tender portal is in this search\'s domain list');
        $this->assertContains('mahatenders.gov.in', $q['k12-tender-class']['domains']);
        $this->assertStringContainsString('Pune OR Nashik OR Mumbai', $q['k12-news-campus-mh']['q']);
        $this->assertStringContainsString('Mumbai OR Pune', $q['k12-news-leader-mh']['q']);
        $this->assertStringContainsString('Maharashtra', $q['k12-sem-competitor']['q']);
        $this->assertStringContainsString('Maharashtra', $q['k12-news-fee-rules']['q']);
        $this->assertStringContainsString('Maharashtra OR Daman', $q['k12-affiliation']['q']);
        $this->assertStringContainsString('India', $q['k12-li-ask-erp']['q']);
        $this->assertStringContainsString('India', $q['k12-sem-career']['q']);
    }

    public function test_the_gujarati_searches_are_scoped_to_the_gujarati_press_and_use_the_existing_search_shape(): void
    {
        $q = $this->queries();
        foreach (['k12-gu-news-campus', 'k12-gu-news-digital'] as $id) {
            $this->assertSame(['divyabhaskar.co.in', 'sandesh.com', 'gujaratsamachar.com'], $q[$id]['domains']);
            $this->assertSame('news', $q[$id]['topic']);
            $this->assertSame(30, $q[$id]['days']);
            $this->assertSame(1, preg_match('/\p{Gujarati}/u', $q[$id]['q']), 'Gujarati script');
            $this->assertSame(['id', 'source', 'topic', 'days', 'domains', 'q'], array_keys($q[$id]), 'the shape every other catalogue entry has');
        }
    }

    public function test_the_gujarati_searches_can_be_switched_off(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'g2gq') . '.php';
        file_put_contents($script, '<?php require ' . var_export(realpath(__DIR__ . '/../../../vendor/autoload.php'), true) . '; $c = include ' . var_export(realpath(__DIR__ . '/../../../config/signals_icp.php'), true) . '; echo count($c["scholar_k12"]["queries"]);');
        $run = function (string $value) use ($script): int {
            $env = array_merge(getenv(), ['SIGNALS_GUJARATI_QUERIES' => $value]);
            $proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $out = stream_get_contents($pipes[1]);
            proc_close($proc);

            return (int) $out;
        };

        $this->assertSame(25, $run('false'));
        $this->assertSame(27, $run('true'));
        @unlink($script);
    }

    public function test_a_gujarati_page_is_not_screened_out_as_off_topic_and_the_old_screen_is_unchanged(): void
    {
        $this->assertFalse(SourceScreen::offTopic('અમદાવાદમાં નવી શાળાનું ઉદ્ઘાટન', 'શાળા સંકુલમાં વિદ્યાર્થીઓ માટે નવું કેમ્પસ શરૂ થયું'));
        $this->assertTrue(SourceScreen::offTopic('અમદાવાદમાં નવી હોટેલ', 'હોટેલનું ઉદ્ઘાટન આજે થયું'), 'a Gujarati page with no education word is still off topic');
        $this->assertTrue(SourceScreen::offTopic('Hotel opens', 'A new hotel opened downtown.'));
        $this->assertTrue(SourceScreen::offTopic('School district in Texas', 'The school board in Texas, United States voted.'));
        $this->assertFalse(SourceScreen::offTopic('New CBSE school in Surat', 'Admissions open at the new campus.'));
    }

    // ── 4. source-specific registry queries ────────────────────────────────────────

    private function row(string $name, ?string $url, string $region = 'gujarat', string $category = 'circular', array $types = ['circular']): array
    {
        return ['name' => $name, 'url' => $url, 'region' => $region, 'category' => $category, 'signal_types' => $types, 'classification' => 'official', 'products' => ['core']];
    }

    public function test_a_registry_query_carries_the_source_name_and_the_words_of_its_path(): void
    {
        $this->assertSame(
            '"Gujarat COGENT circulars" cogent circular school circular digital OR ERP OR "teacher training" OR "learning management" Gujarat',
            SourceSelector::query($this->row('Gujarat COGENT circulars', 'https://gujgov.edu.in/cogent/circular'))
        );
        $this->assertStringContainsString('cbsenew cbse tender', SourceSelector::query($this->row('CBSE tender listings', 'https://www.cbse.gov.in/cbsenew/cbse_tender.html', 'india', 'tender_portal', ['tender'])));
        $this->assertStringContainsString('city ahmedabad', SourceSelector::query($this->row('Times of India Ahmedabad', 'https://timesofindia.indiatimes.com/city/ahmedabad', 'gujarat', 'news', ['news'])));
        $this->assertStringContainsString('tender OR RFP OR EOI', SourceSelector::query($this->row('Surat Municipal Corporation', 'https://www.suratmunicipal.gov.in/', 'gujarat', 'municipal_board', ['tender', 'circular'])));
    }

    public function test_gujarat_news_names_the_cities_and_other_regions_keep_their_own_wording(): void
    {
        $this->assertStringContainsString('Gujarat Ahmedabad OR Surat OR Vadodara OR Rajkot', SourceSelector::query($this->row('Divya Bhaskar', 'https://www.divyabhaskar.co.in/', 'gujarat', 'news', ['news'])));
        $this->assertStringEndsWith('international school group', SourceSelector::query($this->row('EdSurge', 'https://www.edsurge.com/', 'international', 'news', ['news'])));
        $this->assertStringEndsWith('India', SourceSelector::query($this->row('Ministry of Education', 'https://www.education.gov.in/', 'india', 'ministry', ['policy'])));
    }

    public function test_unusable_names_and_paths_add_nothing_and_never_an_address(): void
    {
        $q = SourceSelector::query($this->row('', 'https://example.org/12345/index.html/ab'));
        $this->assertStringNotContainsString('""', $q);
        $this->assertStringNotContainsString('12345', $q);
        $this->assertStringNotContainsString('example.org', $q);
        $this->assertStringNotContainsString('index', $q);
        $long = SourceSelector::query($this->row(str_repeat('Very long name ', 8), 'https://example.org/'));
        $this->assertStringNotContainsString('Very long name', $long, 'a name too long to help is left out');
        $this->assertStringStartsWith('"Name with quotes" ', SourceSelector::query($this->row('Name "with" quotes (and a note)', 'https://example.org/')), 'quotes and a parenthetical note are removed from the name');
    }

    public function test_every_real_registry_source_has_its_own_short_query_and_selection_stays_bounded(): void
    {
        $registry = new SourceRegistry(include __DIR__ . '/../../../config/signals_sources.php');
        $texts = [];
        foreach ($registry->all() as $row) {
            if ($row['url'] === null) {
                continue;
            }
            $q = SourceSelector::query($row);
            $this->assertLessThanOrEqual(300, mb_strlen($q), $row['name']);
            $this->assertStringNotContainsString('http', $q, $row['name']);
            $texts[$row['id']] = $q;
        }
        $this->assertCount(count($texts), array_unique($texts), 'no two sources share a query text');

        $sel = new SourceSelector($registry);
        foreach ([0, 1, 2, 5] as $slots) {
            $pick = $sel->select(Carbon::parse('2026-10-26'), $slots);
            $this->assertCount($slots, $pick['specs'], "{$slots} slots give exactly {$slots} searches");
            foreach ($pick['specs'] as $spec) {
                $this->assertCount(1, $spec['domains']);
                $this->assertSame('pending_verification', $spec['registry_status'], 'selecting a source never verifies it');
            }
        }
        $this->assertSame([], $registry->operational());
    }

    // ── limits and rules untouched ─────────────────────────────────────────────────

    public function test_source_text_budget_checks_and_the_researchers_gate_inputs_are_still_in_place(): void
    {
        $f = $this->source('app/Domain/Signals/Support/SafeUrlFetcher.php');
        $this->assertLessThan(strpos($f, '->get($url)'), strpos($f, 'RunBudget::take('), 'the request ceiling is still taken before the request');
        $this->assertStringContainsString("'budget'", $f);
        $this->assertStringContainsString("if (\$e->category === 'budget')", $this->source('app/Domain/Signals/Support/RobotsPolicy.php'));

        $r = $this->source('app/Domain/Signals/Opportunities/OpportunityResearcher.php');
        $this->assertStringContainsString("'sales_pull.qualification.max_age_days', 120", str_replace('config(\'sales_pull.qualification.max_age_days\', 120)', "'sales_pull.qualification.max_age_days', 120", $r));
        $this->assertSame(1, substr_count($r, "reject('stale_source')"));
        $this->assertSame(1, substr_count($r, "reject('quote_not_in_source')"));
        $this->assertStringContainsString("fetch_pages', 8", $r, 'the page-read budget is unchanged');
    }
}
