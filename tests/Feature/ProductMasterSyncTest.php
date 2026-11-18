<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductLinks;
use App\Domain\ProductMaster\ProductMaster;
use App\Domain\ProductMaster\ProductMasterBackfill;
use App\Domain\ProductMaster\ProductMasterSync;
use App\Domain\Signals\Products\ProductCatalog;
use App\Models\auth\tbluserModel;
use App\Services\Events\EventRecorder;
use App\Support\RoleKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Product Master follows the three stores that own the products (slice 1, Docs/agentic-crm/slices/S01-product-master.md), and the extra columns
 * the Products screen reads. Everything here goes through the real routes and controllers on in-memory sqlite: the existing CRM Products API
 * (/api/crm/products*), the Portfolio offers API (/api/portfolio/offers*) and the custom product API (/api/signals/icp/products*).
 */
class ProductMasterSyncTest extends TestCase
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
        Schema::create('crm_opportunity_products', function ($t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
        });
        Schema::create('crm_quote_line_items', function ($t) {
            $t->id();
            $t->unsignedBigInteger('product_id')->nullable();
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
        // Full rights on Products and Services for everyone in the fixture (the rights matrix is tested in ProductMasterApiTest); view on the CRM root.
        foreach ([[1, self::T], [2, self::T], [3, self::T2]] as [$profile, $tenant]) {
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $tenant, 'menu_id' => 199, 'profile_id' => $profile, 'can_view' => 1]);
            foreach ([468, 469] as $menuId) {
                DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $tenant, 'menu_id' => $menuId, 'profile_id' => $profile, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1]);
            }
        }

        // The shared built-in products (organisation 0) every sub-product hangs under.
        (new ProductMasterBackfill)->run(self::T, true);
        (new ProductMasterBackfill)->run(self::T2, true);
    }

    // ---- helpers ----

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

    /** Creates a catalogue item through the real CRM API and returns its id. */
    private function crmCreate(string $name = 'Starter kit', string $type = 'product', int $tenant = self::T, int $profile = 1): int
    {
        $h = $this->h($tenant, $profile);

        return (int) $this->postJson('/api/crm/products', ['itemType' => $type, 'name' => $name, 'assignedTo' => $h['_user']], $this->headers($h))->assertStatus(201)->json('data.id');
    }

    private function masterOfCrm(int $crmId, int $tenant = self::T): ?object
    {
        return DB::table('g2g_product_master')->where('sub_institute_id', $tenant)->where('crm_product_id', $crmId)->first();
    }

    private function masterOfOffer(int $offerRowId, int $tenant = self::T): ?object
    {
        return DB::table('g2g_product_master')->where('sub_institute_id', $tenant)->where('offer_row_id', $offerRowId)->first();
    }

    private function masterByKey(string $key, int $tenant = self::T): ?object
    {
        return DB::table('g2g_product_master')->where('sub_institute_id', $tenant)->where('product_key', $key)->first();
    }

    private function sharedId(string $key): int
    {
        return (int) DB::table('g2g_product_master')->where('sub_institute_id', 0)->where('product_key', $key)->value('id');
    }

    /** Creates a Portfolio offer through the real API and returns its row id. */
    private function offerCreate(string $offerId = 'P9', string $name = 'Scholar Nine', string $group = 'Scholar K-12', int $tenant = self::T): int
    {
        return (int) $this->postJson('/api/portfolio/offers', ['offer_id' => $offerId, 'name' => $name, 'parent_product' => $group, 'readiness_status' => 'Prototype'], $this->headers($this->h($tenant, $tenant === self::T ? 1 : 3)))
            ->assertStatus(201)->json('data.id');
    }

    private function dump(): array
    {
        return DB::table('g2g_product_master')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    /** The master table without the columns that legitimately differ between two ways of making the same row. */
    private function shape(int $tenant): array
    {
        return DB::table('g2g_product_master')->where('sub_institute_id', $tenant)->orderBy('product_key')->get()
            ->map(fn ($r) => array_diff_key((array) $r, array_flip(['id', 'created_at', 'updated_at', 'created_by', 'updated_by', 'offer_row_id', 'crm_product_id'])) + ['has_offer' => $r->offer_row_id !== null, 'has_crm' => $r->crm_product_id !== null])->all();
    }

    // ---- the CRM catalogue ----

    public function test_a_product_created_through_the_crm_api_gets_one_catalogue_row_in_the_master(): void
    {
        $id = $this->crmCreate('Starter kit');

        $row = $this->masterOfCrm($id);
        $this->assertNotNull($row, 'the master follows the catalogue');
        $this->assertSame('item', $row->kind);
        $this->assertSame('crm', $row->origin);
        $this->assertSame('Starter kit', $row->name);
        $this->assertSame('active', $row->status);
        $this->assertSame(self::T, (int) $row->sub_institute_id);
        $this->assertNull($row->parent_id);
        $this->assertSame(ProductMasterSync::catalogueKey(DB::table('crm_products')->where('id', $id)->value('product_no'), $id), $row->product_key);
        $this->assertMatchesRegularExpression('#^crm/prd-[a-z0-9]{8}$#', $row->product_key);
        $this->assertSame(1, DB::table('g2g_product_master')->where('crm_product_id', $id)->count());
    }

    public function test_a_service_gets_a_row_too_and_the_caller_still_gets_exactly_its_own_answer(): void
    {
        $res = $this->postJson('/api/crm/products', ['itemType' => 'service', 'name' => 'Training day', 'assignedTo' => 1], $this->headers($this->h()))->assertStatus(201);

        $this->assertSame('Training day', $res->json('data.name'));
        $this->assertSame('service', $res->json('data.itemType'));
        $this->assertNotNull($this->masterOfCrm((int) $res->json('data.id')));
        // The answer carries the new columns, empty because this item is tied to no sub-product.
        $this->assertNull($res->json('data.productLine'));
    }

    public function test_editing_renames_the_catalogue_item_but_never_the_name_of_a_sub_product_that_uses_it(): void
    {
        $id = $this->crmCreate('Starter kit');
        $this->putJson("/api/crm/products/{$id}", ['name' => 'Starter kit 2'], $this->headers($this->h()))->assertOk();
        $this->assertSame('Starter kit 2', $this->masterOfCrm($id)->name);

        // A sub-product sold through this item: its name is the Portfolio offer's, not the catalogue item's.
        $offer = $this->offerCreate('P9', 'Scholar Nine');
        DB::table('g2g_product_master')->where('kind', 'item')->where('crm_product_id', $id)->delete();   // the item row folds into the sub-product row
        DB::table('g2g_product_master')->where('offer_row_id', $offer)->update(['crm_product_id' => $id]);
        $this->putJson("/api/crm/products/{$id}", ['name' => 'Renamed in the catalogue'], $this->headers($this->h()))->assertOk();

        $this->assertSame('Scholar Nine', $this->masterOfOffer($offer)->name);
        $this->assertSame(1, DB::table('g2g_product_master')->where('crm_product_id', $id)->count(), 'no second row for the same item');
    }

    public function test_deleting_archives_the_item_row_and_the_recycle_bin_brings_it_back(): void
    {
        $id = $this->crmCreate('Starter kit');
        $h = $this->headers($this->h());

        $this->deleteJson("/api/crm/products/{$id}", [], $h)->assertOk();
        $this->assertSame('archived', $this->masterOfCrm($id)->status);

        $this->postJson("/api/crm/recycle-bin/products/{$id}/restore", [], $h)->assertOk();
        $this->assertSame('active', $this->masterOfCrm($id)->status, 'restored from the Recycle Bin: the row follows');

        $this->deleteJson("/api/crm/products/{$id}", [], $h)->assertOk();
        $this->deleteJson("/api/crm/recycle-bin/products/{$id}", [], $h)->assertOk();
        $this->assertSame('archived', $this->masterOfCrm($id)->status, 'deleted for good: the item row stays archived, as history');
    }

    public function test_a_row_a_person_archived_on_purpose_stays_archived_when_the_catalogue_item_is_edited(): void
    {
        $id = $this->crmCreate('Starter kit');
        DB::table('g2g_product_master')->where('crm_product_id', $id)->update(['status' => 'archived']);

        $this->putJson("/api/crm/products/{$id}", ['name' => 'Starter kit v2'], $this->headers($this->h()))->assertOk();

        $row = $this->masterOfCrm($id);
        $this->assertSame('archived', $row->status);
        $this->assertSame('Starter kit v2', $row->name, 'the name still follows');
    }

    public function test_bulk_delete_import_and_merge_keep_the_master_in_step(): void
    {
        $h = $this->headers($this->h());
        $a = $this->crmCreate('Item A');
        $b = $this->crmCreate('Item B');
        $c = $this->crmCreate('Item C');

        $this->postJson('/api/crm/products/bulk/delete', ['itemType' => 'product', 'ids' => [$a, $b]], $h)->assertOk();
        $this->assertSame(['archived', 'archived', 'active'], [$this->masterOfCrm($a)->status, $this->masterOfCrm($b)->status, $this->masterOfCrm($c)->status]);

        $this->postJson('/api/crm/products/import', ['itemType' => 'product', 'rows' => [['name' => 'Imported 1', 'assignedTo' => 1], ['name' => 'Imported 2', 'assignedTo' => 1], ['name' => '', 'assignedTo' => 1]]], $h)->assertOk()->assertJsonPath('data.created', 2);
        $imported = DB::table('crm_products')->whereIn('name', ['Imported 1', 'Imported 2'])->pluck('id')->all();
        $this->assertCount(2, $imported);
        foreach ($imported as $crmId) {
            $this->assertSame('item', $this->masterOfCrm((int) $crmId)->kind);
        }

        $d = $this->crmCreate('Item D');
        $this->postJson('/api/crm/products/merge', ['survivorId' => $c, 'duplicateIds' => [$d]], $h)->assertOk();
        $this->assertSame(['active', 'archived'], [$this->masterOfCrm($c)->status, $this->masterOfCrm($d)->status], 'the merged-away duplicate leaves the master, the survivor stays');
    }

    public function test_a_second_sync_of_the_same_item_changes_nothing_and_makes_no_second_row(): void
    {
        $id = $this->crmCreate('Starter kit');
        $before = $this->dump();

        $sync = new ProductMasterSync;
        foreach ([ProductMasterSync::SAVED, ProductMasterSync::CREATED, ProductMasterSync::RESTORED, ProductMasterSync::SAVED] as $event) {
            $this->assertSame(ProductMasterSync::ACT_UNCHANGED, $sync->catalogueItem(self::T, $id, $event));
        }
        ProductMasterSync::throughCatalogueItems(self::T, [$id, $id], ProductMasterSync::SAVED);

        $this->assertSame($before, $this->dump(), 'nothing was written, not even updated_at');
    }

    public function test_an_item_number_that_another_row_already_uses_as_a_key_falls_back_to_the_id_and_then_skips_with_a_reason(): void
    {
        $other = $this->crmCreate('First');
        $no = DB::table('crm_products')->where('id', $other)->value('product_no');
        // A second catalogue item with the same item number (the column is not unique).
        $dup = DB::table('crm_products')->insertGetId(['sub_institute_id' => self::T, 'product_no' => $no, 'name' => 'Same number', 'item_type' => 'product']);

        $sync = new ProductMasterSync;
        $this->assertSame(ProductMasterSync::ACT_CREATED, $sync->catalogueItem(self::T, $dup));
        $this->assertSame("crm/{$dup}", $this->masterOfCrm($dup)->product_key);

        // Now 'crm/<id>' is taken as well, by a row made by hand for another item.
        $third = DB::table('crm_products')->insertGetId(['sub_institute_id' => self::T, 'product_no' => $no, 'name' => 'Third', 'item_type' => 'product']);
        DB::table('g2g_product_master')->insert(['sub_institute_id' => self::T, 'product_key' => "crm/{$third}", 'kind' => 'item', 'name' => 'Squatter', 'status' => 'active', 'origin' => 'manual']);
        $this->assertSame(ProductMasterSync::ACT_SKIPPED, $sync->catalogueItem(self::T, $third));
        $this->assertNull($this->masterOfCrm($third));
        $this->assertCount(1, $sync->issues());
    }

    public function test_one_organisation_never_reads_or_writes_another_organisations_catalogue_rows(): void
    {
        $theirs = $this->crmCreate('Their kit', 'product', self::T2, 3);
        $this->assertNotNull($this->masterOfCrm($theirs, self::T2));
        $this->assertNull($this->masterOfCrm($theirs, self::T), 'created under their organisation only');

        // Asking organisation 1 to sync organisation 2's item makes nothing and touches nothing.
        $before = $this->dump();
        $this->assertSame(ProductMasterSync::ACT_UNCHANGED, (new ProductMasterSync)->catalogueItem(self::T, $theirs));
        $this->assertSame($before, $this->dump());

        // And organisation 1 deleting its own item leaves organisation 2's row alone.
        $mine = $this->crmCreate('My kit');
        $this->deleteJson("/api/crm/products/{$mine}", [], $this->headers($this->h()))->assertOk();
        $this->assertSame('active', $this->masterOfCrm($theirs, self::T2)->status);
        $this->assertSame(1, DB::table('g2g_product_master')->where('sub_institute_id', self::T2)->where('kind', 'item')->count());
    }

    // ---- the Product Portfolio ----

    public function test_an_offer_created_through_the_portfolio_api_gets_a_sub_product_row_under_its_product(): void
    {
        $offer = $this->offerCreate('P9', 'Scholar Nine');

        $row = $this->masterOfOffer($offer);
        $this->assertNotNull($row);
        $this->assertSame('sub_product', $row->kind);
        $this->assertSame('portfolio', $row->origin);
        $this->assertSame('scholarclone/p9', $row->product_key);
        $this->assertSame($this->sharedId('scholarclone'), (int) $row->parent_id);
        $this->assertSame('Scholar Nine', $row->name);
        $this->assertSame(self::T, (int) $row->sub_institute_id);
        $this->assertSame(1, DB::table('g2g_product_master')->where('offer_row_id', $offer)->count());
    }

    public function test_editing_an_offer_renames_the_row_and_moving_it_to_another_product_moves_the_row_and_its_key(): void
    {
        $offer = $this->offerCreate('P9', 'Scholar Nine');
        $h = $this->headers($this->h());

        $this->putJson("/api/portfolio/offers/{$offer}", ['name' => 'Scholar Nine Plus'], $h)->assertOk();
        $this->assertSame('Scholar Nine Plus', $this->masterOfOffer($offer)->name);

        $this->putJson("/api/portfolio/offers/{$offer}", ['parent_product' => 'EB'], $h)->assertOk();
        $row = $this->masterOfOffer($offer);
        $this->assertSame($this->sharedId('enterprise_brain'), (int) $row->parent_id);
        $this->assertSame('enterprise_brain/p9', $row->product_key);
        $this->assertSame(1, DB::table('g2g_product_master')->where('offer_row_id', $offer)->count());
    }

    public function test_deleting_an_offer_archives_its_row_and_creating_the_same_id_again_brings_the_same_row_back(): void
    {
        $offer = $this->offerCreate('P9', 'Scholar Nine');
        $rowId = (int) $this->masterOfOffer($offer)->id;

        $this->deleteJson("/api/portfolio/offers/{$offer}", [], $this->headers($this->h()))->assertOk();
        $row = DB::table('g2g_product_master')->where('id', $rowId)->first();
        $this->assertSame('archived', $row->status);
        $this->assertNull($row->offer_row_id, 'the offer is gone, so the pointer is cleared');

        $again = $this->offerCreate('P9', 'Scholar Nine again');
        $revived = $this->masterOfOffer($again);
        $this->assertSame($rowId, (int) $revived->id, 'one row for one key, however many times the offer is deleted and made again');
        $this->assertSame('active', $revived->status);
        $this->assertSame('Scholar Nine again', $revived->name);
        $this->assertSame(1, DB::table('g2g_product_master')->where('product_key', 'scholarclone/p9')->count());
    }

    public function test_a_deleted_offer_that_is_still_sold_in_the_crm_carries_on_as_a_catalogue_item(): void
    {
        $offer = $this->offerCreate('P9', 'Scholar Nine');
        $crm = $this->crmCreate('Scholar Nine (catalogue)');
        // The sub-product is sold through this item: the item's own row folds into the sub-product's.
        DB::table('g2g_product_master')->where('kind', 'item')->where('crm_product_id', $crm)->delete();
        DB::table('g2g_product_master')->where('offer_row_id', $offer)->update(['crm_product_id' => $crm]);

        $this->deleteJson("/api/portfolio/offers/{$offer}", [], $this->headers($this->h()))->assertOk();

        $row = $this->masterOfCrm($crm);
        $this->assertSame('item', $row->kind);
        $this->assertSame('active', $row->status);
        $this->assertNull($row->parent_id);
        $this->assertNull($row->offer_row_id);
        $this->assertSame('crm', $row->origin);
        $this->assertMatchesRegularExpression('#^crm/prd-#', $row->product_key);
        $this->assertSame(1, DB::table('g2g_product_master')->where('crm_product_id', $crm)->count());
    }

    public function test_a_sub_product_a_person_archived_stays_archived_when_its_offer_is_edited(): void
    {
        $offer = $this->offerCreate('P9', 'Scholar Nine');
        DB::table('g2g_product_master')->where('offer_row_id', $offer)->update(['status' => 'archived']);

        $this->putJson("/api/portfolio/offers/{$offer}", ['name' => 'Scholar Nine v2'], $this->headers($this->h()))->assertOk();

        $row = $this->masterOfOffer($offer);
        $this->assertSame('archived', $row->status);
        $this->assertSame('Scholar Nine v2', $row->name);
    }

    public function test_an_offer_whose_product_group_has_no_master_product_is_skipped_with_a_reason_and_the_offer_is_still_saved(): void
    {
        $id = DB::table('g2g_product_offers')->insertGetId(['sub_institute_id' => self::T, 'offer_id' => 'Z1', 'name' => 'Orphan', 'parent_product' => 'No such group', 'readiness_status' => 'Prototype']);
        $sync = new ProductMasterSync;

        $this->assertSame(ProductMasterSync::ACT_SKIPPED, $sync->offer(self::T, $id));
        $this->assertNull($this->masterOfOffer($id));
        $this->assertStringContainsString("no product named 'No such group'", $sync->issues()[0]);
    }

    public function test_two_offers_with_the_same_id_in_one_organisation_do_not_make_two_rows(): void
    {
        $first = DB::table('g2g_product_offers')->insertGetId(['sub_institute_id' => self::T, 'offer_id' => 'D1', 'name' => 'First', 'parent_product' => 'G2G', 'readiness_status' => 'Prototype']);
        $second = DB::table('g2g_product_offers')->insertGetId(['sub_institute_id' => self::T, 'offer_id' => 'D1', 'name' => 'Second', 'parent_product' => 'G2G', 'readiness_status' => 'Prototype']);
        $sync = new ProductMasterSync;

        $this->assertSame(ProductMasterSync::ACT_CREATED, $sync->offer(self::T, $first));
        $this->assertSame(ProductMasterSync::ACT_SKIPPED, $sync->offer(self::T, $second));
        $this->assertSame(1, DB::table('g2g_product_master')->where('product_key', 'g2g/d1')->count());
        $this->assertNull($this->masterOfOffer($second));
    }

    public function test_an_import_that_reports_only_counts_is_followed_by_syncing_every_offer_of_the_organisation(): void
    {
        foreach (['I1', 'I2', 'I3'] as $n => $offerId) {
            DB::table('g2g_product_offers')->insert(['sub_institute_id' => self::T, 'offer_id' => $offerId, 'name' => "Imported {$n}", 'parent_product' => 'EB', 'readiness_status' => 'Prototype']);
        }
        DB::table('g2g_product_offers')->insert(['sub_institute_id' => self::T2, 'offer_id' => 'I9', 'name' => 'Other organisation', 'parent_product' => 'EB', 'readiness_status' => 'Prototype']);

        ProductMasterSync::throughOffersOfTenant(self::T);

        $this->assertSame(['enterprise_brain/i1', 'enterprise_brain/i2', 'enterprise_brain/i3'], DB::table('g2g_product_master')->where('sub_institute_id', self::T)->where('kind', 'sub_product')->orderBy('product_key')->pluck('product_key')->all());
        $this->assertSame(0, DB::table('g2g_product_master')->where('sub_institute_id', self::T2)->where('kind', 'sub_product')->count(), 'only the organisation that imported');
    }

    public function test_one_organisation_never_reads_or_writes_another_organisations_offers(): void
    {
        $theirs = $this->offerCreate('Q1', 'Their offer', 'Scholar K-12', self::T2);
        $this->assertNotNull($this->masterOfOffer($theirs, self::T2));
        $this->assertNull($this->masterOfOffer($theirs, self::T));

        $before = $this->dump();
        $this->assertSame(ProductMasterSync::ACT_UNCHANGED, (new ProductMasterSync)->offer(self::T, $theirs));
        $this->assertSame($before, $this->dump(), 'organisation 1 asking about organisation 2\'s offer writes nothing');

        // A row of organisation 1 that points at organisation 2's offer is treated as pointing at nothing of its own.
        DB::table('g2g_product_master')->insert(['sub_institute_id' => self::T, 'product_key' => 'scholarclone/q1', 'kind' => 'sub_product', 'parent_id' => $this->sharedId('scholarclone'), 'name' => 'Pointer', 'status' => 'active', 'origin' => 'portfolio', 'offer_row_id' => $theirs]);
        $this->assertSame(ProductMasterSync::ACT_ARCHIVED, (new ProductMasterSync)->offer(self::T, $theirs));
        $this->assertSame('active', $this->masterOfOffer($theirs, self::T2)->status, "organisation 2's own row is untouched");
    }

    // ---- an organisation's own product ----

    public function test_a_product_created_through_the_custom_product_api_gets_a_product_row_and_deleting_it_archives_the_row(): void
    {
        $h = $this->headers($this->h());
        $this->postJson('/api/signals/icp/products', ['key' => 'payroll_cloud', 'label' => 'Payroll Cloud'], $h)->assertCreated();

        $row = $this->masterByKey('payroll_cloud');
        $this->assertNotNull($row);
        $this->assertSame('product', $row->kind);
        $this->assertSame('custom', $row->origin);
        $this->assertSame('payroll_cloud', $row->signal_key);
        $this->assertSame('Payroll Cloud', $row->portfolio_name);
        $this->assertSame('Payroll Cloud', $row->name);
        $this->assertSame('active', $row->status);
        $this->assertNull($this->masterByKey('payroll_cloud', self::T2));

        $this->deleteJson('/api/signals/icp/products/payroll_cloud', [], $h)->assertOk();
        $this->assertSame('archived', $this->masterByKey('payroll_cloud')->status);

        $this->postJson('/api/signals/icp/products', ['key' => 'payroll_cloud', 'label' => 'Payroll Cloud 2'], $h)->assertCreated();
        $again = $this->masterByKey('payroll_cloud');
        $this->assertSame((int) $row->id, (int) $again->id, 'the same key is the same row');
        $this->assertSame('active', $again->status);
        $this->assertSame('Payroll Cloud 2', $again->name);
        $this->assertSame(1, DB::table('g2g_product_master')->where('product_key', 'payroll_cloud')->count());
    }

    // ---- a failure inside the master never reaches the caller ----

    /**
     * Makes every write into the master table fail (a trigger that aborts an insert or an update), while leaving the table and its shared rows as they
     * are. A table with a column missing would not do on sqlite: it reads an unknown "column" in a where as a string and finds nothing instead of failing.
     */
    private function breakTheMaster(): void
    {
        DB::unprepared("CREATE TRIGGER pm_no_insert BEFORE INSERT ON g2g_product_master BEGIN SELECT RAISE(ABORT, 'the master is broken'); END");
        DB::unprepared("CREATE TRIGGER pm_no_update BEFORE UPDATE ON g2g_product_master BEGIN SELECT RAISE(ABORT, 'the master is broken'); END");
    }

    public function test_a_failing_master_write_never_changes_the_crm_answer_and_is_logged(): void
    {
        $existing = $this->crmCreate('Existing kit');
        $this->breakTheMaster();
        Log::spy();
        $h = $this->headers($this->h());

        // A new item: the master insert fails.
        $res = $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Starter kit', 'assignedTo' => 1], $h)->assertStatus(201);
        $this->assertSame('Starter kit', $res->json('data.name'));
        $this->assertSame(2, DB::table('crm_products')->count(), 'the catalogue item was saved');
        $this->assertNull($this->masterOfCrm((int) $res->json('data.id')), 'and the master could not follow');

        // An existing item: the master update fails twice (rename, delete) and the caller still gets its own answers.
        $this->putJson("/api/crm/products/{$existing}", ['name' => 'Renamed'], $h)->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->deleteJson("/api/crm/products/{$existing}", [], $h)->assertOk();
        $this->assertNotNull(DB::table('crm_products')->where('id', $existing)->value('deleted_at'));
        $this->assertSame('Existing kit', $this->masterOfCrm($existing)->name, 'the master row stayed as it was');

        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_a_failing_master_write_never_changes_the_portfolio_or_custom_product_answers(): void
    {
        $existingOffer = $this->offerCreate('P8', 'Scholar Eight');
        $this->postJson('/api/signals/icp/products', ['key' => 'payroll_cloud', 'label' => 'Payroll Cloud'], $this->headers($this->h()))->assertCreated();
        $this->breakTheMaster();
        Log::spy();
        $h = $this->headers($this->h());

        // New: the master insert fails. Existing: the master update fails (a rename; the delete archives).
        $offer = $this->postJson('/api/portfolio/offers', ['offer_id' => 'P9', 'name' => 'Scholar Nine', 'parent_product' => 'Scholar K-12', 'readiness_status' => 'Prototype'], $h)->assertStatus(201);
        $this->assertSame('Scholar Nine', $offer->json('data.name'));
        $this->assertSame(2, DB::table('g2g_product_offers')->count());
        $this->putJson("/api/portfolio/offers/{$existingOffer}", ['name' => 'Scholar Eight v2'], $h)->assertOk()->assertJsonPath('data.name', 'Scholar Eight v2');
        $this->deleteJson("/api/portfolio/offers/{$existingOffer}", [], $h)->assertOk();
        $this->assertSame(1, DB::table('g2g_product_offers')->count());

        $this->postJson('/api/signals/icp/products', ['key' => 'second_line', 'label' => 'Second Line'], $h)->assertCreated();
        $this->deleteJson('/api/signals/icp/products/payroll_cloud', [], $h)->assertOk();
        $this->assertSame(0, DB::table('g2g_signal_products')->where('key', 'payroll_cloud')->count());

        // 1 offer insert + 1 offer rename + 1 offer delete (the row cannot be archived) + 1 product insert + 1 product delete (cannot be archived)
        Log::shouldHaveReceived('warning')->times(5);
    }

    public function test_without_a_master_table_every_write_still_works_and_nothing_fails(): void
    {
        Schema::drop('g2g_product_master');
        $h = $this->headers($this->h());

        $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Starter kit', 'assignedTo' => 1], $h)->assertStatus(201);
        $offer = (int) $this->postJson('/api/portfolio/offers', ['offer_id' => 'P9', 'name' => 'Scholar Nine', 'parent_product' => 'Scholar K-12', 'readiness_status' => 'Prototype'], $h)->assertStatus(201)->json('data.id');
        $this->postJson('/api/signals/icp/products', ['key' => 'payroll_cloud', 'label' => 'Payroll Cloud'], $h)->assertCreated();

        $this->assertSame(ProductMasterSync::ACT_SKIPPED, (new ProductMasterSync)->offer(self::T, $offer));
        $this->getJson('/api/crm/products?itemType=product', $h)->assertOk()->assertJsonPath('data.lines.linked', 0);
    }

    // ---- the two ways of making a row agree ----

    public function test_the_write_through_and_the_backfill_make_the_same_rows(): void
    {
        DB::table('g2g_product_offers')->insert(['sub_institute_id' => self::T, 'offer_id' => 'E1', 'name' => 'Scholar E', 'parent_product' => 'Scholar K-12', 'readiness_status' => 'Prototype']);
        DB::table('g2g_signal_products')->insert(['sub_institute_id' => self::T, 'key' => 'payroll', 'label' => 'Payroll', 'business_fit' => 'payroll', 'icp_product_line' => 'payroll', 'portfolio_names' => json_encode(['Payroll']), 'status' => 'active']);
        $crm = DB::table('crm_products')->insertGetId(['sub_institute_id' => self::T, 'product_no' => 'PRD-AAAA1111', 'name' => 'Kit', 'item_type' => 'product']);

        (new ProductMasterBackfill)->run(self::T, true);
        $byBackfill = $this->shape(self::T);
        DB::table('g2g_product_master')->where('sub_institute_id', self::T)->delete();

        $sync = new ProductMasterSync;
        $sync->customProduct(self::T, 'payroll');
        $sync->offer(self::T, (int) DB::table('g2g_product_offers')->where('offer_id', 'E1')->value('id'));
        $sync->catalogueItem(self::T, $crm);
        $byWriteThrough = $this->shape(self::T);

        $this->assertSame(3, count($byBackfill));
        $this->assertEquals($byBackfill, $byWriteThrough, 'same key, kind, parent, origin, signal key and name whichever way the row was made');
    }

    // ---- the repair command ----

    /** Drift of every kind: sources with no row, a stale name, a deleted source, a soft-deleted item, a deliberate archive, and another organisation's data. */
    private function drift(): array
    {
        $offer = $this->offerCreate('P9', 'Scholar Nine');
        $stale = $this->offerCreate('P8', 'Scholar Eight');
        $gone = $this->offerCreate('P7', 'Scholar Seven');
        $archivedOnPurpose = $this->offerCreate('P6', 'Scholar Six');
        // Deleted through the API (its row archived, pointer cleared) and then added again straight into the table, which the write-through never saw.
        $firstP5 = $this->offerCreate('P5', 'Scholar Five');
        $this->deleteJson("/api/portfolio/offers/{$firstP5}", [], $this->headers($this->h()))->assertOk();
        $readded = DB::table('g2g_product_offers')->insertGetId(['sub_institute_id' => self::T, 'offer_id' => 'P5', 'name' => 'Scholar Five again', 'parent_product' => 'Scholar K-12', 'readiness_status' => 'Prototype']);
        $crmKept = $this->crmCreate('Kit kept');
        $crmDeleted = $this->crmCreate('Kit deleted');
        $theirs = $this->offerCreate('Q1', 'Their offer', 'Scholar K-12', self::T2);

        // Things the write-through never saw: rows written straight into the stores.
        DB::table('g2g_product_offers')->insert(['sub_institute_id' => self::T, 'offer_id' => 'N1', 'name' => 'New offer', 'parent_product' => 'EB', 'readiness_status' => 'Prototype']);
        DB::table('g2g_signal_products')->insert(['sub_institute_id' => self::T, 'key' => 'payroll', 'label' => 'Payroll', 'business_fit' => 'payroll', 'icp_product_line' => 'payroll', 'portfolio_names' => json_encode(['Payroll']), 'status' => 'active']);
        $newCrm = DB::table('crm_products')->insertGetId(['sub_institute_id' => self::T, 'product_no' => 'PRD-NEW00001', 'name' => 'New kit', 'item_type' => 'product']);
        DB::table('g2g_product_offers')->where('id', $stale)->update(['name' => 'Scholar Eight (renamed behind our back)']);
        DB::table('g2g_product_offers')->where('id', $gone)->delete();
        DB::table('crm_products')->where('id', $crmDeleted)->update(['deleted_at' => now()]);
        DB::table('crm_products')->where('id', $crmKept)->update(['name' => 'Kit kept (renamed behind our back)']);
        DB::table('g2g_product_master')->where('offer_row_id', $archivedOnPurpose)->update(['status' => 'archived']);

        return compact('offer', 'stale', 'gone', 'archivedOnPurpose', 'readded', 'crmKept', 'crmDeleted', 'newCrm', 'theirs');
    }

    public function test_the_repair_command_is_a_dry_run_first_and_writes_nothing(): void
    {
        $this->drift();
        $before = $this->dump();

        $this->artisan('product-master:repair', ['--tenant' => self::T])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('would create')
            ->expectsOutputToContain('would change')
            ->expectsOutputToContain('Run again with --apply')
            ->assertExitCode(0);

        $this->assertSame($before, $this->dump(), 'a dry run changes nothing');
    }

    public function test_the_repair_command_applies_every_kind_of_drift_and_a_rerun_changes_nothing(): void
    {
        $d = $this->drift();
        $theirsBefore = DB::table('g2g_product_master')->where('sub_institute_id', self::T2)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->artisan('product-master:repair', ['--tenant' => self::T, '--apply' => true])->expectsOutputToContain('APPLY')->assertExitCode(0);

        $this->assertNotNull($this->masterByKey('enterprise_brain/n1'), 'an offer written straight into the Portfolio gets its row');
        $this->assertSame('custom', $this->masterByKey('payroll')->origin, 'an own product written straight into its table gets its row');
        $this->assertSame('item', $this->masterOfCrm($d['newCrm'])->kind, 'a catalogue item written straight into the table gets its row');
        $this->assertSame('Scholar Eight (renamed behind our back)', $this->masterOfOffer($d['stale'])->name, 'a stale name is copied across');
        $this->assertSame('Kit kept (renamed behind our back)', $this->masterOfCrm($d['crmKept'])->name);
        $this->assertSame('archived', DB::table('g2g_product_master')->where('product_key', 'scholarclone/p7')->value('status'), 'a deleted offer archives its row');
        $this->assertSame('archived', $this->masterOfCrm($d['crmDeleted'])->status, 'a soft-deleted item archives its row');
        $this->assertSame('archived', $this->masterOfOffer($d['archivedOnPurpose'])->status, 'a row a person archived on purpose is never made active again');
        $this->assertSame('active', $this->masterOfOffer($d['offer'])->status);
        $adopted = $this->masterOfOffer($d['readded']);
        $this->assertNotNull($adopted, 'an offer added again behind our back takes over the row its deleted twin left');
        $this->assertSame('scholarclone/p5', $adopted->product_key);
        $this->assertSame('active', $adopted->status);
        $this->assertSame('Scholar Five again', $adopted->name);
        $this->assertSame(1, DB::table('g2g_product_master')->where('product_key', 'scholarclone/p5')->count(), 'and no second row is made for the same key');
        $this->assertSame($theirsBefore, DB::table('g2g_product_master')->where('sub_institute_id', self::T2)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), "--tenant=1 leaves organisation 2 exactly as it was");

        // A second run: nothing to do, and the table is byte for byte what it was.
        $after = $this->dump();
        $this->artisan('product-master:repair', ['--tenant' => self::T])->expectsOutputToContain('Nothing to change')->assertExitCode(0);
        $this->artisan('product-master:repair', ['--tenant' => self::T, '--apply' => true])->expectsOutputToContain('Nothing to change')->assertExitCode(0);
        $this->assertSame($after, $this->dump());
    }

    public function test_the_repair_command_counts_a_new_row_once_as_created_and_never_again_as_a_change(): void
    {
        $this->drift();

        \Illuminate\Support\Facades\Artisan::call('product-master:repair', ['--tenant' => self::T]);
        $dry = \Illuminate\Support\Facades\Artisan::output();
        \Illuminate\Support\Facades\Artisan::call('product-master:repair', ['--tenant' => self::T, '--apply' => true]);
        $applied = \Illuminate\Support\Facades\Artisan::output();

        // Three sources have no row (offer N1, own product payroll, item New kit), and the creation belongs to the backfill's lines.
        foreach ([$dry, $applied] as $output) {
            $this->assertMatchesRegularExpression('/(would create|created)\s+sub-product\s+1\b/', $output);
            $this->assertMatchesRegularExpression('/(would create|created)\s+own product\s+1\b/', $output);
            $this->assertMatchesRegularExpression('/(would create|created)\s+catalogue item\s+1\b/', $output);
        }
        // The sync lines list what changed in rows that already existed. A row that is new is not also reported there.
        $this->assertDoesNotMatchRegularExpression('/(would change|changed)\s+created\b/', $dry);
        $this->assertDoesNotMatchRegularExpression('/(would change|changed)\s+created\b/', $applied);
        $this->assertMatchesRegularExpression('/would change\s+archived\s+2\b/', $dry, 'the deleted offer and the soft-deleted item');
        $this->assertMatchesRegularExpression('/changed\s+archived\s+2\b/', $applied);
    }

    public function test_an_own_product_a_person_archived_stays_archived_until_it_is_created_again(): void
    {
        DB::table('g2g_signal_products')->insert(['sub_institute_id' => self::T, 'key' => 'payroll', 'label' => 'Payroll', 'business_fit' => 'payroll', 'icp_product_line' => 'payroll', 'portfolio_names' => json_encode(['Payroll']), 'status' => 'active']);
        $sync = new ProductMasterSync;
        $this->assertSame(ProductMasterSync::ACT_CREATED, $sync->customProduct(self::T, 'payroll'));
        DB::table('g2g_product_master')->where('product_key', 'payroll')->update(['status' => 'archived']);

        $this->assertSame(ProductMasterSync::ACT_UNCHANGED, $sync->customProduct(self::T, 'payroll', ProductMasterSync::SAVED));
        $this->assertSame('archived', $this->masterByKey('payroll')->status);
        $this->assertSame(ProductMasterSync::ACT_REVIVED, $sync->customProduct(self::T, 'payroll', ProductMasterSync::CREATED), 'made again through the API: it is wanted again');
        $this->assertSame('active', $this->masterByKey('payroll')->status);
    }

    public function test_a_sync_never_wipes_who_last_edited_the_row_and_records_who_made_a_change_when_it_knows(): void
    {
        $id = $this->crmCreate('Starter kit');
        DB::table('g2g_product_master')->where('crm_product_id', $id)->update(['updated_by' => 77]);
        DB::table('crm_products')->where('id', $id)->update(['name' => 'Starter kit 2']);

        $this->assertSame(ProductMasterSync::ACT_UPDATED, (new ProductMasterSync)->catalogueItem(self::T, $id));
        $this->assertSame(77, (int) $this->masterOfCrm($id)->updated_by, 'a sync with no person behind it leaves the name of the last editor');

        DB::table('crm_products')->where('id', $id)->update(['name' => 'Starter kit 3']);
        (new ProductMasterSync(null, true, 5))->catalogueItem(self::T, $id);
        $this->assertSame(5, (int) $this->masterOfCrm($id)->updated_by);
    }

    public function test_an_offer_id_that_would_make_the_key_longer_than_fifty_characters_is_skipped_with_a_reason(): void
    {
        $id = DB::table('g2g_product_offers')->insertGetId(['sub_institute_id' => self::T, 'offer_id' => str_repeat('x', 45), 'name' => 'Long', 'parent_product' => 'Scholar K-12', 'readiness_status' => 'Prototype']);
        $sync = new ProductMasterSync;

        $this->assertSame(ProductMasterSync::ACT_SKIPPED, $sync->offer(self::T, $id));
        $this->assertNull($this->masterOfOffer($id));
        $this->assertStringContainsString('longer than 50 characters', $sync->issues()[0]);
    }

    public function test_a_clash_on_a_unique_key_while_making_a_row_is_not_an_error_and_makes_no_second_row(): void
    {
        // A row made by hand already holds the product's signal key under another product key (the organisation + signal key pair is unique).
        DB::table('g2g_product_master')->insert(['sub_institute_id' => self::T, 'product_key' => 'payroll_old', 'kind' => 'product', 'name' => 'Payroll (old)', 'status' => 'active', 'origin' => 'manual', 'signal_key' => 'payroll', 'portfolio_name' => 'Payroll']);
        DB::table('g2g_signal_products')->insert(['sub_institute_id' => self::T, 'key' => 'payroll', 'label' => 'Payroll', 'business_fit' => 'payroll', 'icp_product_line' => 'payroll', 'portfolio_names' => json_encode(['Payroll']), 'status' => 'active']);
        $before = $this->dump();

        $this->assertSame(ProductMasterSync::ACT_UNCHANGED, (new ProductMasterSync)->customProduct(self::T, 'payroll'), 'the row someone else just made is the row wanted');
        ProductMasterSync::throughCustomProduct(self::T, 'payroll', ProductMasterSync::CREATED);

        $this->assertSame($before, $this->dump());
    }

    public function test_the_repair_command_needs_an_organisation_and_can_do_them_all_or_name_the_connection(): void
    {
        $this->artisan('product-master:repair')->assertExitCode(1);

        $this->drift();
        DB::table('g2g_product_offers')->insert(['sub_institute_id' => self::T2, 'offer_id' => 'N2', 'name' => 'Their new offer', 'parent_product' => 'EB', 'readiness_status' => 'Prototype']);

        $this->artisan('product-master:repair', ['--all' => true, '--apply' => true, '--database' => 'sqlite'])
            ->expectsOutputToContain('organisations: 1, 2')
            ->assertExitCode(0);
        $this->assertNotNull($this->masterByKey('enterprise_brain/n2', self::T2), '--all reaches every organisation');
        $this->assertNotNull($this->masterByKey('enterprise_brain/n1', self::T));
    }

    public function test_the_repair_command_reports_a_missing_table_instead_of_failing_halfway(): void
    {
        Schema::drop('g2g_product_master');

        $this->artisan('product-master:repair', ['--tenant' => self::T, '--apply' => true])->expectsOutputToContain('does not exist')->assertExitCode(1);
        // The dry run still says what it would do.
        $this->artisan('product-master:repair', ['--tenant' => self::T])->assertExitCode(0);
    }

    // ---- what the Products screen reads ----

    private function linkedFixture(): array
    {
        $offer = $this->offerCreate('P9', 'Scholar Nine');
        $crm = $this->crmCreate('Scholar Nine (catalogue)');
        DB::table('g2g_product_master')->where('kind', 'item')->where('crm_product_id', $crm)->delete();
        DB::table('g2g_product_master')->where('offer_row_id', $offer)->update(['crm_product_id' => $crm]);
        $plain = $this->crmCreate('Plain kit');

        foreach ([[self::T, 'P9', 0], [self::T, 'P9', 0], [self::T, 'P9', 1], [self::T2, 'P9', 0], [self::T, 'P8', 0]] as [$tenant, $offerId, $sample]) {
            DB::table('g2g_company_opportunities')->insert([
                'sub_institute_id' => $tenant, 'company_id' => 1, 'research_run_id' => 1, 'title' => 'A signal', 'category' => 'expansion', 'observed_event' => 'e', 'why_indicates_need' => 'w',
                'product_fit' => 'f', 'recommended_action' => 'a', 'priority' => 'High', 'qualification' => 'Qualified', 'confidence' => 'High', 'sources' => '[]',
                'fingerprint' => str_pad(uniqid('', true), 64, '0'), 'relevant_offer_id' => $offerId, 'is_sample' => $sample,
            ]);
        }
        foreach ([['sub_p9', 'draft'], ['sub_p9_b2c', 'active'], ['sub_p8', 'active']] as [$key, $status]) {
            DB::table('g2g_icp_profiles')->insert(['sub_institute_id' => self::T, 'key' => $key, 'name' => $key, 'status' => $status, 'config' => '{}']);
        }
        DB::table('g2g_icp_profiles')->insert(['sub_institute_id' => self::T2, 'key' => 'sub_p9', 'name' => 'theirs', 'status' => 'active', 'config' => '{}']);

        return compact('offer', 'crm', 'plain');
    }

    public function test_the_products_list_says_which_line_signals_and_icp_state_each_item_has_and_whether_any_item_does(): void
    {
        $f = $this->linkedFixture();
        $items = collect($this->getJson('/api/crm/products?itemType=product', $this->headers($this->h()))->assertOk()->assertJsonPath('data.lines.linked', 1)->json('data.items'))->keyBy('id');

        $linked = $items[(string) $f['crm']];
        $this->assertSame($f['crm'], (int) $linked['id']);
        $this->assertSame('ScholarClone', $linked['productLine']['name']);
        $this->assertSame($this->sharedId('scholarclone'), $linked['productLine']['id']);
        $this->assertSame('P9', $linked['offerId']);
        $this->assertSame(2, $linked['signalsFound'], "this organisation's real signals for the offer: not the sample row, not organisation 2's");
        $this->assertSame('active', $linked['icp'], 'the best of its B2B draft and B2C active profiles; organisation 2\'s profile is not counted');
        $this->assertSame('sub_product', $linked['masterKind']);
        $this->assertNotNull($linked['masterId']);

        $plain = $items[(string) $f['plain']];
        foreach (['productLine', 'offerId', 'signalsFound', 'icp'] as $field) {
            $this->assertArrayHasKey($field, $plain, "the field {$field} is always there");
            $this->assertNull($plain[$field], "an item tied to no sub-product has an empty {$field}");
        }
        $this->assertSame('item', $plain['masterKind'], 'it does have a master row, a catalogue-only one');
        $this->assertSame((int) $this->masterOfCrm($f['plain'])->id, $plain['masterId']);

        // The fields that were there before are still there.
        foreach (['id', 'itemType', 'productNo', 'name', 'sku', 'unitPrice', 'isActive', 'assignedTo', 'createdAt'] as $field) {
            $this->assertArrayHasKey($field, $linked);
        }
    }

    public function test_the_products_list_has_no_extra_columns_to_show_when_no_item_is_tied_to_a_sub_product(): void
    {
        $this->crmCreate('Plain kit');

        $this->getJson('/api/crm/products?itemType=product', $this->headers($this->h()))->assertOk()->assertJsonPath('data.lines.linked', 0);
    }

    public function test_one_item_carries_the_same_extra_fields_as_the_list(): void
    {
        $f = $this->linkedFixture();

        $one = $this->getJson("/api/crm/products/{$f['crm']}", $this->headers($this->h()))->assertOk()->json('data');
        $this->assertSame('ScholarClone', $one['productLine']['name']);
        $this->assertSame(2, $one['signalsFound']);
        $this->assertSame('active', $one['icp']);
        $this->assertSame('Scholar Nine (catalogue)', $one['name']);
    }

    public function test_the_list_costs_the_same_number_of_queries_for_two_items_as_for_twenty(): void
    {
        $this->linkedFixture();
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $count = function () use (&$n) {
            $headers = $this->headers($this->h());
            $this->getJson('/api/crm/products?itemType=product&per_page=100', $headers)->assertOk();   // warm: caches filled on first use are not the list's cost
            $n = 0;
            $this->getJson('/api/crm/products?itemType=product&per_page=100', $headers)->assertOk();

            return $n;
        };
        $few = $count();
        for ($i = 0; $i < 18; $i++) {
            $this->crmCreate("Extra {$i}");
        }
        $many = $count();

        $this->assertSame($few, $many, 'the extra columns are read in a fixed number of queries');
    }

    public function test_a_failure_reading_the_extra_columns_leaves_the_list_exactly_as_it_was(): void
    {
        $this->crmCreate('Plain kit');
        Schema::drop('g2g_product_master');
        Schema::create('g2g_product_master', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
        });
        Log::spy();

        $res = $this->getJson('/api/crm/products?itemType=product', $this->headers($this->h()))->assertOk();

        $this->assertCount(1, $res->json('data.items'));
        $this->assertSame('Plain kit', $res->json('data.items.0.name'));
        $this->assertNull($res->json('data.items.0.productLine'));
        $this->assertSame(0, $res->json('data.lines.linked'));
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_the_master_list_carries_icp_state_and_signals_per_sub_product_and_stops_calling_a_deleted_item_sold(): void
    {
        $f = $this->linkedFixture();
        $h = $this->headers($this->h());

        $list = $this->getJson('/api/product-master', $h)->assertOk()->json('data.products');
        $scholar = collect($list)->firstWhere('key', 'scholarclone');
        $sub = collect($scholar['subProducts'])->firstWhere('offerId', 'P9');
        $this->assertSame('active', $sub['icp']);
        $this->assertSame(2, $sub['signalsFound']);
        $this->assertTrue($sub['soldInCrm']);
        $this->assertSame('template', $scholar['icp'], 'a built-in product with no saved ICP runs on its built-in one');
        $this->assertSame('none', collect($list)->firstWhere('key', 'enterprise_brain')['icp']);
        $this->assertNull(collect($list)->firstWhere('key', 'he')['icp'], 'no ICP applies to a group Signals has no value for');

        $this->deleteJson("/api/crm/products/{$f['crm']}", [], $h)->assertOk();
        $sub = collect(collect($this->getJson('/api/product-master', $h)->json('data.products'))->firstWhere('key', 'scholarclone')['subProducts'])->firstWhere('offerId', 'P9');
        $this->assertFalse($sub['soldInCrm'], 'the item is in the Recycle Bin, so it is not sold');
        $this->assertNull($sub['crmProductId']);
        $this->getJson('/api/crm/products?itemType=product', $h)->assertOk()->assertJsonPath('data.lines.linked', 0);

        $this->postJson("/api/crm/recycle-bin/products/{$f['crm']}/restore", [], $h)->assertOk();
        $sub = collect(collect($this->getJson('/api/product-master', $h)->json('data.products'))->firstWhere('key', 'scholarclone')['subProducts'])->firstWhere('offerId', 'P9');
        $this->assertTrue($sub['soldInCrm'], 'and sold again once it is restored');
    }

    public function test_usage_says_the_icp_state_of_a_product_and_a_sub_product_and_nothing_for_an_item(): void
    {
        $f = $this->linkedFixture();
        $h = $this->headers($this->h());
        $icp = fn (int $masterId) => $this->getJson("/api/product-master/{$masterId}/usage", $h)->assertOk()->json('data.icp');

        $this->assertSame('active', $icp((int) $this->masterOfOffer($f['offer'])->id));
        $this->assertSame('template', $icp($this->sharedId('scholarclone')));
        $this->assertNull($icp((int) $this->masterOfCrm($f['plain'])->id));
    }

    public function test_the_links_helper_asked_about_nothing_or_about_other_organisations_returns_empty_links(): void
    {
        $f = $this->linkedFixture();

        $this->assertSame([], ProductLinks::forCatalogueItems(self::T, []));
        $theirsView = ProductLinks::forCatalogueItems(self::T2, [$f['crm']]);
        $this->assertSame(ProductLinks::NO_LINK, $theirsView[$f['crm']], "another organisation's view of this item finds no master row");
        $this->assertSame(0, ProductLinks::linkedCount(self::T2, 'product'));
        $this->assertSame(1, ProductLinks::linkedCount(self::T, 'product'));
        $this->assertSame(0, ProductLinks::linkedCount(self::T, 'service'), 'by item type');
    }

    // ---- gaps a mutation sweep found in the safeguards above (each of these tests failed against a deliberately broken copy of the code) ----

    public function test_the_sync_class_asked_for_a_dry_run_says_what_it_would_create_and_writes_nothing(): void
    {
        $crm = (int) DB::table('crm_products')->insertGetId(['sub_institute_id' => self::T, 'product_no' => 'PRD-DRY1', 'item_type' => 'product', 'name' => 'Written straight to the table', 'is_active' => 1]);
        $this->assertNull($this->masterOfCrm($crm), 'nothing followed it: it was not made through the API');

        $this->assertSame(ProductMasterSync::ACT_CREATED, (new ProductMasterSync(null, false))->catalogueItem(self::T, $crm));
        $this->assertNull($this->masterOfCrm($crm), 'a dry run reports and writes nothing');

        $this->assertSame(ProductMasterSync::ACT_CREATED, (new ProductMasterSync)->catalogueItem(self::T, $crm));
        $this->assertNotNull($this->masterOfCrm($crm));
        $this->assertSame(ProductMasterSync::ACT_UNCHANGED, (new ProductMasterSync)->catalogueItem(self::T, $crm), 'and a second real run changes nothing');
    }

    public function test_an_own_product_of_another_organisation_is_never_read_or_followed(): void
    {
        $this->postJson('/api/signals/icp/products', ['key' => 'payroll_cloud', 'label' => 'Payroll Cloud'], $this->headers($this->h()))->assertCreated();
        $before = $this->dump();

        $this->assertSame(ProductMasterSync::ACT_UNCHANGED, (new ProductMasterSync)->customProduct(self::T2, 'payroll_cloud'), 'organisation 2 has no such product');
        $this->assertNull($this->masterByKey('payroll_cloud', self::T2));
        $this->assertSame($before, $this->dump());
    }

    public function test_another_organisations_icp_profile_is_never_counted_for_an_offer(): void
    {
        DB::table('g2g_icp_profiles')->insert(['sub_institute_id' => self::T2, 'key' => 'sub_p7', 'name' => 'theirs', 'status' => 'active', 'config' => '{}']);
        DB::table('g2g_icp_profiles')->insert(['sub_institute_id' => self::T, 'key' => 'sub_p6', 'name' => 'ours', 'status' => 'draft', 'config' => '{}']);

        $this->assertEquals(['P7' => 'none', 'P6' => 'draft'], ProductLinks::icpByOffer(self::T, ['P7', 'P6']), 'P7 has a profile only in organisation 2');
        $this->assertEquals(['P7' => 'active', 'P6' => 'none'], ProductLinks::icpByOffer(self::T2, ['P7', 'P6']));
    }

    public function test_deleting_a_catalogue_item_for_good_from_the_recycle_bin_lets_go_of_the_sub_product_that_pointed_at_it(): void
    {
        $f = $this->linkedFixture();
        $h = $this->headers($this->h());
        $this->deleteJson("/api/crm/products/{$f['crm']}", [], $h)->assertOk();
        $this->assertSame($f['crm'], (int) $this->masterOfOffer($f['offer'])->crm_product_id, 'in the Recycle Bin the pointer is kept, so a restore brings the link back');

        $this->deleteJson("/api/crm/recycle-bin/products/{$f['crm']}", [], $h)->assertOk();

        $this->assertNull($this->masterOfOffer($f['offer'])->crm_product_id, 'gone for good: the sub-product no longer points at a row that does not exist');
        $this->assertSame('active', $this->masterOfOffer($f['offer'])->status, 'the sub-product itself carries on');
    }

    public function test_the_portfolio_import_route_is_followed_by_syncing_every_offer_of_the_organisation(): void
    {
        // The real importer reads a workbook from disk; a stand-in writes the offers the way it does and reports only counts.
        $this->app->instance(\App\Domain\Portfolio\PortfolioImportService::class, new class extends \App\Domain\Portfolio\PortfolioImportService {
            public function import(?string $customPath = null, ?int $tenantId = null): array
            {
                foreach (['I1', 'I2'] as $offerId) {
                    DB::table('g2g_product_offers')->insert(['sub_institute_id' => $tenantId, 'offer_id' => $offerId, 'name' => "Imported {$offerId}", 'parent_product' => 'EB', 'readiness_status' => 'Prototype']);
                }

                return ['imported' => 2, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
            }
        });

        $this->postJson('/api/portfolio/import', ['path' => 'whatever.xlsx'], $this->headers($this->h()))->assertOk()->assertJsonPath('data.imported', 2);

        $this->assertSame(['enterprise_brain/i1', 'enterprise_brain/i2'], DB::table('g2g_product_master')->where('sub_institute_id', self::T)->where('kind', 'sub_product')->orderBy('product_key')->pluck('product_key')->all());
        $this->assertSame(0, DB::table('g2g_product_master')->where('sub_institute_id', self::T2)->where('kind', 'sub_product')->count());
    }

    public function test_the_repair_for_every_organisation_also_visits_one_whose_only_trace_is_a_master_row(): void
    {
        $offer = $this->offerCreate('P9', 'Scholar Nine');
        DB::table('g2g_product_offers')->where('id', $offer)->delete();   // removed behind the application's back: nothing else of organisation 1 is left
        $this->assertSame('active', $this->masterOfOffer($offer)->status);

        $this->artisan('product-master:repair', ['--all' => true, '--apply' => true])->expectsOutputToContain('organisations: ' . self::T)->assertExitCode(0);

        $row = $this->masterByKey('scholarclone/p9');
        $this->assertSame('archived', $row->status, 'the row whose source is gone is archived');
        $this->assertNull($row->offer_row_id, 'and no longer points at an offer that does not exist');
    }

    // ---- what the scope switch on the Products screen reads ----

    public function test_the_products_list_summarises_the_product_master_for_the_scope_switch(): void
    {
        $this->linkedFixture();
        $second = $this->offerCreate('P8', 'Scholar Eight');
        $h = $this->headers($this->h());

        $lines = $this->getJson('/api/crm/products?itemType=product', $h)->assertOk()->json('data.lines');
        $this->assertSame(['available' => true, 'linked' => 1, 'productLines' => 5, 'subProducts' => 2], $lines, 'the five built-in lines every organisation sees, two own sub-products, one item tied to one of them');

        // An archived sub-product is not counted, and another organisation sees the built-in lines only.
        DB::table('g2g_product_master')->where('offer_row_id', $second)->update(['status' => 'archived']);
        $this->assertSame(1, $this->getJson('/api/crm/products?itemType=product', $h)->assertOk()->json('data.lines.subProducts'));
        $theirs = $this->getJson('/api/crm/products?itemType=product', $this->headers($this->h(self::T2, 3)))->assertOk()->json('data.lines');
        $this->assertSame(['available' => true, 'linked' => 0, 'productLines' => 5, 'subProducts' => 0], $theirs, "organisation 2 never counts organisation 1's sub-products or its tied item");
    }

    public function test_without_the_product_master_the_list_says_so_and_the_master_answers_an_honest_empty_list(): void
    {
        $this->crmCreate('Plain kit');
        Schema::drop('g2g_product_master');
        $h = $this->headers($this->h());

        $this->getJson('/api/crm/products?itemType=product', $h)->assertOk()->assertJsonPath('data.lines', ['available' => false, 'linked' => 0, 'productLines' => 0, 'subProducts' => 0])->assertJsonCount(1, 'data.items');
        $this->getJson('/api/product-master', $h)->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.products', [])->assertJsonPath('data.items', [])
            ->assertJsonPath('data.totals', ['products' => 0, 'subProducts' => 0, 'items' => 0]);
        $this->getJson('/api/product-master/options', $h)->assertOk()->assertJsonPath('data', []);
    }

    public function test_a_sold_sub_product_says_whether_its_catalogue_item_is_a_product_or_a_service_and_an_unsold_one_says_nothing(): void
    {
        $sold = $this->offerCreate('P9', 'Scholar Nine');
        $this->offerCreate('P7', 'Scholar Seven');
        $crm = $this->crmCreate('Scholar Nine (service)', 'service');
        DB::table('g2g_product_master')->where('kind', 'item')->where('crm_product_id', $crm)->delete();
        DB::table('g2g_product_master')->where('offer_row_id', $sold)->update(['crm_product_id' => $crm]);
        $h = $this->headers($this->h());

        $subs = fn () => collect(collect($this->getJson('/api/product-master', $h)->assertOk()->assertJsonPath('data.available', true)->json('data.products'))->firstWhere('key', 'scholarclone')['subProducts'])->keyBy('offerId');

        $this->assertSame('service', $subs()['P9']['crmItemType']);
        $this->assertSame($crm, $subs()['P9']['crmProductId']);
        $this->assertNull($subs()['P7']['crmItemType']);
        $this->assertNull($subs()['P7']['crmProductId']);

        // In the Recycle Bin it is not sold, so it names no screen either.
        $this->deleteJson("/api/crm/products/{$crm}", [], $h)->assertOk();
        $this->assertNull($subs()['P9']['crmItemType']);
    }
}
