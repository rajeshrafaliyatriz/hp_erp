<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductMasterBackfill;
use App\Models\auth\tbluserModel;
use App\Services\Events\EventRecorder;
use App\Support\RoleKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CrmProductProductMasterLinkTest extends TestCase
{
    private const T = 1;
    private const T2 = 2;

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

        foreach ([
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables',
            '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind',
            '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields',
            '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import',
            '2026_11_29_090000_add_assessment_to_g2g_company_opportunities',
            '2026_12_01_090000_add_product_key_to_signals_runs_and_sources',
            '2026_10_14_100000_create_g2g_icp_profiles_tables',
            '2026_11_29_100000_add_date_provenance_to_g2g_research_sources',
            '2026_11_29_110000_add_modified_date_to_g2g_research_sources',
            '2026_10_13_100000_create_g2g_target_accounts_table',
            '2026_12_05_090000_create_g2g_signal_products_table',
            '2026_10_31_090000_add_research_run_configuration_scope',
            '2026_12_07_090000_add_scope_to_g2g_company_opportunities',
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

        $this->app->instance(EventRecorder::class, new class extends EventRecorder {
            public function record(string $type, int $subInstituteId, string $entityType, ?int $entityId, ?int $actorId, array $payload, ?array $metadata = null, ?string $idempotencyKey = null, ?string $correlationId = null, ?string $causationId = null, ?int $actingForId = null, ?string $occurredAt = null): int
            {
                return 1;
            }
        });

        RoleKey::flushCache();
        Cache::flush();

        $menu = fn (int $id, string $name, int $parent, string $link) => DB::table('tblmenumaster_g2g')->insert(['id' => $id, 'menu_name' => $name, 'parent_id' => $parent, 'level' => $parent === 0 ? 1 : 2, 'access_link' => $link, 'sort_order' => $id]);
        $menu(199, 'Sales & Marketing Intelligence', 0, '/module/crm');
        $menu(468, 'Products', 199, '/module/crm/sales/products');
        $menu(469, 'Services', 199, '/module/crm/sales/services');
        $menu(600, 'Product Master', 199, '/module/crm/products/master');

        DB::table('tbluserprofilemaster')->insert([
            ['id' => 1, 'name' => 'Admin', 'role_key' => 'administrator', 'sub_institute_id' => self::T],
            ['id' => 2, 'name' => 'Admin T2', 'role_key' => 'administrator', 'sub_institute_id' => self::T2],
        ]);

        foreach ([[1, self::T], [2, self::T2]] as [$profile, $tenant]) {
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $tenant, 'menu_id' => 199, 'profile_id' => $profile, 'can_view' => 1]);
            foreach ([468, 469, 600] as $menuId) {
                DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $tenant, 'menu_id' => $menuId, 'profile_id' => $profile, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1]);
            }
        }

        (new ProductMasterBackfill)->run(self::T, true);
        (new ProductMasterBackfill)->run(self::T2, true);
    }

    private function h(int $tenant = self::T, int $profile = 1): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken, '_user' => $id];
    }

    private function headers(array $h): array
    {
        unset($h['_user']);

        return $h;
    }

    public function test_lines_endpoint_returns_accessible_product_lines(): void
    {
        $h = $this->h(self::T);
        $res = $this->getJson('/api/product-master/lines', $this->headers($h));
        $res->assertStatus(200);
        $this->assertSame(1, $res->json('status'));
        $data = $res->json('data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);

        // Every item should have id, name, key
        foreach ($data as $line) {
            $this->assertArrayHasKey('id', $line);
            $this->assertArrayHasKey('name', $line);
            $this->assertArrayHasKey('key', $line);
        }
    }

    public function test_create_and_update_product_with_product_master_line(): void
    {
        $h = $this->h(self::T);
        $linesRes = $this->getJson('/api/product-master/lines', $this->headers($h));
        $lineId = (int) $linesRes->json('data.0.id');
        $this->assertGreaterThan(0, $lineId);

        // 1. Create a product linked to this line
        $createRes = $this->postJson('/api/crm/products', [
            'itemType' => 'product',
            'name' => 'Solar Inverter 5kW',
            'assignedTo' => $h['_user'],
            'unitPrice' => 1200.50,
            'productMasterId' => $lineId,
        ], $this->headers($h));

        $createRes->assertStatus(201);
        $this->assertSame(1, $createRes->json('status'));
        $productId = (int) $createRes->json('data.id');
        $this->assertSame($lineId, $createRes->json('data.productMasterId'));

        // Check DB row in crm_products
        $crmRow = DB::table('crm_products')->where('id', $productId)->first();
        $this->assertSame($lineId, (int) $crmRow->product_master_id);

        // Check ProductMasterSync linked parent_id
        $masterRow = DB::table('g2g_product_master')->where('crm_product_id', $productId)->first();
        $this->assertNotNull($masterRow);
        $this->assertSame('item', $masterRow->kind);
        $this->assertSame($lineId, (int) $masterRow->parent_id);

        // 2. Fetch single product via GET
        $getRes = $this->getJson('/api/crm/products/' . $productId, $this->headers($h));
        $getRes->assertStatus(200);
        $this->assertSame($lineId, $getRes->json('data.productMasterId'));

        // 3. Update product with new productMasterId
        $line2Id = (int) $linesRes->json('data.1.id');
        if ($line2Id > 0 && $line2Id !== $lineId) {
            $updateRes = $this->putJson('/api/crm/products/' . $productId, [
                'name' => 'Solar Inverter 5kW V2',
                'productMasterId' => $line2Id,
            ], $this->headers($h));
            $updateRes->assertStatus(200);
            $this->assertSame($line2Id, $updateRes->json('data.productMasterId'));

            $updatedMasterRow = DB::table('g2g_product_master')->where('crm_product_id', $productId)->first();
            $this->assertSame($line2Id, (int) $updatedMasterRow->parent_id);
        }

        // 4. Update with null productMasterId
        $unlinkRes = $this->putJson('/api/crm/products/' . $productId, [
            'productMasterId' => null,
        ], $this->headers($h));
        $unlinkRes->assertStatus(200);
        $this->assertNull($unlinkRes->json('data.productMasterId'));

        $unlinkedMasterRow = DB::table('g2g_product_master')->where('crm_product_id', $productId)->first();
        $this->assertNull($unlinkedMasterRow->parent_id);
    }

    public function test_rejects_inaccessible_product_master_id(): void
    {
        $h1 = $this->h(self::T);
        $h2 = $this->h(self::T2, 2);

        // Create a custom product line for Tenant 2
        $t2LineId = DB::table('g2g_product_master')->insertGetId([
            'sub_institute_id' => self::T2,
            'name' => 'Tenant 2 Exclusive Line',
            'product_key' => 't2-exclusive-line',
            'kind' => 'product',
            'status' => 'active',
            'origin' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tenant 1 attempts to create a product referencing Tenant 2's custom line
        $res = $this->postJson('/api/crm/products', [
            'itemType' => 'product',
            'name' => 'Exploit Product',
            'assignedTo' => $h1['_user'],
            'productMasterId' => $t2LineId,
        ], $this->headers($h1));

        $res->assertStatus(422);
        $this->assertSame('The selected product line is invalid or not accessible.', $res->json('message'));
    }

    public function test_service_creation_and_update_clears_product_master_id(): void
    {
        $h = $this->h(self::T);
        $linesRes = $this->getJson('/api/product-master/lines', $this->headers($h));
        $lineId = (int) $linesRes->json('data.0.id');
        $this->assertGreaterThan(0, $lineId);

        // Create a service passing a productMasterId - should be ignored/nulled
        $createRes = $this->postJson('/api/crm/products', [
            'itemType' => 'service',
            'name' => 'Consulting Service',
            'assignedTo' => $h['_user'],
            'unitPrice' => 500.00,
            'productMasterId' => $lineId,
        ], $this->headers($h));

        $createRes->assertStatus(201);
        $serviceId = (int) $createRes->json('data.id');
        $this->assertNull($createRes->json('data.productMasterId'));

        $crmRow = DB::table('crm_products')->where('id', $serviceId)->first();
        $this->assertNull($crmRow->product_master_id);

        // Attempt to update service with productMasterId - should remain null
        $updateRes = $this->putJson('/api/crm/products/' . $serviceId, [
            'name' => 'Consulting Service Pro',
            'productMasterId' => $lineId,
        ], $this->headers($h));

        $updateRes->assertStatus(200);
        $this->assertNull($updateRes->json('data.productMasterId'));

        $crmRowUpdated = DB::table('crm_products')->where('id', $serviceId)->first();
        $this->assertNull($crmRowUpdated->product_master_id);
    }
}
