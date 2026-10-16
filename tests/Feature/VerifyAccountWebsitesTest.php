<?php

namespace Tests\Feature;

use App\Console\Commands\VerifyAccountWebsites;
use App\Domain\Signals\Support\SafeUrlFetcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The website check tells a wrong domain from a slow or blocking one, and says only what it observed. Mocked HTTP; public IPs stand in for hosts. */
class VerifyAccountWebsitesTest extends TestCase
{
    /** Http::fake() calls stack (the first match wins), so every scenario starts from a clean client. */
    private function fake(array $map): void
    {
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstance(\Illuminate\Http\Client\Factory::class);
        Http::fake($map);
    }

    private function check(array $row): array
    {
        return app(VerifyAccountWebsites::class)->check(new SafeUrlFetcher(fn () => ['93.184.216.34']), $row);
    }

    public function test_a_page_with_every_name_word_is_verified_and_a_group_page_is_scoped_honestly(): void
    {
        $this->fake(['*' => Http::response('<html><head><title>Nirma Vidyavihar - Ahmedabad</title></head><body><p>Nirma Vidya Vihar, Bodakdev, Ahmedabad</p></body></html>', 200, ['Content-Type' => 'text/html'])]);
        $r = $this->check(['crm_ref' => 'LEA35', 'name' => 'NIRMA VIDYA VIHAR', 'city' => 'Ahmedabad', 'website' => 'http://93.184.216.34']);
        $this->assertSame('verified', $r['verdict']);
        $this->assertTrue($r['city_on_page']);

        $this->fake(['*' => Http::response('<html><head><title>Ryan Group of Institutions</title></head><body>Ryan schools across India</body></html>', 200, ['Content-Type' => 'text/html'])]);
        $g = $this->check(['crm_ref' => 'LEA82', 'name' => 'RYAN INTERNATIONAL SCHOOL', 'city' => 'Rajkot', 'website' => 'http://93.184.216.34']);
        $this->assertSame('verified', $g['verdict']);
        $this->assertStringContainsString('not necessarily this branch', $g['detail']);
    }

    public function test_a_page_that_does_not_name_the_school_is_not_confirmed(): void
    {
        $this->fake(['*' => Http::response('<html><head><title>Domain parked</title></head><body>This domain is for sale.</body></html>', 200, ['Content-Type' => 'text/html'])]);
        $r = $this->check(['crm_ref' => 'LEA1', 'name' => 'V CARE INTERNATIONAL SCHOOL', 'city' => 'Surat', 'website' => 'http://93.184.216.34']);
        $this->assertSame('resolves_unconfirmed', $r['verdict']);
    }

    public function test_refusals_and_server_errors_are_not_evidence_the_domain_is_wrong(): void
    {
        $this->fake(['*' => Http::response('Forbidden', 403)]);
        $this->assertSame('blocked', $this->check(['crm_ref' => 'A', 'name' => 'Some School', 'city' => 'X', 'website' => 'http://93.184.216.34'])['verdict']);

        $this->fake(['*' => Http::response('Down', 503)]);
        $this->assertSame('temporary_failure', $this->check(['crm_ref' => 'A', 'name' => 'Some School', 'city' => 'X', 'website' => 'http://93.184.216.34'])['verdict']);
    }

    public function test_redirects_are_followed_and_reported_and_no_website_is_not_invented(): void
    {
        $this->fake([
            'http://93.184.216.34/' => Http::response('', 301, ['Location' => 'http://93.184.216.34/home']),
            'http://93.184.216.34/home' => Http::response('<html><title>Podar Education Complex</title><body>Podar World School Maneja</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $r = $this->check(['crm_ref' => 'LEA59', 'name' => 'PODAR WORLD SCHOOL MANEJA', 'city' => 'Vadodara', 'website' => 'http://93.184.216.34/']);
        $this->assertSame('verified', $r['verdict']);
        $this->assertSame('http://93.184.216.34/home', $r['final_url']);

        $none = $this->check(['crm_ref' => 'X', 'name' => 'Some School', 'city' => 'Y', 'website' => '']);
        $this->assertSame('no_website_given', $none['verdict']);
    }

    public function test_a_non_public_address_is_refused_before_any_request(): void
    {
        Http::fake();
        $r = $this->check(['crm_ref' => 'X', 'name' => 'Some School', 'city' => 'Y', 'website' => 'http://127.0.0.1/']);
        $this->assertNotSame('verified', $r['verdict']);
        Http::assertNothingSent();
    }

    public function test_a_partly_named_group_site_is_verified_at_group_level_only_with_a_matching_non_free_crm_email_domain(): void
    {
        $page = '<html><head><title>Podar Education Complex</title></head><body>Podar World School network</body></html>';
        $this->fake(['*' => fn () => Http::response($page, 200, ['Content-Type' => 'text/html'])]);   // a fresh response per request
        // A public IP stands in for the host so no DNS is needed: the CRM email domain equals the site host.
        $row = ['crm_ref' => 'LEA59', 'name' => 'PODAR WORLD SCHOOL MANEJA', 'city' => 'Vadodara', 'website' => 'http://93.184.216.34'];

        $this->assertSame('resolves_unconfirmed', $this->check($row)['verdict'], 'no corroborating email: the name is only partly on the page');
        $this->assertSame('resolves_unconfirmed', $this->check($row + ['email' => 'principal@gmail.com'])['verdict'], 'a free mail provider says nothing about the school');
        $this->assertSame('resolves_unconfirmed', $this->check($row + ['email' => 'x@other-domain.org'])['verdict'], 'a different domain proves nothing');

        $r = $this->check($row + ['email' => 'vadodara3@93.184.216.34']);
        $this->assertSame('verified_group', $r['verdict']);
        $this->assertStringContainsString('does not confirm this branch', $r['detail']);
        $this->assertArrayNotHasKey('email', $r, 'only the domain is kept, never the address');
        $this->assertSame('93.184.216.34', $r['crm_email']);
    }
}
