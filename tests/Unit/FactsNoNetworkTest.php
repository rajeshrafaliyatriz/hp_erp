<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Decision D6 of slice 4 (the contact facts ledger): no LinkedIn fetching, ever. A LinkedIn address is a value to store and show, never a page to read
 * (its terms forbid it, and a profile is personal data). The strongest simple guarantee is that the facts domain makes NO network request of any kind, so
 * this reads every file of it (and of the facts API and the consent command) and fails on any way of making one, and on any mention of linkedin.com outside
 * the one class that PARSES such addresses. Discovery (slice 5) will fetch pages through its own source adapters and the SSRF-safe fetcher, and hand
 * facts to this domain as text.
 *
 * Plain PHPUnit, no database: it only reads files. The detector is itself tested on a piece of code that does the forbidden thing, so a pass means something.
 */
class FactsNoNetworkTest extends TestCase
{
    /** Anything that can open a connection, as a pattern and the reason it is forbidden. */
    private const FORBIDDEN = [
        '/\bHttp::/' => 'the Http facade',
        '/Facades\\\\Http\b/' => 'the Http facade (import)',
        '/GuzzleHttp|Illuminate\\\\Http\\\\Client/' => 'an HTTP client',
        '/\bcurl_[a-z_]+\s*\(/i' => 'curl',
        '/\b(?:file_get_contents|fopen|readfile|file|simplexml_load_file|get_headers|copy)\s*\(\s*[\'"]https?:/i' => 'a read of a URL',
        '/\b(?:fsockopen|pfsockopen|stream_socket_client|socket_connect|dns_get_record|gethostbyname)\s*\(/i' => 'a socket or a DNS lookup',
        '/SafeUrlFetcher|SoapClient|PendingRequest/' => 'a fetcher',
    ];

    /** @return list<string> */
    private static function files(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app/Domain/Facts', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        foreach (['/app/Http/Controllers/Api/Facts', '/app/Console/Commands'] as $dir) {
            foreach (['Fact*.php', 'Consent*.php'] as $pattern) {
                foreach (glob($root . $dir . '/' . $pattern) ?: [] as $path) {
                    $files[] = $path;
                }
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<string> what is forbidden in this source, one line each */
    private static function violations(string $source, bool $mayMentionLinkedin): array
    {
        $found = [];
        foreach (self::FORBIDDEN as $pattern => $what) {
            if (preg_match($pattern, $source)) {
                $found[] = $what;
            }
        }
        if (! $mayMentionLinkedin && stripos($source, 'linkedin.com') !== false) {
            $found[] = 'a mention of linkedin.com outside the address parser';
        }

        return $found;
    }

    public function test_the_scan_really_covers_the_facts_domain(): void
    {
        $names = array_map('basename', self::files());

        foreach (['EvidenceScorer.php', 'FactWriter.php', 'SocialUrl.php', 'ConsentGate.php', 'FactsController.php', 'ConsentReconcileCommand.php'] as $expected) {
            $this->assertContains($expected, $names, "{$expected} is scanned");
        }
        $this->assertGreaterThanOrEqual(15, count($names));
    }

    public function test_no_code_of_the_facts_domain_can_make_a_network_request(): void
    {
        foreach (self::files() as $path) {
            $this->assertSame([], self::violations((string) file_get_contents($path), basename($path) === 'SocialUrl.php'), basename($path) . ' must not request anything over the network');
        }
    }

    public function test_only_the_address_parser_mentions_linkedin_com(): void
    {
        $mentions = [];
        foreach (self::files() as $path) {
            if (stripos((string) file_get_contents($path), 'linkedin.com') !== false) {
                $mentions[] = basename($path);
            }
        }

        $this->assertSame(['SocialUrl.php'], array_values(array_unique($mentions)), 'SocialUrl is the one class that knows the host');
    }

    public function test_the_detector_catches_a_request_to_linkedin_and_every_other_way_in(): void
    {
        $bad = [
            "Http::get('https://www.linkedin.com/in/someone');",
            "use Illuminate\\Support\\Facades\\Http;",
            "\$c = new \\GuzzleHttp\\Client();",
            "curl_init('https://example.test');",
            "file_get_contents('https://example.test/p');",
            "fsockopen('example.test', 443);",
            "app(SafeUrlFetcher::class)->get(\$url);",
        ];
        foreach ($bad as $code) {
            $this->assertNotSame([], self::violations($code, true), $code);
        }
        $this->assertSame(['a mention of linkedin.com outside the address parser'], self::violations("\$x = 'https://www.linkedin.com/in/someone';", false));
        $this->assertSame([], self::violations("\$a = parse_url(\$value); // https://www.linkedin.com/in/x is only text here", true));
    }
}
