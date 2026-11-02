<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Support\SourceUrl;
use PHPUnit\Framework\TestCase;

/** The source link of an opportunity added by hand: any website or platform is accepted; anything unsafe or malformed is refused; nothing is fetched. */
class SourceUrlTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> a real-world address => the platform name shown */
    public static function accepted(): array
    {
        return [
            'LinkedIn post' => ['https://www.linkedin.com/posts/acme-plastics_expansion-activity-7123456789012345678-AbCd?utm_source=share&utm_medium=member_desktop', 'LinkedIn'],
            'LinkedIn feed update' => ['https://www.linkedin.com/feed/update/urn:li:activity:7123456789012345678/', 'LinkedIn'],
            'LinkedIn profile' => ['https://in.linkedin.com/in/jane-doe-1a2b3c', 'LinkedIn'],
            'LinkedIn company page' => ['https://www.linkedin.com/company/acme-plastics/', 'LinkedIn'],
            'Facebook post' => ['https://www.facebook.com/AcmePlastics/posts/pfbid02abcDEF123', 'Facebook'],
            'Facebook permalink' => ['https://m.facebook.com/permalink.php?story_fbid=123456&id=987654', 'Facebook'],
            'Facebook page' => ['https://www.facebook.com/AcmePlastics', 'Facebook'],
            'Facebook video short link' => ['https://fb.watch/abc123XYZ/', 'Facebook'],
            'Instagram post' => ['https://www.instagram.com/p/CxYz123AbC/?igsh=MWx4', 'Instagram'],
            'Instagram profile' => ['https://www.instagram.com/acme.plastics/', 'Instagram'],
            'X post' => ['https://x.com/acmeplastics/status/1712345678901234567?s=20', 'X (Twitter)'],
            'Twitter post' => ['https://twitter.com/acmeplastics/status/1712345678901234567', 'X (Twitter)'],
            'X profile' => ['https://x.com/acmeplastics', 'X (Twitter)'],
            'YouTube video' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s', 'YouTube'],
            'YouTube short link' => ['https://youtu.be/dQw4w9WgXcQ?si=abc', 'YouTube'],
            'YouTube channel' => ['https://www.youtube.com/@AcmePlastics', 'YouTube'],
            'company website' => ['https://www.acmeplastics.co.in/about-us/news', 'acmeplastics.co.in'],
            'news article' => ['https://economictimes.indiatimes.com/industry/acme-opens-plant/articleshow/12345.cms', 'economictimes.indiatimes.com'],
            'blog' => ['https://blog.acmeplastics.com/2026/11/new-plant', 'blog.acmeplastics.com'],
            'job portal' => ['https://www.naukri.com/job-listings-erp-manager-acme-plastics-surat-5-to-8-years-021126001234', 'naukri.com'],
            'plain http' => ['http://acmeplastics.com/tender?id=77', 'acmeplastics.com'],
            'uppercase scheme and host' => ['HTTPS://WWW.Example.COM/Path', 'example.com'],
        ];
    }

    /** @dataProvider accepted */
    public function test_a_link_from_any_platform_or_website_is_accepted_and_named(string $url, string $platform): void
    {
        $r = SourceUrl::normalize($url);

        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertStringStartsWith('http', $r['url']);
        $this->assertSame($platform, SourceUrl::platform($r['url']));
    }

    /** @return array<string, array{0: string}> */
    public static function refused(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(1)'],
            'javascript scheme with a host look' => ['javascript://www.linkedin.com/%0Aalert(1)'],
            'data scheme' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'file scheme' => ['file:///etc/passwd'],
            'ftp scheme' => ['ftp://files.example.com/a.pdf'],
            'mailto' => ['mailto:someone@example.com'],
            'protocol-relative' => ['//www.linkedin.com/posts/x'],
            'no scheme' => ['www.linkedin.com/posts/x'],
            'only the scheme' => ['https://'],
            'no host' => ['https:///path'],
            'localhost' => ['http://localhost/admin'],
            'ipv4 address' => ['http://192.168.1.10/x'],
            'ipv6 address' => ['http://[::1]/x'],
            'credentials in the link' => ['https://user:secret@example.com/a'],
            'a space in it' => ['https://example.com/a b'],
            'a newline in it' => ["https://example.com/a\nb"],
            'not a link at all' => ['not a url'],
            'empty' => [''],
            'only spaces' => ['   '],
            'label starting with a hyphen' => ['https://-bad.example.com/'],
            'too long' => ['https://example.com/' . str_repeat('a', 1100)],
        ];
    }

    /** @dataProvider refused */
    public function test_a_malformed_or_unsafe_link_is_refused_with_a_reason(string $url): void
    {
        $r = SourceUrl::normalize($url);

        $this->assertFalse($r['ok'], "should be refused: {$url}");
        $this->assertNotSame('', $r['error']);
    }

    public function test_an_address_with_non_latin_or_unsafe_characters_is_stored_percent_encoded_as_a_browser_would(): void
    {
        $r = SourceUrl::normalize('https://example.com/समाचार/plant?q=नया&x="1"');

        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame(1, preg_match('#^https://example\.com/%E0%A4%B8[0-9A-F%]+/plant\?q=%E0%A4%A8[0-9A-F%]+&x=%221%22$#', $r['url']), $r['url']);
        $this->assertFalse((bool) preg_match('/[^\x21-\x7E]/', $r['url']), 'plain ASCII once stored: it passes the gate\'s own address check');
        $this->assertNotFalse(filter_var($r['url'], FILTER_VALIDATE_URL));
    }

    public function test_the_address_is_normalised_but_otherwise_kept_as_given(): void
    {
        $this->assertSame('https://www.example.com/Path/AbC?id=7&utm_source=x', SourceUrl::normalize('HTTPS://WWW.Example.COM/Path/AbC?id=7&utm_source=x')['url'], 'only scheme and host are lower-cased; path case and query are untouched');
        $this->assertSame('https://example.com/a', SourceUrl::normalize("  https://example.com/a  ")['url'], 'surrounding spaces from a paste are trimmed');
        $this->assertSame('https://example.com:8443/a', SourceUrl::normalize('https://example.com:8443/a#section')['url'], 'a port is kept, the #fragment is not');
    }

    public function test_the_same_post_shared_two_ways_is_one_link_and_two_different_posts_are_two(): void
    {
        $key = fn (string $u) => SourceUrl::identityKey($u);

        // the same content
        $this->assertSame($key('https://www.linkedin.com/posts/acme_x-123'), $key('https://linkedin.com/posts/acme_x-123/?utm_source=share&utm_medium=member_ios&rcm=ACoAAA'));
        $this->assertSame($key('https://www.youtube.com/watch?v=dQw4w9WgXcQ'), $key('https://youtu.be/dQw4w9WgXcQ?si=abc123'));
        $this->assertSame($key('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s&feature=shared'), $key('https://m.youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertSame($key('https://twitter.com/acme/status/17123'), $key('https://x.com/acme/status/17123?s=20&t=abc'));
        $this->assertSame($key('https://www.instagram.com/p/CxYz/'), $key('https://instagram.com/p/CxYz?igsh=MWx4&utm_source=ig_web_copy_link'));
        $this->assertSame($key('https://www.facebook.com/AcmePlastics/posts/1'), $key('https://m.facebook.com/AcmePlastics/posts/1?fbclid=IwAR0&mibextid=abc'));

        // different content
        $this->assertNotSame($key('https://www.youtube.com/watch?v=AAAAAAAAAAA'), $key('https://www.youtube.com/watch?v=BBBBBBBBBBB'), 'two videos');
        $this->assertNotSame($key('https://www.youtube.com/watch?v=abcDEFghiJK'), $key('https://www.youtube.com/watch?v=ABCdefGHIjk'), 'video ids are case-sensitive');
        $this->assertNotSame($key('https://m.facebook.com/permalink.php?story_fbid=1&id=9'), $key('https://m.facebook.com/permalink.php?story_fbid=2&id=9'), 'two Facebook posts');
        $this->assertNotSame($key('https://acme.com/jobs?id=1'), $key('https://acme.com/jobs?id=2'), 'two job pages');
        $this->assertNotSame($key('https://forum.example.com/showthread.php?t=11'), $key('https://forum.example.com/showthread.php?t=12'), '?t= is a thread id on a forum, not tracking');
        $this->assertNotSame($key('https://www.linkedin.com/posts/acme_x-123'), $key('https://www.linkedin.com/posts/acme_y-456'), 'two LinkedIn posts');
    }

    public function test_an_unknown_site_is_named_by_its_hostname_and_a_non_address_has_no_platform(): void
    {
        $this->assertSame('blog.example.org', SourceUrl::platform('https://www.blog.example.org/x'));
        $this->assertSame('LinkedIn', SourceUrl::platform('https://fr.linkedin.com/in/x'), 'a country subdomain is still LinkedIn');
        $this->assertSame('linkedin.com.evil.example.com', SourceUrl::platform('https://linkedin.com.evil.example.com/x'), 'linkedin.com as the START of another domain is not LinkedIn');
        $this->assertSame('notlinkedin.com', SourceUrl::platform('https://notlinkedin.com/x'), 'a look-alike domain is not LinkedIn');
        $this->assertNull(SourceUrl::platform('not an address'));
    }
}
