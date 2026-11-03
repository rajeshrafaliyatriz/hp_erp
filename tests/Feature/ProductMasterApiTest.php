<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductMaster;
use App\Domain\ProductMaster\ProductMasterBackfill;
use App\Domain\Signals\Products\ProductCatalog;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Product Master menu row, its grant command and its read API (slice 1, task 5, Docs/agentic-crm/slices/S01-product-master.md).
 * Tables come from the real migration files and minimal fixtures on in-memory sqlite.
 */
class ProductMasterApiTest extends TestCase
{
    private const T = 1;

    private const T2 = 2;

    private const ROOT = '/module/crm';

    /** The Products screen's menu row: the Product Master has no row of its own, its rights are this row's. */
    private const PRODUCTS = '/module/crm/sales/products';

    /** The row the first version of the Product Master screen had. It was removed (2026-11-03); nothing may consult it. */
    private const OLD_LEAF = '/module/crm/product-master';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('personal_access_tokens', function ($t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('tenant_setting', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('setting_key');
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
        });
        Schema::create('tblmenumaster_g2g', function ($t) {
            $t->id();
            $t->string('menu_name');
            $t->unsignedBigInteger('parent_id')->default(0);
            $t->integer('level')->default(1);
            $t->string('page_type')->nullable();
            $t->string('access_link')->nullable();
            $t->string('icon')->nullable();
            $t->integer('status')->default(1);
            $t->integer('sort_order')->default(0);
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('menu_type')->nullable();
            $t->timestamps();
        });
        Schema::create('tblgroupwise_rights_g2g', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('menu_id');
            $t->unsignedBigInteger('profile_id');
            $t->tinyInteger('can_view')->default(0);
            $t->tinyInteger('can_add')->default(0);
            $t->tinyInteger('can_edit')->default(0);
            $t->tinyInteger('can_delete')->default(0);
            $t->tinyInteger('dashboard_right')->default(0);
            $t->tinyInteger('is_mobile')->default(0);
            $t->string('right_view')->nullable();
            $t->string('right_add')->nullable();
            $t->string('right_edit')->nullable();
            $t->string('right_delete')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        foreach (['2026_12_07_090000_create_g2g_product_master_table', '2026_12_07_090100_ensure_g2g_signal_products_table'] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        Schema::create('g2g_product_offers', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('offer_id', 50);
            $t->string('name', 191);
            $t->string('parent_product', 100)->nullable();
            $t->string('readiness_status', 100)->nullable();
        });
        Schema::create('crm_products', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('product_no', 100)->nullable();
            $t->string('name', 191);
            $t->string('sku', 100)->nullable();
            $t->string('item_type', 20)->default('product');
            $t->string('gl_account', 100)->nullable();
            $t->decimal('unit_price', 15, 2)->nullable();
            $t->string('currency', 10)->nullable();
            $t->unsignedBigInteger('tax_rate_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('g2g_company_opportunities', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('business_fit', 20)->nullable();
            $t->string('relevant_offer_id', 50)->nullable();
            $t->boolean('is_sample')->default(false);
        });
        Schema::create('crm_price_books', function ($t) {
            $t->id();
            $t->string('price_book_no', 50)->nullable();
            $t->string('name', 191);
            $t->string('currency', 10)->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('sub_institute_id');
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('crm_price_book_items', function ($t) {
            $t->id();
            $t->unsignedBigInteger('price_book_id');
            $t->unsignedBigInteger('product_id');
            $t->decimal('list_price', 15, 2);
        });
        Schema::create('crm_quotes', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('crm_quote_line_items', function ($t) {
            $t->id();
            $t->unsignedBigInteger('quote_id');
            $t->unsignedBigInteger('product_id')->nullable();
        });

        RoleKey::flushCache();
        Cache::flush();
        ProductCatalog::forgetCustom();

        // The CRM root and the profiles: an administrator and an employee in organisation 1, an administrator in organisation 2.
        DB::table('tblmenumaster_g2g')->insert(['id' => 199, 'menu_name' => 'Sales & Marketing Intelligence', 'parent_id' => 0, 'level' => 1, 'access_link' => self::ROOT, 'sort_order' => 1]);
        DB::table('tblmenumaster_g2g')->insert(['id' => 201, 'menu_name' => 'Leads', 'parent_id' => 199, 'level' => 2, 'access_link' => '/module/crm/marketing/leads', 'sort_order' => 9]);
        DB::table('tblmenumaster_g2g')->insert(['id' => 468, 'menu_name' => 'Products', 'parent_id' => 199, 'level' => 2, 'access_link' => self::PRODUCTS, 'sort_order' => 14]);
        DB::table('tblmenumaster_g2g')->insert(['id' => 469, 'menu_name' => 'Services', 'parent_id' => 199, 'level' => 2, 'access_link' => '/module/crm/sales/services', 'sort_order' => 15]);
        DB::table('tbluserprofilemaster')->insert([
            ['id' => 1, 'name' => 'Admin', 'role_key' => 'administrator', 'sub_institute_id' => self::T],
            ['id' => 2, 'name' => 'Employee', 'role_key' => 'employee', 'sub_institute_id' => self::T],
            ['id' => 3, 'name' => 'Admin', 'role_key' => 'administrator', 'sub_institute_id' => self::T2],
        ]);
        foreach ([1, 2, 3] as $profile) {
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $profile === 3 ? self::T2 : self::T, 'menu_id' => 199, 'profile_id' => $profile, 'can_view' => 1]);
        }
    }

    /** Gives a profile of an organisation rights on one menu row. */
    private function grant(int $profile, int $menuId, int $view = 1, int $add = 0, int $edit = 0, int $delete = 0, int $tenant = self::T): void
    {
        DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $tenant, 'menu_id' => $menuId, 'profile_id' => $profile, 'can_view' => $view, 'can_add' => $add, 'can_edit' => $edit, 'can_delete' => $delete]);
    }

    private function token(int $tenant, int $profile): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    /** The administrator of an organisation with full rights on the Products screen (a rights row on that menu row, as any profile needs). */
    private function admin(int $tenant = self::T, int $profile = 1): array
    {
        if (DB::table('tblgroupwise_rights_g2g')->where('menu_id', 468)->where('profile_id', $profile)->doesntExist()) {
            $this->grant($profile, 468, 1, 1, 1, 1, $tenant);
        }

        return $this->token($tenant, $profile);
    }

    private function seedProducts(): void
    {
        $offer = fn (?int $t, string $id, string $name, string $parent, string $ready) => DB::table('g2g_product_offers')->insertGetId(['sub_institute_id' => $t, 'offer_id' => $id, 'name' => $name, 'parent_product' => $parent, 'readiness_status' => $ready]);
        $offer(self::T, 'P1', 'Scholar One', 'Scholar K-12', 'Live – with client');
        $offer(self::T, 'P2', 'Scholar Core', 'Scholar K-12', 'Prototype');
        $offer(self::T, 'BUN-01', 'SME Bundle', 'Bundle', 'Proposal / concept');
        $offer(self::T2, 'Q1', 'Other organisation offer', 'Scholar K-12', 'Live – with client');
        DB::table('g2g_signal_products')->insert(['sub_institute_id' => self::T2, 'key' => 'payroll', 'label' => 'Payroll', 'business_fit' => 'payroll', 'icp_product_line' => 'payroll', 'portfolio_names' => json_encode(['Payroll']), 'status' => 'active']);
        (new ProductMasterBackfill)->run(self::T, true);
        (new ProductMasterBackfill)->run(self::T2, true);
    }

    private function row(int $tenant, string $key): ProductMaster
    {
        return ProductMaster::where('sub_institute_id', $tenant)->where('product_key', $key)->firstOrFail();
    }

    // ---- the removed leaf ----

    public function test_there_is_no_product_master_menu_row_migration_or_grant_command_any_more(): void
    {
        $this->assertSame([], glob(database_path('migrations/*product_master_menu_row*')) ?: [], 'the unreleased migration that made the sidebar entry is deleted');
        $this->assertFileDoesNotExist(app_path('Console/Commands/ProductMasterGrantCommand.php'));
        $this->assertArrayNotHasKey('product-master:grant', \Illuminate\Support\Facades\Artisan::all());
        $this->assertArrayHasKey('product-master:repair', \Illuminate\Support\Facades\Artisan::all(), 'the repair command replaces it');
        $this->assertSame(0, DB::table('tblmenumaster_g2g')->where('access_link', self::OLD_LEAF)->count(), 'nothing in this fixture creates the row');
    }

    // ---- rights on the API: they are the Products row's ----

    public function test_the_api_needs_a_token_and_a_rights_row_on_products_and_has_no_administrator_bypass(): void
    {
        $this->seedProducts();

        $this->getJson('/api/product-master')->assertStatus(401);
        $this->getJson('/api/product-master', $this->token(self::T, 2))->assertStatus(403);               // an employee: no rights row
        $this->getJson('/api/product-master', $this->token(self::T, 1))->assertStatus(403);               // an administrator before any rights row: no bypass
        $this->getJson('/api/product-master', $this->admin())->assertOk()->assertJsonPath('status', 1);
        $this->getJson('/api/product-master', $this->token(self::T2, 3))->assertStatus(403);              // organisation 2's administrator has no rights row on Products
    }

    public function test_rights_on_the_removed_leaf_or_on_services_or_on_the_crm_root_do_not_open_the_api(): void
    {
        $this->seedProducts();
        // Someone still holding a rights row on the old Product Master row (left on a host that ran the first version), and rights on Services and on the root.
        $oldLeaf = DB::table('tblmenumaster_g2g')->insertGetId(['menu_name' => 'Product Master', 'parent_id' => 199, 'level' => 2, 'access_link' => self::OLD_LEAF, 'sort_order' => 29]);
        $this->grant(1, $oldLeaf, 1, 1, 1, 1);
        $this->grant(1, 469, 1, 1, 1, 1);
        $this->grant(1, 199, 1, 1, 1, 1);

        $this->getJson('/api/product-master', $this->token(self::T, 1))->assertStatus(403);
        $this->getJson('/api/product-master/options', $this->token(self::T, 1))->assertStatus(403);
        $this->postJson('/api/product-master', ['kind' => 'product', 'name' => 'Payroll', 'key' => 'payroll'], $this->token(self::T, 1))->assertStatus(403);
    }

    public function test_each_action_follows_the_matching_right_on_the_products_row(): void
    {
        $this->seedProducts();
        $scholar = (int) $this->row(0, 'scholarclone')->id;
        $p2 = (int) $this->row(self::T, 'scholarclone/p2')->id;

        // 4 view only, 5 view + add, 6 view + edit, 7 view + delete, 8 add without view. All are administrators by role, so ProductWriter's own
        // rules (administrator for a line, the Portfolio right for an offer) pass and what is left under test is the ROUTE's right.
        foreach ([4, 5, 6, 7, 8] as $id) {
            DB::table('tbluserprofilemaster')->insert(['id' => $id, 'name' => "P{$id}", 'role_key' => 'administrator', 'sub_institute_id' => self::T]);
        }
        $this->grant(4, 468);
        $this->grant(5, 468, 1, 1);
        $this->grant(6, 468, 1, 0, 1);
        $this->grant(7, 468, 1, 0, 0, 1);
        $this->grant(8, 468, 0, 1);

        $reads = ['/api/product-master', '/api/product-master/options', '/api/product-master/meta', "/api/product-master/{$scholar}", "/api/product-master/{$scholar}/usage"];
        foreach ([4, 5, 6, 7] as $profile) {
            foreach ($reads as $url) {
                $this->getJson($url, $this->token(self::T, $profile))->assertOk();
            }
        }
        foreach ($reads as $url) {
            $this->getJson($url, $this->token(self::T, 8))->assertStatus(403);
        }

        // Create needs add; update and restore need edit; archive needs delete. The body is invalid on purpose where a pass would otherwise write:
        // a 422 means the route let the request through, a 403 that the route refused it.
        $create = fn (int $profile) => $this->postJson('/api/product-master', ['kind' => 'nonsense'], $this->token(self::T, $profile))->status();
        $update = fn (int $profile) => $this->putJson("/api/product-master/{$p2}", ['readiness' => 'Not a state'], $this->token(self::T, $profile))->status();
        $restore = fn (int $profile) => $this->postJson("/api/product-master/{$p2}/restore", [], $this->token(self::T, $profile))->status();
        $archive = fn (int $profile) => $this->postJson("/api/product-master/{$p2}/archive", [], $this->token(self::T, $profile))->status();

        $this->assertSame([403, 422, 403, 403], [$create(4), $create(5), $create(6), $create(7)], 'only the profile with the add right gets past the route');
        $this->assertNotSame(403, $update(6), 'edit may update');
        foreach ([4, 5, 7] as $profile) {
            $this->assertSame(403, $update($profile), "profile {$profile} has no edit right");
            $this->assertSame(403, $restore($profile), "profile {$profile} has no edit right");
        }
        $this->assertNotSame(403, $restore(6), 'edit may restore');
        $this->assertNotSame(403, $archive(7), 'delete may archive');
        foreach ([4, 5, 6] as $profile) {
            $this->assertSame(403, $archive($profile), "profile {$profile} has no delete right");
        }
    }

    // ---- the list ----

    public function test_the_list_nests_sub_products_under_products_with_readiness_and_counts(): void
    {
        $this->seedProducts();
        DB::table('g2g_company_opportunities')->insert(array_map(fn (array $r) => $r + ['is_sample' => 0], [
            ['sub_institute_id' => self::T, 'business_fit' => 'scholar'], ['sub_institute_id' => self::T, 'business_fit' => 'scholar'],
            ['sub_institute_id' => self::T, 'business_fit' => 'eb'], ['sub_institute_id' => self::T, 'business_fit' => 'scholar', 'is_sample' => 1],
            ['sub_institute_id' => self::T2, 'business_fit' => 'scholar'], ['sub_institute_id' => self::T, 'business_fit' => null],
        ]));
        $crm = DB::table('crm_products')->insertGetId(['sub_institute_id' => self::T, 'product_no' => 'PRD-1', 'name' => 'Scholar One (catalogue)', 'sku' => 'P1']);
        $this->row(self::T, 'scholarclone/p1')->update(['crm_product_id' => $crm]);

        $res = $this->getJson('/api/product-master', $this->admin())->assertOk();
        $products = collect($res->json('data.products'))->keyBy('key');

        $this->assertSame(['scholarclone', 'enterprise_brain', 'g2g', 'he', 'bundle'], $products->keys()->all(), "the five shared products, and not organisation 2's own 'payroll'");
        $scholar = $products['scholarclone'];
        $this->assertTrue($scholar['shared']);
        $this->assertSame('Scholar K-12', $scholar['portfolioName']);
        $this->assertSame(2, $scholar['subProductCount']);
        $this->assertSame(1, $scholar['soldInCrmCount']);
        $this->assertSame(2, $scholar['signalsFound'], "this organisation's own scholar signals only: not the sample row, not organisation 2's");
        $this->assertSame(1, $products['enterprise_brain']['signalsFound']);
        $this->assertNull($products['he']['signalsFound'], 'a product Signals has no value for shows no count');
        $this->assertSame(0, $products['g2g']['signalsFound']);

        $p1 = collect($scholar['subProducts'])->firstWhere('key', 'scholarclone/p1');
        $this->assertSame('P1', $p1['offerId']);
        $this->assertSame('Live – with client', $p1['readiness']);
        $this->assertTrue($p1['soldInCrm']);
        $this->assertSame($crm, $p1['crmProductId']);
        $p2 = collect($scholar['subProducts'])->firstWhere('key', 'scholarclone/p2');
        $this->assertFalse($p2['soldInCrm']);
        $this->assertSame(['products' => 5, 'subProducts' => 3, 'items' => 0], $res->json('data.totals'));
    }

    public function test_search_and_status_filters_narrow_the_list(): void
    {
        $this->seedProducts();
        $h = $this->admin();

        $found = $this->getJson('/api/product-master?q=core', $h)->assertOk()->json('data.products');
        $this->assertSame(['scholarclone'], array_column($found, 'key'));
        $this->assertSame(['scholarclone/p2'], array_column($found[0]['subProducts'], 'key'), 'only the matching sub-product under its product');

        $this->assertSame(['bundle'], array_column($this->getJson('/api/product-master?q=bun-01', $h)->json('data.products'), 'key'), 'the offer id is searchable');
        $this->assertSame([], $this->getJson('/api/product-master?q=zzzz', $h)->json('data.products'));

        $this->row(self::T, 'scholarclone/p2')->update(['status' => 'archived']);
        $this->assertSame(1, collect($this->getJson('/api/product-master', $h)->json('data.products'))->firstWhere('key', 'scholarclone')['subProductCount'], 'archived rows are hidden by default');
        $this->assertSame(1, collect($this->getJson('/api/product-master?status=archived', $h)->json('data.products'))->sum('subProductCount'));
        $this->assertSame(3, collect($this->getJson('/api/product-master?status=all', $h)->json('data.products'))->sum('subProductCount'));
    }

    public function test_catalogue_only_items_are_listed_separately(): void
    {
        $this->seedProducts();
        $crm = DB::table('crm_products')->insertGetId(['sub_institute_id' => self::T, 'product_no' => 'SRV-1', 'name' => 'Training day', 'item_type' => 'service']);
        (new ProductMasterBackfill)->run(self::T, true);

        $items = $this->getJson('/api/product-master', $this->admin())->assertOk()->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('Training day', $items[0]['name']);
        $this->assertSame($crm, $items[0]['crmProductId']);
    }

    public function test_the_options_list_is_flat_and_labelled(): void
    {
        $this->seedProducts();
        $opts = collect($this->getJson('/api/product-master/options', $this->admin())->assertOk()->json('data'));

        $this->assertSame('ScholarClone › Scholar One', $opts->firstWhere('key', 'scholarclone/p1')['label']);
        $this->assertSame('ScholarClone', $opts->firstWhere('key', 'scholarclone')['label']);
        $this->assertNull($opts->firstWhere('key', 'payroll'), "another organisation's own product is not offered");
    }

    // ---- one record, tenant isolation ----

    public function test_show_returns_the_record_with_its_offer_and_catalogue_item_and_404_for_other_organisations(): void
    {
        $this->seedProducts();
        $h = $this->admin();
        $other = DB::table('crm_products')->insertGetId(['sub_institute_id' => self::T2, 'product_no' => 'X', 'name' => "Other organisation's catalogue item"]);
        $mine = DB::table('crm_products')->insertGetId(['sub_institute_id' => self::T, 'product_no' => 'PRD-1', 'name' => 'Scholar One (catalogue)', 'sku' => 'P1', 'unit_price' => 1500, 'currency' => 'INR', 'is_active' => 1]);
        $p1 = $this->row(self::T, 'scholarclone/p1');
        $p1->update(['crm_product_id' => $mine]);
        $p2 = $this->row(self::T, 'scholarclone/p2');
        $p2->update(['crm_product_id' => $other]);

        $r = $this->getJson('/api/product-master/' . $p1->id, $h)->assertOk()->json('data');
        $this->assertSame('Scholar One', $r['name']);
        $this->assertSame('ScholarClone', $r['parent']['name']);
        $this->assertSame('P1', $r['offer']['offerId']);
        $this->assertSame('Live – with client', $r['offer']['readiness']);
        $this->assertSame('P1', $r['crmProduct']['sku']);
        $this->assertEquals(1500.0, $r['crmProduct']['unitPrice']);

        $this->assertNull($this->getJson('/api/product-master/' . $p2->id, $h)->assertOk()->json('data.crmProduct'), "a catalogue pointer into another organisation's row is not leaked");

        $product = $this->getJson('/api/product-master/' . $this->row(0, 'scholarclone')->id, $h)->assertOk()->json('data');
        $this->assertTrue($product['shared']);
        $this->assertCount(2, $product['subProducts']);

        $this->getJson('/api/product-master/' . $this->row(self::T2, 'payroll')->id, $h)->assertStatus(404);              // another organisation's own product
        $this->getJson('/api/product-master/' . $this->row(self::T2, 'scholarclone/q1')->id, $h)->assertStatus(404);        // another organisation's sub-product
        $this->getJson('/api/product-master/999999', $h)->assertStatus(404);
        $this->getJson('/api/product-master/abc', $h)->assertStatus(404);
    }

    // ---- usage ----

    public function test_usage_reports_signals_price_books_and_quotes_for_this_organisation_only(): void
    {
        $this->seedProducts();
        $h = $this->admin();
        DB::table('g2g_company_opportunities')->insert([
            ['sub_institute_id' => self::T, 'business_fit' => 'scholar', 'relevant_offer_id' => 'P1'], ['sub_institute_id' => self::T, 'business_fit' => 'scholar', 'relevant_offer_id' => 'P1'],
            ['sub_institute_id' => self::T, 'business_fit' => 'scholar', 'relevant_offer_id' => 'P2'], ['sub_institute_id' => self::T2, 'business_fit' => 'scholar', 'relevant_offer_id' => 'P1'],
        ]);
        $crm = DB::table('crm_products')->insertGetId(['sub_institute_id' => self::T, 'name' => 'Scholar One (catalogue)', 'unit_price' => 1500, 'currency' => 'INR']);
        $p1 = $this->row(self::T, 'scholarclone/p1');
        $p1->update(['crm_product_id' => $crm]);
        $book = DB::table('crm_price_books')->insertGetId(['price_book_no' => 'PB-1', 'name' => 'Retail 2027', 'currency' => 'INR', 'sub_institute_id' => self::T]);
        $gone = DB::table('crm_price_books')->insertGetId(['price_book_no' => 'PB-2', 'name' => 'Deleted book', 'sub_institute_id' => self::T, 'deleted_at' => now()]);
        $foreign = DB::table('crm_price_books')->insertGetId(['price_book_no' => 'PB-3', 'name' => 'Other organisation', 'sub_institute_id' => self::T2]);
        foreach ([[$book, 1200], [$gone, 1], [$foreign, 2]] as [$b, $price]) {
            DB::table('crm_price_book_items')->insert(['price_book_id' => $b, 'product_id' => $crm, 'list_price' => $price]);
        }
        $q = DB::table('crm_quotes')->insertGetId(['sub_institute_id' => self::T]);
        DB::table('crm_quote_line_items')->insert([['quote_id' => $q, 'product_id' => $crm], ['quote_id' => $q, 'product_id' => $crm]]);

        $u = $this->getJson('/api/product-master/' . $p1->id . '/usage', $h)->assertOk()->json('data');
        $this->assertSame(2, $u['signals'], 'signals that matched offer P1, this organisation only');
        $this->assertCount(1, $u['priceBooks'], 'a deleted price book and another organisation\'s book are not listed');
        $this->assertSame('Retail 2027', $u['priceBooks'][0]['name']);
        $this->assertEquals(1200.0, $u['priceBooks'][0]['listPrice']);
        $this->assertSame(1, $u['quotes'], 'one quote, counted once although it has two lines');
        $this->assertEquals(1500.0, $u['basePrice']);

        $product = $this->getJson('/api/product-master/' . $this->row(0, 'scholarclone')->id . '/usage', $h)->assertOk()->json('data');
        $this->assertSame(3, $product['signals'], 'signals stored for the product: this organisation only');
        $this->assertSame([], $product['priceBooks']);
        $this->assertNull($product['basePrice']);

        $noCatalogue = $this->getJson('/api/product-master/' . $this->row(self::T, 'scholarclone/p2')->id . '/usage', $h)->assertOk()->json('data');
        $this->assertSame([], $noCatalogue['priceBooks'], 'empty state: not in any price book yet');
        $this->assertSame(0, $noCatalogue['quotes']);

        $this->getJson('/api/product-master/' . $this->row(self::T2, 'payroll')->id . '/usage', $h)->assertStatus(404);
    }
}
