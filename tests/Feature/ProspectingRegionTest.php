<?php

namespace Tests\Feature;

use App\Domain\Gtm\ContactImporter;
use App\Domain\Gtm\GtmAccount;
use App\Domain\Gtm\GtmContact;
use App\Domain\Gtm\ProspectScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Region-wise prospecting: an account and its contacts belong to one product, one customer type and ONE place. Gujarat and Maharashtra lists never mix;
 * choosing a country shows its states, choosing a state shows only that state; older accounts (no place) are untouched and only seen under "All regions".
 */
class ProspectingRegionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // gtm_accounts as it was BEFORE this feature: product-wise, but with no place.
        Schema::create('gtm_accounts', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('name', 191);
            $t->string('domain', 191)->nullable();
            $t->string('website', 255)->nullable();
            $t->string('location', 191)->nullable();
            $t->text('notes')->nullable();
            $t->string('stage', 20)->default('target');
            $t->string('source', 20)->nullable();
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
        DB::table('gtm_accounts')->insert(['sub_institute_id' => 1, 'name' => 'Old school', 'domain' => 'old.example', 'product_key' => 'scholarclone', 'customer_model' => 'b2b', 'created_at' => now(), 'updated_at' => now()]);
        (require base_path('database/migrations/2026_12_20_100000_add_region_to_gtm_accounts.php'))->up();
        (require base_path('database/migrations/2026_12_21_100000_add_email_phone_to_gtm_accounts.php'))->up();
    }

    private function scope(?string $state, ?string $city = null): array
    {
        $place = ['country' => 'India'] + ($state !== null ? ['state' => $state] : []) + ($city !== null ? ['city' => $city] : []);

        return ['product' => 'g2g', 'model' => 'b2c', 'region' => ProspectScope::place($place)];
    }

    private function import(array $scope, string $csv): array
    {
        return (new ContactImporter())->run(1, 7, $csv, ['full_name' => 0, 'company' => 1, 'email' => 2], null, true, true, $scope);
    }

    private function names(array $scope): array
    {
        return ProspectScope::apply(GtmAccount::where('sub_institute_id', 1), $scope)->orderBy('name')->pluck('name')->all();
    }

    public function test_the_migration_keeps_old_accounts_with_no_place_and_can_run_twice(): void
    {
        (require base_path('database/migrations/2026_12_20_100000_add_region_to_gtm_accounts.php'))->up();

        $old = DB::table('gtm_accounts')->where('name', 'Old school')->first();
        $this->assertNull($old->region_country);
        $this->assertSame('', $old->region_key);
        $this->assertSame(['scholarclone', 'b2b'], [$old->product_key, $old->customer_model]);
    }

    public function test_two_regions_import_separately_and_stay_separate_when_switching(): void
    {
        $gujarat = $this->scope('Gujarat');
        $maha = $this->scope('Maharashtra');

        $a = $this->import($gujarat, "name,company,email\nAsha Rao,TVRLS,asha@tvrls.example\nVikram Shah,NamanHR,vikram@namanhr.example\n");
        $b = $this->import($maha, "name,company,email\nPrem Joshi,YASHADA,prem@yashada.example\nMeena Kale,MITCON,meena@mitcon.example\n");

        $this->assertSame(2, $a['summary']['accounts_created']);
        $this->assertSame(2, $b['summary']['accounts_created']);

        $this->assertSame(['NamanHR', 'TVRLS'], $this->names($gujarat));
        $this->assertSame(['MITCON', 'YASHADA'], $this->names($maha));

        // contacts follow their account's region
        $contactsIn = fn (array $scope) => GtmContact::whereIn('account_id', ProspectScope::apply(GtmAccount::where('sub_institute_id', 1), $scope)->pluck('id'))->orderBy('full_name')->pluck('full_name')->all();
        $this->assertSame(['Asha Rao', 'Vikram Shah'], $contactsIn($gujarat));
        $this->assertSame(['Meena Kale', 'Prem Joshi'], $contactsIn($maha));

        // saved under the exact product + type + place
        $row = GtmAccount::where('name', 'TVRLS')->first();
        $this->assertSame(['g2g', 'b2c', 'India', 'Gujarat', null], [$row->product_key, $row->customer_model, $row->region_country, $row->region_state, $row->region_city]);
    }

    public function test_a_country_shows_its_states_a_state_shows_only_itself_and_all_regions_shows_everything(): void
    {
        $this->import($this->scope('Gujarat'), "name,company,email\nAsha,TVRLS,a@x.example\n");
        $this->import($this->scope('Maharashtra'), "name,company,email\nPrem,YASHADA,p@x.example\n");
        $this->import($this->scope(null), "name,company,email\nRaj,India Wide Co,r@x.example\n");

        $this->assertSame(['TVRLS'], $this->names($this->scope('Gujarat')));
        $this->assertSame(['YASHADA'], $this->names($this->scope('Maharashtra')));
        $this->assertSame(['India Wide Co', 'TVRLS', 'YASHADA'], $this->names($this->scope(null)), 'India includes its states');

        $all = ['product' => 'g2g', 'model' => 'b2c', 'region' => null];
        $this->assertSame(['India Wide Co', 'TVRLS', 'YASHADA'], $this->names($all));
        $this->assertSame(['Old school'], $this->names(['product' => 'scholarclone', 'model' => 'b2b', 'region' => null]), 'the older account is untouched and not in the G2G lists');
        $this->assertSame([], $this->names(['product' => 'scholarclone', 'model' => 'b2b', 'region' => ProspectScope::place(['country' => 'India', 'state' => 'Gujarat'])]), 'it has no place, so no region shows it');
    }

    public function test_an_import_never_reuses_an_account_from_another_region_and_a_chosen_account_must_be_on_the_list(): void
    {
        $this->import($this->scope('Gujarat'), "name,company,email\nAsha,Shared Firm,a@x.example\n");
        $gujaratAccount = GtmAccount::where('name', 'Shared Firm')->value('id');

        // the same firm name imported under Maharashtra becomes its own account there
        $b = $this->import($this->scope('Maharashtra'), "name,company,email\nPrem,Shared Firm,p@x.example\n");
        $this->assertSame(1, $b['summary']['accounts_created']);
        $this->assertSame(2, GtmAccount::where('name', 'Shared Firm')->count());

        // a Gujarat account cannot be chosen as the target of a Maharashtra import
        $this->expectException(\DomainException::class);
        (new ContactImporter())->run(1, 7, "name,email\nZed,z@x.example\n", ['full_name' => 0, 'email' => 1], $gujaratAccount, false, false, $this->scope('Maharashtra'));
    }

    public function test_the_same_website_may_be_an_account_in_two_regions_but_not_twice_in_one(): void
    {
        $g = ProspectScope::place(['country' => 'India', 'state' => 'Gujarat']);
        $m = ProspectScope::place(['country' => 'India', 'state' => 'Maharashtra']);
        $make = fn (array $r) => DB::table('gtm_accounts')->insert([
            'sub_institute_id' => 1, 'name' => 'Firm', 'domain' => 'firm.example', 'product_key' => 'g2g', 'customer_model' => 'b2c',
            'region_country' => $r['country'], 'region_state' => $r['state'], 'region_city' => $r['city'], 'region_key' => $r['key'],
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $make($g);
        $make($m);
        $this->assertSame(2, DB::table('gtm_accounts')->where('domain', 'firm.example')->count());

        $this->expectException(QueryException::class);
        $make($g);
    }

    public function test_a_place_the_location_data_does_not_accept_is_refused_with_a_clear_message(): void
    {
        $this->expectException(\DomainException::class);
        ProspectScope::place(['country' => 'India', 'state' => 'Gujarat', 'city' => '!!']);
    }
}
