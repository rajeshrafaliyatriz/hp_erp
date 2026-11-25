<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Sources\Adapters\FeedReader;
use App\Domain\Signals\Sources\Adapters\GoogleCseAdapter;
use App\Domain\Signals\Sources\Adapters\MarketItem;
use App\Domain\Signals\Sources\Adapters\MarketSourceHub;
use App\Domain\Signals\Sources\Adapters\PlatformPolicy;
use App\Domain\Signals\Sources\Adapters\RedditSearchAdapter;
use App\Domain\Signals\Sources\Adapters\YouTubeSearchAdapter;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

class MarketAdaptersTest extends TestCase
{
    private const KEY = 'SECRET-KEY-abc123XYZ';

    // ---- PlatformPolicy ----

    public function test_social_hosts_are_lead_hint_only_with_subdomains(): void
    {
        $p = new PlatformPolicy();
        foreach (['linkedin.com', 'www.linkedin.com', 'in.linkedin.com', 'm.facebook.com', 'www.instagram.com', 'twitter.com', 'x.com', 'www.tiktok.com'] as $h) {
            $r = $p->forHost($h);
            $this->assertSame('lead_hint_only', $r['mode'], $h);
            $this->assertFalse($r['may_fetch'], $h);
            $this->assertFalse($r['may_use_as_evidence'], $h);
        }
        $this->assertSame('x', $p->forHost('twitter.com')['platform']);
    }

    public function test_api_feed_and_web_modes(): void
    {
        $p = new PlatformPolicy();
        $this->assertSame('official_api', $p->forHost('www.youtube.com')['mode']);
        $this->assertSame('official_api', $p->forHost('old.reddit.com')['mode']);
        $this->assertFalse($p->forHost('youtube.com')['may_fetch']);
        $this->assertSame('web_page', $p->forHost('example.gov.in')['mode']);
        $this->assertTrue($p->forHost('example.gov.in')['may_fetch']);
        $this->assertSame('public_feed', $p->forFeed('https://tenders.example.gov.in/rss.xml')['mode']);
        $this->assertSame('lead_hint_only', $p->forFeed('https://www.linkedin.com/feed.rss')['mode']);
        $this->assertSame('web_page', $p->forHost('notlinkedin.com')['mode']);
    }

    // ---- FeedReader ----

    public function test_rss_parse(): void
    {
        $xml = '<?xml version="1.0"?><rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><title>T</title>'
            . '<item><title>Tender &amp; RFP</title><link>https://a.example/1</link><description><![CDATA[<p>Hello <b>world</b></p>]]></description><pubDate>Tue, 10 Jun 2025 08:00:00 GMT</pubDate><dc:creator>Ann</dc:creator></item>'
            . '<item><title>No link</title></item></channel></rss>';
        $items = (new FeedReader())->parse($xml, 'src');
        $this->assertCount(1, $items);
        $this->assertSame('Tender & RFP', $items[0]->title);
        $this->assertSame('Hello world', $items[0]->excerpt);
        $this->assertSame('2025-06-10', $items[0]->publishedAt);
        $this->assertSame('Ann', $items[0]->author);
        $this->assertSame('src', $items[0]->origin);
    }

    public function test_atom_parse(): void
    {
        $xml = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><entry><title>Entry</title><link rel="self" href="https://x.example/self"/><link rel="alternate" href="https://x.example/e1"/>'
            . '<updated>2025-03-04T10:00:00Z</updated><summary>Sum &lt;i&gt;mary&lt;/i&gt;</summary><author><name>Bob</name></author></entry></feed>';
        $items = (new FeedReader())->parse($xml);
        $this->assertCount(1, $items);
        $this->assertSame('https://x.example/e1', $items[0]->url);
        $this->assertSame('2025-03-04', $items[0]->publishedAt);
        $this->assertSame('Bob', $items[0]->author);
        $this->assertStringNotContainsString('<', $items[0]->excerpt);
    }

    public function test_malformed_xxe_and_huge_input_return_empty(): void
    {
        $r = new FeedReader();
        $this->assertSame([], $r->parse('<rss><channel><item><title>x</title>'));
        $this->assertSame([], $r->parse('not xml at all'));
        $xxe = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss><channel><item><title>&x;</title><link>https://a.example/1</link></item></channel></rss>';
        $this->assertSame([], $r->parse($xxe));
        $this->assertSame([], (new FeedReader(50, 100))->parse(str_repeat('<rss>', 100)));
    }

    public function test_caps_items_and_field_length(): void
    {
        $x = '<rss><channel>';
        for ($i = 0; $i < 80; $i++) {
            $x .= '<item><title>' . str_repeat('t', 1000) . '</title><link>https://a.example/' . $i . '</link></item>';
        }
        $items = (new FeedReader(50))->parse($x . '</channel></rss>');
        $this->assertCount(50, $items);
        $this->assertLessThanOrEqual(300, mb_strlen($items[0]->title));
    }

    // ---- API adapters ----

    private function factory(array $fakes): Factory
    {
        $f = new Factory();
        $f->fake($fakes);

        return $f;
    }

    public function test_youtube_success(): void
    {
        $f = $this->factory(['googleapis.com/*' => Factory::response(['items' => [
            ['id' => ['videoId' => 'abcDEF12345'], 'snippet' => ['title' => 'Tender tips', 'description' => 'desc', 'channelTitle' => 'Chan', 'publishedAt' => '2025-05-01T00:00:00Z']],
            ['id' => [], 'snippet' => ['title' => 'channel result']],
        ]], 200)]);
        $a = new YouTubeSearchAdapter(['enabled' => true, 'api_key' => self::KEY], $f);
        $items = $a->search('tender', 30, 5);
        $this->assertCount(1, $items);
        $this->assertSame('https://www.youtube.com/watch?v=abcDEF12345', $items[0]->url);
        $this->assertSame('Chan', $items[0]->author);
        $this->assertSame('2025-05-01', $items[0]->publishedAt);
        $this->assertNull($a->lastError());
    }

    public function test_not_configured_or_disabled_makes_no_request(): void
    {
        $f = $this->factory(['*' => Factory::response([], 200)]);
        foreach ([new YouTubeSearchAdapter([], $f), new YouTubeSearchAdapter(['enabled' => false, 'api_key' => self::KEY], $f), new RedditSearchAdapter(['enabled' => true], $f), new GoogleCseAdapter(['enabled' => true, 'api_key' => 'k'], $f)] as $a) {
            $this->assertSame([], $a->search('q', 30, 5));
            $this->assertSame('not_configured', $a->lastError());
            $this->assertSame(0, $a->requestsMade());
        }
        $f->assertNothingSent();
    }

    public function test_http_errors_yield_category_only_and_no_key(): void
    {
        foreach ([403 => 'http_403', 429 => 'http_429'] as $status => $cat) {
            $f = $this->factory(['*' => Factory::response(['error' => ['message' => 'bad key ' . self::KEY]], $status)]);
            $a = new GoogleCseAdapter(['enabled' => true, 'api_key' => self::KEY, 'cx' => 'cx1'], $f);
            $this->assertSame([], $a->search('q', 7, 5));
            $this->assertSame($cat, $a->lastError());
            $this->assertStringNotContainsString(self::KEY, (string) $a->lastError());
        }
    }

    public function test_connection_failure_is_swallowed_without_key(): void
    {
        $f = $this->factory(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error for https://x?key=' . self::KEY)]);
        $a = new YouTubeSearchAdapter(['enabled' => true, 'api_key' => self::KEY], $f);
        $this->assertSame([], $a->search('q', 7, 5));
        $this->assertSame('timeout', $a->lastError());
        $this->assertStringNotContainsString(self::KEY, (string) $a->lastError());
    }

    public function test_google_success_and_key_not_in_items(): void
    {
        $f = $this->factory(['*' => Factory::response(['items' => [
            ['title' => 'Bid notice', 'link' => 'https://gov.example/bid', 'snippet' => 'Open bid', 'pagemap' => ['metatags' => [['article:published_time' => '2025-02-02T00:00:00Z']]]],
            ['title' => 'bad', 'link' => 'javascript:alert(1)'],
        ]], 200)]);
        $a = new GoogleCseAdapter(['enabled' => true, 'api_key' => self::KEY, 'cx' => 'cx1'], $f);
        $items = $a->search('bid', 14, 10);
        $this->assertCount(1, $items);
        $this->assertSame('2025-02-02', $items[0]->publishedAt);
        $this->assertStringNotContainsString(self::KEY, json_encode($items[0]->toArray()));
    }

    public function test_reddit_token_then_search_and_request_cap(): void
    {
        $f = $this->factory([
            'www.reddit.com/*' => Factory::response(['access_token' => 'tok-xyz'], 200),
            'oauth.reddit.com/*' => Factory::response(['data' => ['children' => [['data' => ['permalink' => '/r/x/comments/1/t/', 'title' => 'Need vendor', 'selftext' => 'body', 'author' => 'u1', 'created_utc' => 1750000000, 'score' => 5, 'num_comments' => 2]]]]], 200),
        ]);
        $a = new RedditSearchAdapter(['enabled' => true, 'client_id' => 'cid', 'client_secret' => self::KEY, 'max_requests' => 3], $f);
        $items = $a->search('vendor', 30, 5);
        $this->assertCount(1, $items);
        $this->assertSame('https://www.reddit.com/r/x/comments/1/t/', $items[0]->url);
        $this->assertSame(7, $items[0]->engagement);
        $this->assertSame(2, $a->requestsMade());
        // second search needs 2 more requests; cap is 3 so the token call succeeds (3rd) and the search call hits the cap.
        $this->assertSame([], $a->search('vendor', 30, 5));
        $this->assertSame('request_cap', $a->lastError());
        $this->assertSame(3, $a->requestsMade());
        $this->assertStringNotContainsString(self::KEY, json_encode($items[0]->toArray()));
    }

    public function test_default_request_cap_is_five(): void
    {
        $f = $this->factory(['*' => Factory::response(['items' => []], 200)]);
        $a = new YouTubeSearchAdapter(['enabled' => true, 'api_key' => self::KEY], $f);
        for ($i = 0; $i < 8; $i++) {
            $a->search('q', 7, 5);
        }
        $this->assertSame(5, $a->requestsMade());
        $this->assertSame('request_cap', $a->lastError());
    }

    // ---- Hub ----

    public function test_hub_dedupes_flags_lead_only_and_caps(): void
    {
        $adapter = new class {
            public function search(string $q, int $d, int $l): array
            {
                return [
                    new MarketItem('https://www.linkedin.com/posts/abc?utm_source=x', 'Post', '', null, 'google', 'google_cse'),
                    new MarketItem('https://news.example/a', 'A dup of feed', '', null, 'google', 'google_cse'),
                    new MarketItem('https://news.example/b', 'B', '', null, 'google', 'google_cse'),
                ];
            }
        };
        $rss = '<rss><channel><item><title>A</title><link>https://www.news.example/a/</link></item></channel></rss>';
        $hub = new MarketSourceHub([$adapter], new FeedReader(), fn (string $u) => $u === 'https://feed.example/rss' ? $rss : null);
        $items = $hub->gather(['q1', 'q2'], ['https://feed.example/rss', 'https://www.facebook.com/feed.rss']);
        $this->assertCount(3, $items);
        $byUrl = [];
        foreach ($items as $i) {
            $byUrl[$i->url] = $i;
        }
        $this->assertSame('public_feed', $items[0]->policyMode);
        $this->assertFalse($items[0]->leadOnly);
        $this->assertTrue($byUrl['https://www.linkedin.com/posts/abc?utm_source=x']->leadOnly);
        $this->assertSame('lead_hint_only', $byUrl['https://www.linkedin.com/posts/abc?utm_source=x']->policyMode);

        $capped = (new MarketSourceHub([$adapter], new FeedReader(), fn () => null, null, 2))->gather(['q'], []);
        $this->assertCount(2, $capped);
    }

    public function test_hub_never_fetches_social_feed_urls(): void
    {
        $called = [];
        $hub = new MarketSourceHub([], new FeedReader(), function (string $u) use (&$called) {
            $called[] = $u;

            return null;
        });
        $hub->gather([], ['https://twitter.com/x/rss', 'https://ok.example/rss']);
        $this->assertSame(['https://ok.example/rss'], $called);
    }
}
