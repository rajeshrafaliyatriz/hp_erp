<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Ingestion\DocumentExtractor;
use App\Domain\Signals\Run\RunAccounting;
use App\Domain\Signals\Run\RunBudget;
use App\Domain\Signals\Sources\DirectSourceReader;
use App\Domain\Signals\Sources\SourceReader;
use App\Domain\Signals\Sources\SourceRegistry;
use App\Domain\Signals\Sources\SourceVerificationStore;
use App\Domain\Signals\Support\RobotsPolicy;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Domain\Signals\Support\UnsafeUrlException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Direct reading of verified sources. Pure: a fake fetcher serves canned pages, the verification store is a temp file, and no network, database or
 * provider is touched. Tests named "source text" inspect code that cannot run here and prove wiring only.
 */
class DirectSourcesTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $app = new Container();
        Container::setInstance($app);
        $app->instance('config', new Repository(['signals' => ['timezone' => 'Asia/Kolkata', 'ingestion' => ['user_agent' => 'G2GSignalsBot/1.0']]]));
        $this->file = sys_get_temp_dir() . '/g2g_sv_' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        RunBudget::disarm();
        Container::setInstance(null);
    }

    private function source(string $relative): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../../../' . $relative));
    }

    private const RSS = '<?xml version="1.0"?><rss version="2.0"><channel><title>News</title>'
        . '<item><title>Old story about a school</title><link>https://news.example/old</link><pubDate>Mon, 01 Sep 2025 10:00:00 +0000</pubDate><description>x</description></item>'
        . '<item><title>New campus opens for a school group</title><link>https://news.example/new</link><pubDate>Fri, 24 Oct 2026 08:00:00 +0000</pubDate><description>&lt;p&gt;A school trust &amp;amp; its campus&lt;/p&gt;</description></item>'
        . '<item><title>No date item</title><link>https://news.example/nodate</link></item>'
        . '<item><title>Bad link</title><link>javascript:void(0)</link></item></channel></rss>';

    private const ATOM = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>t</title>'
        . '<entry><title>Atom entry</title><link rel="self" href="https://a.example/self"/><link rel="alternate" href="https://a.example/e1"/><updated>2026-10-20T10:00:00Z</updated><summary>S</summary></entry></feed>';

    // ── structure parsing (real SourceReader) ──────────────────────────────────────

    public function test_rss_entries_come_back_newest_first_with_clean_summaries_and_only_real_links(): void
    {
        $items = SourceReader::parseFeed(self::RSS);

        $this->assertSame(['https://news.example/new', 'https://news.example/old', 'https://news.example/nodate'], array_column($items, 'url'), 'newest first, undated last, the javascript: link dropped');
        $this->assertSame('2026-10-24', $items[0]['date']);
        $this->assertSame('A school trust & its campus', $items[0]['summary'], 'tags removed, entities decoded');
        $this->assertNull($items[2]['date']);
    }

    public function test_atom_entries_use_the_alternate_link_and_a_garbage_document_gives_nothing(): void
    {
        $items = SourceReader::parseFeed(self::ATOM);
        $this->assertSame('https://a.example/e1', $items[0]['url']);
        $this->assertSame('2026-10-20', $items[0]['date']);
        $this->assertSame([], SourceReader::parseFeed('<html><body>not a feed</body></html>'));
        $this->assertSame([], SourceReader::parseFeed(''));
        $this->assertSame([], SourceReader::parseFeed('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><rss><channel><item><title>&e;</title><link>https://x.example/a</link></item></channel></rss>')[0]['title'] === '' ? [] : ['leaked']);
    }

    public function test_advertised_feeds_must_be_on_the_same_site(): void
    {
        $html = '<html><head><link rel="alternate" type="application/rss+xml" href="/feed.xml"><link rel="alternate" type="application/rss+xml" href="https://other.example/feed">'
            . '<link rel="stylesheet" href="/a.css"><link rel="alternate" type="application/atom+xml" href="https://blog.site.example/atom"></head></html>';

        $this->assertSame(['https://www.site.example/feed.xml', 'https://blog.site.example/atom'], SourceReader::feedLinks($html, 'https://www.site.example/news/'));
    }

    public function test_listing_links_keep_announcements_on_the_same_site_in_page_order(): void
    {
        $html = '<html><body><a href="/circular/2026-45.pdf">Circular 45</a>'
            . '<a href="/news/new-campus-announced">New campus announced for the school in Surat</a>'
            . '<a href="/about">About us and our long and very general history page</a>'
            . '<a href="https://elsewhere.example/news/school-story">A school story on another site entirely</a>'
            . '<a href="/login">Login to the school portal for parents</a>'
            . '<a href="/news/new-campus-announced">New campus announced for the school in Surat</a>'
            . '<a href="#top">Back to top of the education page</a>'
            . '<a href="/tender/erp-eoi">Expression of interest for school ERP software</a></body></html>';
        $links = SourceReader::listingLinks($html, 'https://board.example/index.html', 10);

        $this->assertSame(['https://board.example/circular/2026-45.pdf', 'https://board.example/news/new-campus-announced', 'https://board.example/tender/erp-eoi'], array_column($links, 'url'));
        $this->assertCount(1, array_filter($links, fn ($l) => str_contains($l['url'], 'new-campus')), 'a repeated link is listed once');
        $this->assertCount(2, SourceReader::listingLinks($html, 'https://board.example/', 2));
    }

    public function test_relative_addresses_resolve_and_odd_schemes_do_not(): void
    {
        $this->assertSame('https://x.example/a/b.html', SourceReader::absolute('https://x.example/a/index.html', 'b.html'));
        $this->assertSame('https://x.example/z', SourceReader::absolute('https://x.example/a/', '/z'));
        $this->assertSame('https://y.example/q', SourceReader::absolute('https://x.example/', '//y.example/q'));
        $this->assertNull(SourceReader::absolute('https://x.example/', 'mailto:a@b.c'));
        $this->assertNull(SourceReader::absolute('https://x.example/', 'javascript:void(0)'));
        $this->assertTrue(SourceReader::sameSite('news.site.example', 'site.example'));
        $this->assertFalse(SourceReader::sameSite('notsite.example', 'site.example'));
    }

    // ── verification store + registry (real classes, temp file) ────────────────────

    public function test_a_source_is_verified_only_because_a_fetch_result_was_recorded(): void
    {
        $cfg = ['candidates' => [['Board', 'https://board.example/', 'gujarat', 'board', true, 'core', 'circular', 'official_page'], ['Blocked', 'https://blocked.example/', 'gujarat', 'board', true, 'core', 'circular', 'official_page']]];
        $store = new SourceVerificationStore($this->file);
        $registry = fn () => new SourceRegistry($cfg, $store);
        $id = $registry()->all()[0]['id'];
        $blockedId = $registry()->all()[1]['id'];

        $this->assertSame([], $registry()->operational(), 'listing a source verifies nothing');
        $store->record($id, ['verified_at' => '2026-10-26', 'http_status' => 200, 'machine_readable' => true, 'method' => 'feed', 'feed_url' => 'https://board.example/rss']);
        $store->record($blockedId, ['verified_at' => '2026-10-26', 'http_status' => null, 'machine_readable' => false, 'method' => 'none', 'failure' => 'robots_disallowed']);

        $this->assertSame([$id], array_column($registry()->operational(), 'id'));
        $byId = array_column($registry()->all(), null, 'id');
        $this->assertSame('verified_operational', $byId[$id]['status']);
        $this->assertSame('verified_not_machine_readable', $byId[$blockedId]['status']);
        $this->assertSame('2026-10-26', $byId[$id]['last_verified_at']);
        $this->assertSame(['verified_operational' => 1, 'verified_not_machine_readable' => 1], array_intersect_key($registry()->counts(), array_flip(['verified_operational', 'verified_not_machine_readable'])));
        $this->assertSame([], (new SourceRegistry($cfg))->operational(), 'a registry built without the store is unaffected');
    }

    public function test_seen_pages_are_remembered_per_source_and_bounded(): void
    {
        $store = new SourceVerificationStore($this->file);
        $store->markSeen('src-a', ['https://x.example/p1', 'https://www.x.example/p2/'], '2026-10-26');

        $this->assertTrue($store->hasSeen('src-a', 'https://x.example/p1'));
        $this->assertTrue($store->hasSeen('src-a', 'https://X.example/p2'), 'host case, www. and a trailing slash do not matter');
        $this->assertFalse($store->hasSeen('src-b', 'https://x.example/p1'), 'per source');
        $this->assertFalse($store->hasSeen('src-a', 'https://x.example/p1?x=1'), 'a different query is a different page');
        $store->markSeen('src-a', array_map(fn ($i) => "https://x.example/n{$i}", range(1, 450)), '2026-10-27');
        $data = json_decode((string) file_get_contents($this->file), true);
        $this->assertCount(400, $data['seen']['src-a'], 'the memory is capped');
        $this->assertStringNotContainsString('https://', (string) file_get_contents($this->file), 'only hashes of page addresses are kept');
    }

    // ── direct reading (real DirectSourceReader, fake fetcher) ─────────────────────

    private function fetcher(): object
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
                $page = fn (string $body, string $type = 'text/html') => ['url' => $url, 'status' => 200, 'content_type' => $type, 'body' => $body, 'truncated' => false, 'last_modified' => null];
                $article = fn (string $t) => $page('<html><head><title>' . $t . '</title></head><body><p>' . str_repeat('The school trust announced a new campus with admissions open and a new school ERP. ', 6) . '</p></body></html>');
                if (str_ends_with($url, '/robots.txt')) {
                    return $page(str_contains($url, 'closed.example') ? "User-agent: *\nDisallow: /" : "User-agent: *\nAllow: /", 'text/plain');
                }

                return match (true) {
                    $url === 'https://news.example/rss' => $page(DirectSourcesTest::RSS_FOR_READER, 'application/rss+xml'),
                    $url === 'https://board.example/' => $page('<html><body><a href="/circular/45.pdf">Circular 45</a><a href="/news/campus-one-announced-today">New campus announced for a school in Anand</a><a href="/news/second-school-story-here">A second story about school admissions</a></body></html>'),
                    str_ends_with($url, '/circular/45.pdf') => $page('%PDF-1.4 fake', 'application/pdf'),
                    str_contains($url, 'broken.example') => throw new UnsafeUrlException('The page returned HTTP 503.', 'http_status', 503),
                    default => $article('Story at ' . $url),
                };
            }
        };
    }

    public const RSS_FOR_READER = '<?xml version="1.0"?><rss version="2.0"><channel>'
        . '<item><title>New campus opens for a school group</title><link>https://news.example/new</link><pubDate>Fri, 24 Oct 2026 08:00:00 +0000</pubDate></item>'
        . '<item><title>School ERP tender floated</title><link>https://news.example/tender</link><pubDate>Thu, 23 Oct 2026 08:00:00 +0000</pubDate></item>'
        . '<item><title>Very old school story</title><link>https://news.example/old</link><pubDate>Mon, 01 Sep 2025 10:00:00 +0000</pubDate></item>'
        . '<item><title>Already read story</title><link>https://news.example/seen</link><pubDate>Wed, 22 Oct 2026 08:00:00 +0000</pubDate></item></channel></rss>';

    private function reader(object $fetcher): DirectSourceReader
    {
        $docs = new class extends DocumentExtractor {
            public function extract(string $bytes, string $extension): array
            {
                return ['segments' => [['ref' => 'Page 1', 'text' => str_repeat('Circular: the board invites expression of interest for a school management system. ', 4)]], 'truncated' => false];
            }
        };

        return new DirectSourceReader($fetcher, new RobotsPolicy($fetcher), $docs);
    }

    private function row(string $url, ?string $feed = null): array
    {
        return ['id' => 'src-test', 'name' => 'Test source', 'url' => $url, 'verification' => ['method' => $feed ? 'feed' : 'listing', 'feed_url' => $feed]];
    }

    public function test_a_feed_source_gives_its_newest_fresh_unseen_items_as_ready_to_use_sources(): void
    {
        $f = $this->fetcher();
        $out = $this->reader($f)->read($this->row('https://news.example/', 'https://news.example/rss'), 5, Carbon::parse('2026-09-01'), fn ($u) => str_ends_with($u, '/seen'), fn () => false, Carbon::parse('2026-10-26'));

        $this->assertSame(['https://news.example/new', 'https://news.example/tender'], array_column($out['items'], 'url'), 'newest first; the 2025 item is outside the window; the seen one is skipped');
        $item = $out['items'][0];
        $this->assertTrue($item['page_fetched']);
        $this->assertSame('2026-10-24', $item['published_at'], 'the feed\'s own date');
        $this->assertSame('source_feed', $item['date_provenance']);
        $this->assertSame('direct', $item['origin']);
        $this->assertSame('src-test', $item['registry_id']);
        $this->assertLessThanOrEqual(6000, mb_strlen($item['text']));
        $this->assertSame(['method' => 'feed', 'listing_read' => true, 'candidates' => 4, 'read' => 2, 'skipped_seen' => 1], array_intersect_key($out['report'], array_flip(['method', 'listing_read', 'candidates', 'read', 'skipped_seen'])));
    }

    public function test_a_listing_source_reads_html_and_pdf_items_and_respects_the_item_limit(): void
    {
        $f = $this->fetcher();
        $all = $this->reader($f)->read($this->row('https://board.example/'), 5, null, fn () => false, fn () => false, Carbon::parse('2026-10-26'));
        $this->assertCount(3, $all['items']);
        $pdf = array_values(array_filter($all['items'], fn ($i) => str_ends_with($i['url'], '.pdf')))[0];
        $this->assertStringContainsString('expression of interest', $pdf['text'], 'PDF text is read through the document extractor');

        $one = $this->reader($this->fetcher())->read($this->row('https://board.example/'), 1, null, fn () => false, fn () => false);
        $this->assertCount(1, $one['items']);
    }

    public function test_robots_refusals_failures_and_a_stop_signal_are_respected_and_counted(): void
    {
        $f = $this->fetcher();
        $closed = $this->reader($f)->read($this->row('https://closed.example/'), 3, null, fn () => false, fn () => false);
        $this->assertSame([], $closed['items']);
        $this->assertSame(1, $closed['report']['refused_robots']);
        $this->assertNotContains('https://closed.example/', array_diff($f->asked, ['https://closed.example/robots.txt']), 'the page itself was never requested');

        $broken = $this->reader($this->fetcher())->read($this->row('https://broken.example/'), 3, null, fn () => false, fn () => false);
        $this->assertSame([], $broken['items']);
        $this->assertSame(['http_5xx' => 1], $broken['report']['failed']);
        $this->assertFalse($broken['report']['listing_read']);

        $n = 0;
        $stopped = $this->reader($this->fetcher())->read($this->row('https://board.example/'), 5, null, fn () => false, function () use (&$n) {
            return ++$n > 1;
        });
        $this->assertCount(1, $stopped['items'], 'the run stopped the reader after the first item');
    }

    public function test_a_request_ceiling_stops_reading_and_is_reported_as_a_budget_failure(): void
    {
        $real = new SafeUrlFetcher(fn () => ['93.184.216.34']);
        RunBudget::arm([RunBudget::PAGE => 0]);
        $out = (new DirectSourceReader($real, new RobotsPolicy($real), new DocumentExtractor()))->read($this->row('https://board.example/'), 3, null, fn () => false, fn () => false);

        $this->assertSame([], $out['items']);
        $this->assertSame(['budget' => 1], $out['report']['failed']);
    }

    // ── accounting and wiring ──────────────────────────────────────────────────────

    public function test_the_run_reports_direct_reading_apart_from_search_and_null_when_it_did_not_happen(): void
    {
        $by = [['id' => 'src-a', 'name' => 'A', 'method' => 'feed', 'found' => 4, 'read' => 3, 'failed' => 1]];
        $diag = ['direct' => ['sources_selected' => 3, 'listings_read' => 2, 'items_found' => 9, 'items_read' => 5, 'items_skipped_seen' => 2, 'refused_robots' => 1, 'failed' => ['http_403' => 1], 'by_source' => $by]];
        $s = RunAccounting::build($diag, [], ['sources' => 5, 'candidates' => 0]);

        $this->assertSame(['sources_selected' => 3, 'listings_read' => 2, 'items_found' => 9, 'items_read' => 5, 'items_skipped_seen' => 2, 'refused_robots' => 1, 'failed_by_category' => ['http_403' => 1], 'by_source' => $by], $s['direct']);
        $this->assertNull(RunAccounting::build([], [], ['sources' => 0, 'candidates' => 0])['direct']);
    }

    public function test_source_text_researcher_reads_only_verified_sources_and_marks_pages_seen_after_the_model_read_them(): void
    {
        $r = $this->source('app/Domain/Signals/Opportunities/OpportunityResearcher.php');

        $this->assertStringContainsString('private function directSources(', $r);
        $this->assertStringContainsString('if ($company === null && $custom === null) {', $r);
        $this->assertLessThan(strpos($r, '$this->officialReport = [];'), strpos($r, '$direct = $this->directSources($recency);'));
        $this->assertLessThan(strpos($r, '$this->validate((array) ($result'), strpos($r, '$this->commitDirectSeen();'), 'seen is recorded only after the AI call returned');
        $this->assertGreaterThan(strpos($r, '$result = $this->ai->json($tenantId, $this->messages('), strpos($r, '$this->commitDirectSeen();'));
        $this->assertStringContainsString("fn (\$s) => isset(\$verification[\$s['id']])", $r, 'a source without a recorded verification is not read');
        $cfg = $this->source('config/signals.php');
        $this->assertStringContainsString("'direct_sources_per_run' => (int) env('SIGNALS_DIRECT_SOURCES_PER_RUN', 6)", $cfg);
        $this->assertStringContainsString("'direct_items_per_source' => (int) env('SIGNALS_DIRECT_ITEMS_PER_SOURCE', 3)", $cfg);
    }

    public function test_source_text_the_verify_command_makes_one_page_request_and_never_touches_a_database(): void
    {
        $c = $this->source('app/Console/Commands/SignalsVerifySources.php');

        $this->assertStringNotContainsString('DB::', $c);
        $this->assertStringNotContainsString('Schema::', $c);
        $this->assertStringContainsString('robots->allows($s[\'url\'])', $c);
        $this->assertStringContainsString('usleep(', $c, 'a pause between sources');
        $this->assertSame(1, substr_count($c, '$fetcher->fetch($s[\'url\']'), 'one request for the configured page');
        $this->assertStringContainsString('$links >= 3', $c, 'a listing counts as readable only with at least three announcement links');
    }

    // ── which sources a run reads (real DirectSourcePicker + store) ────────────────

    private function rows(): array
    {
        $mk = fn (string $id, string $region) => ['id' => $id, 'region' => $region, 'classification' => 'official', 'signal_types' => ['circular'], 'products' => ['core']];

        return [$mk('src-a', 'international'), $mk('src-b', 'gujarat'), $mk('src-c', 'india'), $mk('src-d', 'india')];
    }

    public function test_the_least_recently_read_sources_go_first_and_never_read_ones_before_all(): void
    {
        $pick = fn (array $stats, int $n = 2) => array_column(\App\Domain\Signals\Sources\DirectSourcePicker::pick($this->rows(), $stats, $n, '2026-10-27'), 'id');

        $this->assertSame(['src-b', 'src-c'], $pick([]), 'nothing read yet: strongest first (Gujarat, then India)');
        $this->assertSame(['src-d', 'src-a'], $pick(['src-b' => ['last_attempt' => '2026-10-27'], 'src-c' => ['last_attempt' => '2026-10-27']]), 'the two just read wait; unread ones go first');
        $this->assertSame(['src-c'], $pick(['src-a' => ['last_attempt' => '2026-10-27'], 'src-b' => ['last_attempt' => '2026-10-27'], 'src-d' => ['last_attempt' => '2026-10-27'], 'src-c' => ['last_attempt' => '2026-10-20']], 1), 'oldest attempt first');
        $this->assertSame([], \App\Domain\Signals\Sources\DirectSourcePicker::pick($this->rows(), [], 0, '2026-10-27'));
    }

    public function test_a_source_that_read_nothing_twice_rests_for_a_week_then_returns(): void
    {
        $stats = ['src-b' => ['last_attempt' => '2026-10-25', 'fail_streak' => 2]];
        $ids = fn (string $today) => array_column(\App\Domain\Signals\Sources\DirectSourcePicker::pick($this->rows(), $stats, 4, $today), 'id');

        $this->assertNotContains('src-b', $ids('2026-10-27'), 'backed off');
        $this->assertNotContains('src-b', $ids('2026-10-31'));
        $this->assertContains('src-b', $ids('2026-11-02'), 'tried again after seven days');
        $this->assertContains('src-b', array_column(\App\Domain\Signals\Sources\DirectSourcePicker::pick($this->rows(), ['src-b' => ['last_attempt' => '2026-10-26', 'fail_streak' => 1]], 4, '2026-10-27'), 'id'), 'one miss is not enough');
    }

    public function test_the_store_counts_consecutive_attempts_that_read_nothing(): void
    {
        $store = new SourceVerificationStore($this->file);
        $store->touch('src-x', '2026-10-25', 0);
        $store->touch('src-x', '2026-10-26', 0);
        $this->assertSame(['last_attempt' => '2026-10-26', 'fail_streak' => 2], $store->readStats()['src-x']);
        $store->touch('src-x', '2026-10-27', 3);
        $this->assertSame(0, $store->readStats()['src-x']['fail_streak'], 'reading an item ends the streak');
        $this->assertSame([], (new SourceVerificationStore(sys_get_temp_dir() . '/g2g_none_' . bin2hex(random_bytes(3)) . '.json'))->readStats());
    }

    public function test_news_listings_keep_only_educational_headlines_while_official_ones_also_keep_notices(): void
    {
        $html = '<html><body>'
            . '<a href="/surat/saroli-police-issues-notice-to-navratri-organisers">Saroli police issues notice to JG Navratri organisers in the city</a>'
            . '<a href="/world/smart-dude-travis-kelce-great-things">Smart dude that has done a lot of great things says the coach</a>'
            . '<a href="/surat/parents-protest-school-shift-change">Parents protest the shift change at a Piplod school in Surat</a>'
            . '<a href="/circular/2026-45">Circular number 45 of 2026 for all concerned offices</a></body></html>';

        $news = array_column(SourceReader::listingLinks($html, 'https://paper.example/', 10, true), 'url');
        $official = array_column(SourceReader::listingLinks($html, 'https://board.example/', 10, false), 'url');

        $this->assertSame(['https://paper.example/surat/parents-protest-school-shift-change'], $news, 'a news page keeps only what names a school topic');
        $this->assertContains('https://board.example/circular/2026-45', $official, 'an official page keeps a numbered circular');
        $this->assertNotContains('https://board.example/world/smart-dude-travis-kelce-great-things', $official, '"smart" alone no longer qualifies a link');
    }
}
