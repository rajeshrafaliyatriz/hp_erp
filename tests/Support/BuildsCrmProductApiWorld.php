<?php

namespace Tests\Support;

use App\Domain\ProductMaster\ProductMasterBackfill;
use App\Domain\Signals\Products\ProductCatalog;
use App\Models\auth\tbluserModel;
use App\Services\Events\EventRecorder;
use App\Support\RoleKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The database and the people the product API tests run on (S15 P2-B2), on in-memory sqlite: the CRM catalogue (`crm_products`, with its product line column), the Product
 * Portfolio (the real offer migrations), the Product Master (its real migration and the shared built-in products) and two organisations, each with an administrator that
 * holds every right on Products and Services. It is the world tests/Feature/ProductMasterSyncTest.php builds in its own setUp, plus one column that file leaves out:
 * `crm_products.product_master_id`, the Product (level 1) an item sits under, which is what a mirrored rename and a mirrored product change write to.
 *
 * The class that uses it defines `T` (1) and `T2` (2), the two organisations, and calls buildCrmProductApiWorld() from setUp() after parent::setUp().
 */
trait BuildsCrmProductApiWorld
{
    protected function buildCrmProductApiWorld(): void
    {
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
        foreach ([
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables', '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind', '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields', '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_11_29_090000_add_assessment_to_g2g_company_opportunities',
            '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', '2026_10_14_100000_create_g2g_icp_profiles_tables',
            '2026_11_29_100000_add_date_provenance_to_g2g_research_sources', '2026_11_29_110000_add_modified_date_to_g2g_research_sources',
            '2026_10_13_100000_create_g2g_target_accounts_table', '2026_12_05_090000_create_g2g_signal_products_table',
            '2026_10_31_090000_add_research_run_configuration_scope', '2026_12_07_090000_add_scope_to_g2g_company_opportunities',
            '2026_12_07_090000_create_g2g_product_master_table',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        Schema::create('crm_products', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('product_no', 100)->nullable();
            $t->string('item_type', 20)->default('product');
            $t->string('name', 191);
            $t->string('sku', 100)->nullable();
            $t->string('category', 100)->nullable();
            $t->string('gl_account', 100)->nullable();
            $t->text('description')->nullable();
            $t->decimal('unit_price', 15, 2)->nullable();
            $t->decimal('cost_price', 15, 2)->nullable();
            $t->decimal('commission_rate', 7, 3)->nullable();
            $t->string('currency', 10)->nullable();
            $t->unsignedBigInteger('tax_rate_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('vendor')->nullable();
            $t->decimal('qty_in_stock', 15, 2)->nullable();
            $t->decimal('qty_in_demand', 15, 2)->nullable();
            $t->integer('reorder_level')->nullable();
            $t->decimal('weight', 15, 2)->nullable();
            $t->string('usage_unit')->nullable();
            $t->string('image')->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->unsignedBigInteger('product_master_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->unsignedBigInteger('deleted_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('crm_tax_rates', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('name');
            $t->decimal('percentage', 7, 3)->default(0);
            $t->boolean('is_active')->default(true);
        });

        // The history of a CRM change is not under test here; a stand-in keeps the tests off the event store.
        $this->app->instance(EventRecorder::class, new class extends EventRecorder {
            public function record(string $type, int $subInstituteId, string $entityType, ?int $entityId, ?int $actorId, array $payload, ?array $metadata = null, ?string $idempotencyKey = null, ?string $correlationId = null, ?string $causationId = null, ?int $actingForId = null, ?string $occurredAt = null): int
            {
                return 1;
            }
        });

        RoleKey::flushCache();
        Cache::flush();
        ProductCatalog::forgetCustom();

        $menu = fn (int $id, string $name, int $parent, string $link) => DB::table('tblmenumaster_g2g')->insert(['id' => $id, 'menu_name' => $name, 'parent_id' => $parent, 'level' => $parent === 0 ? 1 : 2, 'access_link' => $link, 'sort_order' => $id]);
        $menu(199, 'Sales & Marketing Intelligence', 0, '/module/crm');
        $menu(468, 'Products', 199, '/module/crm/sales/products');
        $menu(469, 'Services', 199, '/module/crm/sales/services');
        DB::table('tbluserprofilemaster')->insert([
            ['id' => 1, 'name' => 'Admin', 'role_key' => 'administrator', 'sub_institute_id' => self::T],
            ['id' => 2, 'name' => 'Employee', 'role_key' => 'employee', 'sub_institute_id' => self::T],
            ['id' => 3, 'name' => 'Admin', 'role_key' => 'administrator', 'sub_institute_id' => self::T2],
        ]);
        // Full rights on Products and Services for everyone in the fixture; view on the CRM root.
        foreach ([[1, self::T], [2, self::T], [3, self::T2]] as [$profile, $tenant]) {
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $tenant, 'menu_id' => 199, 'profile_id' => $profile, 'can_view' => 1]);
            foreach ([468, 469] as $menuId) {
                DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $tenant, 'menu_id' => $menuId, 'profile_id' => $profile, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1]);
            }
        }

        // The built-in products every sub-product hangs under. Since the isolation (S15 follow-up, 24 Nov 2026) they are NOT shared: each of the two organisations of the
        // fixture is an owner of the built-ins (config product_isolation.builtin_owner_tenants) and gets ITS OWN copy of them, with the same keys. A test of the old sharing
        // (a row of organisation 0 that every organisation reads) is a test of something that no longer exists.
        config(['product_isolation.builtin_owner_tenants' => [self::T, self::T2]]);
        (new ProductMasterBackfill)->run(self::T, true);
        (new ProductMasterBackfill)->run(self::T2, true);
    }

    // ---- people ----

    /** @return array<string, mixed> a bearer token for a new user of one organisation (profile 1 is organisation 1's administrator, 3 organisation 2's) */
    protected function h(int $tenant = self::T, int $profile = 1): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken, '_user' => $id];
    }

    /** @param array<string, mixed> $h @return array<string, mixed> */
    protected function headers(array $h): array
    {
        unset($h['_user']);

        return $h;
    }

    // ---- rows made through the real doors ----

    /** Creates a catalogue item through the real CRM API and returns its id. */
    protected function crmCreate(string $name = 'Starter kit', string $type = 'product', int $tenant = self::T, int $profile = 1): int
    {
        $h = $this->h($tenant, $profile);

        return (int) $this->postJson('/api/crm/products', ['itemType' => $type, 'name' => $name, 'assignedTo' => $h['_user']], $this->headers($h))->assertStatus(201)->json('data.id');
    }

    /** Creates a Portfolio offer through the real API and returns its row id. */
    protected function offerCreate(string $offerId = 'P9', string $name = 'Scholar Nine', string $group = 'Scholar K-12', int $tenant = self::T): int
    {
        return (int) $this->postJson('/api/portfolio/offers', ['offer_id' => $offerId, 'name' => $name, 'parent_product' => $group, 'readiness_status' => 'Prototype'], $this->headers($this->h($tenant, $tenant === self::T ? 1 : 3)))
            ->assertStatus(201)->json('data.id');
    }

    protected function masterOfCrm(int $crmId, int $tenant = self::T): ?object
    {
        return DB::table('g2g_product_master')->where('sub_institute_id', $tenant)->where('crm_product_id', $crmId)->first();
    }

    protected function masterOfOffer(int $offerRowId, int $tenant = self::T): ?object
    {
        return DB::table('g2g_product_master')->where('sub_institute_id', $tenant)->where('offer_row_id', $offerRowId)->first();
    }

    protected function masterByKey(string $key, int $tenant = self::T): ?object
    {
        return DB::table('g2g_product_master')->where('sub_institute_id', $tenant)->where('product_key', $key)->first();
    }

    /** The id of a built-in Product of an organisation (every organisation of the fixture owns its own copy of them: nothing is shared any more). The name is the old one. */
    protected function sharedId(string $key, int $tenant = self::T): int
    {
        return (int) DB::table('g2g_product_master')->where('sub_institute_id', $tenant)->where('product_key', $key)->value('id');
    }

    /**
     * One Sub-product that is sold through the CRM, the way one record is meant to look: the Portfolio offer, the catalogue item and ONE master row that holds both
     * pointers, all three with the same name and the same Product. Nothing here is wrong, so the checker finds nothing to say about it.
     *
     * @return array{offer: int, crm: int, master: int, product: int}
     */
    protected function soldSubProduct(string $offerId = 'P9', string $name = 'Scholar Nine', string $group = 'Scholar K-12', string $productKey = 'scholarclone', int $tenant = self::T): array
    {
        $offer = $this->offerCreate($offerId, $name, $group, $tenant);
        $product = $this->sharedId($productKey, $tenant);
        $crm = (int) DB::table('crm_products')->insertGetId([
            'sub_institute_id' => $tenant, 'product_no' => 'PRD-' . strtoupper($offerId) . 'X' . $tenant, 'item_type' => 'product', 'name' => $name, 'is_active' => 1,
            'product_master_id' => $product, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('g2g_product_master')->where('sub_institute_id', $tenant)->where('offer_row_id', $offer)->update(['crm_product_id' => $crm]);

        return ['offer' => $offer, 'crm' => $crm, 'master' => (int) $this->masterOfOffer($offer, $tenant)->id, 'product' => $product];
    }
}
