<?php

namespace Tests\Feature;

use App\Domain\Signals\Scholar\OfficialSiteDiscovery;
use App\Domain\Signals\Support\SafeUrlFetcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Reading a verified school website: bounded, same host, robots-respecting, dated from the page, honest about failures. Mocked HTTP. */
class OfficialSiteDiscoveryTest extends TestCase
{
    private const SITE = 'https://www.nirmaschool.example';

    private function account(array $verification = ['verdict' => 'verified', 'final_url' => self::SITE, 'scope' => 'campus']): array
    {
        return ['name' => 'Nirma Vidya Vihar', 'crm_ref' => 'LEA35', 'attributes' => ['website_verification' => $verification]];
    }

    private function discovery(): OfficialSiteDiscovery
    {
        return new OfficialSiteDiscovery(new SafeUrlFetcher(fn () => ['93.184.216.34']));
    }

    private function fake(array $pages, string $robots = ''): void
    {
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
        // A fresh response per request: one Http::response() object is a single stream that is empty after its first read.
        $map = ['*/robots.txt' => fn () => Http::response($robots, $robots === '' ? 404 : 200)];
        foreach ($pages as $url => $body) {
            $map[$url] = fn () => is_int($body) ? Http::response('error', $body) : Http::response($body, 200, ['Content-Type' => 'text/html']);
        }
        Http::fake($map + ['*' => Http::response('not found', 404)]);
    }

    private const HOME = '<html><head><title>Nirma Vidya Vihar</title></head><body>
        <a href="/news">News</a> <a href="/careers">Careers</a> <a href="/tenders/it-2026">Tenders</a> <a href="/about">About us</a>
        <a href="/login">Parent login</a> <a href="/brochure.pdf">Brochure</a> <a href="https://facebook.com/nirma">Facebook</a>
        <a href="https://other.example/news">Other site news</a> <a href="/news?utm_source=x">News again</a> <a href="#top">Top</a>
        <a href="/digital-initiatives">Digital initiatives</a> <a href="/admin/panel">Admin</a> <a href="mailto:a@b.c">Mail</a>
        </body></html>';

    public function test_only_a_verified_website_is_ever_used(): void
    {
        $this->assertNotNull(OfficialSiteDiscovery::verifiedSite($this->account()));
        $this->assertNotNull(OfficialSiteDiscovery::verifiedSite($this->account(['verdict' => 'verified_tls_warning', 'final_url' => self::SITE, 'scope' => 'group'])));
        foreach (['invalid_domain', 'resolves_unconfirmed', 'blocked', 'temporary_failure', 'tls_error', 'no_website_given'] as $v) {
            $this->assertNull(OfficialSiteDiscovery::verifiedSite($this->account(['verdict' => $v, 'final_url' => self::SITE])), $v);
        }
        $this->assertNull(OfficialSiteDiscovery::verifiedSite(['attributes' => null]));
        $this->assertNull(OfficialSiteDiscovery::verifiedSite(['attributes' => ['website' => self::SITE]]), 'a bare URL is not a verification');

        $this->fake([]);
        $r = $this->discovery()->discover(6, ['name' => 'X School', 'attributes' => null], Carbon::now());
        $this->assertSame([], $r['sources']);
        $this->assertStringContainsString('No verified official website', $r['report']['limitation']);
        Http::assertNothingSent();
    }

    public function test_it_reads_the_home_page_and_the_best_internal_pages_and_nothing_else(): void
    {
        $this->fake([
            self::SITE => self::HOME,
            self::SITE . '/news' => '<html><title>News</title><body>School news</body></html>',
            self::SITE . '/careers' => '<html><title>Careers</title><body>We are hiring</body></html>',
            self::SITE . '/tenders/it-2026' => '<html><title>Tender</title><body>Quotation for IT</body></html>',
            self::SITE . '/digital-initiatives' => '<html><title>Digital</title><body>Smart classrooms</body></html>',
        ]);

        $r = $this->discovery()->discover(6, $this->account(), Carbon::now());

        $urls = array_column($r['sources'], 'url');
        $this->assertContains(self::SITE, $urls);
        foreach (['/news', '/careers', '/tenders/it-2026', '/digital-initiatives'] as $p) {
            $this->assertContains(self::SITE . $p, $urls, $p);
        }
        $fetched = collect(Http::recorded())->map(fn ($pair) => $pair[0]->url())->all();
        foreach (['/login', '/brochure.pdf', '/admin/panel', 'facebook.com', 'other.example', 'mailto:', '/about'] as $never) {
            $this->assertEmpty(array_filter($fetched, fn ($u) => str_contains($u, $never)), "never fetched: {$never}");
        }
        $this->assertCount(1, array_filter($fetched, fn ($u) => str_contains($u, '/news')), 'a page is fetched once, tracking parameters or not');
        foreach ($r['sources'] as $s) {
            $this->assertTrue($s['official']);
            $this->assertTrue($s['page_fetched']);
            $this->assertNotNull($s['retrieved_at']);
        }
    }

    public function test_the_page_cap_is_respected(): void
    {
        config(['signals.official_site.max_pages' => 2]);
        $this->fake([self::SITE => self::HOME, self::SITE . '/news' => '<title>n</title>', self::SITE . '/careers' => '<title>c</title>', self::SITE . '/tenders/it-2026' => '<title>t</title>', self::SITE . '/digital-initiatives' => '<title>d</title>']);

        $r = $this->discovery()->discover(6, $this->account(), Carbon::now());

        $this->assertCount(2, $r['sources'], 'home plus one');
    }

    public function test_robots_txt_is_honoured_per_page(): void
    {
        $this->fake([self::SITE => self::HOME, self::SITE . '/news' => '<title>n</title>', self::SITE . '/careers' => '<title>c</title>', self::SITE . '/tenders/it-2026' => '<title>t</title>', self::SITE . '/digital-initiatives' => '<title>d</title>'], "User-agent: *\nDisallow: /careers");

        $r = $this->discovery()->discover(6, $this->account(), Carbon::now());

        $this->assertNotContains(self::SITE . '/careers', array_column($r['sources'], 'url'));
        $this->assertContains('blocked_by_robots_txt', array_column($r['report']['pages'], 'outcome'));
    }

    public function test_an_unreadable_home_page_is_recorded_as_a_limitation_not_invented(): void
    {
        $this->fake([self::SITE => 503]);
        $r = $this->discovery()->discover(6, $this->account(), Carbon::now());
        $this->assertSame([], $r['sources']);
        $this->assertSame(0, $r['report']['fetched']);
        $this->assertSame(1, $r['report']['failed']);
        $this->assertStringContainsString('could not be read', $r['report']['limitation']);
    }

    public function test_one_failing_internal_page_does_not_lose_the_others(): void
    {
        $this->fake([self::SITE => self::HOME, self::SITE . '/news' => 500, self::SITE . '/careers' => '<title>c</title>', self::SITE . '/tenders/it-2026' => '<title>t</title>', self::SITE . '/digital-initiatives' => '<title>d</title>']);
        $r = $this->discovery()->discover(6, $this->account(), Carbon::now());
        $this->assertContains(self::SITE . '/careers', array_column($r['sources'], 'url'));
        $this->assertSame(1, $r['report']['failed']);
        $this->assertNull($r['report']['limitation']);
    }

    public function test_dates_come_from_the_page_and_an_undated_page_stays_undated(): void
    {
        $dated = '<html><head><title>Admissions open</title><script type="application/ld+json">{"@type":"NewsArticle","datePublished":"2026-09-12T08:00:00+05:30"}</script></head><body>News</body></html>';
        $this->fake([self::SITE => self::HOME, self::SITE . '/news' => $dated, self::SITE . '/careers' => '<title>c</title><body>no date here</body>', self::SITE . '/tenders/it-2026' => '<title>t</title>', self::SITE . '/digital-initiatives' => '<title>d</title>']);

        $r = $this->discovery()->discover(6, $this->account(), Carbon::parse('2026-10-14'));

        $by = collect($r['sources'])->keyBy('url');
        $this->assertSame('2026-09-12', $by[self::SITE . '/news']['published_at']);
        $this->assertSame('structured_data', $by[self::SITE . '/news']['date_provenance']);
        $this->assertNull($by[self::SITE . '/careers']['published_at'], 'no date on the page: unknown, never the fetch day');
        $this->assertSame('2026-10-14', substr($by[self::SITE . '/careers']['retrieved_at'], 0, 10), 'the fetch time is kept separately');
    }

    public function test_a_page_read_recently_is_not_read_again(): void
    {
        config(['signals.official_site.recheck_hours' => 12]);
        Cache::flush();
        $this->fake([self::SITE => self::HOME, self::SITE . '/news' => '<title>n</title>', self::SITE . '/careers' => '<title>c</title>', self::SITE . '/tenders/it-2026' => '<title>t</title>', self::SITE . '/digital-initiatives' => '<title>d</title>']);

        $first = $this->discovery()->discover(6, $this->account(), Carbon::now());
        $second = $this->discovery()->discover(6, $this->account(), Carbon::now());

        $this->assertNotEmpty($first['sources']);
        $this->assertSame([], $second['sources']);
        $this->assertContains('fetched_recently', array_column($second['report']['pages'], 'outcome'));
    }

    public function test_a_group_site_with_an_unverifiable_certificate_is_read_only_when_identity_was_verified(): void
    {
        $f = new class(fn () => ['93.184.216.34']) extends SafeUrlFetcher {
            public array $verify = [];

            public function fetch(string $url, ?int $maxBytes = null, ?int $timeout = null, bool $verifyTls = true): array
            {
                $this->verify[] = $verifyTls;

                return ['url' => $url, 'status' => 200, 'content_type' => 'text/html', 'body' => str_ends_with($url, 'robots.txt') ? '' : '<title>Home</title>', 'truncated' => false];
            }
        };
        $acc = $this->account(['verdict' => 'verified_tls_warning', 'final_url' => self::SITE, 'scope' => 'group']);
        $r = (new OfficialSiteDiscovery($f))->discover(6, $acc, Carbon::now());

        $this->assertSame([false], array_values(array_unique($f->verify)), 'certificate check skipped only because identity was verified by other evidence');
        $this->assertFalse($r['sources'][0]['tls_verified'], 'and the source says so');
        $this->assertSame('group', $r['sources'][0]['official_scope']);
    }

    private function sitemap(array $entries): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($entries as $loc => $lastmod) {
            $x .= '<url><loc>' . $loc . '</loc>' . ($lastmod ? '<lastmod>' . $lastmod . '</lastmod>' : '') . '</url>';
        }

        return $x . '</urlset>';
    }

    public function test_a_sitemap_adds_pages_and_its_lastmod_is_stored_as_a_modification_date_never_a_publication_date(): void
    {
        $this->fake([
            self::SITE => '<html><title>Home</title><body>No links here</body></html>',
            self::SITE . '/sitemap.xml' => $this->sitemap([
                self::SITE . '/news/new-campus' => '2026-09-30', self::SITE . '/about' => '2026-01-01', self::SITE . '/login' => '2026-10-01',
                'https://elsewhere.example/news' => '2026-10-01', self::SITE . '/tenders/erp' => '2027-12-31',
            ]),
            self::SITE . '/news/new-campus' => '<html><title>New campus</title><body>We are opening a campus.</body></html>',
            self::SITE . '/tenders/erp' => '<html><title>Tender</title><body>Quotation</body></html>',
        ]);

        $r = $this->discovery()->discover(6, $this->account(), Carbon::parse('2026-10-14'));

        $by = collect($r['sources'])->keyBy('url');
        $this->assertArrayHasKey(self::SITE . '/news/new-campus', $by->all(), 'a page found only through the sitemap is read');
        $this->assertSame('2026-09-30', $by[self::SITE . '/news/new-campus']['modified_at']);
        $this->assertSame('sitemap_lastmod', $by[self::SITE . '/news/new-campus']['modified_provenance']);
        $this->assertNull($by[self::SITE . '/news/new-campus']['published_at'], 'a sitemap lastmod is NOT a publication date');
        $this->assertNull($by[self::SITE . '/tenders/erp']['modified_at'], 'a lastmod in the future is ignored');
        $fetched = collect(Http::recorded())->map(fn ($p) => $p[0]->url())->all();
        foreach (['/login', '/about', 'elsewhere.example'] as $never) {
            $this->assertEmpty(array_filter($fetched, fn ($u) => str_contains($u, $never)), "never fetched: {$never}");
        }
        $this->assertContains('sitemap_read', array_column($r['report']['pages'], 'outcome'));
    }

    public function test_a_page_with_its_own_publication_date_keeps_it_apart_from_the_sitemap_lastmod(): void
    {
        $post = '<html><head><title>Post</title><script type="application/ld+json">{"@type":"NewsArticle","datePublished":"2026-03-01","dateModified":"2026-10-01"}</script></head><body>x</body></html>';
        $this->fake([
            self::SITE => '<html><title>Home</title><body><a href="/news/post">Post</a></body></html>',
            self::SITE . '/sitemap.xml' => $this->sitemap([self::SITE . '/news/post' => '2026-10-10']),
            self::SITE . '/news/post' => $post,
        ]);

        $by = collect($this->discovery()->discover(6, $this->account(), Carbon::parse('2026-10-14'))['sources'])->keyBy('url');

        $this->assertSame('2026-03-01', $by[self::SITE . '/news/post']['published_at']);
        $this->assertSame('2026-10-01', $by[self::SITE . '/news/post']['modified_at'], 'the page\'s own dateModified wins over the sitemap');
        $this->assertSame('structured_data_modified', $by[self::SITE . '/news/post']['modified_provenance']);
    }

    public function test_a_sitemap_index_malformed_xml_robots_and_a_missing_sitemap_are_all_handled_quietly(): void
    {
        $index = '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>' . self::SITE . '/sm-news.xml</loc></sitemap><sitemap><loc>https://elsewhere.example/sm.xml</loc></sitemap></sitemapindex>';
        $this->fake([
            self::SITE => '<html><title>Home</title></html>',
            self::SITE . '/sitemap.xml' => $index,
            self::SITE . '/sm-news.xml' => $this->sitemap([self::SITE . '/news/a' => '2026-10-01']),
            self::SITE . '/news/a' => '<html><title>A</title></html>',
        ]);
        $r = $this->discovery()->discover(6, $this->account(), Carbon::parse('2026-10-14'));
        $this->assertContains(self::SITE . '/news/a', array_column($r['sources'], 'url'), 'a child sitemap of the same host is followed');
        $this->assertEmpty(array_filter(collect(Http::recorded())->map(fn ($p) => $p[0]->url())->all(), fn ($u) => str_contains($u, 'elsewhere.example')), 'a foreign sitemap is never fetched');

        $this->fake([self::SITE => '<html><title>Home</title></html>', self::SITE . '/sitemap.xml' => '<urlset><url><loc>broken']);
        $bad = $this->discovery()->discover(6, $this->account(), Carbon::parse('2026-10-14'));
        $this->assertCount(1, $bad['sources']);
        $this->assertContains('sitemap_unreadable', array_column($bad['report']['pages'], 'outcome'));

        $this->fake([self::SITE => '<html><title>Home</title></html>'], "User-agent: *\nDisallow: /sitemap.xml");
        $blocked = $this->discovery()->discover(6, $this->account(), Carbon::parse('2026-10-14'));
        $this->assertContains('sitemap_blocked_by_robots_txt', array_column($blocked['report']['pages'], 'outcome'));

        $this->fake([self::SITE => '<html><title>Home</title></html>']);   // no sitemap at all
        $none = $this->discovery()->discover(6, $this->account(), Carbon::parse('2026-10-14'));
        $this->assertCount(1, $none['sources']);
        $this->assertContains('sitemap_unavailable', array_column($none['report']['pages'], 'outcome'));
        $this->assertNull($none['report']['limitation'], 'no sitemap is not a failure');
    }
}
