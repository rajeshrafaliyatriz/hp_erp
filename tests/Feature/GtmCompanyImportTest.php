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
 * Importing a company list: each row is a contact AT a company, and the company's own details (name, website, notes, city, general email and
 * phone) are saved on the ACCOUNT, not only on the person. Uses the real 9-column file (Name, Email, Job title, Phone, LinkedIn URL, Buying role,
 * Company, Company website / domain, Notes): 67 rows, 67 companies.
 */
class GtmCompanyImportTest extends TestCase
{
    private const FILE = __DIR__ . '/../Fixtures/gtm/g2g_gujarat_partner_import.csv';

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
        foreach (['2026_12_20_100000_add_region_to_gtm_accounts', '2026_12_21_100000_add_email_phone_to_gtm_accounts'] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
    }

    private function csv(): string
    {
        return (string) file_get_contents(self::FILE);
    }

    private function scope(string $state = 'Gujarat'): array
    {
        return ['product' => 'g2g', 'model' => 'b2c', 'region' => ProspectScope::place(['country' => 'India', 'state' => $state])];
    }

    private function doImport(string $csv, bool $commit = true, ?array $scope = null, bool $create = true): array
    {
        $importer = new ContactImporter();
        $mapping = $importer->suggestMapping($importer->parse($csv)['headers']);

        return $importer->run(1, 7, $csv, $mapping, null, $create, $commit, $scope ?? $this->scope());
    }

    public function test_every_one_of_the_nine_headers_is_recognised_without_help(): void
    {
        $importer = new ContactImporter();
        $map = $importer->suggestMapping($importer->parse($this->csv())['headers']);

        $this->assertSame(['full_name' => 0, 'email' => 1, 'title' => 2, 'phone' => 3, 'linkedin_url' => 4, 'role_in_deal' => 5, 'company' => 6, 'domain' => 7, 'notes' => 8], $map);
    }

    public function test_all_67_companies_become_accounts_with_their_details_and_the_chosen_product_type_and_region(): void
    {
        $r = $this->doImport($this->csv());

        $this->assertSame(67, $r['summary']['companies']);
        $this->assertSame(67, $r['summary']['accounts_created']);
        $this->assertSame(67, GtmAccount::count());
        $this->assertSame(67, GtmAccount::distinct()->count('name'), 'one account per company');

        // product, customer type and region on every account
        $this->assertSame(67, GtmAccount::where(['product_key' => 'g2g', 'customer_model' => 'b2c', 'region_country' => 'India', 'region_state' => 'Gujarat'])->count());

        // the company's own details are on the account: its note on every one, its website on the 9 that have one
        $this->assertSame(67, GtmAccount::whereNotNull('notes')->where('notes', '!=', '')->count());
        $this->assertSame(9, GtmAccount::whereNotNull('domain')->count());
        $this->assertSame(9, GtmAccount::whereNotNull('website')->count());

        $huminivex = GtmAccount::where('name', 'Huminivex')->first();
        $this->assertSame('Ahmedabad. Competency mapping, talent development, HR audit', $huminivex->notes);
        $this->assertSame('Ahmedabad', $huminivex->location, 'the city the note opens with, known as a city of Gujarat');

        $alp = GtmAccount::where('name', 'Alp Consulting')->first();
        $this->assertSame(['alp.consulting', 'https://alp.consulting'], [$alp->domain, $alp->website]);
    }

    public function test_every_contact_is_saved_under_its_own_company(): void
    {
        $r = $this->doImport($this->csv());

        $this->assertSame(67, $r['summary']['imported']);
        $this->assertSame(67, GtmContact::count());
        // the file says which company each person belongs to; every contact must sit under exactly that company
        $parsed = (new ContactImporter())->parse($this->csv());
        $wrong = 0;
        foreach ($parsed['rows'] as $row) {
            $contact = GtmContact::where('full_name', $row[0])->first();
            if ($contact === null || GtmAccount::find($contact->account_id)->name !== $row[6]) {
                $wrong++;
            }
        }
        $this->assertSame(0, $wrong, 'no contact under another company');
        $this->assertSame(67, GtmContact::whereNotNull('notes')->count(), 'the note is kept on the contact too');
        // a real person keeps their own details
        $spipa = GtmContact::where('full_name', 'Hareet Shukla, IAS')->first();
        $this->assertSame(['dg-spipa@gujarat.gov.in', '079-26919900'], [$spipa->email, $spipa->phone]);
    }

    public function test_importing_the_same_file_again_creates_no_second_company_and_no_second_contact(): void
    {
        $this->doImport($this->csv());
        $again = $this->doImport($this->csv());

        $this->assertSame(0, $again['summary']['accounts_created']);
        $this->assertSame(0, $again['summary']['accounts_updated'], 'nothing new to fill');
        $this->assertSame(67, GtmAccount::count());
        $this->assertSame(67, GtmContact::count());
    }

    public function test_an_existing_company_is_filled_in_not_duplicated_and_what_it_already_has_is_kept(): void
    {
        $key = $this->scope()['region']['key'];
        $base = ['sub_institute_id' => 1, 'product_key' => 'g2g', 'customer_model' => 'b2c', 'region_country' => 'India', 'region_state' => 'Gujarat', 'region_key' => $key, 'created_at' => now(), 'updated_at' => now()];
        $id = DB::table('gtm_accounts')->insertGetId($base + ['name' => 'Fulcrum Resources', 'location' => 'Surat']);
        $other = DB::table('gtm_accounts')->insertGetId($base + ['name' => 'Alp Consulting', 'website' => 'https://old.alp.example']);

        $r = $this->doImport($this->csv());

        $this->assertSame(65, $r['summary']['accounts_created']);
        $this->assertSame(2, $r['summary']['accounts_updated']);
        $this->assertSame(67, GtmAccount::count(), 'the two that existed were reused');
        $f = GtmAccount::find($id);
        $this->assertSame(['fulcrumresources.net', 'https://fulcrumresources.net'], [$f->domain, $f->website]);
        $this->assertSame('Surat', $f->location, 'a value that was already there is never overwritten');
        $this->assertNotEmpty($f->notes);
        $alp = GtmAccount::find($other);
        $this->assertSame('https://old.alp.example', $alp->website, 'its own website is kept');
        $this->assertSame('alp.consulting', $alp->domain, 'the empty domain is filled');
        $this->assertSame(1, GtmAccount::where('name', 'Alp Consulting')->count());
        $this->assertSame(67, GtmContact::count());
    }

    public function test_the_check_before_importing_reports_the_same_numbers_and_writes_nothing(): void
    {
        $r = $this->doImport($this->csv(), false);

        $this->assertSame(67, $r['summary']['companies']);
        $this->assertCount(67, $r['accounts_to_create']);
        $this->assertSame(0, GtmAccount::count());
        $this->assertSame(0, GtmContact::count());
    }

    public function test_a_contact_that_cannot_be_saved_never_stops_its_company_from_being_saved(): void
    {
        $csv = "Name,Email,Company,Company website / domain,Notes\nAsha Rao,not-an-email,Good Firm,goodfirm.example,Pune. Training\n";
        $r = $this->doImport($csv, true, $this->scope('Maharashtra'));

        $this->assertSame(1, $r['summary']['invalid']);
        $this->assertSame(0, GtmContact::count());
        $firm = GtmAccount::where('name', 'Good Firm')->first();
        $this->assertNotNull($firm);
        $this->assertSame(['goodfirm.example', 'https://goodfirm.example', 'Pune'], [$firm->domain, $firm->website, $firm->location]);
    }

    public function test_the_company_general_contact_email_and_phone_are_kept_on_the_company(): void
    {
        $csv = "Name,Email,Phone,Company,Company website / domain,Notes\n"
            . "Acme HR (general contact),hello@acme.example,+91 90000 11111,Acme HR,acme.example,Surat. HR advisory\n"
            . "Ravi Shah,ravi@acme.example,+91 98888 22222,Acme HR,,\n";
        $this->doImport($csv);

        $acme = GtmAccount::where('name', 'Acme HR')->first();
        $this->assertSame(['hello@acme.example', '+91 90000 11111'], [$acme->email, $acme->phone], 'a person own email is not the company email');
        $this->assertSame(2, GtmContact::where('account_id', $acme->id)->count());
        $this->assertSame(1, GtmAccount::count());
    }

    public function test_two_regions_keep_their_own_company_records(): void
    {
        $this->doImport($this->csv());
        $maha = $this->scope('Maharashtra');
        $this->doImport("Name,Company,Company website / domain,Notes\nPrem Joshi (general contact),Huminivex,huminivex.com,Pune. Listed again\n", true, $maha);

        $this->assertSame(2, GtmAccount::where('name', 'Huminivex')->count(), 'the same firm in another region is its own account');
        $this->assertSame(67, ProspectScope::apply(GtmAccount::query(), $this->scope())->count());
        $this->assertSame(['Huminivex'], ProspectScope::apply(GtmAccount::query(), $maha)->pluck('name')->all());
    }

    public function test_companies_saved_before_regions_existed_are_reused_and_moved_into_the_region_not_added_twice(): void
    {
        $legacy = ['sub_institute_id' => 1, 'product_key' => 'g2g', 'customer_model' => 'b2c', 'region_key' => '', 'created_at' => now(), 'updated_at' => now()];
        $a = DB::table('gtm_accounts')->insertGetId($legacy + ['name' => 'Huminivex']);
        $b = DB::table('gtm_accounts')->insertGetId($legacy + ['name' => 'Fulcrum Resources']);
        // another product's account of the same name is a different company record and is left alone
        $other = DB::table('gtm_accounts')->insertGetId(['name' => 'Alp Consulting', 'product_key' => 'scholarclone', 'customer_model' => 'b2b'] + $legacy);
        DB::table('gtm_accounts')->where('id', $other)->update(['product_key' => 'scholarclone', 'customer_model' => 'b2b']);
        // one that already belongs to ANOTHER region is never taken
        $maha = $this->scope('Maharashtra')['region'];
        $elsewhere = DB::table('gtm_accounts')->insertGetId(['name' => 'NamanHR', 'region_country' => 'India', 'region_state' => 'Maharashtra', 'region_key' => $maha['key']] + $legacy);

        $r = $this->doImport($this->csv());

        $this->assertSame(65, $r['summary']['accounts_created'], '67 companies, two of which already existed');
        $this->assertSame(2, $r['summary']['accounts_updated']);
        $this->assertSame(['India', 'Gujarat'], [GtmAccount::find($a)->region_country, GtmAccount::find($a)->region_state], 'moved into the region');
        $this->assertSame('Ahmedabad. Competency mapping, talent development, HR audit', GtmAccount::find($a)->notes);
        $this->assertSame(['https://fulcrumresources.net'], [GtmAccount::find($b)->website]);
        $this->assertNull(GtmAccount::find($other)->region_country);
        $this->assertSame('Maharashtra', GtmAccount::find($elsewhere)->region_state);
        $this->assertSame(1, GtmAccount::where('name', 'Huminivex')->count());
        $this->assertSame(2, GtmAccount::where('name', 'NamanHR')->count(), 'its own record in Gujarat, and the Maharashtra one untouched');
        $this->assertSame(67, ProspectScope::apply(GtmAccount::query(), $this->scope())->count());
    }
}
