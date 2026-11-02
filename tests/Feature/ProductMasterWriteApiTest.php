<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductMaster;
use App\Domain\ProductMaster\ProductMasterBackfill;
use App\Domain\ProductMaster\ProductMasterInvalid;
use App\Domain\ProductMaster\ProductWriter;
use App\Domain\Signals\Products\ProductCatalog;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Product Master write API (slice 1, task 6, Docs/agentic-crm/slices/S01-product-master.md): create, update, archive and restore.
 * Every refusal must leave NOTHING written, a failure part-way must roll everything back, and the rights of the screens it writes through
 * (administrator for a product, the Portfolio for an offer, Products or Services for a catalogue item) must hold.
 */
class ProductMasterWriteApiTest extends TestCase
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
        foreach (['2026_12_07_090000_create_g2g_product_master_table', '2026_12_07_090100_ensure_g2g_signal_products_table'] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        Schema::create('g2g_product_offers', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('offer_id', 50);
            $t->string('name', 191);
            $t->string('parent_product', 100)->nullable();
            $t->text('modules_components')->nullable();
            $t->text('what_it_does')->nullable();
            $t->text('needs_solved')->nullable();
            $t->text('primary_segments')->nullable();
            $t->text('trigger_signals')->nullable();
            $t->string('deployment_model', 100)->nullable();
            $t->string('readiness_status', 100)->nullable();
            $t->boolean('readiness_confirmed')->default(false);
            $t->text('bundles_with')->nullable();
            $t->text('proof_points')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('partner_sellable')->default(false);
            $t->boolean('is_user_edited')->default(false);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
        Schema::create('crm_products', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('product_no', 100)->nullable();
            $t->string('item_type', 20)->default('product');
            $t->string('name', 191);
            $t->string('sku', 100)->nullable();
            $t->string('gl_account', 100)->nullable();
            $t->decimal('unit_price', 15, 2)->nullable();
            $t->string('currency', 10)->nullable();
            $t->unsignedBigInteger('tax_rate_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('crm_tax_rates', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('name');
        });
        Schema::create('g2g_company_opportunities', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('business_fit', 20)->nullable();
            $t->string('relevant_offer_id', 50)->nullable();
            $t->boolean('is_sample')->default(false);
        });

        RoleKey::flushCache();
        Cache::flush();
        ProductCatalog::forgetCustom();

        $menu = fn (int $id, string $name, int $parent, string $link) => DB::table('tblmenumaster_g2g')->insert(['id' => $id, 'menu_name' => $name, 'parent_id' => $parent, 'level' => $parent === 0 ? 1 : 2, 'access_link' => $link, 'sort_order' => $id]);
        $menu(199, 'Sales & Marketing Intelligence', 0, '/module/crm');
        $menu(468, 'Products', 199, '/module/crm/sales/products');
        $menu(469, 'Services', 199, '/module/crm/sales/services');
        (require base_path('database/migrations/2026_12_07_090200_create_product_master_menu_row.php'))->up();

        DB::table('tbluserprofilemaster')->insert([
            ['id' => 1, 'name' => 'Admin', 'role_key' => 'administrator', 'sub_institute_id' => self::T],
            ['id' => 2, 'name' => 'Employee', 'role_key' => 'employee', 'sub_institute_id' => self::T],
            ['id' => 3, 'name' => 'Admin', 'role_key' => 'administrator', 'sub_institute_id' => self::T2],
            ['id' => 5, 'name' => 'HR', 'role_key' => 'hr_manager', 'sub_institute_id' => self::T],
        ]);
        $leaf = (int) DB::table('tblmenumaster_g2g')->where('access_link', '/module/crm/product-master')->value('id');
        foreach ([1, 2, 3, 5] as $p) {
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $p === 3 ? self::T2 : self::T, 'menu_id' => 199, 'profile_id' => $p, 'can_view' => 1]);
            // Full rights on the Product Master leaf for everyone in this fixture: the route lets them in, so the checks under test are ProductWriter's own.
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $p === 3 ? self::T2 : self::T, 'menu_id' => $leaf, 'profile_id' => $p, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1]);
        }
        // Catalogue rights: the administrator may add and edit Products and add Services; HR and the employee have none.
        DB::table('tblgroupwise_rights_g2g')->insert([
            ['sub_institute_id' => self::T, 'menu_id' => 468, 'profile_id' => 1, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1],
            ['sub_institute_id' => self::T, 'menu_id' => 469, 'profile_id' => 1, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 0, 'can_delete' => 0],
        ]);
        DB::table('crm_tax_rates')->insert([['id' => 7, 'sub_institute_id' => self::T, 'name' => 'GST 18'], ['id' => 8, 'sub_institute_id' => self::T2, 'name' => 'Other organisation']]);

        DB::table('g2g_product_offers')->insert([
            ['sub_institute_id' => self::T, 'offer_id' => 'P1', 'name' => 'Scholar One', 'parent_product' => 'Scholar K-12', 'readiness_status' => 'Live – with client'],
            ['sub_institute_id' => null, 'offer_id' => 'SHARED-1', 'name' => 'Shared offer', 'parent_product' => 'G2G', 'readiness_status' => 'Live – with client'],
        ]);
        (new ProductMasterBackfill)->run(self::T, true);
    }

    private function h(int $profile = 1): array
    {
        $tenant = $profile === 3 ? self::T2 : self::T;
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    private function row(string $key, int $tenant = self::T): ProductMaster
    {
        return ProductMaster::where('sub_institute_id', $tenant)->where('product_key', $key)->firstOrFail();
    }

    private function counts(): array
    {
        return [
            'master' => DB::table('g2g_product_master')->count(), 'offers' => DB::table('g2g_product_offers')->count(),
            'catalogue' => DB::table('crm_products')->count(), 'own' => DB::table('g2g_signal_products')->count(),
        ];
    }

    private function scholarId(): int
    {
        return (int) $this->row('scholarclone', 0)->id;
    }

    private function sub(array $over = []): array
    {
        return array_replace_recursive([
            'kind' => 'sub_product', 'name' => 'Scholar Seven', 'productId' => $this->scholarId(), 'offerId' => 'P7', 'readiness' => 'Prototype',
            'about' => ['whatItDoes' => 'Runs the school office.', 'needsSolved' => ['Fee collection', 'Attendance'], 'primarySegments' => ['K-12 schools']],
        ], $over);
    }

    // ---- products ----

    public function test_only_an_administrator_can_create_a_product_and_a_refusal_writes_nothing(): void
    {
        $before = $this->counts();

        foreach ([2, 5] as $notAdmin) {
            $this->postJson('/api/product-master', ['kind' => 'product', 'name' => 'Payroll', 'key' => 'payroll'], $this->h($notAdmin))
                ->assertStatus(403)->assertJsonPath('status', 0);
        }
        $this->assertSame($before, $this->counts(), 'a refusal writes nothing at all, not even half');
    }

    public function test_an_administrator_creates_a_product_and_it_gets_its_own_signal_key_and_group_name(): void
    {
        $res = $this->postJson('/api/product-master', ['kind' => 'product', 'name' => 'Payroll', 'key' => 'payroll'], $this->h())->assertStatus(201);

        $this->assertSame('product', $res->json('data.kind'));
        $this->assertSame('payroll', $res->json('data.signalKey'));
        $this->assertSame('Payroll', $res->json('data.portfolioName'));
        $this->assertFalse($res->json('data.shared'));
        $own = DB::table('g2g_signal_products')->where('sub_institute_id', self::T)->first();
        $this->assertSame(['payroll', 'Payroll', 'payroll', 'payroll'], [$own->key, $own->label, $own->business_fit, $own->icp_product_line]);
    }

    public function test_the_product_key_and_name_rules_are_returned_field_by_field_and_nothing_is_written(): void
    {
        $before = $this->counts();
        $h = $this->h();

        foreach ([
            [['key' => 'Bad Key!', 'name' => 'Payroll'], 'key'],
            [['key' => 'all', 'name' => 'Payroll'], 'key'],                 // reserved
            [['key' => 'scholar', 'name' => 'Payroll'], 'key'],             // a built-in product's stored value
            [['key' => 'payroll', 'name' => 'Enterprise Brain'], 'name'],   // a built-in product's name
            [['key' => 'payroll', 'name' => 'X'], 'name'],                  // too short
            [['key' => 'payroll'], 'name'],                                 // missing
        ] as [$body, $field]) {
            $res = $this->postJson('/api/product-master', ['kind' => 'product'] + $body, $h)->assertStatus(422);
            $this->assertArrayHasKey($field, $res->json('errors'), json_encode($body));
        }
        $this->assertSame($before, $this->counts());

        $this->postJson('/api/product-master', ['kind' => 'product', 'name' => 'Payroll', 'key' => 'payroll'], $h)->assertStatus(201);
        $this->postJson('/api/product-master', ['kind' => 'product', 'name' => 'Payroll Two', 'key' => 'payroll'], $h)->assertStatus(422);   // the same key again
        $this->postJson('/api/product-master', ['kind' => 'nonsense', 'name' => 'x'], $h)->assertStatus(422)->assertJsonStructure(['errors' => ['kind']]);
    }

    // ---- sub-products ----

    public function test_a_sub_product_is_created_in_the_portfolio_and_the_master_with_readiness_left_unconfirmed(): void
    {
        $res = $this->postJson('/api/product-master', $this->sub(), $this->h())->assertStatus(201);

        $this->assertSame('sub_product', $res->json('data.kind'));
        $this->assertSame('scholarclone/p7', $res->json('data.key'));
        $this->assertSame('ScholarClone', $res->json('data.parent.name'));
        $this->assertSame('P7', $res->json('data.offer.offerId'));
        $offer = DB::table('g2g_product_offers')->where('offer_id', 'P7')->first();
        $this->assertSame('Scholar K-12', $offer->parent_product, "the offer is grouped under the product's Portfolio name");
        $this->assertSame('Prototype', $offer->readiness_status);
        $this->assertSame(0, (int) $offer->readiness_confirmed, 'readiness is confirmed only by a person through the Portfolio, never here');
        $this->assertSame(1, (int) $offer->is_user_edited);
        $this->assertSame(['Fee collection', 'Attendance'], json_decode($offer->needs_solved, true));
        $this->assertSame(self::T, (int) $offer->sub_institute_id);
    }

    public function test_hr_may_add_to_the_portfolio_but_an_employee_may_not(): void
    {
        $this->postJson('/api/product-master', $this->sub(['offerId' => 'P8']), $this->h(5))->assertStatus(201);

        $before = $this->counts();
        $this->postJson('/api/product-master', $this->sub(['offerId' => 'P9']), $this->h(2))->assertStatus(403);
        $this->assertSame($before, $this->counts());
    }

    public function test_sub_product_validation_and_clashes_write_nothing(): void
    {
        $before = $this->counts();
        $h = $this->h();

        $this->postJson('/api/product-master', $this->sub(['productId' => 999999]), $h)->assertStatus(422)->assertJsonStructure(['errors' => ['productId']]);
        $this->postJson('/api/product-master', $this->sub(['readiness' => 'Whatever']), $h)->assertStatus(422)->assertJsonStructure(['errors' => ['readiness']]);
        $this->postJson('/api/product-master', $this->sub(['offerId' => str_repeat('x', 45)]), $h)->assertStatus(422)->assertJsonStructure(['errors' => ['offerId']]);   // key longer than 50
        $this->postJson('/api/product-master', $this->sub(['about' => ['needsSolved' => 'not a list']]), $h)->assertStatus(422);
        $this->postJson('/api/product-master', $this->sub(['name' => '']), $h)->assertStatus(422);
        $this->postJson('/api/product-master', $this->sub(['offerId' => 'P1']), $h)->assertStatus(409);          // this organisation's own offer id
        $this->postJson('/api/product-master', $this->sub(['offerId' => 'SHARED-1']), $h)->assertStatus(409);    // a shared offer's id
        $this->assertSame($before, $this->counts());
    }

    public function test_a_sub_product_can_be_sold_in_crm_in_the_same_step(): void
    {
        $res = $this->postJson('/api/product-master', $this->sub(['sell' => ['enabled' => true, 'type' => 'product', 'sku' => 'P7', 'taxRateId' => 7, 'basePrice' => 1500, 'currency' => 'INR', 'glAccount' => '4000']]), $this->h())->assertStatus(201);

        $crm = $res->json('data.crmProduct');
        $this->assertSame('P7', $crm['sku']);
        $this->assertSame('product', $crm['type']);
        $this->assertEquals(1500.0, $crm['unitPrice']);
        $row = DB::table('crm_products')->where('id', $crm['id'])->first();
        $this->assertStringStartsWith('PRD-', $row->product_no);
        $this->assertSame('Scholar Seven', $row->name);
        $this->assertSame(self::T, (int) $row->sub_institute_id);
    }

    public function test_the_catalogue_rights_and_the_tax_rate_are_checked_before_anything_is_written(): void
    {
        $before = $this->counts();

        // HR may add to the Portfolio but has no right on the CRM catalogue: the whole request is refused, the offer is not created either.
        $this->postJson('/api/product-master', $this->sub(['sell' => ['enabled' => true, 'type' => 'product']]), $this->h(5))->assertStatus(403);
        // The administrator has no right on... services' edit, but may add one; a tax rate of another organisation is refused.
        $this->postJson('/api/product-master', $this->sub(['sell' => ['enabled' => true, 'type' => 'product', 'taxRateId' => 8]]), $this->h())->assertStatus(422)->assertJsonStructure(['errors' => ['sell.taxRateId']]);
        $this->assertSame($before, $this->counts());
    }

    public function test_a_failure_part_way_rolls_the_offer_back(): void
    {
        $before = $this->counts();
        // Make the catalogue insert fail AFTER the offer was written: a required column the writer does not set.
        Schema::drop('crm_products');
        Schema::create('crm_products', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('must_have_a_value');
        });

        $this->assertThrows(
            fn () => $this->withoutExceptionHandling()->postJson('/api/product-master', $this->sub(['sell' => ['enabled' => true, 'type' => 'product']]), $this->h()),
            \Illuminate\Database\QueryException::class
        );
        $this->assertSame($before['offers'], DB::table('g2g_product_offers')->count(), 'the offer written before the failure was rolled back');
        $this->assertSame($before['master'], DB::table('g2g_product_master')->count());
    }

    // ---- items ----

    public function test_a_service_can_be_added_to_the_catalogue_through_the_master(): void
    {
        $res = $this->postJson('/api/product-master', ['kind' => 'item', 'name' => 'Training day', 'sell' => ['type' => 'service', 'sku' => 'TRN-1', 'basePrice' => 25000, 'currency' => 'INR']], $this->h())->assertStatus(201);

        $this->assertSame('item', $res->json('data.kind'));
        $this->assertSame('service', $res->json('data.crmProduct.type'));
        $this->assertStringStartsWith('crm/svc-', $res->json('data.key'));
        $this->assertStringStartsWith('SVC-', DB::table('crm_products')->value('product_no'));
        $this->assertNull($res->json('data.offer'), 'a catalogue-only item has no Portfolio offer');

        $before = $this->counts();
        $this->postJson('/api/product-master', ['kind' => 'item', 'name' => 'x service', 'sell' => ['type' => 'service']], $this->h(5))->assertStatus(403);
        $this->postJson('/api/product-master', ['kind' => 'item', 'name' => 'Odd type', 'sell' => ['type' => 'gadget']], $this->h())->assertStatus(422);
        $this->postJson('/api/product-master', ['kind' => 'item', 'name' => 'No sell section'], $this->h())->assertStatus(422);
        $this->assertSame($before, $this->counts());
    }

    // ---- update ----

    public function test_updating_a_sub_product_changes_the_offer_and_mirrors_the_name_everywhere(): void
    {
        $h = $this->h();
        $id = $this->postJson('/api/product-master', $this->sub(['sell' => ['enabled' => true, 'type' => 'product', 'sku' => 'P7', 'basePrice' => 100]]), $h)->json('data.id');

        $res = $this->putJson('/api/product-master/' . $id, ['name' => 'Scholar Seven Plus', 'readiness' => 'In development', 'about' => ['whatItDoes' => 'Updated text.'], 'sell' => ['enabled' => true, 'sku' => 'P7-PLUS', 'basePrice' => 250]], $h)->assertOk();

        $this->assertSame('Scholar Seven Plus', $res->json('data.name'));
        $offer = DB::table('g2g_product_offers')->where('offer_id', 'P7')->first();
        $this->assertSame('Scholar Seven Plus', $offer->name);
        $this->assertSame('In development', $offer->readiness_status);
        $this->assertSame('Updated text.', $offer->what_it_does);
        $this->assertSame(['Fee collection', 'Attendance'], json_decode($offer->needs_solved, true), 'a field that was not sent is left alone');
        $this->assertSame(0, (int) $offer->readiness_confirmed);
        $crm = DB::table('crm_products')->first();
        $this->assertSame(['Scholar Seven Plus', 'P7-PLUS'], [$crm->name, $crm->sku]);
        $this->assertEquals(250.0, $crm->unit_price);
    }

    public function test_an_update_can_switch_on_crm_but_not_take_a_sub_product_out_of_the_catalogue(): void
    {
        $h = $this->h();
        $id = $this->postJson('/api/product-master', $this->sub(), $h)->json('data.id');
        $this->assertNull(DB::table('crm_products')->first());

        $this->putJson('/api/product-master/' . $id, ['sell' => ['enabled' => true, 'type' => 'product', 'sku' => 'P7']], $h)->assertOk()->assertJsonPath('data.crmProduct.sku', 'P7');
        $this->assertSame(1, DB::table('crm_products')->count());

        $this->putJson('/api/product-master/' . $id, ['sell' => ['enabled' => false]], $h)->assertStatus(409);
        $this->assertSame(1, DB::table('crm_products')->count(), 'nothing is deleted');
    }

    public function test_what_cannot_be_updated_is_refused_clearly(): void
    {
        $h = $this->h();
        $before = $this->counts();

        $this->putJson('/api/product-master/' . $this->scholarId(), ['name' => 'Renamed'], $h)->assertStatus(403);                    // a built-in product is shared
        $shared = (int) $this->row('g2g/shared-1', 0)->id;
        $this->putJson('/api/product-master/' . $shared, ['name' => 'Renamed shared offer'], $h)->assertStatus(403);                // organisation 0's row
        $mine = (int) $this->row('scholarclone/p1')->id;
        $this->putJson('/api/product-master/' . $mine, ['readiness' => 'Not a real state'], $h)->assertStatus(422);
        $this->putJson('/api/product-master/' . $mine, ['offerId' => 'NEW-ID'], $h)->assertStatus(422);                              // the offer id never changes
        $this->putJson('/api/product-master/' . $mine, ['productId' => $this->scholarId()], $h)->assertStatus(422);                  // nor does the product it belongs to
        $this->putJson('/api/product-master/' . $mine, ['name' => 'Scholar One'], $this->h(2))->assertStatus(403);                   // an employee
        $this->putJson('/api/product-master/999999', ['name' => 'x'], $h)->assertStatus(404);
        $this->assertSame($before, $this->counts());

        // A custom product cannot be renamed (the Portfolio groups its sub-products by the name) but an unchanged name is fine.
        $pid = (int) $this->postJson('/api/product-master', ['kind' => 'product', 'name' => 'Payroll', 'key' => 'payroll'], $h)->json('data.id');
        $this->putJson('/api/product-master/' . $pid, ['name' => 'Payroll Plus'], $h)->assertStatus(422);
        $this->putJson('/api/product-master/' . $pid, ['name' => 'Payroll'], $h)->assertOk();
    }

    public function test_another_organisations_record_is_a_404_for_every_write(): void
    {
        $other = $this->postJson('/api/product-master', $this->sub(['offerId' => 'Q1']), $this->h(5));
        $this->assertSame(201, $other->status());
        $theirs = (int) $other->json('data.id');
        $stranger = $this->h(3);

        $this->putJson('/api/product-master/' . $theirs, ['name' => 'Hijacked'], $stranger)->assertStatus(404);
        $this->postJson('/api/product-master/' . $theirs . '/archive', [], $stranger)->assertStatus(404);
        $this->postJson('/api/product-master/' . $theirs . '/restore', [], $stranger)->assertStatus(404);
        $this->assertSame('Scholar Seven', DB::table('g2g_product_offers')->where('offer_id', 'Q1')->value('name'));
    }

    // ---- archive and restore ----

    public function test_archive_and_restore_follow_the_product_and_sub_product_rules(): void
    {
        $h = $this->h();
        $pid = (int) $this->postJson('/api/product-master', ['kind' => 'product', 'name' => 'Payroll', 'key' => 'payroll'], $h)->json('data.id');
        $sid = (int) $this->postJson('/api/product-master', $this->sub(['productId' => $pid, 'offerId' => 'PAY-1', 'name' => 'Payroll Core']), $h)->json('data.id');

        $this->postJson('/api/product-master/' . $pid . '/archive', [], $h)->assertStatus(409);                       // still has an active sub-product
        $this->postJson('/api/product-master/' . $sid . '/archive', [], $h)->assertOk()->assertJsonPath('data.status', 'archived');
        $this->assertSame('PAY-1', DB::table('g2g_product_offers')->where('offer_id', 'PAY-1')->value('offer_id'), 'the Portfolio offer is not deleted');
        $this->postJson('/api/product-master/' . $pid . '/archive', [], $h)->assertOk();
        $this->postJson('/api/product-master/' . $sid . '/restore', [], $h)->assertStatus(409);                       // restore its product first
        $this->postJson('/api/product-master/' . $pid . '/restore', [], $h)->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson('/api/product-master/' . $sid . '/restore', [], $h)->assertOk()->assertJsonPath('data.status', 'active');

        $this->postJson('/api/product-master/' . $this->scholarId() . '/archive', [], $h)->assertStatus(403);         // a shared built-in product
        $this->postJson('/api/product-master/' . $pid . '/archive', [], $this->h(2))->assertStatus(403);              // an employee cannot archive a product
        $this->postJson('/api/product-master/999999/archive', [], $h)->assertStatus(404);
    }

    public function test_an_archived_row_leaves_the_pickers_and_the_default_list(): void
    {
        $h = $this->h();
        $sid = (int) $this->postJson('/api/product-master', $this->sub(), $h)->json('data.id');
        $this->postJson('/api/product-master/' . $sid . '/archive', [], $h)->assertOk();

        $this->assertNull(collect($this->getJson('/api/product-master/options', $h)->json('data'))->firstWhere('key', 'scholarclone/p7'));
        $subs = collect($this->getJson('/api/product-master', $h)->json('data.products'))->firstWhere('key', 'scholarclone')['subProducts'];
        $this->assertNull(collect($subs)->firstWhere('key', 'scholarclone/p7'));
        $archived = collect($this->getJson('/api/product-master?status=archived', $h)->json('data.products'))->firstWhere('key', 'scholarclone')['subProducts'];
        $this->assertSame(['scholarclone/p7'], array_column($archived, 'key'));
    }

    // ---- rules a mutation sweep showed were not yet pinned down (each was broken on purpose and nothing failed) ----

    public function test_changing_a_product_needs_an_administrator(): void
    {
        $pid = (int) $this->postJson('/api/product-master', ['kind' => 'product', 'name' => 'Payroll', 'key' => 'payroll'], $this->h())->json('data.id');

        $this->putJson('/api/product-master/' . $pid, ['name' => 'Payroll'], $this->h(5))->assertStatus(403);   // HR may write the Portfolio but is not an administrator
        $this->putJson('/api/product-master/' . $pid, ['name' => 'Payroll'], $this->h(2))->assertStatus(403);
    }

    public function test_another_organisations_tax_rate_is_refused_on_every_path_that_takes_one(): void
    {
        $h = $this->h();
        $subId = (int) $this->postJson('/api/product-master', $this->sub(['sell' => ['enabled' => true, 'type' => 'product', 'sku' => 'P7']]), $h)->json('data.id');
        $itemId = (int) $this->postJson('/api/product-master', ['kind' => 'item', 'name' => 'Setup fee', 'sell' => ['type' => 'product']], $h)->json('data.id');
        $before = $this->counts();
        $taxRates = fn () => DB::table('crm_products')->orderBy('id')->pluck('tax_rate_id')->all();
        $rates = $taxRates();

        $this->postJson('/api/product-master', ['kind' => 'item', 'name' => 'Another fee', 'sell' => ['type' => 'product', 'taxRateId' => 8]], $h)->assertStatus(422)->assertJsonStructure(['errors' => ['sell.taxRateId']]);
        $this->putJson('/api/product-master/' . $subId, ['sell' => ['enabled' => true, 'taxRateId' => 8]], $h)->assertStatus(422)->assertJsonStructure(['errors' => ['sell.taxRateId']]);
        $this->putJson('/api/product-master/' . $itemId, ['sell' => ['taxRateId' => 8]], $h)->assertStatus(422)->assertJsonStructure(['errors' => ['sell.taxRateId']]);

        $this->assertSame($before, $this->counts());
        $this->assertSame($rates, $taxRates(), 'no catalogue row picked up the other organisation\'s tax rate');
    }

    public function test_a_sub_product_key_that_a_master_row_already_holds_is_a_clash_even_without_an_offer(): void
    {
        $h = $this->h();
        $this->postJson('/api/product-master', $this->sub(), $h)->assertStatus(201);
        DB::table('g2g_product_offers')->where('offer_id', 'P7')->delete();   // the Portfolio offer was deleted; its Product Master row is still there
        $before = $this->counts();

        $res = $this->postJson('/api/product-master', $this->sub(), $h)->assertStatus(409);
        $this->assertStringContainsString('already exists', (string) $res->json('errors.offerId.0'), 'said up front, not as a late database error');
        $this->assertSame($before, $this->counts());
    }

    public function test_a_sub_product_can_only_go_under_an_active_product_of_the_organisation(): void
    {
        $h = $this->h();
        $archived = (int) $this->postJson('/api/product-master', ['kind' => 'product', 'name' => 'Payroll', 'key' => 'payroll'], $h)->json('data.id');
        $this->postJson('/api/product-master/' . $archived . '/archive', [], $h)->assertOk();
        $aSubProduct = (int) $this->row('scholarclone/p1')->id;
        $theirs = (int) $this->postJson('/api/product-master', ['kind' => 'product', 'name' => 'Admissions', 'key' => 'admissions'], $this->h(3))->json('data.id');
        $before = $this->counts();

        foreach (['an archived product' => $archived, 'a sub-product' => $aSubProduct, 'another organisation\'s product' => $theirs] as $why => $parent) {
            $res = $this->postJson('/api/product-master', $this->sub(['productId' => $parent, 'offerId' => 'PX']), $h)->assertStatus(422);
            $this->assertArrayHasKey('productId', $res->json('errors'), $why);
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_editing_a_catalogue_item_needs_the_edit_right_of_its_own_type(): void
    {
        $h = $this->h();
        $service = (int) $this->postJson('/api/product-master', ['kind' => 'item', 'name' => 'Training day', 'sell' => ['type' => 'service']], $h)->json('data.id');
        $product = (int) $this->postJson('/api/product-master', ['kind' => 'item', 'name' => 'Starter kit', 'sell' => ['type' => 'product']], $h)->json('data.id');

        $this->putJson('/api/product-master/' . $service, ['name' => 'Renamed training'], $h)->assertStatus(403);   // the administrator may add Services but not edit them
        $this->assertSame('Training day', DB::table('crm_products')->where('item_type', 'service')->value('name'));
        $this->putJson('/api/product-master/' . $product, ['name' => 'Renamed kit'], $h)->assertOk()->assertJsonPath('data.name', 'Renamed kit');
        $this->assertSame('Renamed kit', DB::table('crm_products')->where('item_type', 'product')->value('name'));
    }

    public function test_an_offer_that_is_not_the_organisations_own_is_never_written_even_if_a_row_points_at_it(): void
    {
        // Inconsistent data on purpose: this organisation's row points at the SHARED offer (no organisation, read by every organisation).
        $mine = $this->row('scholarclone/p1');
        $mine->update(['offer_row_id' => (int) DB::table('g2g_product_offers')->whereNull('sub_institute_id')->value('id')]);

        $this->putJson('/api/product-master/' . $mine->id, ['name' => 'Hijacked shared offer'], $this->h())->assertStatus(409);
        $this->assertSame('Shared offer', DB::table('g2g_product_offers')->whereNull('sub_institute_id')->value('name'));
    }

    public function test_the_writer_itself_refuses_another_organisations_row_and_a_shared_row(): void
    {
        // The controller's tenant-scoped lookup comes first; this is the second, independent line of defence inside the writer.
        $stranger = ['user' => (object) ['user_profile_id' => 3], 'user_id' => 99, 'sub_institute_id' => self::T2];
        $mine = $this->row('scholarclone/p1');
        $shared = $this->row('g2g/shared-1', 0);
        $writer = new ProductWriter;

        foreach ([
            [fn () => $writer->update($stranger, $mine, ['name' => 'Hijacked']), 404],
            [fn () => $writer->setStatus($stranger, $mine, ProductMaster::STATUS_ARCHIVED), 404],
            [fn () => $writer->update($stranger, $shared, ['name' => 'Hijacked']), 403],
            [fn () => $writer->setStatus($stranger, $shared, ProductMaster::STATUS_ARCHIVED), 403],
        ] as [$call, $status]) {
            try {
                $call();
                $this->fail('the writer accepted a row that is not the caller\'s to change');
            } catch (ProductMasterInvalid $e) {
                $this->assertSame($status, $e->status);
            }
        }
        $this->assertSame('active', $mine->fresh()->status);
    }

    public function test_only_a_duplicate_key_error_counts_as_a_clash_in_either_database_dialect(): void
    {
        $isDuplicate = new \ReflectionMethod(ProductWriter::class, 'isDuplicate');
        $error = function (string $message, ?int $driverCode) {
            $pdo = new \PDOException($message);
            $pdo->errorInfo = ['23000', $driverCode, $message];

            return new \Illuminate\Database\QueryException('mysql', 'insert into t values (?)', [1], $pdo);
        };

        foreach ([
            'MariaDB by its error number alone' => [$error('Integrity constraint violation', 1062), true],
            'MariaDB by its wording' => [$error("SQLSTATE[23000]: Duplicate entry 'x' for key 'k'", null), true],
            'SQLite' => [$error('UNIQUE constraint failed: g2g_product_master.product_key', 19), true],
            'a deadlock' => [$error('Deadlock found when trying to get lock', 1213), false],
            'a missing column' => [$error("Unknown column 'x' in 'field list'", 1054), false],
            'a trigger or other constraint' => [$error('master insert failed', 19), false],
        ] as $what => [$exception, $expected]) {
            $this->assertSame($expected, $isDuplicate->invoke(null, $exception), $what);
        }
    }

    public function test_with_menu_rights_on_the_portfolio_right_comes_from_the_portfolio_menu_row(): void
    {
        config(['signals.permissions.use_menu_rights' => true]);
        Cache::flush();
        $before = $this->counts();

        // HR is allowed by role while menu rights are off; with them on it needs a rights row on /portfolio, and there is no such row yet.
        $this->postJson('/api/product-master', $this->sub(['offerId' => 'P10']), $this->h(5))->assertStatus(403);
        $this->assertSame($before, $this->counts());

        DB::table('tblmenumaster_g2g')->insert(['id' => 300, 'menu_name' => 'Product Portfolio', 'parent_id' => 199, 'level' => 2, 'access_link' => '/portfolio', 'sort_order' => 300]);
        DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => self::T, 'menu_id' => 300, 'profile_id' => 5, 'can_view' => 1, 'can_add' => 1]);
        Cache::flush();
        $this->postJson('/api/product-master', $this->sub(['offerId' => 'P10']), $this->h(5))->assertStatus(201);
        $this->postJson('/api/product-master', $this->sub(['offerId' => 'P11']), $this->h(2))->assertStatus(403);   // the employee has no row
    }

    public function test_a_catalogue_item_is_not_left_behind_when_its_master_row_cannot_be_written(): void
    {
        $before = $this->counts();
        DB::unprepared("CREATE TRIGGER no_master_rows BEFORE INSERT ON g2g_product_master BEGIN SELECT RAISE(ABORT, 'master insert failed'); END");

        $this->assertThrows(
            fn () => $this->withoutExceptionHandling()->postJson('/api/product-master', ['kind' => 'item', 'name' => 'Training day', 'sell' => ['type' => 'service']], $this->h()),
            \Illuminate\Database\QueryException::class
        );
        $this->assertSame($before, $this->counts(), 'the catalogue row written before the failure was rolled back');
    }

    public function test_an_own_product_is_not_left_behind_when_its_master_row_cannot_be_written(): void
    {
        $before = $this->counts();
        DB::unprepared("CREATE TRIGGER no_master_rows BEFORE INSERT ON g2g_product_master BEGIN SELECT RAISE(ABORT, 'master insert failed'); END");

        $this->assertThrows(
            fn () => $this->withoutExceptionHandling()->postJson('/api/product-master', ['kind' => 'product', 'name' => 'Payroll', 'key' => 'payroll'], $this->h()),
            \Illuminate\Database\QueryException::class
        );
        $this->assertSame($before, $this->counts(), 'the own-product row written before the failure was rolled back');
    }

    public function test_a_sub_product_update_is_rolled_back_when_its_catalogue_update_fails(): void
    {
        $h = $this->h();
        $id = (int) $this->postJson('/api/product-master', $this->sub(['sell' => ['enabled' => true, 'type' => 'product', 'sku' => 'P7']]), $h)->json('data.id');
        DB::unprepared("CREATE TRIGGER no_catalogue_updates BEFORE UPDATE ON crm_products BEGIN SELECT RAISE(ABORT, 'catalogue update failed'); END");

        $this->assertThrows(
            fn () => $this->withoutExceptionHandling()->putJson('/api/product-master/' . $id, ['name' => 'Scholar Seven Plus', 'sell' => ['enabled' => true, 'sku' => 'P7-PLUS']], $h),
            \Illuminate\Database\QueryException::class
        );
        $this->assertSame('Scholar Seven', DB::table('g2g_product_offers')->where('offer_id', 'P7')->value('name'), 'the offer change made before the failure was rolled back');
        $this->assertSame('Scholar Seven', $this->row('scholarclone/p7')->name);
    }

    // ---- the route's own rights ----

    public function test_each_write_needs_its_own_right_on_the_screen(): void
    {
        $leaf = (int) DB::table('tblmenumaster_g2g')->where('access_link', '/module/crm/product-master')->value('id');
        DB::table('tblgroupwise_rights_g2g')->where('menu_id', $leaf)->where('profile_id', 1)->update(['can_add' => 0, 'can_edit' => 0, 'can_delete' => 0]);
        Cache::flush();
        $h = $this->h();
        $mine = (int) $this->row('scholarclone/p1')->id;

        $this->postJson('/api/product-master', $this->sub(), $h)->assertStatus(403);
        $this->putJson('/api/product-master/' . $mine, ['name' => 'x'], $h)->assertStatus(403);
        $this->postJson('/api/product-master/' . $mine . '/restore', [], $h)->assertStatus(403);
        $this->postJson('/api/product-master/' . $mine . '/archive', [], $h)->assertStatus(403);
        $this->getJson('/api/product-master', $h)->assertOk();     // view is separate

        DB::table('tblgroupwise_rights_g2g')->where('menu_id', $leaf)->where('profile_id', 1)->update(['can_add' => 1]);
        $this->postJson('/api/product-master', $this->sub(), $h)->assertStatus(201);
        $this->putJson('/api/product-master/' . $mine, ['name' => 'x'], $h)->assertStatus(403);   // add does not give edit
    }
}
