<?php

namespace Tests\Feature;

use App\Domain\Gtm\ContactImporter;
use App\Domain\Gtm\ProspectScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Product-wise prospecting: an account belongs to one product and one customer type; the same firm can be added under another product or
 * type as a separate account; older accounts are placed under scholarclone / b2b; an import matches accounts inside the chosen scope only.
 */
class ProspectingScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // gtm_accounts as it was BEFORE this feature: one account per website and organisation.
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
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['sub_institute_id', 'domain'], 'gtm_accounts_tenant_domain_unique');
        });
        Schema::create('gtm_contacts', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('account_id');
            $t->string('full_name', 191);
            $t->string('email', 191)->nullable();
            $t->softDeletes();
        });
        Schema::create('gtm_suppressions', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('kind', 10);
            $t->string('value', 191);
        });
    }

    private function migrate(): void
    {
        (require base_path('database/migrations/2026_12_21_100000_add_email_phone_to_gtm_accounts.php'))->up();
        (require base_path('database/migrations/2026_12_13_100000_add_product_scope_to_gtm_accounts.php'))->up();
    }

    private function account(string $name, ?string $domain, ?string $product = null, ?string $model = null): int
    {
        return DB::table('gtm_accounts')->insertGetId([
            'sub_institute_id' => 1, 'name' => $name, 'domain' => $domain, 'product_key' => $product, 'customer_model' => $model,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_older_accounts_are_placed_under_scholarclone_b2b_and_running_it_twice_changes_nothing(): void
    {
        DB::table('gtm_accounts')->insert(['sub_institute_id' => 1, 'name' => 'Old school', 'domain' => 'old.example', 'created_at' => now(), 'updated_at' => now()]);

        $this->migrate();
        $this->migrate();

        $row = DB::table('gtm_accounts')->first();
        $this->assertSame(['scholarclone', 'b2b'], [$row->product_key, $row->customer_model]);
    }

    public function test_the_same_website_can_be_an_account_under_another_product_or_type_but_not_twice_in_one(): void
    {
        $this->migrate();
        $this->account('Firm', 'firm.example', 'scholarclone', 'b2b');

        $this->account('Firm', 'firm.example', 'g2g', 'b2c');       // another product and type: allowed
        $this->account('Firm', 'firm.example', 'scholarclone', 'b2g'); // another type: allowed
        $this->assertSame(3, DB::table('gtm_accounts')->where('domain', 'firm.example')->count());

        $this->expectException(QueryException::class);
        $this->account('Firm again', 'firm.example', 'scholarclone', 'b2b'); // the same scope: refused
    }

    public function test_an_import_matches_accounts_inside_the_chosen_scope_only(): void
    {
        $this->migrate();
        $this->account('Partner Co', null, 'scholarclone', 'b2b');   // same name, but a different list
        $csv = "name,company\nAsha Rao,Partner Co\n";
        $mapping = ['full_name' => 0, 'company' => 1];

        $other = (new ContactImporter())->run(1, null, $csv, $mapping, null, false, false, ['product' => 'g2g', 'model' => 'b2c']);
        $this->assertSame(1, $other['summary']['invalid'], 'the account on the ScholarClone list is not reused for a G2G partner import');

        $created = (new ContactImporter())->run(1, null, $csv, $mapping, null, true, false, ['product' => 'g2g', 'model' => 'b2c']);
        $this->assertSame(['Partner Co'], $created['accounts_to_create'], 'with "create missing accounts" it becomes a new account on the G2G list');

        $same = (new ContactImporter())->run(1, null, $csv, $mapping, null, false, false, ['product' => 'scholarclone', 'model' => 'b2b']);
        $this->assertSame(1, $same['summary']['valid'], 'inside its own list it matches the existing account');
    }

    public function test_the_customer_type_must_be_b2b_b2c_or_b2g_and_all_means_no_filter(): void
    {
        $this->assertSame(['product' => null, 'model' => null, 'region' => null], ProspectScope::fromInput('all', 'all', 1));
        $this->assertSame(['product' => null, 'model' => 'b2g', 'region' => null], ProspectScope::fromInput('', 'B2G', 1));

        $this->expectException(\DomainException::class);
        ProspectScope::fromInput('all', 'consumer', 1);
    }
}
