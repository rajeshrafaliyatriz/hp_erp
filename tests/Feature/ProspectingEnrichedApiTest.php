<?php

namespace Tests\Feature;

use Tests\Support\BuildsCampaignsWorld;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/** The enriched company file through the real endpoints: preview, check, import, the company list and a company's detail. */
class ProspectingEnrichedApiTest extends TestCase
{
    use BuildsCampaignsWorld;
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCampaignsWorld();
        $this->buildSignalsWorld();
        foreach ([
            '2026_12_13_100000_add_product_scope_to_gtm_accounts', '2026_12_20_100000_add_region_to_gtm_accounts',
            '2026_12_21_100000_add_email_phone_to_gtm_accounts', '2026_12_22_100000_add_import_details_to_gtm_accounts',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        $this->h = $this->gtmAdmin();
    }

    public function test_the_enriched_file_shows_every_company_with_all_its_details(): void
    {
        $csv = (string) file_get_contents(__DIR__ . '/../Fixtures/gtm/g2g_gujarat_partner_import_enriched.csv');
        $preview = $this->postJson('/api/gtm/contacts/import/preview', ['csv' => $csv], $this->h)->assertOk()->json('data');
        $this->assertSame(12, count($preview['headers']));
        $this->assertSame(['full_name' => 0, 'email' => 1, 'title' => 2, 'phone' => 3, 'linkedin_url' => 4, 'role_in_deal' => 5, 'company' => 6, 'domain' => 7, 'notes' => 8], $preview['suggested_mapping']);

        $body = ['csv' => $csv, 'mapping' => $preview['suggested_mapping'], 'create_missing_accounts' => true,
            'product' => 'scholarclone', 'customer_model' => 'b2c', 'region_country' => 'India', 'region_state' => 'Gujarat'];
        $check = $this->postJson('/api/gtm/contacts/import', $body + ['dry_run' => true], $this->h)->assertOk()->json('data');
        $this->assertSame(65, $check['summary']['companies']);
        $this->assertSame(0, $check['summary']['invalid']);

        $done = $this->postJson('/api/gtm/contacts/import', $body + ['dry_run' => false], $this->h)->assertOk()->json('data');
        $this->assertSame([65, 65, 65], [$done['summary']['companies'], $done['summary']['accounts_created'], $done['summary']['imported']]);

        $list = $this->getJson('/api/gtm/accounts?product=scholarclone&customer_model=b2c&region_country=India&region_state=Gujarat&per_page=100', $this->h)->assertOk()->json('data');
        $this->assertSame(65, $list['total']);
        $this->assertSame(65, collect($list['items'])->filter(fn ($a) => ! empty($a['notes']))->count());

        $tvrls = collect($list['items'])->first(fn ($a) => str_starts_with($a['name'], 'TVRLS'));
        $detail = $this->getJson("/api/gtm/accounts/{$tvrls['id']}", $this->h)->assertOk()->json('data');
        $a = $detail['account'];
        $this->assertSame('tvrls.com', $a['domain']);
        $this->assertSame('https://tvrls.com', $a['website']);
        $this->assertSame('Gujarat', $a['region_state']);
        $this->assertSame('Ahmedabad', $a['location']);
        $this->assertSame('High for general contact; verify current decision-maker', $a['import_details']['Data confidence']);
        $this->assertSame('https://tvrls.com/contact-us/', $a['import_details']['Contact verification source']);
        $this->assertSame('Dr. T. V. Rao', $detail['contacts'][0]['full_name']);
        $this->assertSame('tvrls@tvrao.com', $detail['contacts'][0]['email']);

        // the same file again changes nothing
        $again = $this->postJson('/api/gtm/contacts/import', $body + ['dry_run' => false], $this->h)->assertOk()->json('data');
        $this->assertSame([0, 0, 0, 0], [$again['summary']['accounts_created'], $again['summary']['accounts_updated'], $again['summary']['imported'], $again['summary']['contacts_updated']]);
        $this->assertSame(65, $this->getJson('/api/gtm/accounts?product=scholarclone&customer_model=b2c&per_page=100', $this->h)->json('data.total'));
    }
}
