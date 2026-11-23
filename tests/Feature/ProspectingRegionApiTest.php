<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCampaignsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\Support\BuildsFactsWorld;
use Tests\TestCase;

/**
 * The Prospecting endpoints with a region: adding an account, listing it, importing contacts, and the "From research" candidates all follow
 * the chosen product, customer type and region, and two regions never see each other's accounts.
 */
class ProspectingRegionApiTest extends TestCase
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
        foreach (['2026_12_13_100000_add_product_scope_to_gtm_accounts', '2026_12_20_100000_add_region_to_gtm_accounts', '2026_12_21_100000_add_email_phone_to_gtm_accounts', '2026_12_22_100000_add_import_details_to_gtm_accounts'] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        $this->h = $this->gtmAdmin();
        // The candidates query orders with MySQL's FIELD(); the sqlite test database has no such function, so it is given one here (tests only).
        DB::connection()->getPdo()->sqliteCreateFunction('FIELD', function ($value, ...$list) {
            $i = array_search($value, $list, true);

            return $i === false ? 0 : $i + 1;
        });
    }

    private function add(string $name, string $state, string $domain): void
    {
        $this->postJson('/api/gtm/accounts', [
            'name' => $name, 'domain' => $domain, 'product' => 'scholarclone', 'customer_model' => 'b2c',
            'region_country' => 'India', 'region_state' => $state,
        ], $this->h)->assertStatus(201);
    }

    private function listed(string $state = '', string $extra = ''): array
    {
        $q = 'product=scholarclone&customer_model=b2c' . ($state !== '' ? '&region_country=India&region_state=' . urlencode($state) : '') . $extra;

        return array_column($this->getJson('/api/gtm/accounts?' . $q, $this->h)->assertOk()->json('data.items'), 'name');
    }

    public function test_accounts_added_under_two_regions_are_listed_separately(): void
    {
        $this->add('TVRLS', 'Gujarat', 'tvrls.example');
        $this->add('YASHADA', 'Maharashtra', 'yashada.example');

        $this->assertSame(['TVRLS'], $this->listed('Gujarat'));
        $this->assertSame(['YASHADA'], $this->listed('Maharashtra'));
        $this->assertEqualsCanonicalizing(['TVRLS', 'YASHADA'], $this->listed(''));
    }

    public function test_the_same_website_is_allowed_in_another_region_but_refused_in_the_same_one(): void
    {
        $this->add('Shared', 'Gujarat', 'shared.example');
        $this->add('Shared', 'Maharashtra', 'shared.example');

        $this->postJson('/api/gtm/accounts', [
            'name' => 'Shared again', 'domain' => 'shared.example', 'product' => 'scholarclone', 'customer_model' => 'b2c',
            'region_country' => 'India', 'region_state' => 'Gujarat',
        ], $this->h)->assertStatus(422);
    }

    public function test_an_import_through_the_api_files_accounts_and_contacts_under_the_chosen_region(): void
    {
        $csv = "name,company,email\nAsha Rao,TVRLS,asha@tvrls.example\n";
        $this->postJson('/api/gtm/contacts/import', [
            'csv' => $csv, 'mapping' => ['full_name' => 0, 'company' => 1, 'email' => 2], 'create_missing_accounts' => true, 'dry_run' => false,
            'product' => 'scholarclone', 'customer_model' => 'b2c', 'region_country' => 'India', 'region_state' => 'Gujarat',
        ], $this->h)->assertOk()->assertJsonPath('data.summary.imported', 1);

        $this->assertSame(['TVRLS'], $this->listed('Gujarat'));
        $this->assertSame([], $this->listed('Maharashtra'));
    }

    public function test_an_import_that_creates_accounts_must_name_a_region(): void
    {
        $this->postJson('/api/gtm/contacts/import', [
            'csv' => "name,company\nAsha,TVRLS\n", 'mapping' => ['full_name' => 0, 'company' => 1], 'create_missing_accounts' => true, 'dry_run' => false,
            'product' => 'scholarclone', 'customer_model' => 'b2c',
        ], $this->h)->assertStatus(422);
    }

    public function test_a_region_the_location_data_rejects_is_refused_not_ignored(): void
    {
        $this->getJson('/api/gtm/accounts?product=scholarclone&customer_model=b2c&region_country=India&region_state=Gujarat&region_city=!!', $this->h)->assertStatus(422);
    }

    private function signalFor(string $company, ?string $state): void
    {
        $this->makeSignal(self::T, [
            'product_key' => 'scholarclone', 'customer_model' => 'b2c',
            'region_scope' => $state === null ? null : json_encode([['country' => 'India', 'state' => $state, 'city' => null]]),
        ], ['name' => $company, 'normalized_name' => strtolower($company)]);
    }

    private function candidates(string $state = ''): array
    {
        $q = 'product=scholarclone&customer_model=b2c' . ($state !== '' ? '&region_country=India&region_state=' . urlencode($state) : '');

        return array_column($this->getJson('/api/gtm/accounts/candidates?' . $q, $this->h)->assertOk()->json('data.items'), 'name');
    }

    public function test_from_research_shows_only_companies_with_a_signal_in_the_chosen_region(): void
    {
        $this->signalFor('Gujarat Firm', 'Gujarat');
        $this->signalFor('Maharashtra Firm', 'Maharashtra');
        $this->signalFor('Everywhere Firm', null);   // from an "All regions" run

        $this->assertSame(['Gujarat Firm'], $this->candidates('Gujarat'));
        $this->assertSame(['Maharashtra Firm'], $this->candidates('Maharashtra'));
        $this->assertEqualsCanonicalizing(['Gujarat Firm', 'Maharashtra Firm', 'Everywhere Firm'], $this->candidates(''));
    }

    public function test_promoting_a_researched_company_files_it_under_the_chosen_region_only(): void
    {
        $this->signalFor('Gujarat Firm', 'Gujarat');
        $id = (int) collect($this->getJson('/api/gtm/accounts/candidates?product=scholarclone&customer_model=b2c&region_country=India&region_state=Gujarat', $this->h)->json('data.items'))->first()['id'];

        $this->postJson("/api/gtm/accounts/from-company/{$id}", ['product' => 'scholarclone', 'customer_model' => 'b2c', 'region_country' => 'India', 'region_state' => 'Gujarat'], $this->h)->assertStatus(201);

        $this->assertSame(['Gujarat Firm'], $this->listed('Gujarat'));
        $this->assertSame([], $this->listed('Maharashtra'));
        $this->assertSame([], $this->candidates('Gujarat'), 'it is an account on that list now');
    }

    public function test_the_real_company_file_imports_through_the_api_with_every_company_visible_in_the_list_and_the_detail(): void
    {
        $csv = (string) file_get_contents(__DIR__ . '/../Fixtures/gtm/g2g_gujarat_partner_import.csv');
        $preview = $this->postJson('/api/gtm/contacts/import/preview', ['csv' => $csv], $this->h)->assertOk()->json('data');
        $this->assertSame(['full_name' => 0, 'email' => 1, 'title' => 2, 'phone' => 3, 'linkedin_url' => 4, 'role_in_deal' => 5, 'company' => 6, 'domain' => 7, 'notes' => 8], $preview['suggested_mapping']);

        $body = ['csv' => $csv, 'mapping' => $preview['suggested_mapping'], 'create_missing_accounts' => true,
            'product' => 'scholarclone', 'customer_model' => 'b2c', 'region_country' => 'India', 'region_state' => 'Gujarat'];
        $check = $this->postJson('/api/gtm/contacts/import', $body + ['dry_run' => true], $this->h)->assertOk()->json('data');
        $this->assertSame([67, 67, 0], [$check['summary']['companies'], $check['summary']['accounts_created'], $check['summary']['accounts_updated']]);

        $done = $this->postJson('/api/gtm/contacts/import', $body + ['dry_run' => false], $this->h)->assertOk()->json('data');
        $this->assertSame([67, 67, 67], [$done['summary']['companies'], $done['summary']['accounts_created'], $done['summary']['imported']]);

        $items = $this->getJson('/api/gtm/accounts?product=scholarclone&customer_model=b2c&region_country=India&region_state=Gujarat&per_page=100', $this->h)->assertOk()->json('data');
        $this->assertSame(67, $items['total']);
        $this->assertCount(67, $items['items']);
        $this->assertSame(67, collect($items['items'])->filter(fn ($a) => ! empty($a['notes']))->count(), 'every company shows its details');
        $this->assertSame(67, collect($items['items'])->filter(fn ($a) => (int) $a['contacts_count'] === 1)->count(), 'each with its contact');

        $namanhr = collect($items['items'])->firstWhere('name', 'NamanHR');
        $detail = $this->getJson("/api/gtm/accounts/{$namanhr['id']}", $this->h)->assertOk()->json('data');
        $this->assertSame(['namanhr.com', 'https://www.namanhr.com', 'Gujarat'], [$detail['account']['domain'], $detail['account']['website'], $detail['account']['region_state']]);
        $this->assertNotEmpty($detail['account']['notes']);
        $this->assertSame('Samir Parikh', $detail['contacts'][0]['full_name']);

        // a second import of the same file changes nothing
        $again = $this->postJson('/api/gtm/contacts/import', $body + ['dry_run' => false], $this->h)->assertOk()->json('data');
        $this->assertSame([0, 0, 0], [$again['summary']['accounts_created'], $again['summary']['accounts_updated'], $again['summary']['imported']]);
        $this->assertSame(67, $this->getJson('/api/gtm/accounts?product=scholarclone&customer_model=b2c&per_page=100', $this->h)->json('data.total'));
    }
}
