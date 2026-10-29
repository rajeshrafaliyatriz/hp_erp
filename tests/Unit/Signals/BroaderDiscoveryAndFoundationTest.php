<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Market\QualificationGate;
use App\Domain\Signals\Opportunities\OpportunityResearcher;
use App\Domain\Signals\Opportunities\WebSearchProvider;
use App\Domain\Signals\Run\RunBudget;
use App\Domain\Signals\Sources\SourceRegistry;
use App\Domain\Signals\Sources\SourceVerificationStore;
use App\Domain\Signals\Support\HostClasses;
use App\Domain\Signals\Support\RegionScope;
use App\Domain\Signals\Support\RobotsPolicy;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Domain\Signals\Support\UnsafeUrlException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * Pure regression tests for the 50-Source Foundation and Broader Website Discovery.
 * No database, no live network, no provider calls.
 */
class BroaderDiscoveryAndFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        $container = new Container();
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $container->instance('config', new ConfigRepository([
            'signals' => [
                'timezone' => 'Asia/Kolkata',
                'run_budget_seconds' => 600,
                'fetch' => ['skip_hosts' => HostClasses::UNREADABLE_SOCIAL],
                'discovery' => [
                    'query_cache_hours' => 0,
                    'max_linked_pages' => 2,
                ],
                'opportunities' => [
                    'results_per_query' => 6,
                    'max_sources' => 40,
                    'fetch_pages' => 8,
                ],
            ],
            'signals_sources' => include __DIR__ . '/../../../config/signals_sources.php',
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
    }

    protected function tearDown(): void
    {
        RunBudget::disarm();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }

    // ── 1. 50-Source Foundation Coverage & Uniqueness ────────────────────────

    public function test_curated_foundation_covers_all_six_required_categories(): void
    {
        $reg = new SourceRegistry();
        $all = $reg->all();

        // 1. Official school websites and school groups
        $schoolsAndGroups = array_filter($all, fn ($s) => in_array($s['category'], ['school', 'school_group'], true));
        $this->assertGreaterThanOrEqual(6, count($schoolsAndGroups), 'foundation covers school groups and official schools');
        $groupNames = array_column($schoolsAndGroups, 'name');
        $this->assertTrue((bool) array_filter($groupNames, fn ($n) => str_contains($n, 'Delhi Public School') || str_contains($n, 'Podar') || str_contains($n, 'Euro')));

        // 2. School identity and education directories
        $directories = array_filter($all, fn ($s) => in_array($s['category'], ['directory', 'affiliation', 'platform'], true));
        $this->assertGreaterThanOrEqual(3, count($directories), 'foundation covers directories and platforms');
        $dirNames = array_column($directories, 'name');
        $this->assertTrue((bool) array_filter($dirNames, fn ($n) => str_contains($n, 'CBSE SARAS') || str_contains($n, 'DIKSHA')));

        // 3. Education boards, government and policy portals
        $boardsAndGov = array_filter($all, fn ($s) => in_array($s['category'], ['board', 'government', 'assessment', 'circular'], true));
        $this->assertGreaterThanOrEqual(10, count($boardsAndGov), 'foundation covers boards, government and policy portals');

        // 4. Education news and industry intelligence
        $news = array_filter($all, fn ($s) => $s['category'] === 'news');
        $this->assertGreaterThanOrEqual(15, count($news), 'foundation covers education news portals');

        // 5. Company websites, associations and relevant business sources
        $companiesAndAssoc = array_filter($all, fn ($s) => in_array($s['category'], ['association', 'vendor', 'market_research'], true) || in_array($s['name'], ['Inc42', 'YourStory']));
        $this->assertGreaterThanOrEqual(5, count($companiesAndAssoc), 'foundation covers business, vendor and association sources');

        // 6. Procurement, tender and buying-intent sources
        $tenders = array_filter($all, fn ($s) => in_array($s['category'], ['tender_portal', 'tender_aggregator', 'it_procurement'], true));
        $this->assertGreaterThanOrEqual(8, count($tenders), 'foundation covers procurement, tender portals and aggregators');
    }

    public function test_foundation_sources_have_unique_canonical_urls_and_no_generic_duplicates(): void
    {
        $reg = new SourceRegistry();
        $urls = array_filter(array_column($reg->all(), 'url'));
        $canonicals = array_map([$reg, 'canonical'], $urls);

        $this->assertSame(count($canonicals), count(array_unique($canonicals)), 'all registered source URLs are canonically unique');
    }

    public function test_unresolved_gseb_host_pair_is_preserved_pending_verification(): void
    {
        $reg = new SourceRegistry();
        $urls = array_filter(array_column($reg->all(), 'url'));
        $hosts = array_map(fn ($u) => strtolower(preg_replace('/^www\./', '', parse_url($u, PHP_URL_HOST) ?? '')), $urls);

        $this->assertContains('website.gseb.org', $hosts, 'website.gseb.org is preserved');
        $this->assertContains('gseb.org', $hosts, 'gseb.org is preserved as unresolved partner pending verification');
    }

    // ── 2. Broader Discovery of External Domains ─────────────────────────────

    public function test_discovery_gathers_and_enriches_domains_outside_configured_registry(): void
    {
        $externalUrl = 'https://unknown-district-school-board.org.in/announcements';
        $fakeSearch = new class($externalUrl) implements WebSearchProvider {
            public function __construct(private string $url) {}
            public function name(): string { return 'mock_search'; }
            public function isConfigured(): bool { return true; }
            public function search(string $query, int $recencyDays, int $limit, array $options = []): array {
                return [[
                    'url' => $this->url,
                    'title' => 'District School Board New Campus and ERP RFP',
                    'excerpt' => 'School board invites proposals for new campus and management system.',
                    'published_at' => '2026-10-25',
                ]];
            }
        };

        $fakeFetcher = new class extends SafeUrlFetcher {
            public function fetch(string $url, ?int $maxBytes = null, ?int $timeout = null, bool $verifyTls = true, array $extraTypes = []): array {
                return [
                    'url' => $url,
                    'status' => 200,
                    'content_type' => 'text/html',
                    'body' => '<html><body><h1>District School Board</h1><p>Full tender document for school management software published on 2026-10-25.</p></body></html>',
                    'truncated' => false,
                    'last_modified' => null,
                ];
            }
        };

        $researcher = (new \ReflectionClass(OpportunityResearcher::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($fakeFetcher) {
            $this->fetcher = $fakeFetcher;
            $this->startedAt = microtime(true);
            $this->diag = $this->freshDiagnostics();
        }, $researcher, OpportunityResearcher::class)();

        $mGather = new \ReflectionMethod(OpportunityResearcher::class, 'gather');
        $mGather->setAccessible(true);
        [$sources] = $mGather->invoke($researcher, $fakeSearch, [['id' => 'test-q', 'q' => 'school tender']], 30, 1);

        $this->assertCount(1, $sources);
        $this->assertSame($externalUrl, $sources[0]['url']);

        // Enrich the external domain page
        $mEnrich = new \ReflectionMethod(OpportunityResearcher::class, 'enrich');
        $mEnrich->setAccessible(true);
        $enriched = $mEnrich->invoke($researcher, $sources);

        $this->assertTrue($enriched[0]['page_fetched']);
        $this->assertStringContainsString('Full tender document for school management software', $enriched[0]['text']);
    }

    // ── 3. Link Following From Discovered Pages ──────────────────────────────

    public function test_link_following_discovers_relevant_announcement_and_tender_links(): void
    {
        $hubUrl = 'https://education-portal.example.org/news-hub';
        $tenderLink = 'https://tenders.state.gov.in/notices/rfp-school-erp-2026.html';

        $fakeFetcher = new class($hubUrl, $tenderLink) extends SafeUrlFetcher {
            public array $fetched = [];
            public function __construct(private string $hub, private string $tender) {}
            public function fetch(string $url, ?int $maxBytes = null, ?int $timeout = null, bool $verifyTls = true, array $extraTypes = []): array {
                $this->fetched[] = $url;
                if ($url === $this->hub) {
                    return [
                        'url' => $url, 'status' => 200, 'content_type' => 'text/html', 'truncated' => false, 'last_modified' => null,
                        'body' => '<html><body><h1>Education News</h1><p>Important updates.</p>' .
                            '<a href="' . $this->tender . '">Official Tender Notice for School ERP Implementation</a>' .
                            '<a href="/privacy-policy">Privacy</a>' .
                            '</body></html>',
                    ];
                }
                return [
                    'url' => $url, 'status' => 200, 'content_type' => 'text/html', 'truncated' => false, 'last_modified' => '2026-10-28',
                    'body' => '<html><body><h1>State Tender Portal</h1><p>Expression of Interest (EOI) for School ERP and LMS implementation across 50 secondary schools. Published on 2026-10-27.</p></body></html>',
                ];
            }
        };

        $researcher = (new \ReflectionClass(OpportunityResearcher::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($fakeFetcher) {
            $this->fetcher = $fakeFetcher;
            $this->startedAt = microtime(true);
            $this->diag = $this->freshDiagnostics();
        }, $researcher, OpportunityResearcher::class)();

        $initialSources = [[
            'id' => 'S1',
            'url' => $hubUrl,
            'title' => 'Education Portal Hub',
            'published_at' => '2026-10-26',
            'text' => 'Education News snippet',
            'page_fetched' => false,
            'retrieved_at' => now()->toIso8601String(),
        ]];

        $mEnrich = new \ReflectionMethod(OpportunityResearcher::class, 'enrich');
        $mEnrich->setAccessible(true);
        $enriched = $mEnrich->invoke($researcher, $initialSources);

        $mFollow = new \ReflectionMethod(OpportunityResearcher::class, 'followDiscoveredLinks');
        $mFollow->setAccessible(true);
        $expanded = $mFollow->invoke($researcher, $enriched, 1);

        $this->assertCount(2, $expanded, 'link following discovered and appended the targeted tender page');
        $this->assertSame('S2', $expanded[1]['id']);
        $this->assertSame($tenderLink, $expanded[1]['url']);
        $this->assertStringContainsString('Expression of Interest (EOI) for School ERP', $expanded[1]['text']);
        $this->assertSame($hubUrl, $expanded[1]['discovered_from']);
        $this->assertTrue($expanded[1]['page_fetched']);
        $this->assertArrayNotHasKey('raw_body', $expanded[0], 'in-memory raw_body was cleaned up');
        $this->assertArrayNotHasKey('raw_body', $expanded[1], 'in-memory raw_body was cleaned up');
    }

    public function test_link_following_respects_skip_hosts_and_social_exclusions(): void
    {
        $pageUrl = 'https://news.example.com/article';
        $fakeFetcher = new class($pageUrl) extends SafeUrlFetcher {
            public array $fetched = [];
            public function __construct(private string $page) {}
            public function fetch(string $url, ?int $maxBytes = null, ?int $timeout = null, bool $verifyTls = true, array $extraTypes = []): array {
                $this->fetched[] = $url;
                return [
                    'url' => $url, 'status' => 200, 'content_type' => 'text/html', 'truncated' => false, 'last_modified' => null,
                    'body' => '<html><body><a href="https://instagram.com/p/tender_post">Tender announcement on Instagram</a></body></html>',
                ];
            }
        };

        $researcher = (new \ReflectionClass(OpportunityResearcher::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($fakeFetcher) {
            $this->fetcher = $fakeFetcher;
            $this->startedAt = microtime(true);
            $this->diag = $this->freshDiagnostics();
        }, $researcher, OpportunityResearcher::class)();

        $sources = [[
            'id' => 'S1', 'url' => $pageUrl, 'title' => 'Article', 'published_at' => '2026-10-26',
            'text' => 'Snippet', 'page_fetched' => false, 'retrieved_at' => now()->toIso8601String(),
        ]];

        $mEnrich = new \ReflectionMethod(OpportunityResearcher::class, 'enrich');
        $mEnrich->setAccessible(true);
        $enriched = $mEnrich->invoke($researcher, $sources);

        $mFollow = new \ReflectionMethod(OpportunityResearcher::class, 'followDiscoveredLinks');
        $mFollow->setAccessible(true);
        $expanded = $mFollow->invoke($researcher, $enriched, 1);

        $this->assertCount(1, $expanded, 'social host link was skipped and not followed');
        $this->assertNotContains('https://instagram.com/p/tender_post', $fakeFetcher->fetched);
    }

    public function test_link_following_strictly_respects_max_linked_pages_cap(): void
    {
        $pageUrl = 'https://portal.example.com/notices';
        $fakeFetcher = new class($pageUrl) extends SafeUrlFetcher {
            public array $fetched = [];
            public function __construct(private string $page) {}
            public function fetch(string $url, ?int $maxBytes = null, ?int $timeout = null, bool $verifyTls = true, array $extraTypes = []): array {
                $this->fetched[] = $url;
                if ($url === $this->page) {
                    $html = '<html><body>';
                    for ($i = 1; $i <= 5; $i++) {
                        $html .= "<a href=\"https://portal.example.com/tender-item-{$i}\">Official Tender Notice {$i} for Schools</a><br>";
                    }
                    $html .= '</body></html>';
                    return ['url' => $url, 'status' => 200, 'content_type' => 'text/html', 'truncated' => false, 'last_modified' => null, 'body' => $html];
                }
                return ['url' => $url, 'status' => 200, 'content_type' => 'text/html', 'truncated' => false, 'last_modified' => null, 'body' => '<html><body><h1>State Education Portal</h1><p>Detailed RFP specification content for school management software and educational resource planning across all district schools for academic year 2026-2027.</p></body></html>'];
            }
        };

        $researcher = (new \ReflectionClass(OpportunityResearcher::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($fakeFetcher) {
            $this->fetcher = $fakeFetcher;
            $this->startedAt = microtime(true);
            $this->diag = $this->freshDiagnostics();
        }, $researcher, OpportunityResearcher::class)();

        $sources = [[
            'id' => 'S1', 'url' => $pageUrl, 'title' => 'Portal', 'published_at' => '2026-10-26',
            'text' => 'Snippet', 'page_fetched' => false, 'retrieved_at' => now()->toIso8601String(),
        ]];

        $mEnrich = new \ReflectionMethod(OpportunityResearcher::class, 'enrich');
        $mEnrich->setAccessible(true);
        $enriched = $mEnrich->invoke($researcher, $sources);

        $mFollow = new \ReflectionMethod(OpportunityResearcher::class, 'followDiscoveredLinks');
        $mFollow->setAccessible(true);
        $expanded = $mFollow->invoke($researcher, $enriched, 1);

        // Max linked pages is configured to 2 in setUp()
        $this->assertCount(3, $expanded, '1 original source + exactly 2 followed links');
        $childFetches = array_filter($fakeFetcher->fetched, fn ($u) => str_contains($u, 'tender-item-'));
        $this->assertCount(2, $childFetches, 'exactly 2 child tender pages were fetched');
    }

    public function test_link_following_deduplicates_repeated_links_and_avoids_re_fetching(): void
    {
        $pageUrl = 'https://portal.example.com/notices';
        $childUrl = 'https://portal.example.com/tender-erp';
        $fakeFetcher = new class($pageUrl, $childUrl) extends SafeUrlFetcher {
            public array $fetched = [];
            public function __construct(private string $page, private string $child) {}
            public function fetch(string $url, ?int $maxBytes = null, ?int $timeout = null, bool $verifyTls = true, array $extraTypes = []): array {
                $this->fetched[] = $url;
                if ($url === $this->page) {
                    $html = '<html><body>' .
                        '<a href="' . $this->child . '">Tender for School ERP Header Link</a>' .
                        '<a href="' . $this->child . '">Tender for School ERP Footer Link</a>' .
                        '</body></html>';
                    return ['url' => $url, 'status' => 200, 'content_type' => 'text/html', 'truncated' => false, 'last_modified' => null, 'body' => $html];
                }
                return ['url' => $url, 'status' => 200, 'content_type' => 'text/html', 'truncated' => false, 'last_modified' => null, 'body' => '<html><body><h1>State Education Portal</h1><p>Detailed ERP specification content for school management software and educational resource planning across all district schools for academic year 2026-2027.</p></body></html>'];
            }
        };

        $researcher = (new \ReflectionClass(OpportunityResearcher::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($fakeFetcher) {
            $this->fetcher = $fakeFetcher;
            $this->startedAt = microtime(true);
            $this->diag = $this->freshDiagnostics();
        }, $researcher, OpportunityResearcher::class)();

        $sources = [[
            'id' => 'S1', 'url' => $pageUrl, 'title' => 'Portal', 'published_at' => '2026-10-26',
            'text' => 'Snippet', 'page_fetched' => false, 'retrieved_at' => now()->toIso8601String(),
        ]];

        $mEnrich = new \ReflectionMethod(OpportunityResearcher::class, 'enrich');
        $mEnrich->setAccessible(true);
        $enriched = $mEnrich->invoke($researcher, $sources);

        $mFollow = new \ReflectionMethod(OpportunityResearcher::class, 'followDiscoveredLinks');
        $mFollow->setAccessible(true);
        $expanded = $mFollow->invoke($researcher, $enriched, 1);

        $this->assertCount(2, $expanded, '1 original source + exactly 1 deduplicated child link');
        $childFetches = array_filter($fakeFetcher->fetched, fn ($u) => $u === $childUrl);
        $this->assertCount(1, $childFetches, 'child URL was fetched only once despite duplicate anchors');
    }

    public function test_link_following_aborts_immediately_when_over_run_budget(): void
    {
        $pageUrl = 'https://portal.example.com/notices';
        $fakeFetcher = new class($pageUrl) extends SafeUrlFetcher {
            public array $fetched = [];
            public function __construct(private string $page) {}
            public function fetch(string $url, ?int $maxBytes = null, ?int $timeout = null, bool $verifyTls = true, array $extraTypes = []): array {
                $this->fetched[] = $url;
                return [
                    'url' => $url, 'status' => 200, 'content_type' => 'text/html', 'truncated' => false, 'last_modified' => null,
                    'body' => '<html><body><a href="https://portal.example.com/tender-rfp">Official Tender Notice for School ERP</a></body></html>',
                ];
            }
        };

        $researcher = (new \ReflectionClass(OpportunityResearcher::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($fakeFetcher) {
            $this->fetcher = $fakeFetcher;
            $this->startedAt = microtime(true);
            $this->diag = $this->freshDiagnostics();
        }, $researcher, OpportunityResearcher::class)();

        $sources = [[
            'id' => 'S1', 'url' => $pageUrl, 'title' => 'Portal', 'published_at' => '2026-10-26',
            'text' => 'Snippet', 'page_fetched' => false, 'retrieved_at' => now()->toIso8601String(),
        ]];

        $mEnrich = new \ReflectionMethod(OpportunityResearcher::class, 'enrich');
        $mEnrich->setAccessible(true);
        $enriched = $mEnrich->invoke($researcher, $sources);

        // Now simulate that the run took long during enrichment and is now over the 65% discovery threshold
        \Closure::bind(function () {
            $this->startedAt = microtime(true) - 500;
        }, $researcher, OpportunityResearcher::class)();

        $mFollow = new \ReflectionMethod(OpportunityResearcher::class, 'followDiscoveredLinks');
        $mFollow->setAccessible(true);
        $expanded = $mFollow->invoke($researcher, $enriched, 1);

        $this->assertCount(1, $expanded, 'no child links followed because run budget exceeded 65%');
        $childFetches = array_filter($fakeFetcher->fetched, fn ($u) => str_contains($u, 'tender-rfp'));
        $this->assertCount(0, $childFetches, 'child link exploration was completely skipped');
        $this->assertArrayNotHasKey('raw_body', $expanded[0], 'raw_body was still cleaned up');
    }

    public function test_link_following_ignores_irrelevant_non_intent_links(): void
    {
        $pageUrl = 'https://portal.example.com/school-news';
        $fakeFetcher = new class($pageUrl) extends SafeUrlFetcher {
            public array $fetched = [];
            public function __construct(private string $page) {}
            public function fetch(string $url, ?int $maxBytes = null, ?int $timeout = null, bool $verifyTls = true, array $extraTypes = []): array {
                $this->fetched[] = $url;
                $html = '<html><body><h1>School News</h1>' .
                    '<a href="https://portal.example.com/privacy-policy">Privacy and Terms of Service</a>' .
                    '<a href="https://portal.example.com/photo-gallery">Annual Sports Day Photos and Highlights</a>' .
                    '<a href="https://portal.example.com/contact-us">Contact our Administrative Office</a>' .
                    '</body></html>';
                return ['url' => $url, 'status' => 200, 'content_type' => 'text/html', 'truncated' => false, 'last_modified' => null, 'body' => $html];
            }
        };

        $researcher = (new \ReflectionClass(OpportunityResearcher::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($fakeFetcher) {
            $this->fetcher = $fakeFetcher;
            $this->startedAt = microtime(true);
            $this->diag = $this->freshDiagnostics();
        }, $researcher, OpportunityResearcher::class)();

        $sources = [[
            'id' => 'S1', 'url' => $pageUrl, 'title' => 'School News', 'published_at' => '2026-10-26',
            'text' => 'Snippet', 'page_fetched' => false, 'retrieved_at' => now()->toIso8601String(),
        ]];

        $mEnrich = new \ReflectionMethod(OpportunityResearcher::class, 'enrich');
        $mEnrich->setAccessible(true);
        $enriched = $mEnrich->invoke($researcher, $sources);

        $mFollow = new \ReflectionMethod(OpportunityResearcher::class, 'followDiscoveredLinks');
        $mFollow->setAccessible(true);
        $expanded = $mFollow->invoke($researcher, $enriched, 1);

        $this->assertCount(1, $expanded, 'no non-intent links (privacy, gallery, contact) were followed');
        $this->assertSame(2, count($fakeFetcher->fetched), 'only initial page and its robots check were requested');
    }

    // ── 4. URL Safety & SSRF Protections ─────────────────────────────────────

    public function test_ssrf_and_private_network_addresses_are_blocked(): void
    {
        $fetcher = new SafeUrlFetcher();

        foreach (['http://127.0.0.1/admin', 'http://localhost/secret', 'http://169.254.169.254/latest/meta-data', 'http://10.0.0.1/status'] as $badUrl) {
            try {
                $fetcher->fetch($badUrl);
                $this->fail("SSRF target {$badUrl} was not blocked");
            } catch (UnsafeUrlException $e) {
                $this->assertContains($e->category, ['blocked_host', 'blocked_address', 'private_ip', 'invalid_url', 'disallowed_scheme']);
            }
        }
    }

    public function test_unsafe_urls_with_non_standard_ports_or_credentials_are_blocked(): void
    {
        $fetcher = new SafeUrlFetcher();

        // Non-standard ports
        foreach (['http://example.com:8080/path', 'http://example.com:22/ssh', 'https://example.com:8443/api'] as $badPort) {
            try {
                $fetcher->validate($badPort);
                $this->fail("Non-standard port target {$badPort} was not blocked");
            } catch (UnsafeUrlException $e) {
                $this->assertSame('blocked_url', $e->category);
            }
        }

        // Embedded credentials
        try {
            $fetcher->validate('http://admin:secret@example.com/portal');
            $this->fail("URL with embedded credentials was not blocked");
        } catch (UnsafeUrlException $e) {
            $this->assertSame('blocked_url', $e->category);
        }

        // Disallowed scheme
        try {
            $fetcher->validate('ftp://files.example.com/tender.doc');
            $this->fail("Non-http/https URL was not blocked");
        } catch (UnsafeUrlException $e) {
            $this->assertSame('blocked_url', $e->category);
        }
    }

    // ── 5. Evidence & Qualification Requirements ─────────────────────────────

    public function test_homepage_availability_alone_does_not_qualify_without_buying_intent_evidence(): void
    {
        $gate = new QualificationGate();

        // 1. Missing trigger
        $res1 = $gate->evaluate([
            'buyer' => ['name' => 'Podar International School', 'type' => 'school'],
            'source_url' => 'https://podareducation.org/',
            'event_date' => '2026-10-25',
        ]);
        $this->assertFalse($res1['ok']);
        $this->assertSame('missing_trigger', $res1['code']);

        // 2. Missing buyer
        $res2 = $gate->evaluate([
            'trigger_summary' => 'School ERP RFP issued',
            'source_url' => 'https://tenders.example.gov.in/eoi',
            'event_date' => '2026-10-25',
        ]);
        $this->assertFalse($res2['ok']);
        $this->assertSame('missing_buyer', $res2['code']);

        // 3. Excluded vendor / coaching industry
        $res3 = $gate->evaluate([
            'buyer' => ['name' => 'Allen Career Institute Coaching', 'type' => 'school'],
            'trigger_summary' => 'Expansion into new classrooms',
            'icp_disqualifier' => 'coaching or tuition centre',
            'source_url' => 'https://allen.example/news',
            'event_date' => '2026-10-25',
        ]);
        $this->assertFalse($res3['ok']);
        $this->assertSame('outside_icp', $res3['code']);

        // 4. Valid evidence-backed record passes
        $res4 = $gate->evaluate([
            'buyer' => ['name' => 'Delhi Public School R.K. Puram', 'type' => 'school'],
            'trigger_type' => 'tender',
            'trigger_summary' => 'School management ERP and learning portal tender published',
            'source_url' => 'https://dpsrkp.net/tenders/erp',
            'event_date' => '2026-10-25',
            'claim_level' => 'confirmed',
            'fetch_level' => 'full_document',
            'business_fit' => 'scholar',
        ]);
        $this->assertTrue($res4['ok']);
        $this->assertSame('confirmed', $res4['record']['claim_level']);
    }

    public function test_search_snippets_alone_are_downgraded_and_cannot_produce_confirmed_signals(): void
    {
        $gate = new QualificationGate();

        // Attempt to claim 'confirmed' when the fetch level was only a search snippet
        $res = $gate->evaluate([
            'buyer' => ['name' => 'The Cathedral and John Connon School', 'type' => 'school'],
            'trigger_type' => 'tender',
            'trigger_summary' => 'School ERP RFP mentioned in search snippet',
            'source_url' => 'https://cathedral-school.com/rfp-snippet',
            'event_date' => '2026-10-25',
            'claim_level' => 'confirmed',
            'fetch_level' => 'search_snippet',
            'business_fit' => 'scholar',
        ]);

        $this->assertTrue($res['ok'], 'record evaluates successfully');
        $this->assertSame('inference', $res['record']['claim_level'], 'confirmed claim is downgraded to inference');
        $this->assertTrue((bool) array_filter($res['notes'], fn ($n) => str_contains($n, 'Downgraded from confirmed to inference')));
    }

    public function test_out_of_geography_opportunities_are_rejected_by_region_scope(): void
    {
        // When region scope targets Gujarat, India
        $scope = RegionScope::fromInput([['country' => 'India', 'state' => 'Gujarat']]);
        $this->assertNotNull($scope);

        // Foreign/out-of-region text (e.g. Kenya / international tender from Phase 3)
        $kenyaEvidence = $scope->evidence(null, 'Teachers Service Commission in Nairobi Kenya issues procurement notice for secondary school curriculum');
        $this->assertSame('outside', $kenyaEvidence['status'], 'out-of-target-geography opportunity is rejected as outside');

        // Target region text
        $gujaratEvidence = $scope->evidence(null, 'Delhi Public School Bopal Ahmedabad Gujarat announces tender for integrated ERP and student information portal');
        $this->assertSame('confirmed', $gujaratEvidence['status'], 'target geography opportunity is confirmed');
        $this->assertSame('Gujarat', $gujaratEvidence['matched']);
    }

    public function test_deduplication_detects_same_event_across_different_sources_via_text_similarity(): void
    {
        // Two independent descriptions of the same school tender published across different portals
        $textA = 'Delhi Public School invites tender for school management ERP software implementation across campuses.';
        $textB = 'Delhi Public School issues tender for school management ERP software implementation across campuses.';

        $words = fn (?string $t) => array_values(array_unique(array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower((string) $t)) ?: [], fn ($w) => strlen($w) > 3)));
        $a = $words($textA);
        $b = $words($textB);
        $union = count(array_unique(array_merge($a, $b)));
        $similarity = $union > 0 ? count(array_intersect($a, $b)) / $union : 0;

        $threshold = (float) config('signals.dedupe_similarity', 0.6);
        $this->assertGreaterThanOrEqual($threshold, $similarity, 'similarity threshold recognizes both pages describe the same underlying event');
    }

    // ── 6. Environment Consistency ───────────────────────────────────────────

    public function test_registry_produces_identical_definitions_in_local_and_production_shapes(): void
    {
        $cfg = include __DIR__ . '/../../../config/signals_sources.php';
        $reg1 = new SourceRegistry($cfg);
        $reg2 = new SourceRegistry($cfg);

        $this->assertSame($reg1->all(), $reg2->all(), 'source definitions are purely deterministic and code-driven');
        $this->assertGreaterThanOrEqual(50, count($reg1->all()));
    }

    public function test_source_verification_state_is_distinct_from_code_configuration(): void
    {
        $cfg = [
            'candidates' => [
                ['Test School', 'https://testschool.example.edu/', 'gujarat', 'school', true, 'core', 'news', 'official_page'],
            ],
        ];

        // 1. Without verification proof (pure code configuration)
        $regNoProof = new SourceRegistry($cfg);
        $all1 = $regNoProof->all();
        $this->assertSame(SourceRegistry::PENDING, $all1[0]['status']);

        // 2. With recorded verification store proof (runtime state)
        $tmpPath = sys_get_temp_dir() . '/test_signals_verification_' . uniqid() . '.json';
        file_put_contents($tmpPath, json_encode([
            'sources' => [
                $all1[0]['id'] => [
                    'machine_readable' => true,
                    'verified_at' => '2026-10-29',
                    'http_status' => 200,
                ],
            ],
        ]));

        try {
            $store = new SourceVerificationStore($tmpPath);
            $regWithProof = new SourceRegistry($cfg, $store);
            $all2 = $regWithProof->all();

            $this->assertSame(SourceRegistry::VERIFIED, $all2[0]['status']);
            $this->assertSame(200, $all2[0]['last_http_status']);
            $this->assertSame('2026-10-29', $all2[0]['last_verified_at']);
        } finally {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }
}
