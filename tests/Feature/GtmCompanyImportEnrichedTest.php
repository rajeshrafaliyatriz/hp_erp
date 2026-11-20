<?php

namespace Tests\Feature;

use App\Domain\Gtm\ContactImporter;
use App\Domain\Gtm\GtmAccount;
use App\Domain\Gtm\GtmContact;
use App\Domain\Gtm\ProspectScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The enriched 12-column company file: the 9 standard columns plus "Contact verification source", "Research status" and "Data confidence".
 * 67 rows, two companies listed twice under one website (BRIOS, HiGraph), cells with two emails or phones, roles written in words, and (on the live
 * server) companies and contacts that an earlier import already saved.
 */
class GtmCompanyImportEnrichedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('gtm_accounts', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('name', 191);
            $t->string('domain', 191)->nullable();
            $t->string('website', 255)->nullable();
            $t->string('industry', 150)->nullable();
            $t->string('location', 191)->nullable();
            $t->string('stage', 20)->default('target');
            $t->string('source', 20)->nullable();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('product_key', 40)->nullable();
            $t->string('customer_model', 3)->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['sub_institute_id', 'domain', 'product_key', 'customer_model'], 'gtm_accounts_tenant_domain_scope_unique');
        });
        Schema::create('gtm_contacts', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('account_id');
            $t->string('full_name', 191);
            $t->string('title', 191)->nullable();
            $t->string('email', 191)->nullable();
            $t->string('phone', 50)->nullable();
            $t->string('linkedin_url', 191)->nullable();
            $t->string('role_in_deal', 50)->nullable();
            $t->string('status', 20)->nullable();
            $t->string('source', 20)->nullable();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('gtm_suppressions', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('kind', 10);
            $t->string('value', 191);
        });
        foreach (['2026_12_20_100000_add_region_to_gtm_accounts', '2026_12_21_100000_add_email_phone_to_gtm_accounts', '2026_12_22_100000_add_import_details_to_gtm_accounts'] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
    }

    private function csv(): string
    {
        return (string) file_get_contents(__DIR__ . '/../Fixtures/gtm/g2g_gujarat_partner_import_enriched.csv');
    }

    private function scope(): array
    {
        return ['product' => 'g2g', 'model' => 'b2c', 'region' => ProspectScope::place(['country' => 'India', 'state' => 'Gujarat'])];
    }

    private function doImport(string $csv, bool $commit = true): array
    {
        $importer = new ContactImporter();
        $mapping = $importer->suggestMapping($importer->parse($csv)['headers']);

        return $importer->run(1, 7, $csv, $mapping, null, true, $commit, $this->scope());
    }

    private function legacyBase(): array
    {
        return ['sub_institute_id' => 1, 'product_key' => 'g2g', 'customer_model' => 'b2c', 'region_country' => 'India', 'region_state' => 'Gujarat',
            'region_key' => $this->scope()['region']['key'], 'source' => 'import', 'stage' => 'target', 'created_at' => now(), 'updated_at' => now()];
    }

    public function test_one_account_per_company_with_rows_that_share_a_website_merged(): void
    {
        $r = $this->doImport($this->csv());

        // 67 rows, but BRIOS and HiGraph are each listed twice with the same website: 65 companies
        $this->assertSame(65, $r['summary']['companies']);
        $this->assertSame(65, GtmAccount::count());
        $this->assertSame(1, GtmAccount::where('domain', 'brios.co.in')->count());
        $this->assertSame(1, GtmAccount::where('domain', 'higraph.in')->count());
        $this->assertSame(0, $r['summary']['invalid'], 'no row is refused: several emails or phones in one cell are kept');
        // the two repeated people are the same contact, not two
        $this->assertSame(65, GtmContact::count());
        $this->assertSame(2, $r['summary']['duplicate']);
        $this->assertSame(65, ProspectScope::apply(GtmAccount::query(), $this->scope())->count());
    }

    public function test_the_three_extra_research_columns_are_kept_on_each_company(): void
    {
        $this->doImport($this->csv());

        $tvrls = GtmAccount::where('name', 'like', 'TVRLS%')->first();
        $this->assertSame('https://tvrls.com/contact-us/', $tvrls->import_details['Contact verification source']);
        $this->assertSame('Official company contact verified; contact office listed in Bengaluru, not Ahmedabad', $tvrls->import_details['Research status']);
        $this->assertSame('High for general contact; verify current decision-maker', $tvrls->import_details['Data confidence']);
        $this->assertSame(65, GtmAccount::whereNotNull('import_details')->count(), 'every company has its research details');

        // a company that is in two rows keeps what both rows say
        $brios = GtmAccount::where('domain', 'brios.co.in')->first();
        $this->assertStringContainsString('zaubacorp.com/BRIOS-CONSULTING-LLP-AAE-3641', $brios->import_details['Contact verification source']);
        $this->assertStringContainsString('Duplicate/alternate of Row 4', (string) $brios->notes);
    }

    public function test_cells_with_two_emails_or_phones_keep_the_first_in_the_field_and_the_rest_in_the_notes(): void
    {
        $this->doImport($this->csv());

        $shreya = GtmContact::where('full_name', 'Shreya Parikh')->first();
        $this->assertSame('hello@huminivex.com', $shreya->email);
        $this->assertSame('+91 91049 24609', $shreya->phone);
        $this->assertStringContainsString('Other email: shreya@huminivex.com', $shreya->notes);
        $this->assertStringContainsString('Other phone: +91 82008 52548', $shreya->notes);
        $this->assertSame('Huminivex', GtmAccount::find($shreya->account_id)->name);
        $this->assertSame('Director', $shreya->title);
    }

    public function test_a_role_written_in_words_becomes_the_nearest_role_and_the_words_are_kept(): void
    {
        $this->doImport($this->csv());

        $directors = GtmContact::where('full_name', 'like', 'Shukladhan%')->first();
        $this->assertSame('decision_maker', $directors->role_in_deal);
        $this->assertStringContainsString('Buying role (as written): Decision maker (Directors) / General contact', $directors->notes);
        $this->assertSame('decision_maker', GtmContact::where('full_name', 'Dr. T. V. Rao')->first()->role_in_deal);
    }

    public function test_the_live_situation_two_saved_copies_of_one_company_and_contacts_already_there_import_without_error(): void
    {
        $base = $this->legacyBase();
        // as an earlier import left them: the same company twice (one copy with the website, one without), each with a contact
        $llp = DB::table('gtm_accounts')->insertGetId($base + ['name' => 'BRIOS Consulting LLP', 'domain' => 'brios.co.in', 'website' => 'https://brios.co.in']);
        $plain = DB::table('gtm_accounts')->insertGetId($base + ['name' => 'BRIOS Consulting']);
        DB::table('gtm_contacts')->insert(['sub_institute_id' => 1, 'account_id' => $llp, 'full_name' => 'Surendra Hirdayaram Varma', 'email' => 'surendra.varma@hiringlink.in', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('gtm_contacts')->insert(['sub_institute_id' => 1, 'account_id' => $plain, 'full_name' => 'Old contact', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        // an unrelated company that already has its contact
        $hum = DB::table('gtm_accounts')->insertGetId($base + ['name' => 'Huminivex']);
        DB::table('gtm_contacts')->insert(['sub_institute_id' => 1, 'account_id' => $hum, 'full_name' => 'Shreya Parikh', 'email' => 'hello@huminivex.com', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $r = $this->doImport($this->csv());

        $this->assertSame(1, GtmAccount::where('name', 'like', 'BRIOS%')->count(), 'the two copies became one company');
        $this->assertSame($llp, (int) GtmAccount::where('name', 'like', 'BRIOS%')->value('id'), 'the copy that owns the website is kept');
        $this->assertSame(1, $r['summary']['accounts_merged']);
        $this->assertSame(2, GtmContact::where('account_id', $llp)->count(), 'its contact and the other copy has contact are together');
        $this->assertSame(0, GtmContact::where('account_id', $plain)->count(), 'nothing is left under the removed copy');
        // the contact that was already there is completed, not repeated
        $this->assertSame(1, GtmContact::where('email', 'hello@huminivex.com')->count());
        $shreya = GtmContact::where('email', 'hello@huminivex.com')->first();
        $this->assertSame(['Director', 'decision_maker'], [$shreya->title, $shreya->role_in_deal]);
        $this->assertSame(1, GtmAccount::where('name', 'Huminivex')->count());
        $this->assertSame(0, $r['summary']['invalid']);
        $this->assertSame(65, ProspectScope::apply(GtmAccount::query(), $this->scope())->count());
    }

    public function test_a_company_that_other_records_point_to_is_never_merged_away(): void
    {
        Schema::create('gtm_deals', function ($t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
        });
        $base = $this->legacyBase();
        DB::table('gtm_accounts')->insert($base + ['name' => 'BRIOS Consulting LLP', 'domain' => 'brios.co.in']);
        $withDeal = DB::table('gtm_accounts')->insertGetId($base + ['name' => 'BRIOS Consulting']);
        DB::table('gtm_deals')->insert(['account_id' => $withDeal]);

        $r = $this->doImport($this->csv());

        $this->assertSame(0, $r['summary']['accounts_merged']);
        $this->assertNotNull(GtmAccount::find($withDeal), 'an account with a deal stays');
        $this->assertSame(0, $r['summary']['invalid']);
    }

    public function test_importing_the_enriched_file_twice_changes_nothing_the_second_time(): void
    {
        $this->doImport($this->csv());
        $accounts = GtmAccount::count();
        $contacts = GtmContact::count();
        $again = $this->doImport($this->csv());

        $this->assertSame([0, 0, 0, 0], [$again['summary']['accounts_created'], $again['summary']['accounts_updated'], $again['summary']['imported'], $again['summary']['contacts_updated']]);
        $this->assertSame([$accounts, $contacts], [GtmAccount::count(), GtmContact::count()]);
    }
}
