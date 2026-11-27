<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CORS_EXTRA_ORIGINS adds origins without a code change, keeps every origin that was always allowed, and accepts only well-formed http(s) origins
 * (never a wildcard, never a path, never a script URL). The config file is evaluated directly with the variable set, so no application boots.
 */
class CorsExtraOriginsTest extends TestCase
{
    private const ALWAYS = ['https://g2g.scholarclone.com', 'https://g2gv0.vercel.app', 'http://localhost:3000', 'http://localhost:3001', 'http://127.0.0.1:3000', 'http://127.0.0.1:3001', 'https://app.gapstogrowth.com'];

    private function origins(?string $extra): array
    {
        $env = fn (string $key, $default = null) => $key === 'CORS_EXTRA_ORIGINS' ? ($extra ?? $default) : $default;
        // The config file calls the global env(); define a stand-in for this evaluation only.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/config/cors.php');
        $source = preg_replace('/^<\?php/', '', $source);
        $source = str_replace('env(', '$env(', $source);

        return (function () use ($source, $env) {
            return eval($source);
        })()['allowed_origins'];
    }

    public function test_nothing_extra_leaves_the_existing_origins_exactly(): void
    {
        $this->assertSame(self::ALWAYS, $this->origins(null));
        $this->assertSame(self::ALWAYS, $this->origins(''));
    }

    public function test_extra_origins_are_added_after_the_existing_ones_without_duplicates(): void
    {
        $got = $this->origins('https://live.example.org, https://staging.example.org/ ,https://g2g.scholarclone.com');
        $this->assertSame(array_merge(self::ALWAYS, ['https://live.example.org', 'https://staging.example.org']), $got);
    }

    public function test_anything_that_is_not_a_plain_http_or_https_origin_is_ignored(): void
    {
        $bad = '*,https://*.example.org,javascript:alert(1),ftp://files.example.org,https://x.example.org/path,example.org,https://ok.example.org:8443';
        $this->assertSame(array_merge(self::ALWAYS, ['https://ok.example.org:8443']), $this->origins($bad));
    }
}
