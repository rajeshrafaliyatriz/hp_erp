<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductMasterBackfill;
use App\Domain\Signals\Products\CustomProductRules;
use App\Domain\Signals\Products\ProductCatalog;
use App\Models\auth\tbluserModel;
use App\Services\Events\EventRecorder;
use App\Support\RoleKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Slice 15, track P2-B1: the five defects on the CRM product write path (Docs/agentic-crm/briefs/S15-P2B1-product-write-path-defects.md), each proved by a
 * test that FAILS on the code as it stood before the fix, plus the guards that say what must keep working.
 *
 *   D1  a Product (level 1, "line") was created before the right to add was checked, and by anyone with that right; now: validate, right, tax rate, then
 *       line resolution, and only an administrator makes a NEW one (an existing one, by name in any case, may be used by anyone with the right);
 *   D2  the line key did not fit g2g_signal_products.key (20) and skipped CustomProductRules; now one key, valid, identical in both tables;
 *   D3  a Service lost its line on every edit; now it is stored when sent and left alone when not;
 *   D4  GET products/{id}/history had no right check and shows cost price and commission; now it needs the VIEW right of the row's item type;
 *   D5  a line id was not checked on import and in ProductWriter::createItem; now it must be a visible Product (kind product), not a Sub-product.
 *
 * sqlite does not enforce VARCHAR lengths, so "fits the column" is asserted in PHP against the real column sizes (key 20, label 50).
 */
class ProductWritePathDefectsTest extends TestCase
{
    private const T = 1;

    private const T2 = 2;

    // Profiles. ADMIN: administrator, every right on Products, Services and Vendors. SALES: add/edit/view on Products only. VIEWER: view only on Products and
    // Services. HR: holds the Portfolio and Vendors (view) but nothing on Products or Services. ADMIN2: another organisation's administrator. ADMIN_VIEW: an
    // administrator whose profile can only VIEW Products and Services: the one person for whom "the right is checked first" is the ONLY thing between a
    // request and a new line (a non-administrator is refused by the administrator rule whatever the order is).
    private const ADMIN = 1;

    private const SALES = 2;

    private const HR = 3;

    private const VIEWER = 4;

    private const ADMIN2 = 5;

    private const ADMIN_VIEW = 6;

    private int $eventSeq = 0;

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
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
        });
        // The history query names the actor with MariaDB's CONCAT, which sqlite does not have.
        DB::connection()->getPdo()->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts), -1);
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

        // The Product Master, Signals and audit tables are built from the real migration files, as the neighbouring product tests do.
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
            '2026_08_10_150000_create_g2g_audit_log',
            '2026_11_24_082200_create_crm_vendors_table',
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

        // The audit pipeline is not under test (the neighbouring product tests stub it the same way): the history tests write the audit rows they read.
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
        $menu(470, 'Vendors', 199, '/module/crm/sales/vendors');
        $menu(492, 'Product Portfolio', 199, '/portfolio');

        DB::table('tbluserprofilemaster')->insert([
            ['id' => self::ADMIN, 'name' => 'Admin', 'role_key' => 'administrator', 'sub_institute_id' => self::T],
            ['id' => self::SALES, 'name' => 'Sales', 'role_key' => 'employee', 'sub_institute_id' => self::T],
            ['id' => self::HR, 'name' => 'HR', 'role_key' => 'hr_manager', 'sub_institute_id' => self::T],
            ['id' => self::VIEWER, 'name' => 'Viewer', 'role_key' => 'employee', 'sub_institute_id' => self::T],
            ['id' => self::ADMIN2, 'name' => 'Admin T2', 'role_key' => 'administrator', 'sub_institute_id' => self::T2],
            ['id' => self::ADMIN_VIEW, 'name' => 'Admin, view only on products', 'role_key' => 'administrator', 'sub_institute_id' => self::T],
        ]);
        $grant = fn (int $tenant, int $menu, int $profile, int $view, int $add, int $edit, int $delete) => DB::table('tblgroupwise_rights_g2g')->insert([
            'sub_institute_id' => $tenant, 'menu_id' => $menu, 'profile_id' => $profile, 'can_view' => $view, 'can_add' => $add, 'can_edit' => $edit, 'can_delete' => $delete,
        ]);
        foreach ([468, 469, 470] as $m) {
            $grant(self::T, $m, self::ADMIN, 1, 1, 1, 1);
        }
        foreach ([468, 469] as $m) {
            $grant(self::T2, $m, self::ADMIN2, 1, 1, 1, 1);
            $grant(self::T, $m, self::VIEWER, 1, 0, 0, 0);
            $grant(self::T, $m, self::ADMIN_VIEW, 1, 0, 0, 0);
        }
        $grant(self::T, 468, self::SALES, 1, 1, 1, 0);
        $grant(self::T, 492, self::HR, 1, 1, 1, 0);
        $grant(self::T, 470, self::HR, 1, 0, 0, 0);

        DB::table('crm_tax_rates')->insert([
            ['id' => 7, 'sub_institute_id' => self::T, 'name' => 'GST 18', 'percentage' => 18],
            ['id' => 8, 'sub_institute_id' => self::T2, 'name' => 'Other organisation', 'percentage' => 5],
        ]);

        (new ProductMasterBackfill)->run(self::T, true);
    }

    // ---- helpers ----

    /** @return array{id:int, h:array<string,string>} */
    private function user(int $profile, int $tenant = self::T): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);

        return ['id' => $id, 'h' => ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken]];
    }

    /** @return array{master:int, signal:int, crm:int} what a refused write must leave exactly as it was */
    private function counts(): array
    {
        return [
            'master' => DB::table('g2g_product_master')->count(),
            'signal' => DB::table('g2g_signal_products')->count(),
            'crm' => DB::table('crm_products')->count(),
        ];
    }

    private function sharedLine(string $key = 'scholarclone'): int
    {
        return (int) DB::table('g2g_product_master')->where('sub_institute_id', 0)->where('product_key', $key)->value('id');
    }

    /** A product line (kind product) of another organisation. Made once per test, however often it is asked for. */
    private function otherTenantLine(): int
    {
        $row = DB::table('g2g_product_master')->where('sub_institute_id', self::T2)->where('product_key', 'theirs')->first();

        return $row !== null ? (int) $row->id : (int) DB::table('g2g_product_master')->insertGetId([
            'sub_institute_id' => self::T2, 'product_key' => 'theirs', 'kind' => 'product', 'name' => 'Tenant 2 Exclusive Line', 'status' => 'active', 'origin' => 'custom',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A Sub-product (level 2) of the organisation: a master row that must never be accepted where a Product is wanted. Made once per test. */
    private function subProductId(): int
    {
        $row = DB::table('g2g_product_master')->where('sub_institute_id', self::T)->where('product_key', 'scholarclone/zz1')->first();

        return $row !== null ? (int) $row->id : (int) DB::table('g2g_product_master')->insertGetId([
            'sub_institute_id' => self::T, 'product_key' => 'scholarclone/zz1', 'kind' => 'sub_product', 'parent_id' => $this->sharedLine(), 'name' => 'A Sub-product', 'status' => 'active',
            'origin' => 'portfolio', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Creates a catalogue row through the API as the administrator and returns its id. */
    private function makeItem(string $type = 'product', array $over = []): int
    {
        $admin = $this->user(self::ADMIN);
        $res = $this->postJson('/api/crm/products', $over + ['itemType' => $type, 'name' => ucfirst($type) . ' ' . uniqid(), 'assignedTo' => $admin['id'], 'unitPrice' => 100], $admin['h']);
        $this->assertSame(201, $res->getStatusCode(), (string) $res->getContent());

        return (int) $res->json('data.id');
    }

    private function audit(string $entityType, int $entityId, string $type, array $changes): void
    {
        DB::table('g2g_audit_log')->insert([
            'event_id' => ++$this->eventSeq, 'sub_institute_id' => self::T, 'type' => $type, 'entity_type' => $entityType, 'entity_id' => $entityId, 'actor_id' => 1,
            'detail' => json_encode(['changes' => $changes]), 'occurred_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
    }

    /** The two rows a line is made of, by key. @return array{master: object, signal: object} */
    private function lineRows(string $name): array
    {
        $master = DB::table('g2g_product_master')->where('sub_institute_id', self::T)->where('kind', 'product')->where('name', $name)->first();
        $this->assertNotNull($master, "no product master row named {$name}");
        $signal = DB::table('g2g_signal_products')->where('sub_institute_id', self::T)->where('label', $name)->first();
        $this->assertNotNull($signal, "no signal-product row labelled {$name}");

        return ['master' => $master, 'signal' => $signal];
    }

    // ---- D1: the right is checked before anything is written, and a NEW line needs an administrator ----

    public function test_d1_a_person_without_the_add_right_creates_no_line_and_no_product(): void
    {
        $viewer = $this->user(self::VIEWER);
        $before = $this->counts();

        $res = $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Sneaky', 'assignedTo' => $viewer['id'], 'productLineName' => 'Sneaky Line'], $viewer['h']);

        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame($before, $this->counts(), 'a refusal writes nothing at all: no line in the master, no signal product, no catalogue row');
    }

    public function test_d1_an_administrator_who_may_not_add_still_creates_no_line_and_no_product(): void
    {
        // The administrator rule alone would let this person make a line; only checking the right to ADD first stops it.
        $admin = $this->user(self::ADMIN_VIEW);
        $before = $this->counts();

        $res = $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Sneaky', 'assignedTo' => $admin['id'], 'productLineName' => 'Sneaky Line'], $admin['h']);

        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame('You do not have permission to perform this action.', $res->json('message'), 'it is the right to add that stopped it');
        $this->assertSame($before, $this->counts(), 'no line, no signal product, no catalogue row');
    }

    public function test_d1_the_order_is_validate_then_the_right_then_the_tax_rate_then_the_line(): void
    {
        $viewer = $this->user(self::VIEWER);
        $theirs = $this->otherTenantLine();
        $before = $this->counts();   // after the fixture: the other organisation's line is a master row too
        $body = ['itemType' => 'product', 'name' => 'Order check', 'assignedTo' => $viewer['id']];

        // Validation first: a body that is not valid is a 422 whoever sends it.
        $this->postJson('/api/crm/products', ['itemType' => 'product', 'assignedTo' => $viewer['id']], $viewer['h'])->assertStatus(422);
        // Then the right: a person who may not add is told so, not told about a tax rate or a line (that would be a free probe of other organisations' data).
        $this->postJson('/api/crm/products', $body + ['taxRateId' => 8], $viewer['h'])->assertStatus(403);
        $this->postJson('/api/crm/products', $body + ['productMasterId' => $theirs], $viewer['h'])->assertStatus(403);
        // The administrator then meets the tax rate before the line.
        $admin = $this->user(self::ADMIN);
        $this->postJson('/api/crm/products', $body + ['taxRateId' => 8, 'productMasterId' => $theirs], $admin['h'])->assertStatus(422)->assertJsonPath('message', 'That tax rate was not found.');
        $this->postJson('/api/crm/products', $body + ['productMasterId' => $theirs], $admin['h'])->assertStatus(422)->assertJsonPath('message', 'The selected product line is invalid or not accessible.');

        $this->assertSame($before, $this->counts());
    }

    public function test_d1_a_bad_tax_rate_leaves_no_new_line_behind_on_create(): void
    {
        $admin = $this->user(self::ADMIN);
        $before = $this->counts();

        $res = $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Late line', 'assignedTo' => $admin['id'], 'taxRateId' => 8, 'productLineName' => 'Line That Must Not Appear'], $admin['h']);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame($before, $this->counts(), 'the tax rate is refused BEFORE the line is made');
    }

    public function test_d1_only_an_administrator_makes_a_new_line_inline_on_create_in_every_request_shape(): void
    {
        $sales = $this->user(self::SALES);
        $before = $this->counts();

        foreach ([['productLineName' => 'Fresh One'], ['newProductLineName' => 'Fresh Two'], ['newProductLine' => ['name' => 'Fresh Three', 'key' => 'fresh_three']]] as $shape) {
            $res = $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Needs a line', 'assignedTo' => $sales['id']] + $shape, $sales['h']);
            $this->assertSame(403, $res->getStatusCode(), json_encode($shape));
            $this->assertStringContainsString('administrator', (string) $res->json('message'), 'the refusal says who may');
            $this->assertSame($before, $this->counts(), json_encode($shape) . ': nothing is written when a new line is refused');
        }
    }

    public function test_d1_a_person_with_the_right_uses_an_existing_line_by_name_in_any_case_and_nothing_new_is_made(): void
    {
        $sales = $this->user(self::SALES);
        $admin = $this->user(self::ADMIN);
        // The organisation's own line (made by the administrator) and a shared one (a built-in product) are both "existing lines".
        $own = (int) $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Seed', 'assignedTo' => $admin['id'], 'productLineName' => 'Payroll Suite'], $admin['h'])->assertStatus(201)->json('data.productMasterId');
        $this->assertGreaterThan(0, $own);
        $before = $this->counts();

        $mine = $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Uses own line', 'assignedTo' => $sales['id'], 'productLineName' => 'pAyRoLl sUiTe'], $sales['h']);
        $shared = $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Uses shared line', 'assignedTo' => $sales['id'], 'productLineName' => 'SCHOLARCLONE'], $sales['h']);

        $this->assertSame(201, $mine->getStatusCode(), (string) $mine->getContent());
        $this->assertSame($own, $mine->json('data.productMasterId'));
        $this->assertSame(201, $shared->getStatusCode(), (string) $shared->getContent());
        $this->assertSame($this->sharedLine(), $shared->json('data.productMasterId'));
        $after = $this->counts();
        $this->assertSame([$before['master'] + 2, $before['signal'], $before['crm'] + 2], [$after['master'], $after['signal'], $after['crm']], 'two catalogue items (and their master rows), but no new line and no new signal product');
    }

    public function test_d1_an_administrator_can_still_make_a_new_line_inline_on_create(): void
    {
        $admin = $this->user(self::ADMIN);

        $res = $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Battery', 'assignedTo' => $admin['id'], 'productLineName' => 'Renewable Storage'], $admin['h']);

        $this->assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        $rows = $this->lineRows('Renewable Storage');
        $this->assertSame((int) $rows['master']->id, $res->json('data.productMasterId'));
        $this->assertSame('product', $rows['master']->kind);
        $this->assertSame((int) $rows['master']->id, (int) DB::table('crm_products')->where('id', $res->json('data.id'))->value('product_master_id'));
    }

    public function test_d1_on_update_a_non_administrator_cannot_make_a_new_line_and_a_viewer_cannot_edit_at_all(): void
    {
        $id = $this->makeItem('product', ['name' => 'Editable']);
        $sales = $this->user(self::SALES);
        $viewer = $this->user(self::VIEWER);
        $before = $this->counts();
        $row = fn () => (array) DB::table('crm_products')->where('id', $id)->first();
        $rowBefore = $row();

        foreach ([['productLineName' => 'Edit Time One'], ['newProductLineName' => 'Edit Time Two'], ['newProductLine' => ['name' => 'Edit Time Three']]] as $shape) {
            $res = $this->putJson('/api/crm/products/' . $id, ['name' => 'Edited'] + $shape, $sales['h']);
            $this->assertSame(403, $res->getStatusCode(), json_encode($shape));
            $this->assertStringContainsString('administrator', (string) $res->json('message'));
        }
        $this->putJson('/api/crm/products/' . $id, ['name' => 'Edited', 'productLineName' => 'Edit Time Four'], $viewer['h'])->assertStatus(403);
        // An administrator whose profile may not edit: the administrator rule would let them make a line, the right to edit must stop it first.
        $adminView = $this->putJson('/api/crm/products/' . $id, ['name' => 'Edited', 'productLineName' => 'Edit Time Five'], $this->user(self::ADMIN_VIEW)['h']);
        $this->assertSame(403, $adminView->getStatusCode());
        $this->assertSame('You do not have permission to perform this action.', $adminView->json('message'), 'it is the right to edit that stopped it');

        $this->assertSame($before, $this->counts(), 'no line is made by a refused edit');
        $this->assertSame($rowBefore, $row(), 'and the product itself is untouched');
    }

    public function test_d1_a_bad_tax_rate_leaves_no_new_line_behind_on_update(): void
    {
        $id = $this->makeItem('product', ['name' => 'Editable']);
        $admin = $this->user(self::ADMIN);
        $before = $this->counts();

        $res = $this->putJson('/api/crm/products/' . $id, ['name' => 'Edited', 'taxRateId' => 8, 'productLineName' => 'Line That Must Not Appear'], $admin['h']);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame($before, $this->counts(), 'the tax rate is refused BEFORE the line is made');
        $this->assertSame('Editable', DB::table('crm_products')->where('id', $id)->value('name'));

        // And the tax rate is looked at before a line id is, as on create.
        $both = $this->putJson('/api/crm/products/' . $id, ['taxRateId' => 8, 'productMasterId' => $this->otherTenantLine()], $admin['h']);
        $this->assertSame(422, $both->getStatusCode());
        $this->assertSame('That tax rate was not found.', $both->json('message'));
    }

    public function test_d1_an_administrator_can_still_make_a_new_line_inline_on_update_and_the_item_follows_it(): void
    {
        $id = $this->makeItem('product', ['name' => 'Editable']);
        $admin = $this->user(self::ADMIN);

        $res = $this->putJson('/api/crm/products/' . $id, ['name' => 'Edited', 'productLineName' => 'Edit Time Line'], $admin['h']);

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $line = (int) $this->lineRows('Edit Time Line')['master']->id;
        $this->assertSame($line, $res->json('data.productMasterId'));
        $this->assertSame($line, (int) DB::table('g2g_product_master')->where('crm_product_id', $id)->value('parent_id'), 'the master row follows the catalogue item');
    }

    public function test_d1_a_blank_line_name_means_no_line_and_is_not_an_error(): void
    {
        // What the form has always done with an empty "new product line" box: the item is made, with no line, and nothing else is written.
        $admin = $this->user(self::ADMIN);
        $before = $this->counts();

        foreach ([['newProductLine' => ['name' => '   ']], ['newProductLine' => ['name' => '']], ['newProductLineName' => '  ']] as $i => $shape) {
            $res = $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'No line ' . $i, 'assignedTo' => $admin['id']] + $shape, $admin['h']);
            $this->assertSame(201, $res->getStatusCode(), json_encode($shape) . ' ' . $res->getContent());
            $this->assertNull($res->json('data.productMasterId'), json_encode($shape));
        }

        $after = $this->counts();
        $this->assertSame([$before['signal'], $before['crm'] + 3], [$after['signal'], $after['crm']], 'three products, no line, no signal product');
    }

    // ---- D2: the key fits, obeys CustomProductRules, and is the same in both tables ----

    public function test_d2_the_line_key_is_valid_at_most_twenty_characters_and_identical_in_both_tables(): void
    {
        $admin = $this->user(self::ADMIN);
        $name = 'Renewable Storage Solutions Division';   // 36 characters: Str::slug() of it is 36 characters with hyphens

        $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Battery', 'assignedTo' => $admin['id'], 'productLineName' => $name], $admin['h'])->assertStatus(201);

        $rows = $this->lineRows($name);
        $key = (string) $rows['signal']->key;
        $this->assertLessThanOrEqual(20, strlen($key), 'g2g_signal_products.key is varchar(20)');
        $this->assertSame(1, preg_match(CustomProductRules::KEY_PATTERN, $key), "'{$key}' is a valid product key");
        $this->assertSame($key, (string) $rows['master']->product_key, 'the master row and the signal-product row carry the SAME key');
        $this->assertSame($key, (string) $rows['signal']->business_fit);
        $this->assertSame($key, (string) $rows['signal']->icp_product_line);
        $this->assertSame('renewable_storage_so', $key);
        $this->assertLessThanOrEqual(50, strlen((string) $rows['signal']->label), 'g2g_signal_products.label is varchar(50)');
    }

    public function test_d2_a_key_the_client_supplies_is_cut_and_cleaned_by_the_same_rules(): void
    {
        $admin = $this->user(self::ADMIN);

        $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Battery', 'assignedTo' => $admin['id'], 'newProductLine' => ['name' => 'Grid Tied Inverters', 'key' => 'My Very Long Custom Product Line Key 99']], $admin['h'])->assertStatus(201);

        $rows = $this->lineRows('Grid Tied Inverters');
        $this->assertSame('my_very_long_custom', (string) $rows['signal']->key);
        $this->assertSame((string) $rows['signal']->key, (string) $rows['master']->product_key);
    }

    public function test_d2_a_key_sent_as_productLineKey_is_cleaned_the_same_way(): void
    {
        $admin = $this->user(self::ADMIN);

        $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Meter', 'assignedTo' => $admin['id'], 'productLineName' => 'Smart Meters', 'productLineKey' => 'Meter Line-2025!'], $admin['h'])->assertStatus(201);

        $rows = $this->lineRows('Smart Meters');
        $this->assertSame('meter_line_2025', (string) $rows['signal']->key, 'the key the client asked for, made valid, and not the one made from the name');
        $this->assertSame('meter_line_2025', (string) $rows['master']->product_key);
    }

    public function test_d2_a_name_the_rules_refuse_makes_no_line_and_says_why(): void
    {
        $admin = $this->user(self::ADMIN);
        $before = $this->counts();

        foreach ([str_repeat('A', 51), 'X', 'Scholar K-12'] as $name) {
            $res = $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Thing', 'assignedTo' => $admin['id'], 'productLineName' => $name], $admin['h']);
            $this->assertSame(422, $res->getStatusCode(), $name . ': ' . $res->getContent());
            $this->assertStringStartsWith('Product name: ', (string) $res->json('message'), 'the message says which field');
            $this->assertArrayHasKey('name', (array) $res->json('errors'));
        }

        $this->assertSame($before, $this->counts(), 'no line, no signal product, no product');
    }

    public function test_d2_without_the_signal_products_table_no_line_is_made_and_nothing_else_is_written(): void
    {
        $admin = $this->user(self::ADMIN);
        Schema::drop('g2g_signal_products');
        $before = [DB::table('g2g_product_master')->count(), DB::table('crm_products')->count()];

        $res = $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Thing', 'assignedTo' => $admin['id'], 'productLineName' => 'Needs The Table'], $admin['h']);

        $this->assertSame(409, $res->getStatusCode(), (string) $res->getContent());
        $this->assertStringContainsString('g2g_signal_products', (string) $res->json('message'));
        $this->assertSame($before, [DB::table('g2g_product_master')->count(), DB::table('crm_products')->count()], 'not even a master-only line');
    }

    public function test_d2_when_every_suffix_is_taken_the_answer_is_a_409_and_nothing_is_written(): void
    {
        $admin = $this->user(self::ADMIN);
        for ($n = 1; $n <= 99; $n++) {
            DB::table('g2g_product_master')->insert(['sub_institute_id' => self::T, 'product_key' => $n === 1 ? 'abc' : 'abc_' . $n, 'kind' => 'product', 'name' => 'Seed ' . $n, 'status' => 'active', 'origin' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        }
        $before = $this->counts();

        $res = $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Thing', 'assignedTo' => $admin['id'], 'productLineName' => 'Abc'], $admin['h']);

        $this->assertSame(409, $res->getStatusCode(), (string) $res->getContent());
        $this->assertSame('No free key was found for this product. Give it a different name.', $res->json('message'));
        $this->assertSame($before, $this->counts());
    }

    public function test_d2_a_clash_gets_a_short_deterministic_suffix_that_still_fits(): void
    {
        $admin = $this->user(self::ADMIN);
        $make = fn (string $name) => $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Battery', 'assignedTo' => $admin['id'], 'productLineName' => $name], $admin['h'])->assertStatus(201);

        $make('Renewable Storage Solutions Division');
        $make('Renewable Storage Solutions Department');
        $make('Renewable Storage Solutions Dept');

        $keys = [
            (string) $this->lineRows('Renewable Storage Solutions Division')['signal']->key,
            (string) $this->lineRows('Renewable Storage Solutions Department')['signal']->key,
            (string) $this->lineRows('Renewable Storage Solutions Dept')['signal']->key,
        ];
        $this->assertSame(['renewable_storage_so', 'renewable_storage_2', 'renewable_storage_3'], $keys, 'the same names always give the same keys: no random part');
        foreach (['Renewable Storage Solutions Division', 'Renewable Storage Solutions Department', 'Renewable Storage Solutions Dept'] as $name) {
            $rows = $this->lineRows($name);
            $this->assertSame((string) $rows['signal']->key, (string) $rows['master']->product_key, $name);
            $this->assertLessThanOrEqual(20, strlen((string) $rows['signal']->key), $name);
        }
        $this->assertSame(3, DB::table('g2g_signal_products')->where('sub_institute_id', self::T)->count());
    }

    public function test_d2_a_key_held_only_by_the_master_or_only_by_a_switched_off_signal_product_is_taken_too(): void
    {
        $admin = $this->user(self::ADMIN);
        // A line the OLD form made: a master row (origin manual) with no signal key and no g2g_signal_products row.
        DB::table('g2g_product_master')->insert(['sub_institute_id' => self::T, 'product_key' => 'payroll', 'kind' => 'product', 'name' => 'Payroll Old Style', 'status' => 'active', 'origin' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        // An own product that was switched off: the rules read only the active ones, yet it still holds its three unique keys.
        DB::table('g2g_signal_products')->insert(['sub_institute_id' => self::T, 'key' => 'old_line', 'label' => 'Retired Line', 'business_fit' => 'old_line', 'icp_product_line' => 'old_line', 'portfolio_names' => '["Retired Line"]', 'status' => 'inactive', 'created_at' => now(), 'updated_at' => now()]);

        foreach (['Payroll', 'Old Line'] as $name) {
            $res = $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Thing', 'assignedTo' => $admin['id'], 'productLineName' => $name], $admin['h']);
            $this->assertSame(201, $res->getStatusCode(), $name . ': ' . $res->getContent());
        }

        $this->assertSame('payroll_2', (string) $this->lineRows('Payroll')['signal']->key, 'the master already holds payroll');
        $this->assertSame('old_line_2', (string) $this->lineRows('Old Line')['signal']->key, 'the switched-off product still holds old_line');
    }

    public function test_d2_products_is_a_reserved_key_so_the_route_products_id_can_never_be_a_product(): void
    {
        $this->assertContains('key: this word is reserved', CustomProductRules::problems('products', 'Some Label', [], []));

        $admin = $this->user(self::ADMIN);
        $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Thing', 'assignedTo' => $admin['id'], 'productLineName' => 'Products'], $admin['h'])->assertStatus(201);
        $key = (string) $this->lineRows('Products')['signal']->key;
        $this->assertNotSame('products', $key, 'a derived key that lands on a reserved word gets a suffix instead');
        $this->assertSame('products_2', $key);
    }

    public function test_d2_a_key_a_built_in_product_already_uses_is_never_taken(): void
    {
        $admin = $this->user(self::ADMIN);

        // The client asks for the key 'g2g', which a built-in product owns (CustomProductRules refuses it), on a NEW name: the line is made, with a suffixed key.
        $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Thing', 'assignedTo' => $admin['id'], 'newProductLine' => ['name' => 'Government To Government', 'key' => 'g2g']], $admin['h'])->assertStatus(201);

        $key = (string) $this->lineRows('Government To Government')['signal']->key;
        $this->assertNotSame('g2g', $key);
        $this->assertSame([], array_values(array_filter(
            CustomProductRules::problems($key, 'Government To Government', ProductCatalog::definitions(), []),
            fn (string $p) => str_starts_with($p, 'key:'),
        )), 'the key it got passes every key rule');
    }

    public function test_d2_an_inline_line_is_the_same_kind_of_record_as_one_made_through_the_product_master_writer(): void
    {
        $admin = $this->user(self::ADMIN);
        $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Thing', 'assignedTo' => $admin['id'], 'productLineName' => 'Alpha Line'], $admin['h'])->assertStatus(201);
        $this->postJson('/api/product-master', ['kind' => 'product', 'name' => 'Beta Line', 'key' => 'beta_line'], $admin['h'])->assertStatus(201);

        foreach (['Alpha Line', 'Beta Line'] as $name) {
            $rows = $this->lineRows($name);
            $m = $rows['master'];
            $s = $rows['signal'];
            $this->assertSame('custom', $m->origin, "{$name}: origin");
            $this->assertSame((string) $m->product_key, (string) $m->signal_key, "{$name}: the master row names its signal key");
            $this->assertSame($name, $m->portfolio_name, "{$name}: portfolio name");
            $this->assertNull($m->parent_id, "{$name}: parent");
            $this->assertSame('active', $m->status);
            $this->assertSame([(string) $m->product_key, (string) $m->product_key, 'active'], [(string) $s->business_fit, (string) $s->icp_product_line, (string) $s->status], "{$name}: signal product");
            $this->assertSame([$name], json_decode((string) $s->portfolio_names, true), "{$name}: portfolio names");
        }
    }

    public function test_d2_the_writer_still_refuses_a_non_administrator_before_it_looks_at_the_input(): void
    {
        // The Product Master screen's rule, kept through the refactor: a person who may not make a product is told so (403), not told what is wrong with the form (422).
        $sales = $this->user(self::SALES);
        $before = $this->counts();

        $this->postJson('/api/product-master', ['kind' => 'product'], $sales['h'])->assertStatus(403);
        $this->postJson('/api/product-master', ['kind' => 'product', 'name' => 'Payroll', 'key' => 'payroll'], $sales['h'])->assertStatus(403);
        // And the administrator, with the same empty form, is told what is wrong with it.
        $this->postJson('/api/product-master', ['kind' => 'product'], $this->user(self::ADMIN)['h'])->assertStatus(422);

        $this->assertSame($before, $this->counts());
    }

    public function test_d2_the_controller_keeps_no_second_private_copy_of_line_creation(): void
    {
        $src = (string) file_get_contents(base_path('app/Http/Controllers/Api/Crm/CrmProductController.php'));

        // assertFalse(str_contains()), not assertStringNotContainsString(): on a failure the latter prints the whole controller.
        $this->assertFalse(str_contains($src, 'g2g_signal_products'), 'the signal-product row is written by the shared domain method only');
        $this->assertFalse(str_contains($src, 'function resolveOrCreateProductLine'), 'no private copy of the line creation is left in the controller');
    }

    // ---- D3: a service keeps its line ----

    public function test_d3_a_service_is_stored_with_its_line_and_its_master_row_follows_it(): void
    {
        $admin = $this->user(self::ADMIN);
        $line = $this->sharedLine();

        $res = $this->postJson('/api/crm/products', ['itemType' => 'service', 'name' => 'Consulting', 'assignedTo' => $admin['id'], 'productMasterId' => $line], $admin['h']);

        $this->assertSame(201, $res->getStatusCode(), (string) $res->getContent());
        $this->assertSame($line, $res->json('data.productMasterId'));
        $id = (int) $res->json('data.id');
        $this->assertSame($line, (int) DB::table('crm_products')->where('id', $id)->value('product_master_id'));
        $this->assertSame($line, (int) DB::table('g2g_product_master')->where('crm_product_id', $id)->value('parent_id'));
        $this->assertSame($line, $this->getJson('/api/crm/products/' . $id, $admin['h'])->json('data.productMasterId'), 'and it is visible when read back');
    }

    public function test_d3_a_service_keeps_its_line_across_an_edit_that_does_not_mention_it(): void
    {
        $admin = $this->user(self::ADMIN);
        $line = $this->sharedLine();
        // A service that already carries a line, as the tenant-6 seeder left two of them.
        $id = (int) DB::table('crm_products')->insertGetId([
            'sub_institute_id' => self::T, 'product_no' => 'SVC-SEED0001', 'item_type' => 'service', 'name' => 'Seeded Service', 'is_active' => 1, 'assigned_to' => $admin['id'],
            'product_master_id' => $line, 'created_by' => $admin['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->putJson('/api/crm/products/' . $id, ['name' => 'Seeded Service, renamed', 'unitPrice' => 750], $admin['h']);

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $this->assertSame($line, $res->json('data.productMasterId'));
        $this->assertSame($line, (int) DB::table('crm_products')->where('id', $id)->value('product_master_id'), 'an edit that does not send the line leaves the stored one alone');
        $this->assertSame($line, (int) DB::table('g2g_product_master')->where('crm_product_id', $id)->value('parent_id'));
    }

    public function test_d3_a_service_line_changes_or_clears_only_when_it_is_sent(): void
    {
        $admin = $this->user(self::ADMIN);
        $first = $this->sharedLine('scholarclone');
        $second = $this->sharedLine('g2g');
        $id = $this->makeItem('service', ['name' => 'Training', 'productMasterId' => $first]);

        $moved = $this->putJson('/api/crm/products/' . $id, ['productMasterId' => $second], $admin['h']);
        $this->assertSame(200, $moved->getStatusCode(), (string) $moved->getContent());
        $this->assertSame($second, $moved->json('data.productMasterId'));
        $this->assertSame($second, (int) DB::table('g2g_product_master')->where('crm_product_id', $id)->value('parent_id'));

        $cleared = $this->putJson('/api/crm/products/' . $id, ['productMasterId' => null], $admin['h']);
        $this->assertSame(200, $cleared->getStatusCode());
        $this->assertNull($cleared->json('data.productMasterId'));
        $this->assertNull(DB::table('crm_products')->where('id', $id)->value('product_master_id'));
        $this->assertNull(DB::table('g2g_product_master')->where('crm_product_id', $id)->value('parent_id'));
    }

    public function test_d3_a_service_can_name_its_line_by_name_on_create_and_on_update(): void
    {
        // The form sends a line by NAME as well as by id; a Service follows either way. (A test that only sends the id would let the by-name path go on nulling it.)
        $admin = $this->user(self::ADMIN);
        $scholar = $this->sharedLine('scholarclone');
        $g2g = $this->sharedLine('g2g');
        $lineOf = fn (int $id) => [(int) DB::table('crm_products')->where('id', $id)->value('product_master_id'), (int) DB::table('g2g_product_master')->where('crm_product_id', $id)->value('parent_id')];

        $created = $this->postJson('/api/crm/products', ['itemType' => 'service', 'name' => 'Onboarding', 'assignedTo' => $admin['id'], 'productLineName' => 'scholarclone'], $admin['h']);
        $this->assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $id = (int) $created->json('data.id');
        $this->assertSame($scholar, $created->json('data.productMasterId'));
        $this->assertSame([$scholar, $scholar], $lineOf($id), 'created under an existing line named in any case');

        $newLine = $this->postJson('/api/crm/products', ['itemType' => 'service', 'name' => 'Field visit', 'assignedTo' => $admin['id'], 'productLineName' => 'Field Services'], $admin['h']);
        $this->assertSame(201, $newLine->getStatusCode(), (string) $newLine->getContent());
        $fieldLine = (int) $this->lineRows('Field Services')['master']->id;
        $this->assertSame($fieldLine, $newLine->json('data.productMasterId'), 'an administrator may make a new line for a service too');
        $this->assertSame([$fieldLine, $fieldLine], $lineOf((int) $newLine->json('data.id')));

        $moved = $this->putJson('/api/crm/products/' . $id, ['productLineName' => 'G2G'], $admin['h']);
        $this->assertSame(200, $moved->getStatusCode(), (string) $moved->getContent());
        $this->assertSame($g2g, $moved->json('data.productMasterId'));
        $this->assertSame([$g2g, $g2g], $lineOf($id), 'moved to another existing line by name');

        $fresh = $this->putJson('/api/crm/products/' . $id, ['productLineName' => 'Service Desk Tools'], $admin['h']);
        $this->assertSame(200, $fresh->getStatusCode(), (string) $fresh->getContent());
        $deskLine = (int) $this->lineRows('Service Desk Tools')['master']->id;
        $this->assertSame($deskLine, $fresh->json('data.productMasterId'));
        $this->assertSame([$deskLine, $deskLine], $lineOf($id), 'moved to a line made on the spot');
    }

    public function test_d3_a_service_line_must_be_a_visible_product_not_a_sub_product_or_another_organisations(): void
    {
        $admin = $this->user(self::ADMIN);
        $before = $this->counts();

        foreach ([$this->otherTenantLine(), $this->subProductId(), 999999] as $bad) {
            $res = $this->postJson('/api/crm/products', ['itemType' => 'service', 'name' => 'Bad line', 'assignedTo' => $admin['id'], 'productMasterId' => $bad], $admin['h']);
            $this->assertSame(422, $res->getStatusCode(), "line id {$bad}");
            $this->assertSame('The selected product line is invalid or not accessible.', $res->json('message'));
        }
        $id = $this->makeItem('service', ['name' => 'Good service']);
        foreach ([$this->otherTenantLine(), $this->subProductId()] as $bad) {
            $this->putJson('/api/crm/products/' . $id, ['productMasterId' => $bad], $admin['h'])->assertStatus(422);
        }

        $after = $this->counts();
        $this->assertSame($before['crm'] + 1, $after['crm'], 'only the good service was written');
    }

    public function test_d3_a_product_line_must_be_a_visible_product_too_a_sub_product_is_not_one(): void
    {
        $admin = $this->user(self::ADMIN);
        $sub = $this->subProductId();
        $before = $this->counts();   // after the fixture: the Sub-product is a master row too

        $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Bad line', 'assignedTo' => $admin['id'], 'productMasterId' => $sub], $admin['h'])->assertStatus(422);

        $this->assertSame($before, $this->counts());
    }

    public function test_d3_a_product_with_an_existing_line_id_still_works_on_create_and_update(): void
    {
        $admin = $this->user(self::ADMIN);
        $line = $this->sharedLine();
        $id = $this->makeItem('product', ['name' => 'Anchor', 'productMasterId' => $line]);

        $this->assertSame($line, (int) DB::table('crm_products')->where('id', $id)->value('product_master_id'));
        $this->putJson('/api/crm/products/' . $id, ['name' => 'Anchor, renamed'], $admin['h'])->assertOk()->assertJsonPath('data.productMasterId', $line);
        $this->assertSame($line, (int) DB::table('g2g_product_master')->where('crm_product_id', $id)->value('parent_id'));
    }

    // ---- D4: history needs the right to VIEW that item type ----

    public function test_d4_hr_cannot_read_a_products_history_but_an_administrator_and_a_viewer_can(): void
    {
        $id = $this->makeItem('product', ['name' => 'Priced']);
        $this->audit('crm_products', $id, 'crm.product.updated', [
            ['field' => 'cost_price', 'oldValue' => '10.00', 'newValue' => '12.50'],
            ['field' => 'commission_rate', 'oldValue' => '2.000', 'newValue' => '3.500'],
        ]);
        $hr = $this->user(self::HR);
        $admin = $this->user(self::ADMIN);
        $viewer = $this->user(self::VIEWER);

        $denied = $this->getJson('/api/crm/products/' . $id . '/history', $hr['h']);
        $this->assertSame(403, $denied->getStatusCode(), 'HR holds the Portfolio but not Products');
        $this->assertStringNotContainsString('cost_price', (string) $denied->getContent());
        $this->assertStringNotContainsString('commission_rate', (string) $denied->getContent());

        $seen = $this->getJson('/api/crm/products/' . $id . '/history', $admin['h']);
        $this->assertSame(200, $seen->getStatusCode());
        $this->assertSame(['cost_price', 'commission_rate'], array_column($seen->json('data.0.changes'), 'field'), 'the history is real, and it is what HR must not read');
        $this->getJson('/api/crm/products/' . $id . '/history', $viewer['h'])->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/crm/products/999999/history', $hr['h'])->assertStatus(404);
    }

    public function test_d4_the_right_follows_the_item_type_of_the_row(): void
    {
        $product = $this->makeItem('product', ['name' => 'A product']);
        $service = $this->makeItem('service', ['name' => 'A service']);
        $this->audit('crm_products', $product, 'crm.product.updated', [['field' => 'name', 'oldValue' => 'a', 'newValue' => 'b']]);
        $this->audit('crm_products', $service, 'crm.product.updated', [['field' => 'name', 'oldValue' => 'a', 'newValue' => 'b']]);
        $sales = $this->user(self::SALES);   // Products yes, Services no

        $this->getJson('/api/crm/products/' . $product . '/history', $sales['h'])->assertOk();
        $this->getJson('/api/crm/products/' . $service . '/history', $sales['h'])->assertStatus(403);
    }

    public function test_d4_only_the_product_controller_defines_the_history_hook_so_no_other_controller_changes(): void
    {
        $definers = [];
        foreach (glob(base_path('app/Http/Controllers/Api/Crm/*.php')) as $file) {
            if (str_contains((string) file_get_contents($file), 'function mayReadHistory')) {
                $definers[] = basename($file);
            }
        }
        sort($definers);

        $this->assertSame(['CrmProductController.php'], $definers);
    }

    public function test_d4_another_controller_that_uses_the_trait_still_answers_a_person_who_has_no_product_rights(): void
    {
        $vendor = (int) DB::table('crm_vendors')->insertGetId(['vendor_no' => 'V-1', 'name' => 'Acme Supplies', 'is_active' => 1, 'sub_institute_id' => self::T, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('crm_vendors', $vendor, 'crm.vendor.updated', [['field' => 'name', 'oldValue' => 'Acme', 'newValue' => 'Acme Supplies']]);
        $hr = $this->user(self::HR);          // Vendors: view. Products: nothing.
        $sales = $this->user(self::SALES);    // Vendors: nothing.

        $res = $this->getJson('/api/crm/vendors/' . $vendor . '/history', $hr['h']);
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $this->assertSame('crm.vendor.updated', $res->json('data.0.type'));
        $this->getJson('/api/crm/vendors/' . $vendor . '/history', $sales['h'])->assertStatus(403);   // the route's own right, as before
    }

    // ---- D5: a line id must be a visible Product on import and in the writer ----

    public function test_d5_import_reports_a_row_with_a_bad_line_id_and_still_imports_the_others(): void
    {
        $admin = $this->user(self::ADMIN);
        $line = $this->sharedLine();
        $row = fn (string $name, $lineId = null) => ['name' => $name, 'assignedTo' => $admin['id'], 'unitPrice' => 10] + ($lineId === null ? [] : ['productMasterId' => $lineId]);

        $res = $this->postJson('/api/crm/products/import', ['itemType' => 'product', 'rows' => [
            $row('Imp Good Line', $line),
            $row('Imp Sub Product', $this->subProductId()),
            $row('Imp Other Org', $this->otherTenantLine()),
            $row('Imp Missing', 999999),
            $row('Imp No Line'),
        ]], $admin['h']);

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        $this->assertSame(2, $res->json('data.created'));
        $results = $res->json('data.results');
        $this->assertSame([true, false, false, false, true], array_column($results, 'ok'));
        $this->assertSame([1, 2, 3, 4, 5], array_column($results, 'row'), 'the same shape the importer always used: row, ok, reason');
        foreach ([1, 2, 3] as $i) {
            $this->assertSame('The selected product line is invalid or not accessible.', $results[$i]['reason']);
        }
        $this->assertSame('2 of 5 row(s) imported.', $res->json('message'));
        $this->assertSame(['Imp Good Line', 'Imp No Line'], DB::table('crm_products')->orderBy('id')->pluck('name')->all(), 'the bad rows were not written');
        $this->assertSame($line, (int) DB::table('crm_products')->where('name', 'Imp Good Line')->value('product_master_id'));
        $this->assertNull(DB::table('crm_products')->where('name', 'Imp No Line')->value('product_master_id'));
    }

    public function test_d5_an_archived_product_is_still_a_visible_product_as_it_always_was(): void
    {
        // LeadLinks::productVisible has always counted an archived Product ("a record is filed under what it was about"); the new check keeps that, on every path.
        $admin = $this->user(self::ADMIN);
        $line = $this->sharedLine('g2g');
        DB::table('g2g_product_master')->where('id', $line)->update(['status' => 'archived']);

        $created = $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Under an archived line', 'assignedTo' => $admin['id'], 'productMasterId' => $line], $admin['h']);
        $imported = $this->postJson('/api/crm/products/import', ['itemType' => 'product', 'rows' => [['name' => 'Imported under it', 'assignedTo' => $admin['id'], 'productMasterId' => $line]]], $admin['h']);
        $item = $this->postJson('/api/product-master', ['kind' => 'item', 'name' => 'Item under it', 'sell' => ['type' => 'product'], 'parentId' => $line], $admin['h']);

        $this->assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $this->assertSame([true], array_column($imported->json('data.results'), 'ok'));
        $this->assertSame(201, $item->getStatusCode(), (string) $item->getContent());
    }

    public function test_d5_import_checks_a_services_line_the_same_way(): void
    {
        $admin = $this->user(self::ADMIN);
        $line = $this->sharedLine();

        $res = $this->postJson('/api/crm/products/import', ['itemType' => 'service', 'rows' => [
            ['name' => 'Imp Service Good', 'assignedTo' => $admin['id'], 'productMasterId' => $line],
            ['name' => 'Imp Service Bad', 'assignedTo' => $admin['id'], 'productMasterId' => $this->subProductId()],
        ]], $admin['h']);

        $this->assertSame([true, false], array_column($res->json('data.results'), 'ok'));
        $this->assertSame($line, (int) DB::table('crm_products')->where('name', 'Imp Service Good')->value('product_master_id'));
        $this->assertSame(0, DB::table('crm_products')->where('name', 'Imp Service Bad')->count());
    }

    public function test_d5_the_writer_refuses_a_parent_that_is_not_a_visible_product_and_writes_nothing(): void
    {
        $admin = $this->user(self::ADMIN);

        foreach (['sub-product' => $this->subProductId(), 'other organisation' => $this->otherTenantLine(), 'nothing there' => 999999] as $why => $bad) {
            $before = $this->counts();
            foreach (['parentId', 'parent_id'] as $field) {
                $res = $this->postJson('/api/product-master', ['kind' => 'item', 'name' => 'Item under ' . $why, 'sell' => ['type' => 'product'], $field => $bad], $admin['h']);
                $this->assertSame(422, $res->getStatusCode(), "{$why} via {$field}: " . $res->getContent());
                $this->assertArrayHasKey('parentId', (array) $res->json('errors'), "{$why} via {$field}");
            }
            $this->assertSame($before, $this->counts(), "{$why}: nothing was written");
        }
    }

    public function test_d5_the_writer_still_accepts_a_visible_product_and_no_parent_at_all(): void
    {
        $admin = $this->user(self::ADMIN);
        $line = $this->sharedLine();

        $with = $this->postJson('/api/product-master', ['kind' => 'item', 'name' => 'Item under a line', 'sell' => ['type' => 'product'], 'parentId' => $line], $admin['h']);
        $without = $this->postJson('/api/product-master', ['kind' => 'item', 'name' => 'Item with no line', 'sell' => ['type' => 'service']], $admin['h']);

        $this->assertSame(201, $with->getStatusCode(), (string) $with->getContent());
        $this->assertSame(201, $without->getStatusCode(), (string) $without->getContent());
        $this->assertSame($line, (int) DB::table('crm_products')->where('name', 'Item under a line')->value('product_master_id'));
        $this->assertSame($line, (int) DB::table('g2g_product_master')->where('name', 'Item under a line')->value('parent_id'));
        $this->assertNull(DB::table('crm_products')->where('name', 'Item with no line')->value('product_master_id'));
    }
}
