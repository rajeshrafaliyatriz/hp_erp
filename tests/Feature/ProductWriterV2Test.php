<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\CatalogueFields;
use App\Domain\ProductMaster\ProductIntegrity;
use App\Domain\Signals\Products\SubProductIcp;
use App\Services\Events\EventRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCrmProductApiWorld;
use Tests\TestCase;

/**
 * The one-record writer, v2 (slice 15, P2-C; Docs/agentic-crm/briefs/S15-P2C-writer-v2.md): POST /api/product-master and PUT /api/product-master/{id} write a
 * Sub-product as ONE record that may hold an About (the Portfolio offer), a Sell section (the CRM catalogue row) and its ICPs, each optional, on one master row.
 *
 * The rules (the owner's decisions of 13 Nov 2026) and where each is pinned:
 *   R1  at least one section; Sell alone makes an `item`; an About added to an `item` makes it a sub_product with the SAME key; the offer id never changes
 *   R2  four Sell states (not sold, sold, paused, in the Recycle Bin): switching off pauses, switching on resumes or RESTORES the same row, never a second one
 *   R3  one name, one Product, mirrored to the master row, the offer and the catalogue row in one transaction
 *   R4  rights by section (About and ICP: the Portfolio right; Sell: the right of its type; a new Product: an administrator); a refusal is a 403 that names the
 *       section and writes nothing for any section; the route asks only for a signed-in token
 *   R5  the response carries `sell` (and the old `crmProduct`) only for a caller who may view the Sell section of that type
 *   R6  every store the write changed has an audit line, written after the commit; a failing event store never undoes the write
 *   R7  all stores or none, whichever store fails
 *   R8  readiness is never confirmed here; a shared row can never carry Sell
 * The integrity checker (product-master:check) must be clean after every write, which assertChecker() asks.
 *
 * P2-F (14 Nov 2026) pins the four items P2-C left owed (the section "P2-F" at the end of the class):
 *   G-6  the number columns: one past the column and one under zero are a 422 on the field (the data sets of badValues; the scan is tests/Unit/CatalogueFieldsScanTest, E2)
 *   G-7  the Sell read returns everything the old product page showed: the picture's address, the tax rate's name, the Product, who and when
 *   D-1  ONE switch: `enabled` and `isActive` that contradict are a 422 on sell.isActive
 *   D-3  often sold together: every id is an offer the organisation can see and never the offer itself, else a 422 on about.oftenSoldTogether.N
 *
 * The people (BuildsCrmProductApiWorld has 1 = administrator of organisation 1, 2 = an employee with every right on Products and Services but none on the Portfolio,
 * 3 = organisation 2's administrator): 5 = HR (writes the Portfolio by role, holds no row at all on Products or Services), 6 = may only ADD on Products and Services,
 * 7 = may only EDIT, 9 = may only VIEW, 10 = holds nothing, 11 = HR who also holds the Products row but not the Services row.
 */
class ProductWriterV2Test extends TestCase
{
    use BuildsCrmProductApiWorld;

    private const T = 1;

    private const T2 = 2;

    private const ADMIN = 1;

    private const SALES = 2;

    private const HR = 5;

    private const ADD_ONLY = 6;

    private const EDIT_ONLY = 7;

    private const VIEW_ONLY = 9;

    private const NOBODY = 10;

    private const HR_PRODUCTS = 11;

    private const TAX = 7;

    private const OTHER_TAX = 8;

    /** The event store, recording what the writer sends it (and able to refuse). */
    private object $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrmProductApiWorld();
        (require base_path('database/migrations/2026_10_27_200000_add_icp_config_to_g2g_product_offers_table.php'))->up();

        DB::table('tbluserprofilemaster')->insert([
            ['id' => 5, 'name' => 'HR', 'role_key' => 'hr_manager', 'sub_institute_id' => self::T],
            ['id' => 6, 'name' => 'Add only', 'role_key' => 'employee', 'sub_institute_id' => self::T],
            ['id' => 7, 'name' => 'Edit only', 'role_key' => 'employee', 'sub_institute_id' => self::T],
            ['id' => 9, 'name' => 'Viewer', 'role_key' => 'employee', 'sub_institute_id' => self::T],
            ['id' => 10, 'name' => 'Nobody', 'role_key' => 'employee', 'sub_institute_id' => self::T],
            ['id' => 11, 'name' => 'HR with Products', 'role_key' => 'hr_manager', 'sub_institute_id' => self::T],
        ]);
        $grant = fn (int $profile, int $menu, int $view, int $add, int $edit, int $delete) => DB::table('tblgroupwise_rights_g2g')->insert([
            'sub_institute_id' => self::T, 'menu_id' => $menu, 'profile_id' => $profile, 'can_view' => $view, 'can_add' => $add, 'can_edit' => $edit, 'can_delete' => $delete,
        ]);
        foreach ([5, 6, 7, 9, 10, 11] as $profile) {
            $grant($profile, 199, 1, 0, 0, 0);
        }
        foreach ([468, 469] as $menu) {
            $grant(6, $menu, 1, 1, 0, 0);
            $grant(7, $menu, 1, 0, 1, 0);
            $grant(9, $menu, 1, 0, 0, 0);
        }
        $grant(11, 468, 1, 1, 1, 1);
        DB::table('crm_tax_rates')->insert([
            ['id' => self::TAX, 'sub_institute_id' => self::T, 'name' => 'GST 18', 'percentage' => 18],
            ['id' => self::OTHER_TAX, 'sub_institute_id' => self::T2, 'name' => 'Other organisation', 'percentage' => 5],
        ]);

        $this->events = new class extends EventRecorder {
            /** @var list<array<string, mixed>> */
            public array $lines = [];

            public bool $refuse = false;

            public function record(string $type, int $subInstituteId, string $entityType, ?int $entityId, ?int $actorId, array $payload, ?array $metadata = null, ?string $idempotencyKey = null, ?string $correlationId = null, ?string $causationId = null, ?int $actingForId = null, ?string $occurredAt = null): int
            {
                if ($this->refuse) {
                    throw new \RuntimeException('the event store is down');
                }
                $this->lines[] = ['type' => $type, 'tenant' => $subInstituteId, 'entityType' => $entityType, 'entityId' => $entityId, 'actor' => $actorId, 'changes' => $payload['changes'] ?? [], 'transactionLevel' => DB::transactionLevel()];

                return count($this->lines);
            }
        };
        $this->app->instance(EventRecorder::class, $this->events);
        Cache::flush();
    }

    // ================================================================== helpers

    /** @return array<string, mixed> */
    private function bearer(int $profile): array
    {
        return $this->headers($this->h($profile === 3 ? self::T2 : self::T, $profile));
    }

    private function doPost(array $body, int $profile = self::ADMIN): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/product-master', $body, $this->bearer($profile));
    }

    private function doPut(int $id, array $body, int $profile = self::ADMIN): \Illuminate\Testing\TestResponse
    {
        return $this->putJson('/api/product-master/' . $id, $body, $this->bearer($profile));
    }

    private function scholar(): int
    {
        return $this->sharedId('scholarclone');
    }

    /** @return array<string, mixed> the identity and the About of a new Sub-product */
    private function aboutBody(array $over = []): array
    {
        return array_replace([
            'kind' => 'sub_product', 'name' => 'Scholar Seven', 'productId' => $this->scholar(), 'offerId' => 'P7', 'readiness' => 'Prototype',
            'about' => ['whatItDoes' => 'Runs the school office.', 'needsSolved' => ['Fee collection']],
        ], $over);
    }

    /**
     * Three offers of the organisation (P1, P2, P3) for "often sold together" to point at. P2-F (rule D-3): every id in that list must be an offer the organisation
     * can see, so a test that sends the ids of fullAbout() makes them first. They are made by the writer itself (so the integrity checker stays clean), and the
     * audit lines of making them are forgotten, so a test that counts lines counts its own. Call it BEFORE counts() or snapshot().
     */
    private function siblings(): void
    {
        foreach (['P1' => 'Scholar One', 'P2' => 'Scholar Two', 'P3' => 'Scholar Three'] as $offerId => $name) {
            $this->doPost($this->aboutBody(['offerId' => $offerId, 'name' => $name]))->assertStatus(201);
        }
        $this->events->lines = [];
    }

    /** @return array<string, mixed> every field of the About section (its often-sold-together ids are the offers siblings() makes) */
    private function fullAbout(): array
    {
        return [
            'whatItDoes' => 'Runs the school office.', 'modulesComponents' => 'Fees, attendance, parent app', 'deploymentModel' => 'Cloud', 'proofPoints' => '40 schools in Gujarat',
            'notes' => 'Internal note', 'needsSolved' => ['Fee collection', 'Attendance'], 'primarySegments' => ['K12-PVT'], 'triggerSignals' => ['new_campus'],
            'pricingModel' => 'Per student per year', 'typicalDealBand' => '5-20 lakh', 'implementationEffort' => 'Medium', 'deliveryOwner' => 'Delivery team',
            'oftenSoldTogether' => ['P1', 'P2'],
        ];
    }

    /** @return array<string, mixed> every field of the Sell section */
    private function fullSell(array $over = []): array
    {
        return array_replace([
            'enabled' => true, 'type' => 'product', 'sku' => 'SKU-7', 'category' => 'Software', 'glAccount' => '4000', 'taxRateId' => self::TAX, 'unitPrice' => 1500.5,
            'costPrice' => 900, 'currency' => 'INR', 'commissionRate' => 7.5, 'vendor' => 'Acme Cloud', 'usageUnit' => 'per school', 'qtyInStock' => 12, 'qtyInDemand' => 3,
            'reorderLevel' => 2, 'weight' => 1.25, 'assignedTo' => 99, 'isActive' => true, 'descriptionOnQuotes' => 'Fees, attendance and the parent app.',
        ], $over);
    }

    /** One Sub-product with an About and a Sell section, made by the administrator. @return array{id: int, offer: int, crm: int} */
    private function both(array $about = [], array $sell = []): array
    {
        $res = $this->doPost($this->aboutBody($about + ['sell' => $this->fullSell($sell)]))->assertStatus(201);
        $master = $this->masterRow((int) $res->json('data.id'));

        return ['id' => (int) $master->id, 'offer' => (int) $master->offer_row_id, 'crm' => (int) $master->crm_product_id];
    }

    private function masterRow(int $id): object
    {
        return DB::table('g2g_product_master')->where('id', $id)->first();
    }

    private function crmRow(int $id): object
    {
        return DB::table('crm_products')->where('id', $id)->first();
    }

    private function offerRow(int $id): object
    {
        return DB::table('g2g_product_offers')->where('id', $id)->first();
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'master' => DB::table('g2g_product_master')->count(), 'offers' => DB::table('g2g_product_offers')->count(), 'crm' => DB::table('crm_products')->count(),
            'icp' => DB::table('g2g_icp_profiles')->count(), 'events' => count($this->events->lines),
        ];
    }

    /** Every row of the four stores, so a test can prove that nothing at all moved. @return array<string, list<array<string, mixed>>> */
    private function snapshot(): array
    {
        $out = [];
        foreach (['g2g_product_master', 'g2g_product_offers', 'crm_products', 'g2g_icp_profiles'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $out;
    }

    private function assertChecker(string $why): void
    {
        $report = (new ProductIntegrity(null, self::T))->check();
        $this->assertSame([], array_filter($report['counts']), "the integrity checker is clean {$why}: " . json_encode($report['findings']));
    }

    /** @param list<array<string, mixed>> $lines @return list<string> */
    private function types(array $lines): array
    {
        $types = array_map(fn (array $l): string => $l['type'] . '#' . $l['entityType'], $lines);
        sort($types);

        return $types;
    }

    /** @return array<string, mixed> the line of one type, or a failure */
    private function line(string $type): array
    {
        foreach ($this->events->lines as $line) {
            if ($line['type'] === $type) {
                return $line;
            }
        }
        $this->fail("no audit line of type {$type}; got " . implode(', ', array_column($this->events->lines, 'type')));
    }

    private function failWrites(string $verb, string $table): void
    {
        DB::unprepared("CREATE TRIGGER boom_{$verb}_{$table} BEFORE {$verb} ON {$table} BEGIN SELECT RAISE(ABORT, 'the {$table} write failed'); END");
    }

    // ================================================================== R1: the sections

    public function test_r1_about_only_makes_an_offer_and_a_sub_product_with_every_about_field_and_no_catalogue_row(): void
    {
        $this->siblings();
        $before = $this->counts();

        $res = $this->doPost($this->aboutBody(['about' => $this->fullAbout(), 'readinessConfirmed' => true, 'readiness_confirmed' => true]), self::HR)->assertStatus(201);

        $master = $this->masterRow((int) $res->json('data.id'));
        $offer = $this->offerRow((int) $master->offer_row_id);
        $this->assertSame(['sub_product', 'scholarclone/p7', 'portfolio', null], [$master->kind, $master->product_key, $master->origin, $master->crm_product_id]);
        $this->assertSame($this->scholar(), (int) $master->parent_id);
        $this->assertSame(['Scholar K-12', 'Prototype', 0, 1, 'Scholar Seven'], [$offer->parent_product, $offer->readiness_status, (int) $offer->readiness_confirmed, (int) $offer->is_user_edited, $offer->name]);
        $this->assertSame(
            ['Runs the school office.', 'Fees, attendance, parent app', 'Cloud', '40 schools in Gujarat', 'Internal note', 'Per student per year', '5-20 lakh', 'Medium', 'Delivery team'],
            [$offer->what_it_does, $offer->modules_components, $offer->deployment_model, $offer->proof_points, $offer->notes, $offer->pricing_model, $offer->typical_deal_band, $offer->implementation_effort, $offer->delivery_owner],
        );
        $this->assertSame(
            [['Fee collection', 'Attendance'], ['K12-PVT'], ['new_campus'], ['P1', 'P2']],
            [json_decode($offer->needs_solved, true), json_decode($offer->primary_segments, true), json_decode($offer->trigger_signals, true), json_decode($offer->bundles_with, true)],
            'the often-sold-together list is the offer\'s bundles_with column',
        );
        $this->assertSame(['master' => $before['master'] + 1, 'offers' => $before['offers'] + 1, 'crm' => $before['crm'], 'icp' => $before['icp']], array_diff_key($this->counts(), ['events' => 1]));

        $data = $res->json('data');
        $about = $data['offer']['about'];
        $expected = $this->fullAbout();
        ksort($about);
        ksort($expected);
        $this->assertSame($expected, $about, 'a read returns every field the write stored');
        $this->assertArrayHasKey('sell', $data, 'the response always says whether there is a Sell section');
        $this->assertNull($data['sell']);
        $this->assertNull($data['crmProduct']);
        $this->assertChecker('after an About-only create');
    }

    public function test_r1_sell_only_makes_an_item_with_the_catalogue_row_and_every_sell_field(): void
    {
        $before = $this->counts();

        $res = $this->doPost(['kind' => 'sub_product', 'name' => 'Starter kit', 'productId' => $this->scholar(), 'sell' => $this->fullSell()], self::SALES)->assertStatus(201);

        $master = $this->masterRow((int) $res->json('data.id'));
        $crm = $this->crmRow((int) $master->crm_product_id);
        $this->assertSame(['item', 'crm'], [$master->kind, $master->origin]);
        $this->assertNull($master->offer_row_id);
        $this->assertStringStartsWith('crm/prd-', $master->product_key);
        $this->assertSame([$this->scholar(), $this->scholar()], [(int) $master->parent_id, (int) $crm->product_master_id], 'the item sits under the Product, in both stores');
        $this->assertEquals(
            ['product', 'Starter kit', 'SKU-7', 'Software', '4000', self::TAX, 1500.5, 900, 'INR', 7.5, 'Acme Cloud', 'per school', 12, 3, 2, 1.25, 99, 1, 'Fees, attendance and the parent app.'],
            [$crm->item_type, $crm->name, $crm->sku, $crm->category, $crm->gl_account, $crm->tax_rate_id, $crm->unit_price, $crm->cost_price, $crm->currency, $crm->commission_rate, $crm->vendor,
                $crm->usage_unit, $crm->qty_in_stock, $crm->qty_in_demand, $crm->reorder_level, $crm->weight, $crm->assigned_to, $crm->is_active, $crm->description],
        );
        $this->assertStringStartsWith('PRD-', $crm->product_no);
        $this->assertSame(['master' => $before['master'] + 1, 'offers' => $before['offers'], 'crm' => $before['crm'] + 1], array_intersect_key($this->counts(), ['master' => 1, 'offers' => 1, 'crm' => 1]));

        $sell = $res->json('data.sell');
        $this->assertSame(['sold', (int) $crm->id, 'product'], [$sell['state'], $sell['crmProductId'], $sell['type']], 'the response gives the id that the image, custom field and bundle calls are keyed by');
        $this->assertEquals(['costPrice' => 900, 'unitPrice' => 1500.5, 'commissionRate' => 7.5, 'qtyInStock' => 12, 'vendor' => 'Acme Cloud', 'descriptionOnQuotes' => 'Fees, attendance and the parent app.'],
            array_intersect_key($sell, array_flip(['costPrice', 'unitPrice', 'commissionRate', 'qtyInStock', 'vendor', 'descriptionOnQuotes'])));
        $this->assertSame($sell['crmProductId'], $res->json('data.crmProduct.id'), 'the old drawer\'s key is still there');
        $this->assertChecker('after a Sell-only create');
    }

    public function test_r1_a_service_never_carries_stock_or_a_vendor_and_base_price_is_an_alias_of_unit_price(): void
    {
        $withBasePrice = array_diff_key($this->fullSell(['type' => 'service']), ['unitPrice' => 1]) + ['basePrice' => 25000];
        $res = $this->doPost(['kind' => 'sub_product', 'name' => 'Training day', 'sell' => $withBasePrice], self::ADMIN)->assertStatus(201);

        $crm = $this->crmRow((int) $this->masterRow((int) $res->json('data.id'))->crm_product_id);
        $this->assertSame(['service', null, null, null, null], [$crm->item_type, $crm->vendor, $crm->qty_in_stock, $crm->qty_in_demand, $crm->reorder_level], 'stock and a vendor are product ideas');
        $this->assertStringStartsWith('SVC-', $crm->product_no);
        $this->assertEquals(25000, $crm->unit_price, 'basePrice is accepted as unitPrice');
        $this->assertEquals(25000, $res->json('data.sell.unitPrice'));

        // An edit of a service that sends stock and a vendor still ends with neither: the rule is on every write, as the CRM form has it.
        $this->doPut((int) $res->json('data.id'), ['sell' => ['sku' => 'SVC-9', 'vendor' => 'Acme', 'qtyInStock' => 5, 'qtyInDemand' => 1, 'reorderLevel' => 2]])->assertOk();
        $edited = $this->crmRow((int) $crm->id);
        $this->assertSame(['SVC-9', null, null, null, null], [$edited->sku, $edited->vendor, $edited->qty_in_stock, $edited->qty_in_demand, $edited->reorder_level]);

        // The new name wins when both are sent.
        $both = $this->doPost(['kind' => 'sub_product', 'name' => 'Setup day', 'sell' => $this->fullSell(['type' => 'service', 'unitPrice' => 10, 'basePrice' => 99])], self::ADMIN)->assertStatus(201);
        $this->assertEquals(10, $this->crmRow((int) $this->masterRow((int) $both->json('data.id'))->crm_product_id)->unit_price);
        $this->assertChecker('after services are made');
    }

    public function test_r1_about_and_sell_together_make_one_record_holding_both_pointers_with_one_name_and_one_product(): void
    {
        $before = $this->counts();

        $f = $this->both();

        $master = $this->masterRow($f['id']);
        $this->assertSame(['sub_product', $f['offer'], $f['crm']], [$master->kind, (int) $master->offer_row_id, (int) $master->crm_product_id]);
        $this->assertSame($before['master'] + 1, $this->counts()['master'], 'one master row, not one per store');
        $this->assertSame(['Scholar Seven', 'Scholar Seven', 'Scholar Seven'], [$master->name, $this->offerRow($f['offer'])->name, $this->crmRow($f['crm'])->name]);
        $this->assertSame([$this->scholar(), 'Scholar K-12', $this->scholar()], [(int) $master->parent_id, $this->offerRow($f['offer'])->parent_product, (int) $this->crmRow($f['crm'])->product_master_id]);
        $this->assertChecker('after a create with both sections');
    }

    public function test_r1_the_icp_is_written_with_the_about_in_the_same_request(): void
    {
        $icp = ['b2b' => ['target_segments' => ['K12-PVT'], 'buyer_roles' => ['Principal']], 'b2g' => ['target_segments' => ['K12-GOV'], 'label' => 'Government schools']];

        $res = $this->doPost($this->aboutBody(['icp' => $icp]))->assertStatus(201);

        $offer = $this->offerRow((int) $this->masterRow((int) $res->json('data.id'))->offer_row_id);
        $keys = DB::table('g2g_icp_profiles')->where('sub_institute_id', self::T)->orderBy('key')->pluck('key')->all();
        $this->assertSame([SubProductIcp::key('P7', 'b2b'), SubProductIcp::key('P7', 'b2g')], $keys);
        $this->assertSame(['b2b', 'b2g'], array_keys(array_filter(json_decode($offer->icp_config, true) ?? [], 'is_array')), 'the offer\'s own icp_config carries both models');
        $this->assertChecker('after a create with an ICP');
    }

    public function test_r1_an_icp_with_no_about_is_refused_and_so_is_a_request_with_no_section(): void
    {
        $before = $this->counts();

        $icpOnly = $this->doPost(['kind' => 'sub_product', 'name' => 'Only an ICP', 'productId' => $this->scholar(), 'icp' => ['b2b' => ['target_segments' => ['K12-PVT']]]])->assertStatus(422);
        $this->assertArrayHasKey('icp', $icpOnly->json('errors'));
        $none = $this->doPost(['kind' => 'sub_product', 'name' => 'Nothing at all', 'productId' => $this->scholar()])->assertStatus(422);
        $this->assertArrayHasKey('sections', $none->json('errors'));
        $off = $this->doPost(['kind' => 'sub_product', 'name' => 'Sell switched off', 'sell' => ['enabled' => false, 'type' => 'product']])->assertStatus(422);
        $this->assertArrayHasKey('sections', $off->json('errors'), 'a Sell section that is switched off is no section');
        $item = $this->doPost(['kind' => 'item', 'name' => 'An item with an About', 'sell' => ['type' => 'product'], 'about' => ['whatItDoes' => 'x'], 'offerId' => 'Z1', 'readiness' => 'Prototype'])->assertStatus(422);
        $this->assertArrayHasKey('kind', $item->json('errors'), 'a catalogue item is Sell only: send kind sub_product to give it an About');

        $this->assertSame($before, $this->counts());
    }

    public function test_r1_adding_an_about_to_an_item_makes_it_a_sub_product_with_the_same_key_and_the_same_catalogue_row(): void
    {
        $item = $this->doPost(['kind' => 'sub_product', 'name' => 'Starter kit', 'productId' => $this->scholar(), 'sell' => $this->fullSell()])->assertStatus(201);
        $id = (int) $item->json('data.id');
        $key = $this->masterRow($id)->product_key;
        $crmId = (int) $this->masterRow($id)->crm_product_id;
        $before = $this->counts();

        // The offer id and the readiness are required when an About arrives; nothing is written until both are there.
        $this->doPut($id, ['about' => ['whatItDoes' => 'Now it has an About.']])->assertStatus(422)->assertJsonStructure(['errors' => ['offerId']]);
        $this->doPut($id, ['offerId' => 'P8', 'about' => ['whatItDoes' => 'x']])->assertStatus(422)->assertJsonStructure(['errors' => ['readiness']]);
        $this->assertSame($before, $this->counts());
        $this->assertSame('item', $this->masterRow($id)->kind);

        $res = $this->doPut($id, ['offerId' => 'P8', 'readiness' => 'In development', 'about' => ['whatItDoes' => 'Now it has an About.']])->assertOk();

        $master = $this->masterRow($id);
        $offer = $this->offerRow((int) $master->offer_row_id);
        $this->assertSame(['sub_product', $key, $crmId], [$master->kind, $master->product_key, (int) $master->crm_product_id], 'same key, same catalogue row');
        $this->assertSame(['P8', 'Starter kit', 'Scholar K-12', 'In development', 0], [$offer->offer_id, $offer->name, $offer->parent_product, $offer->readiness_status, (int) $offer->readiness_confirmed]);
        $this->assertSame($before['crm'], $this->counts()['crm'], 'no second catalogue row');
        $this->assertSame('P8', $res->json('data.offer.offerId'));
        $this->assertNotNull($res->json('data.sell'), 'the Sell section is still there');
        $this->assertChecker('after an item gets an About');
    }

    public function test_r1_the_offer_id_never_changes_once_an_about_exists(): void
    {
        $f = $this->both();

        $this->doPut($f['id'], ['offerId' => 'P99'])->assertStatus(422)->assertJsonStructure(['errors' => ['offerId']]);
        $this->assertSame('P7', $this->offerRow($f['offer'])->offer_id);
        $this->assertSame('scholarclone/p7', $this->masterRow($f['id'])->product_key);

        $this->doPut($f['id'], ['offerId' => 'P7', 'about' => ['notes' => 'the same id sent again is fine']])->assertOk();
        $this->assertSame('the same id sent again is fine', $this->offerRow($f['offer'])->notes);
    }

    // ================================================================== update of each section

    public function test_about_edit_is_partial_and_never_touches_readiness_confirmation_or_the_catalogue(): void
    {
        $this->siblings();
        $f = $this->both(['about' => $this->fullAbout()]);
        DB::table('g2g_product_offers')->where('id', $f['offer'])->update(['readiness_confirmed' => 1]);
        $crmBefore = (array) $this->crmRow($f['crm']);

        $res = $this->doPut($f['id'], ['readiness' => 'In development', 'about' => ['whatItDoes' => 'New text.', 'oftenSoldTogether' => ['P3'], 'pricingModel' => 'Per school']], self::HR)->assertOk();

        $offer = $this->offerRow($f['offer']);
        $this->assertSame(['New text.', 'In development', 'Per school', 1], [$offer->what_it_does, $offer->readiness_status, $offer->pricing_model, (int) $offer->readiness_confirmed], 'confirmation is the administrator\'s own action: an edit leaves it as it was');
        $this->assertSame(['P3'], json_decode($offer->bundles_with, true));
        $this->assertSame(['Fees, attendance, parent app', 'Delivery team', ['Fee collection', 'Attendance']], [$offer->modules_components, $offer->delivery_owner, json_decode($offer->needs_solved, true)], 'a field that was not sent is left alone');
        $this->assertSame($crmBefore, (array) $this->crmRow($f['crm']), 'the catalogue row was not part of this edit');
        $this->assertNull($res->json('data.sell'), 'HR may write the About but is never shown a price');
        $this->assertChecker('after an About edit');
    }

    public function test_an_icp_edit_alone_updates_the_profile_and_raises_its_version(): void
    {
        $f = $this->both();
        $icp = ['b2b' => ['target_segments' => ['K12-PVT'], 'buyer_roles' => ['Principal']]];
        $this->doPut($f['id'], ['icp' => $icp])->assertOk();
        $key = SubProductIcp::key('P7', 'b2b');
        $this->assertSame(1, (int) DB::table('g2g_icp_profiles')->where('key', $key)->value('version'));

        $this->doPut($f['id'], ['icp' => ['b2b' => ['target_segments' => ['K12-PVT', 'K12-GOV'], 'buyer_roles' => ['Trustee']]]], self::HR)->assertOk();

        $this->assertSame(1, DB::table('g2g_icp_profiles')->where('key', $key)->count(), 'the same profile, not a second one');
        $this->assertSame(2, (int) DB::table('g2g_icp_profiles')->where('key', $key)->value('version'));
        $this->assertChecker('after an ICP edit');
    }

    public function test_a_sell_edit_is_partial_and_a_sales_profile_with_no_portfolio_right_may_make_it(): void
    {
        $this->siblings();
        $f = $this->both(['about' => $this->fullAbout()]);
        $offerBefore = (array) $this->offerRow($f['offer']);

        $res = $this->doPut($f['id'], ['sell' => ['unitPrice' => 2000, 'costPrice' => null, 'weight' => 3]], self::SALES)->assertOk();

        $crm = $this->crmRow($f['crm']);
        $this->assertEquals([2000, null, 3], [$crm->unit_price, $crm->cost_price, $crm->weight]);
        $this->assertEquals(['SKU-7', 7.5, 'Acme Cloud', 12, 1], [$crm->sku, $crm->commission_rate, $crm->vendor, $crm->qty_in_stock, $crm->is_active], 'a field that was not sent is left alone');
        $this->assertSame($offerBefore, (array) $this->offerRow($f['offer']), 'the About was not part of this edit');
        $this->assertEquals(2000, $res->json('data.sell.unitPrice'), 'a caller who may view prices gets them back');
        $this->assertChecker('after a Sell edit');
    }

    // ================================================================== R3: one name, one Product, mirrored

    public function test_r3_a_rename_and_a_move_to_another_product_reach_all_three_copies_in_one_request(): void
    {
        $f = $this->both();
        $eb = $this->sharedId('enterprise_brain');
        DB::table('g2g_product_offers')->where('id', $f['offer'])->update(['is_user_edited' => 0]);

        $this->doPut($f['id'], ['name' => 'Scholar Seven Plus', 'productId' => $eb])->assertOk();

        $master = $this->masterRow($f['id']);
        $this->assertSame(['Scholar Seven Plus', $eb, 'enterprise_brain/p7'], [$master->name, (int) $master->parent_id, $master->product_key], 'the key follows the Product, as the Portfolio always did');
        $offer = $this->offerRow($f['offer']);
        $this->assertSame(['Scholar Seven Plus', 'EB', 1], [$offer->name, $offer->parent_product, (int) $offer->is_user_edited], 'a rename is a user edit: an import must not overwrite it');
        $crm = $this->crmRow($f['crm']);
        $this->assertSame(['Scholar Seven Plus', $eb], [$crm->name, (int) $crm->product_master_id]);
        $this->assertChecker('after a rename and a move');
    }

    public function test_r3_a_failure_while_the_copies_are_renamed_leaves_every_copy_with_its_old_name(): void
    {
        $f = $this->both();
        $before = $this->snapshot();
        $this->failWrites('UPDATE', 'crm_products');

        $this->assertThrows(fn () => $this->withoutExceptionHandling()->putJson('/api/product-master/' . $f['id'], ['name' => 'Renamed', 'sell' => ['unitPrice' => 1]], $this->bearer(self::ADMIN)), QueryException::class);

        $this->assertSame($before, $this->snapshot(), 'the master row and the offer, written before the catalogue row failed, were taken back');
    }

    // ================================================================== R2: the four Sell states

    public function test_r2_switching_sell_off_pauses_the_row_and_switching_it_on_resumes_the_same_row(): void
    {
        $f = $this->both();

        $off = $this->doPut($f['id'], ['sell' => ['enabled' => false]])->assertOk();
        $row = $this->crmRow($f['crm']);
        $this->assertSame([0, null], [(int) $row->is_active, $row->deleted_at], 'paused: the row is still there, switched off, and not in the Recycle Bin');
        $this->assertSame(1, DB::table('crm_products')->count(), 'the 11 tables that point at it still have something to point at');
        $this->assertSame(['paused', $f['crm']], [$off->json('data.sell.state'), $off->json('data.sell.crmProductId')]);
        $this->assertSame($f['crm'], (int) $this->masterRow($f['id'])->crm_product_id, 'the master row still points at it');
        $this->assertChecker('after a pause');

        $on = $this->doPut($f['id'], ['sell' => ['enabled' => true]])->assertOk();
        $this->assertSame([1, 'sold', $f['crm']], [(int) $this->crmRow($f['crm'])->is_active, $on->json('data.sell.state'), $on->json('data.sell.crmProductId')]);
        $this->assertSame(1, DB::table('crm_products')->count());
        $this->assertChecker('after a resume');
    }

    public function test_r2_is_active_sets_the_flag_explicitly_and_a_second_pause_changes_nothing(): void
    {
        $f = $this->both();

        $this->doPut($f['id'], ['sell' => ['isActive' => false]])->assertOk();
        $this->assertSame(0, (int) $this->crmRow($f['crm'])->is_active);
        $this->assertSame('paused', $this->doPut($f['id'], ['sell' => ['enabled' => false]])->assertOk()->json('data.sell.state'));
        $lines = count($this->events->lines);
        $this->doPut($f['id'], ['sell' => ['enabled' => false]])->assertOk();
        $this->assertSame($lines, count($this->events->lines), 'a pause of a paused row writes no audit line');
        $this->doPut($f['id'], ['sell' => ['isActive' => true]])->assertOk();
        $this->assertSame(1, (int) $this->crmRow($f['crm'])->is_active);
        // A form sends a switch as text: "false" means off and "1" means on.
        $this->doPut($f['id'], ['sell' => ['enabled' => 'false']])->assertOk();
        $this->assertSame(0, (int) $this->crmRow($f['crm'])->is_active, 'the text "false" pauses');
        $this->doPut($f['id'], ['sell' => ['enabled' => '1']])->assertOk();
        $this->assertSame(1, (int) $this->crmRow($f['crm'])->is_active, 'the text "1" resumes');
    }

    public function test_r2_a_row_in_the_recycle_bin_is_restored_never_duplicated(): void
    {
        $f = $this->both();
        $this->deleteJson('/api/crm/products/' . $f['crm'], [], $this->bearer(self::ADMIN))->assertOk();
        $this->assertNotNull($this->crmRow($f['crm'])->deleted_at, 'the CRM screen put it in the Bin');
        $before = $this->counts();

        $res = $this->doPut($f['id'], ['sell' => ['enabled' => true, 'unitPrice' => 2000]])->assertOk();

        $this->assertSame($before['crm'], $this->counts()['crm'], 'no second catalogue row');
        $row = $this->crmRow($f['crm']);
        $this->assertSame([null, 1], [$row->deleted_at, (int) $row->is_active], 'the same row came back from the Bin, active');
        $this->assertEquals(2000, $row->unit_price, 'and took the new price in the same call');
        $this->assertSame($f['crm'], (int) $this->masterRow($f['id'])->crm_product_id);
        $this->assertSame(['sold', $f['crm']], [$res->json('data.sell.state'), $res->json('data.sell.crmProductId')]);
        $this->assertChecker('after a restore from the Bin');
    }

    public function test_r2_a_catalogue_only_item_in_the_bin_comes_back_active_with_its_master_row(): void
    {
        $id = (int) $this->doPost(['kind' => 'item', 'name' => 'Starter kit', 'sell' => ['type' => 'product']])->assertStatus(201)->json('data.id');
        $crmId = (int) $this->masterRow($id)->crm_product_id;
        $this->deleteJson('/api/crm/products/' . $crmId, [], $this->bearer(self::ADMIN))->assertOk();
        $this->assertSame('archived', $this->masterRow($id)->status, 'the sync archives the master row of a binned item');

        $this->doPut($id, ['sell' => ['enabled' => true]])->assertOk();

        $this->assertSame([null, 'active', 1], [$this->crmRow($crmId)->deleted_at, $this->masterRow($id)->status, DB::table('crm_products')->count()]);
        $this->assertChecker('after an item comes back from the Bin');
    }

    public function test_r2_switching_on_a_sub_product_that_was_never_sold_makes_the_catalogue_row_and_switching_off_one_never_sold_writes_nothing(): void
    {
        $id = (int) $this->doPost($this->aboutBody())->assertStatus(201)->json('data.id');
        $before = $this->counts();
        $this->doPut($id, ['sell' => ['enabled' => false, 'type' => 'product']])->assertOk();
        $this->assertSame($before['crm'], $this->counts()['crm'], 'off on a row that was never sold is nothing to do');

        $res = $this->doPut($id, ['sell' => ['enabled' => true, 'type' => 'service', 'sku' => 'SVC-1', 'unitPrice' => 500]])->assertOk();

        $master = $this->masterRow($id);
        $crm = $this->crmRow((int) $master->crm_product_id);
        $this->assertSame(['sub_product', 'service', 'Scholar Seven', $this->scholar()], [$master->kind, $crm->item_type, $crm->name, (int) $crm->product_master_id]);
        $this->assertSame($before['crm'] + 1, $this->counts()['crm']);
        $this->assertSame('sold', $res->json('data.sell.state'));
        // The row an update makes is audited as made, and the master row's new pointer is in its own line.
        $this->assertSame((int) $master->crm_product_id, $this->line('crm.product.created')['entityId']);
        $this->assertContains('crm_product_id', array_column($this->line('product.master.updated')['changes'], 'field'));
        $this->assertChecker('after Sell is switched on for the first time');
    }

    public function test_r2_a_type_never_changes_and_a_sell_section_with_no_switch_means_the_edit_of_what_is_there(): void
    {
        $f = $this->both();
        $this->doPut($f['id'], ['sell' => ['type' => 'service', 'sku' => 'X']])->assertStatus(422)->assertJsonStructure(['errors' => ['sell.type']]);
        $this->assertSame(['product', 'SKU-7'], [$this->crmRow($f['crm'])->item_type, $this->crmRow($f['crm'])->sku]);

        $this->doPut($f['id'], ['sell' => ['type' => 'product', 'sku' => 'SKU-8']])->assertOk();
        $this->assertSame('SKU-8', $this->crmRow($f['crm'])->sku);

        // With nothing to edit, a Sell section without the switch is ignored (as it always was); with a row in the Bin it asks to be switched on.
        $bare = (int) $this->doPost($this->aboutBody(['offerId' => 'P8', 'name' => 'Scholar Eight']))->assertStatus(201)->json('data.id');
        $before = $this->counts();
        $this->doPut($bare, ['sell' => ['sku' => 'IGNORED']])->assertOk();
        $this->assertSame($before['crm'], $this->counts()['crm']);
        $this->deleteJson('/api/crm/products/' . $f['crm'], [], $this->bearer(self::ADMIN))->assertOk();
        $this->doPut($f['id'], ['sell' => ['sku' => 'SKU-9']])->assertStatus(409);
        $this->assertSame('SKU-8', $this->crmRow($f['crm'])->sku);
    }

    // ================================================================== R4: rights by section

    public function test_r4_hr_writes_the_about_with_no_right_on_products_and_is_refused_the_sell_section_with_nothing_written(): void
    {
        $this->doPost($this->aboutBody(['offerId' => 'H1', 'name' => 'HR made this']), self::HR)->assertStatus(201);
        $before = $this->counts();
        $snapshot = $this->snapshot();

        $res = $this->doPost($this->aboutBody(['sell' => $this->fullSell()]), self::HR)->assertStatus(403);

        $this->assertArrayHasKey('sell', $res->json('errors'), 'the refusal names the section');
        $this->assertStringContainsString('Sell', (string) $res->json('message'));
        $this->assertSame($before, $this->counts());
        $this->assertSame($snapshot, $this->snapshot(), 'the About, which HR may write, was not written either');
    }

    public function test_r4_a_sales_profile_may_sell_but_is_refused_the_about_and_the_icp_with_nothing_written(): void
    {
        $this->doPost(['kind' => 'sub_product', 'name' => 'Sales made this', 'sell' => $this->fullSell()], self::SALES)->assertStatus(201);
        $before = $this->counts();
        $snapshot = $this->snapshot();

        $about = $this->doPost($this->aboutBody(['sell' => $this->fullSell(['sku' => 'S-2'])]), self::SALES)->assertStatus(403);
        $icp = $this->doPost(['kind' => 'sub_product', 'name' => 'Sell plus an ICP', 'productId' => $this->scholar(), 'sell' => $this->fullSell(), 'icp' => ['b2b' => ['target_segments' => ['K12-PVT']]]], self::SALES)->assertStatus(403);

        $this->assertArrayHasKey('about', $about->json('errors'));
        $this->assertArrayHasKey('icp', $icp->json('errors'));
        $this->assertSame($before, $this->counts());
        $this->assertSame($snapshot, $this->snapshot(), 'the Sell section, which a sales profile may write, was not written either');
    }

    public function test_r4_a_rename_of_a_sub_product_with_an_about_is_the_portfolios_right_and_a_rename_of_an_item_is_the_catalogues(): void
    {
        $f = $this->both();
        $item = (int) $this->doPost(['kind' => 'item', 'name' => 'Starter kit', 'sell' => ['type' => 'product']])->assertStatus(201)->json('data.id');
        $snapshot = $this->snapshot();

        $refused = $this->doPut($f['id'], ['name' => 'Renamed by sales', 'sell' => ['unitPrice' => 1]], self::SALES)->assertStatus(403);
        $this->assertArrayHasKey('about', $refused->json('errors'));
        $this->assertSame($snapshot, $this->snapshot());

        // An item has no About, so its name is the catalogue row's: HR (the Portfolio only) and a viewer may not rename it either.
        $this->doPut($item, ['name' => 'Renamed by HR'], self::HR)->assertStatus(403);
        $this->doPut($item, ['name' => 'Renamed by a viewer'], self::VIEW_ONLY)->assertStatus(403);
        $this->doPut($item, ['name' => 'Renamed by add only'], self::ADD_ONLY)->assertStatus(403);
        $this->assertSame($snapshot, $this->snapshot(), 'a refused rename changes nothing');

        $this->doPut($item, ['name' => 'Renamed kit'], self::SALES)->assertOk();
        $this->assertSame('Renamed kit', $this->masterRow($item)->name);
        $this->doPut($f['id'], ['sell' => ['unitPrice' => 1]], self::SALES)->assertOk();

        // An add right may bring a catalogue row back from the Recycle Bin, but a new name for it is an EDIT: both in one request is refused, and the restore alone is allowed.
        $binned = (int) $this->doPost(['kind' => 'item', 'name' => 'Old kit', 'sell' => ['type' => 'product']])->assertStatus(201)->json('data.id');
        $this->deleteJson('/api/crm/products/' . $this->masterRow($binned)->crm_product_id, [], $this->bearer(self::ADMIN))->assertOk();
        $snapshot = $this->snapshot();
        $this->doPut($binned, ['name' => 'New kit', 'sell' => ['enabled' => true]], self::ADD_ONLY)->assertStatus(403);
        $this->assertSame($snapshot, $this->snapshot(), 'the restore that was allowed was not written either');
        $this->doPut($binned, ['sell' => ['enabled' => true]], self::ADD_ONLY)->assertOk();
        $this->assertSame('Old kit', $this->masterRow($binned)->name);
        $this->assertChecker('after the allowed writes');
    }

    public function test_r4_no_right_on_any_section_present_is_a_403_before_the_body_is_judged_and_a_right_with_a_bad_body_is_a_422(): void
    {
        $badAbout = ['kind' => 'sub_product', 'name' => 'x', 'about' => ['whatItDoes' => 'text']];   // no offerId, no readiness, a name that is too short
        $badSell = ['kind' => 'sub_product', 'name' => 'Bad sell', 'sell' => ['enabled' => true, 'unitPrice' => 'not a number']];
        $before = $this->counts();

        $this->doPost($badAbout, self::NOBODY)->assertStatus(403);
        $this->doPost($badSell, self::NOBODY)->assertStatus(403);
        $this->doPost($badAbout, self::HR)->assertStatus(422);
        $this->doPost($badSell, self::SALES)->assertStatus(422);

        $this->assertSame($before, $this->counts());
    }

    public function test_r4_a_problem_with_the_row_is_said_only_to_a_person_who_may_write_its_section(): void
    {
        $f = $this->both();

        $this->doPut($f['id'], ['sell' => ['type' => 'service', 'sku' => 'X']], self::NOBODY)->assertStatus(403);
        $this->doPut($f['id'], ['sell' => ['type' => 'service', 'sku' => 'X']], self::ADMIN)->assertStatus(422)->assertJsonStructure(['errors' => ['sell.type']]);
        $this->deleteJson('/api/crm/products/' . $f['crm'], [], $this->bearer(self::ADMIN))->assertOk();
        $this->doPut($f['id'], ['sell' => ['sku' => 'X']], self::NOBODY)->assertStatus(403);
        // HR owns the About of this record but not its Sell section: it is told 403 about the Sell section, not the 409 about the Bin.
        $this->doPut($f['id'], ['sell' => ['sku' => 'X']], self::HR)->assertStatus(403);
        $this->doPut($f['id'], ['sell' => ['sku' => 'X']], self::ADMIN)->assertStatus(409);
    }

    public function test_r4_a_person_with_a_right_may_not_use_it_for_the_wrong_action_add_and_edit_are_separate_on_every_sell_change(): void
    {
        $f = $this->both();
        $binned = $this->both(['offerId' => 'P8', 'name' => 'Scholar Eight']);
        $this->deleteJson('/api/crm/products/' . $binned['crm'], [], $this->bearer(self::ADMIN))->assertOk();
        $none = (int) $this->doPost($this->aboutBody(['offerId' => 'P9', 'name' => 'Scholar Nine']))->assertStatus(201)->json('data.id');
        $snapshot = $this->snapshot();

        // May only ADD: can make a row and bring one back from the Bin, cannot touch a live one.
        $this->doPut($f['id'], ['sell' => ['unitPrice' => 1]], self::ADD_ONLY)->assertStatus(403);
        $this->doPut($f['id'], ['sell' => ['enabled' => false]], self::ADD_ONLY)->assertStatus(403);
        $this->assertSame($snapshot, $this->snapshot());
        $this->doPut($none, ['sell' => ['enabled' => true, 'type' => 'product']], self::ADD_ONLY)->assertOk();
        $this->doPut($binned['id'], ['sell' => ['enabled' => true]], self::ADD_ONLY)->assertOk();
        $this->assertNull($this->crmRow($binned['crm'])->deleted_at);

        // May only EDIT: can change, pause and resume a live row, cannot make one or restore one.
        $other = (int) $this->doPost($this->aboutBody(['offerId' => 'Q1', 'name' => 'Scholar Q1']))->assertStatus(201)->json('data.id');
        $bin2 = $this->both(['offerId' => 'Q2', 'name' => 'Scholar Q2']);
        $this->deleteJson('/api/crm/products/' . $bin2['crm'], [], $this->bearer(self::ADMIN))->assertOk();
        $snapshot = $this->snapshot();
        $this->doPut($other, ['sell' => ['enabled' => true, 'type' => 'product']], self::EDIT_ONLY)->assertStatus(403);
        $this->doPut($bin2['id'], ['sell' => ['enabled' => true]], self::EDIT_ONLY)->assertStatus(403);
        $this->assertSame($snapshot, $this->snapshot());
        $this->doPut($f['id'], ['sell' => ['unitPrice' => 3]], self::EDIT_ONLY)->assertOk();
        $this->doPut($f['id'], ['sell' => ['enabled' => false]], self::EDIT_ONLY)->assertOk();
        $this->doPut($f['id'], ['sell' => ['enabled' => true]], self::EDIT_ONLY)->assertOk();
        $this->assertSame([1, 1], [(int) $this->crmRow($f['crm'])->is_active, DB::table('crm_products')->where('id', $f['crm'])->count()]);
    }

    public function test_r4_the_right_of_a_sell_section_is_the_one_of_its_type_and_a_type_that_does_not_exist_is_a_422(): void
    {
        $before = $this->counts();

        $this->doPost(['kind' => 'sub_product', 'name' => 'A service by profile 11', 'sell' => ['enabled' => true, 'type' => 'service']], self::HR_PRODUCTS)->assertStatus(403);   // holds Products, not Services
        $this->doPost(['kind' => 'sub_product', 'name' => 'A product by profile 11', 'sell' => ['enabled' => true, 'type' => 'product']], self::HR_PRODUCTS)->assertStatus(201);
        $this->doPost(['kind' => 'sub_product', 'name' => 'A gadget', 'sell' => ['enabled' => true, 'type' => 'gadget']], self::ADMIN)->assertStatus(422)->assertJsonStructure(['errors' => ['sell.type']]);

        $this->assertSame($before['crm'] + 1, $this->counts()['crm']);
    }

    public function test_r4_the_type_of_the_row_that_exists_decides_which_right_an_update_needs(): void
    {
        $product = $this->both(['offerId' => 'P1x', 'name' => 'A product row']);
        $service = $this->both(['offerId' => 'P2x', 'name' => 'A service row'], ['type' => 'service']);
        $snapshot = $this->snapshot();

        // Profile 11 holds the Products row (full) and not the Services row.
        $this->doPut($service['id'], ['sell' => ['unitPrice' => 1]], self::HR_PRODUCTS)->assertStatus(403);
        $this->doPut($service['id'], ['sell' => ['enabled' => false]], self::HR_PRODUCTS)->assertStatus(403);
        $this->assertSame($snapshot, $this->snapshot());
        $this->doPut($product['id'], ['sell' => ['unitPrice' => 1]], self::HR_PRODUCTS)->assertOk();
        $this->assertEquals(1, $this->crmRow($product['crm'])->unit_price);
    }

    public function test_r4_a_new_product_needs_an_administrator_and_the_route_asks_only_for_a_token(): void
    {
        $before = $this->counts();
        foreach ([self::HR, self::SALES, self::HR_PRODUCTS, self::NOBODY] as $profile) {
            $this->doPost(['kind' => 'product', 'name' => 'Payroll', 'key' => 'payroll'], $profile)->assertStatus(403);
        }
        $this->assertSame($before, $this->counts());
        $this->doPost(['kind' => 'product', 'name' => 'Payroll', 'key' => 'payroll'], self::ADMIN)->assertStatus(201);

        $this->postJson('/api/product-master', $this->aboutBody())->assertStatus(401);
        $this->putJson('/api/product-master/1', ['name' => 'Anonymous'])->assertStatus(401);
        // The writer decides, so a person with no row at all on the Products screen is let in by the route and judged on the section.
        $this->doPost($this->aboutBody(['offerId' => 'R1', 'name' => 'Through the writer']), self::HR)->assertStatus(201);
    }

    public function test_r4_archive_and_restore_keep_their_screen_rights(): void
    {
        $f = $this->both();

        $this->postJson('/api/product-master/' . $f['id'] . '/archive', [], $this->bearer(self::HR))->assertStatus(403);
        $this->postJson('/api/product-master/' . $f['id'] . '/restore', [], $this->bearer(self::HR))->assertStatus(403);
        $this->postJson('/api/product-master/' . $f['id'] . '/archive', [], $this->bearer(self::ADMIN))->assertOk()->assertJsonPath('data.status', 'archived');
    }

    public function test_r4_on_an_update_a_refused_section_leaves_the_allowed_one_unwritten_too(): void
    {
        $f = $this->both();
        $snapshot = $this->snapshot();
        $lines = $this->events->lines;

        $hr = $this->doPut($f['id'], ['about' => ['notes' => 'HR may write this'], 'sell' => ['unitPrice' => 1]], self::HR)->assertStatus(403);
        $this->assertArrayHasKey('sell', $hr->json('errors'));
        $this->assertArrayNotHasKey('about', $hr->json('errors'), 'HR is allowed the About: only the Sell section is named');
        $sales = $this->doPut($f['id'], ['about' => ['notes' => 'sales may not'], 'sell' => ['unitPrice' => 1]], self::SALES)->assertStatus(403);
        $this->assertArrayHasKey('about', $sales->json('errors'));
        $both = $this->doPut($f['id'], ['about' => ['notes' => 'x'], 'icp' => ['b2b' => ['target_segments' => ['K12-PVT']]], 'sell' => ['unitPrice' => 1]], self::NOBODY)->assertStatus(403);
        $this->assertEqualsCanonicalizing(['about', 'icp', 'sell'], array_keys($both->json('errors')), 'every refused section is named');

        $this->assertSame($snapshot, $this->snapshot(), 'a 403 writes nothing for any section, also when another section of the request was allowed');
        $this->assertSame($lines, $this->events->lines, 'and no audit line for a refusal');
    }

    public function test_r4_an_update_that_asks_for_nothing_is_not_a_way_to_read_a_record(): void
    {
        $f = $this->both();
        $item = (int) $this->doPost(['kind' => 'item', 'name' => 'Starter kit', 'sell' => ['type' => 'product']])->assertStatus(201)->json('data.id');
        $snapshot = $this->snapshot();

        // The update routes no longer ask for the Products row's view right, and an update answers with the record, so a person who may write none of its stores is refused.
        foreach ([self::NOBODY, self::VIEW_ONLY, self::ADD_ONLY] as $profile) {
            $this->doPut($f['id'], [], $profile)->assertStatus(403);
            $this->doPut($item, ['name' => 'Starter kit'], $profile)->assertStatus(403);
        }
        $this->doPut($item, [], self::HR)->assertStatus(403);              // HR writes the Portfolio, and an item has no About
        $this->doPut($f['id'], [], self::SALES)->assertOk();              // may edit the catalogue row of this record
        $this->doPut($f['id'], [], self::HR)->assertOk();                 // may edit its About
        $this->assertSame($snapshot['g2g_product_offers'], $this->snapshot()['g2g_product_offers'], 'and an empty update changes nothing');
        $this->assertSame($snapshot['crm_products'], $this->snapshot()['crm_products']);
    }

    // ================================================================== R5: a response that does not leak

    public function test_r5_hr_editing_the_about_of_a_sold_sub_product_never_receives_a_price_a_cost_or_a_commission(): void
    {
        $f = $this->both();

        $res = $this->doPut($f['id'], ['about' => ['whatItDoes' => 'HR changed the text.']], self::HR)->assertOk();

        $this->assertSame('HR changed the text.', $res->json('data.offer.about.whatItDoes'), 'HR gets the About');
        $this->assertNull($res->json('data.sell'));
        $this->assertNull($res->json('data.crmProduct'), 'the drawer\'s old key is gated by the same right');
        foreach (['costPrice', 'commissionRate', 'unitPrice', 'qtyInStock', 'vendor', '1500.5', 'Acme Cloud'] as $secret) {
            $this->assertFalse(str_contains((string) $res->getContent(), $secret), "the response must not contain '{$secret}'");
        }
    }

    public function test_r5_the_sell_section_is_shown_by_the_view_right_of_the_type_of_the_row(): void
    {
        $product = $this->both(['offerId' => 'P1x', 'name' => 'A product row']);
        $service = $this->both(['offerId' => 'P2x', 'name' => 'A service row'], ['type' => 'service']);

        // Profile 11 may write the About (HR) and holds the Products row but not the Services row.
        $p = $this->doPut($product['id'], ['about' => ['notes' => 'n']], self::HR_PRODUCTS)->assertOk();
        $s = $this->doPut($service['id'], ['about' => ['notes' => 'n']], self::HR_PRODUCTS)->assertOk();

        $this->assertSame($product['crm'], $p->json('data.sell.crmProductId'));
        $this->assertNotNull($p->json('data.crmProduct'));
        $this->assertNull($s->json('data.sell'), 'no Services right: no Sell section of a service');
        $this->assertNull($s->json('data.crmProduct'));
    }

    public function test_r5_a_binned_row_is_shown_to_the_person_who_may_view_it_with_its_state(): void
    {
        $f = $this->both();
        $this->deleteJson('/api/crm/products/' . $f['crm'], [], $this->bearer(self::ADMIN))->assertOk();

        $res = $this->doPut($f['id'], ['about' => ['notes' => 'edited while the row is in the Bin']])->assertOk();

        $this->assertSame(['binned', $f['crm']], [$res->json('data.sell.state'), $res->json('data.sell.crmProductId')], 'the screen needs the id and the state to offer "bring it back"');
        $this->assertNull($res->json('data.crmProduct'), 'the old key still shows only live rows');
    }

    // ================================================================== R8: readiness and shared rows

    public function test_r8_a_shared_row_can_never_carry_sell_and_is_refused_every_other_write(): void
    {
        $scholar = $this->scholar();
        $before = $this->counts();
        $snapshot = $this->snapshot();

        $res = $this->doPut($scholar, ['sell' => ['enabled' => true, 'type' => 'product']])->assertStatus(422);
        $this->assertArrayHasKey('sell', $res->json('errors'));
        $this->doPut($scholar, ['sell' => ['unitPrice' => 5]])->assertStatus(422);
        $this->doPut($scholar, ['name' => 'Renamed'])->assertStatus(403);
        $this->doPut($scholar, ['about' => ['notes' => 'x']])->assertStatus(403);

        $this->assertSame($before, $this->counts());
        $this->assertSame($snapshot, $this->snapshot());
    }

    public function test_r8_a_sub_product_may_hang_under_a_shared_product_and_a_product_of_another_organisation_is_refused(): void
    {
        $theirs = (int) $this->doPost(['kind' => 'product', 'name' => 'Admissions', 'key' => 'admissions'], 3)->assertStatus(201)->json('data.id');
        $before = $this->counts();

        $res = $this->doPost($this->aboutBody(['productId' => $theirs]))->assertStatus(422);
        $this->assertArrayHasKey('productId', $res->json('errors'));
        $res = $this->doPost(['kind' => 'sub_product', 'name' => 'Sell under theirs', 'productId' => $theirs, 'sell' => $this->fullSell()])->assertStatus(422);
        $this->assertArrayHasKey('productId', $res->json('errors'));

        $this->assertSame($before, $this->counts());
        $this->doPost($this->aboutBody())->assertStatus(201);
    }

    // ================================================================== more of the sections

    public function test_r1_blank_about_fields_do_not_turn_the_about_on_for_a_create_and_a_blank_assignee_means_the_caller(): void
    {
        // A form that leaves the offer id and the readiness blank is a Sell-only request, not an About that is missing its fields.
        $res = $this->doPost(['kind' => 'sub_product', 'name' => 'Only sold', 'productId' => $this->scholar(), 'offerId' => '', 'readiness' => '', 'about' => [], 'sell' => ['enabled' => true, 'type' => 'product']], self::SALES)->assertStatus(201);

        $master = $this->masterRow((int) $res->json('data.id'));
        $crm = $this->crmRow((int) $master->crm_product_id);
        $this->assertSame(['item', null], [$master->kind, $master->offer_row_id], 'no About was made');
        $this->assertSame([1, (int) DB::table('tbluser')->max('id')], [(int) $crm->is_active, (int) $crm->assigned_to], 'active, and assigned to the person who made it');
    }

    public function test_the_writer_itself_reads_a_blank_offer_id_or_readiness_as_no_about_on_a_create(): void
    {
        // The framework turns "" into null before a request reaches the writer (ConvertEmptyStringsToNull), so the HTTP test above cannot tell a blank from a missing
        // field. The writer is also called directly (the pair review, the repair), so it must read a blank the same way itself.
        $userId = (int) $this->h(self::T, self::SALES)['_user'];
        $identity = ['user' => \App\Models\auth\tbluserModel::find($userId), 'user_id' => $userId, 'sub_institute_id' => self::T];

        $row = (new \App\Domain\ProductMaster\ProductWriter)->create($identity, [
            'kind' => 'sub_product', 'name' => 'Only sold', 'productId' => $this->scholar(), 'offerId' => '  ', 'readiness' => '', 'about' => [], 'sell' => ['enabled' => true, 'type' => 'product'],
        ]);

        $this->assertSame(['item', null], [$row->kind, $row->offer_row_id], 'a blank offer id and readiness are no About: the sales profile (no Portfolio right) is not refused');
    }

    public function test_r1_an_about_added_in_the_same_request_as_an_icp_and_a_product_carries_all_three(): void
    {
        $id = (int) $this->doPost(['kind' => 'item', 'name' => 'Starter kit', 'sell' => ['type' => 'product']])->assertStatus(201)->json('data.id');
        $eb = $this->sharedId('enterprise_brain');

        $this->doPut($id, ['productId' => $eb, 'offerId' => 'EB-1', 'readiness' => 'Prototype', 'about' => ['whatItDoes' => 'x'], 'icp' => ['b2b' => ['target_segments' => ['K12-PVT']]]])->assertOk();

        $master = $this->masterRow($id);
        $this->assertSame(['sub_product', $eb], [$master->kind, (int) $master->parent_id]);
        $this->assertSame(['EB', 1], [$this->offerRow((int) $master->offer_row_id)->parent_product, DB::table('g2g_icp_profiles')->where('key', SubProductIcp::key('EB-1', 'b2b'))->count()]);
        $this->assertSame($eb, (int) $this->crmRow((int) $master->crm_product_id)->product_master_id, 'the catalogue row follows the Product the About was filed under');
        $this->assertChecker('after an About, an ICP and a Product arrive together');
    }

    public function test_r3_an_item_moves_between_products_and_may_have_none(): void
    {
        $id = (int) $this->doPost(['kind' => 'item', 'name' => 'Starter kit', 'productId' => $this->scholar(), 'sell' => ['type' => 'product']])->assertStatus(201)->json('data.id');
        $crmId = (int) $this->masterRow($id)->crm_product_id;
        $eb = $this->sharedId('enterprise_brain');

        $this->doPut($id, ['productId' => $eb], self::SALES)->assertOk();
        $this->assertSame([$eb, $eb], [(int) $this->masterRow($id)->parent_id, (int) $this->crmRow($crmId)->product_master_id]);

        $this->doPut($id, ['productId' => null], self::SALES)->assertOk();
        $this->assertSame([null, null], [$this->masterRow($id)->parent_id, $this->crmRow($crmId)->product_master_id], 'an item may be filed under no Product');
        $this->doPut($id, ['productId' => 999999], self::SALES)->assertStatus(422)->assertJsonStructure(['errors' => ['productId']]);
        $this->assertChecker('after an item moves');
    }

    public function test_r2_an_item_can_be_paused_and_resumed_and_stays_in_the_list(): void
    {
        $id = (int) $this->doPost(['kind' => 'item', 'name' => 'Starter kit', 'sell' => ['type' => 'product']])->assertStatus(201)->json('data.id');
        $crmId = (int) $this->masterRow($id)->crm_product_id;

        $this->doPut($id, ['sell' => ['enabled' => false]], self::SALES)->assertOk()->assertJsonPath('data.sell.state', 'paused');
        $this->assertSame(['active', 0], [$this->masterRow($id)->status, (int) $this->crmRow($crmId)->is_active], 'a paused item is still an active record: only its catalogue row is switched off');
        $this->doPut($id, ['sell' => ['enabled' => true]], self::SALES)->assertOk()->assertJsonPath('data.sell.state', 'sold');
        $this->assertChecker('after an item is paused and resumed');
    }

    public function test_r8_a_product_of_the_organisation_is_never_sold_itself(): void
    {
        $product = (int) $this->doPost(['kind' => 'product', 'name' => 'Payroll', 'key' => 'payroll'])->assertStatus(201)->json('data.id');
        $before = $this->counts();

        $res = $this->doPut($product, ['sell' => ['enabled' => true, 'type' => 'product']])->assertStatus(422);
        $this->assertArrayHasKey('sell', $res->json('errors'));
        $this->doPut($product, ['name' => 'Payroll'])->assertOk();
        $this->assertSame($before, $this->counts());
    }

    public function test_r6_archiving_and_restoring_are_audited_on_the_master_row(): void
    {
        $f = $this->both();
        $this->events->lines = [];

        $this->postJson('/api/product-master/' . $f['id'] . '/archive', [], $this->bearer(self::ADMIN))->assertOk();

        $this->assertSame(['product.master.updated#g2g_product_master'], $this->types($this->events->lines));
        $this->assertSame([['status', 'active', 'archived']], array_map(fn (array $c): array => [$c['field'], $c['oldValue'], $c['newValue']], $this->line('product.master.updated')['changes']));
        $this->assertSame(0, $this->line('product.master.updated')['transactionLevel']);
    }

    // ================================================================== validation: a bad value is a 422 next to its field and writes nothing

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> a patch of the body and the error key that must come back */
    public static function badValues(): iterable
    {
        yield 'a name that is too short' => [['name' => 'x'], 'name'];
        yield 'a Product that is not there' => [['productId' => 999999], 'productId'];
        yield 'a readiness that is not listed' => [['readiness' => 'Whatever'], 'readiness'];
        yield 'a readiness that was blanked (the framework turns "" into null)' => [['readiness' => null], 'readiness'];
        yield 'an offer id that is too long' => [['offerId' => str_repeat('x', 51)], 'offerId'];
        yield 'an offer id that makes the key too long' => [['offerId' => str_repeat('x', 45)], 'offerId'];
        yield 'needs that are not a list' => [['about' => ['needsSolved' => 'not a list']], 'about.needsSolved'];
        yield 'a deployment model that is too long' => [['about' => ['deploymentModel' => str_repeat('x', 101)]], 'about.deploymentModel'];
        yield 'a deal band that is too long' => [['about' => ['typicalDealBand' => str_repeat('x', 51)]], 'about.typicalDealBand'];
        yield 'an often-sold-together entry that is too long' => [['about' => ['oftenSoldTogether' => [str_repeat('x', 51)]]], 'about.oftenSoldTogether.0'];
        yield 'an icp that is not a list' => [['icp' => 'nonsense'], 'icp'];
        yield 'a type that does not exist' => [['sell' => ['type' => 'gadget']], 'sell.type'];
        yield 'a type that is a list' => [['sell' => ['type' => ['product']]], 'sell.type'];
        yield 'a price that is not a number' => [['sell' => ['unitPrice' => 'abc']], 'sell.unitPrice'];
        yield 'a base price that is not a number (the old drawer\'s key)' => [['sell' => ['basePrice' => 'abc']], 'sell.basePrice'];
        yield 'a cost that is not a number' => [['sell' => ['costPrice' => 'abc']], 'sell.costPrice'];
        yield 'a commission over 100' => [['sell' => ['commissionRate' => 101]], 'sell.commissionRate'];
        yield 'a tax rate of another organisation' => [['sell' => ['taxRateId' => 8]], 'sell.taxRateId'];
        yield 'a tax rate that is not a number' => [['sell' => ['taxRateId' => 'x']], 'sell.taxRateId'];
        yield 'a sku that is too long' => [['sell' => ['sku' => str_repeat('x', 101)]], 'sell.sku'];
        // The sizes of the columns on the dev database (`SHOW COLUMNS`, 13 Nov 2026): a longer value would otherwise pass here and fail in the database as a 500.
        yield 'a category longer than its column (100)' => [['sell' => ['category' => str_repeat('c', 101)]], 'sell.category'];
        yield 'a GL account longer than its column (191)' => [['sell' => ['glAccount' => str_repeat('g', 192)]], 'sell.glAccount'];
        yield 'a vendor longer than its column (191)' => [['sell' => ['vendor' => str_repeat('v', 192)]], 'sell.vendor'];
        yield 'a usage unit longer than its column (191)' => [['sell' => ['usageUnit' => str_repeat('u', 192)]], 'sell.usageUnit'];
        yield 'a currency longer than its column (10)' => [['sell' => ['currency' => str_repeat('x', 11)]], 'sell.currency'];
        yield 'stock that is not a number' => [['sell' => ['qtyInStock' => 'lots']], 'sell.qtyInStock'];
        yield 'a reorder level that is not whole' => [['sell' => ['reorderLevel' => 1.5]], 'sell.reorderLevel'];
        yield 'an active flag that is not a flag' => [['sell' => ['isActive' => 'maybe']], 'sell.isActive'];
        yield 'a switch that is not a switch' => [['sell' => ['enabled' => 'perhaps']], 'sell.enabled'];

        // P2-F, G-6: the NUMBER columns of the dev database (`SHOW COLUMNS FROM crm_products`, 14 Nov 2026) and "no negatives". Strict mode is on, so a value past a
        // column's range is a database error: a 500 unless it is a 422 on its own field. One over the largest the column holds, and one under zero.
        yield 'a price past its column (decimal 15,2)' => [['sell' => ['unitPrice' => 10000000000000]], 'sell.unitPrice'];
        yield 'a base price past its column (the old drawer\'s key)' => [['sell' => ['basePrice' => 10000000000000]], 'sell.basePrice'];
        yield 'a cost past its column (decimal 15,2)' => [['sell' => ['costPrice' => 10000000000000]], 'sell.costPrice'];
        yield 'a negative price' => [['sell' => ['unitPrice' => -1]], 'sell.unitPrice'];
        yield 'a negative base price' => [['sell' => ['basePrice' => -0.01]], 'sell.basePrice'];
        yield 'a negative cost' => [['sell' => ['costPrice' => -0.01]], 'sell.costPrice'];
        yield 'a negative commission' => [['sell' => ['commissionRate' => -1]], 'sell.commissionRate'];
        yield 'stock past its column (decimal 15,2)' => [['sell' => ['qtyInStock' => 10000000000000]], 'sell.qtyInStock'];
        yield 'negative stock' => [['sell' => ['qtyInStock' => -1]], 'sell.qtyInStock'];
        yield 'demand past its column (decimal 12,2)' => [['sell' => ['qtyInDemand' => 10000000000]], 'sell.qtyInDemand'];
        yield 'negative demand' => [['sell' => ['qtyInDemand' => -1]], 'sell.qtyInDemand'];
        yield 'a weight past its column (decimal 10,3)' => [['sell' => ['weight' => 10000000]], 'sell.weight'];
        yield 'a negative weight' => [['sell' => ['weight' => -0.5]], 'sell.weight'];
        yield 'a reorder level past its column (unsigned int)' => [['sell' => ['reorderLevel' => 4294967296]], 'sell.reorderLevel'];
        yield 'a negative reorder level' => [['sell' => ['reorderLevel' => -1]], 'sell.reorderLevel'];
        yield 'a negative assignee (the column is unsigned)' => [['sell' => ['assignedTo' => -1]], 'sell.assignedTo'];
        yield 'a negative tax rate id (the column is unsigned)' => [['sell' => ['taxRateId' => -1]], 'sell.taxRateId'];
        yield 'a description past its column (text, 65,535 bytes: at most 16,383 characters of four bytes)' => [['sell' => ['descriptionOnQuotes' => str_repeat('d', 16384)]], 'sell.descriptionOnQuotes'];
        yield 'a description under its other name past its column' => [['sell' => ['description' => str_repeat('d', 16384)]], 'sell.description'];
    }

    #[DataProvider('badValues')]
    public function test_a_bad_value_is_a_422_on_its_own_field_and_writes_nothing_on_create_and_on_update(array $patch, string $field): void
    {
        $f = $this->both();
        $before = $this->counts();
        $snapshot = $this->snapshot();

        $create = $this->doPost(array_replace_recursive($this->aboutBody(['offerId' => 'N1', 'name' => 'New one', 'sell' => $this->fullSell()]), $patch))->assertStatus(422);
        $this->assertArrayHasKey($field, $create->json('errors'), 'create: ' . json_encode($create->json('errors')));
        $update = $this->doPut($f['id'], $patch)->assertStatus(422);
        $this->assertArrayHasKey($field, $update->json('errors'), 'update: ' . json_encode($update->json('errors')));

        $this->assertSame($before, $this->counts());
        $this->assertSame($snapshot, $this->snapshot());
    }

    public function test_the_offer_id_of_another_sub_product_is_a_409_whoever_owns_it_and_a_shared_offer_counts(): void
    {
        $this->both();
        DB::table('g2g_product_offers')->insert(['sub_institute_id' => null, 'offer_id' => 'SHARED-1', 'name' => 'Shared offer', 'parent_product' => 'G2G', 'readiness_status' => 'Prototype']);
        $before = $this->counts();

        $this->doPost($this->aboutBody())->assertStatus(409);
        $this->doPost($this->aboutBody(['offerId' => 'SHARED-1']))->assertStatus(409);
        $this->assertSame($before, $this->counts());
    }

    // ================================================================== R7: all stores or none

    /** @return iterable<string, array{0: string}> */
    public static function stores(): iterable
    {
        yield 'the Portfolio offer' => ['g2g_product_offers'];
        yield 'the catalogue row' => ['crm_products'];
        yield 'the master row' => ['g2g_product_master'];
        yield 'the ICP profile' => ['g2g_icp_profiles'];
    }

    #[DataProvider('stores')]
    public function test_r7_a_failure_in_any_store_on_create_leaves_every_store_and_the_event_log_as_they_were(string $table): void
    {
        $before = $this->snapshot();
        $this->failWrites('INSERT', $table);
        $body = $this->aboutBody(['sell' => $this->fullSell(), 'icp' => ['b2b' => ['target_segments' => ['K12-PVT']]]]);

        $this->assertThrows(fn () => $this->withoutExceptionHandling()->postJson('/api/product-master', $body, $this->bearer(self::ADMIN)), QueryException::class);

        $this->assertSame($before, $this->snapshot(), "the write failed in {$table}: nothing may be left in any other store");
        $this->assertSame([], $this->events->lines, 'and no audit line is written for a write that was rolled back');
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function storesOnUpdate(): iterable
    {
        yield 'the master row' => ['UPDATE', 'g2g_product_master'];
        yield 'the Portfolio offer' => ['UPDATE', 'g2g_product_offers'];
        yield 'the catalogue row' => ['UPDATE', 'crm_products'];
        yield 'the ICP profile' => ['INSERT', 'g2g_icp_profiles'];
    }

    #[DataProvider('storesOnUpdate')]
    public function test_r7_a_failure_in_any_store_on_update_leaves_every_store_and_the_event_log_as_they_were(string $verb, string $table): void
    {
        $f = $this->both();
        $before = $this->snapshot();
        $lines = $this->events->lines;
        $this->failWrites($verb, $table);

        $this->assertThrows(fn () => $this->withoutExceptionHandling()->putJson('/api/product-master/' . $f['id'], [
            'name' => 'Renamed', 'readiness' => 'In development', 'about' => ['notes' => 'n'], 'sell' => ['unitPrice' => 1], 'icp' => ['b2b' => ['target_segments' => ['K12-PVT']]],
        ], $this->bearer(self::ADMIN)), QueryException::class);

        $this->assertSame($before, $this->snapshot(), "the write failed in {$table}: nothing may have changed in any store");
        $this->assertSame($lines, $this->events->lines);
    }

    // ================================================================== R6: the audit lines

    public function test_r6_a_create_with_both_sections_writes_one_line_per_store_after_the_commit(): void
    {
        $f = $this->both();

        $this->assertSame(['crm.product.created#crm_products', 'portfolio.offer.created#g2g_product_offers', 'product.master.created#g2g_product_master'], $this->types($this->events->lines));
        foreach ($this->events->lines as $line) {
            $this->assertSame(0, $line['transactionLevel'], 'the line is written after the commit, not inside the transaction');
            $this->assertSame([self::T, (int) DB::table('tbluser')->max('id')], [$line['tenant'], $line['actor']], 'organisation and the person who did it (the only user made so far)');
        }
        $this->assertSame($f['crm'], $this->line('crm.product.created')['entityId']);
        $this->assertSame($f['offer'], $this->line('portfolio.offer.created')['entityId']);
        $this->assertSame($f['id'], $this->line('product.master.created')['entityId']);
        $offerFields = array_column($this->line('portfolio.offer.created')['changes'], 'newValue', 'field');
        $this->assertSame(['Scholar Seven', 'P7', 'Prototype'], [$offerFields['name'], $offerFields['offer_id'], $offerFields['readiness_status']]);
        $crmFields = array_column($this->line('crm.product.created')['changes'], 'newValue', 'field');
        $this->assertSame('SKU-7', $crmFields['sku']);
    }

    public function test_r6_an_edit_writes_a_line_only_for_the_store_that_changed_and_only_with_the_columns_that_changed(): void
    {
        $f = $this->both();
        $this->events->lines = [];

        $this->doPut($f['id'], ['sell' => ['unitPrice' => 2000]], self::SALES)->assertOk();
        $this->assertSame(['crm.product.updated#crm_products'], $this->types($this->events->lines), 'the offer and the master row did not change');
        $changes = $this->line('crm.product.updated')['changes'];
        $this->assertSame(['unit_price'], array_column($changes, 'field'), 'updated_at and updated_by are bookkeeping, not changes');
        $this->assertEquals([1500.5, 2000], [$changes[0]['oldValue'], $changes[0]['newValue']]);
        $this->assertSame([$f['crm'], (int) DB::table('tbluser')->max('id')], [$this->line('crm.product.updated')['entityId'], $this->line('crm.product.updated')['actor']], 'the person who made the change, the sales profile that was created last');

        $this->events->lines = [];
        $this->doPut($f['id'], ['about' => ['whatItDoes' => 'About edit by HR']], self::HR)->assertOk();
        $this->assertSame(['portfolio.offer.updated#g2g_product_offers'], $this->types($this->events->lines), 'About edits are audited now');
        $this->assertSame(['what_it_does'], array_column($this->line('portfolio.offer.updated')['changes'], 'field'));
    }

    public function test_r6_a_rename_is_one_line_in_each_of_the_three_stores_and_a_write_that_changes_nothing_writes_none(): void
    {
        $f = $this->both();
        $this->events->lines = [];

        $this->doPut($f['id'], ['name' => 'Scholar Seven Plus'])->assertOk();

        $this->assertSame(['crm.product.updated#crm_products', 'portfolio.offer.updated#g2g_product_offers', 'product.master.updated#g2g_product_master'], $this->types($this->events->lines));
        foreach ($this->events->lines as $line) {
            $this->assertSame('name', $line['changes'][0]['field']);
            $this->assertSame(['Scholar Seven', 'Scholar Seven Plus'], [$line['changes'][0]['oldValue'], $line['changes'][0]['newValue']]);
        }

        $this->events->lines = [];
        $this->doPut($f['id'], ['name' => 'Scholar Seven Plus', 'about' => ['whatItDoes' => $this->offerRow($f['offer'])->what_it_does], 'sell' => ['unitPrice' => 1500.5]])->assertOk();
        $this->assertSame([], $this->events->lines, 'the same values again change nothing, so there is nothing to say');
    }

    public function test_r6_a_restore_and_a_pause_are_audited_on_the_catalogue_row(): void
    {
        $f = $this->both();
        $this->events->lines = [];
        $this->doPut($f['id'], ['sell' => ['enabled' => false]])->assertOk();
        $this->assertSame([['is_active', 1, 0]], array_map(fn (array $c): array => [$c['field'], (int) $c['oldValue'], (int) $c['newValue']], $this->line('crm.product.updated')['changes']));

        $this->deleteJson('/api/crm/products/' . $f['crm'], [], $this->bearer(self::ADMIN))->assertOk();
        $this->events->lines = [];
        $this->doPut($f['id'], ['sell' => ['enabled' => true]])->assertOk();
        $fields = array_column($this->line('crm.product.updated')['changes'], 'field');
        $this->assertContains('deleted_at', $fields, 'coming back from the Recycle Bin is in the history');
        $this->assertContains('is_active', $fields);
    }

    public function test_r6_a_failing_event_store_never_undoes_the_write(): void
    {
        $this->events->refuse = true;

        $res = $this->doPost($this->aboutBody(['sell' => $this->fullSell()]))->assertStatus(201);

        $master = $this->masterRow((int) $res->json('data.id'));
        $this->assertNotNull($master);
        $this->assertSame('Scholar Seven', $this->crmRow((int) $master->crm_product_id)->name, 'the catalogue row is there');
        $this->assertNotNull($this->offerRow((int) $master->offer_row_id));
        $this->events->refuse = false;
        $this->assertChecker('although the event store was down');
    }

    // ================================================================== the old drawer keeps working

    public function test_the_old_drawers_requests_are_still_understood(): void
    {
        // The shapes tests/Feature/ProductMasterWriteApiTest sends: create a sub-product with the five Sell fields, update with the same five, create an item.
        $res = $this->doPost($this->aboutBody(['sell' => ['enabled' => true, 'type' => 'product', 'sku' => 'P7', 'taxRateId' => self::TAX, 'basePrice' => 1500, 'currency' => 'INR', 'glAccount' => '4000']]))->assertStatus(201);
        $this->assertSame(['P7', 'INR', '4000'], [$res->json('data.crmProduct.sku'), $res->json('data.crmProduct.currency'), $res->json('data.crmProduct.glAccount')]);
        $this->assertEquals(1500, $res->json('data.crmProduct.unitPrice'));

        $item = $this->doPost(['kind' => 'item', 'name' => 'Training day', 'sell' => ['type' => 'service', 'sku' => 'TRN-1', 'basePrice' => 25000, 'currency' => 'INR']])->assertStatus(201);
        $this->assertSame('item', $item->json('data.kind'));
        $this->assertSame('service', $item->json('data.crmProduct.type'));

        $this->doPut((int) $res->json('data.id'), ['name' => 'Scholar Seven Plus', 'readiness' => 'In development', 'about' => ['whatItDoes' => 'Updated.'], 'sell' => ['enabled' => true, 'type' => 'product', 'sku' => 'P7-PLUS', 'basePrice' => 250]])->assertOk();
        $this->assertChecker('after the old drawer\'s writes');
    }

    // ================================================================== P2-F: the four items P2-C left owed (G-6, G-7, D-1, D-3)

    // ---- G-6: the number columns ----

    public function test_g6_the_largest_value_each_number_column_holds_is_accepted_and_so_is_zero(): void
    {
        // The two decimals(15,2) are sent as text: PHP turns a float of 15 digits into text with 14, so a JSON float exactly at 9999999999999.99 reads as 10000000000000
        // inside Laravel's max rule and is refused (on the safe side); text is exact, and a form's input is text.
        $top = ['unitPrice' => '9999999999999.99', 'costPrice' => '9999999999999.99', 'qtyInStock' => '9999999999999.99', 'qtyInDemand' => '9999999999.99',
            'weight' => '9999999.999', 'reorderLevel' => 4294967295, 'commissionRate' => 100];
        $res = $this->doPost($this->aboutBody(['sell' => $this->fullSell($top)]))->assertStatus(201);
        $crm = $this->crmRow((int) $this->masterRow((int) $res->json('data.id'))->crm_product_id);
        $this->assertEqualsWithDelta(9999999999999.99, (float) $crm->unit_price, 0.01);
        $this->assertEqualsWithDelta(9999999.999, (float) $crm->weight, 0.001);
        $this->assertSame(4294967295, (int) $crm->reorder_level);

        $zero = ['unitPrice' => 0, 'costPrice' => 0, 'qtyInStock' => 0, 'qtyInDemand' => 0, 'weight' => 0, 'reorderLevel' => 0, 'commissionRate' => 0];
        $this->doPut((int) $res->json('data.id'), ['sell' => $zero])->assertOk();
        $edited = $this->crmRow((int) $crm->id);
        $this->assertEquals(array_fill(0, 7, 0), [$edited->unit_price, $edited->cost_price, $edited->qty_in_stock, $edited->qty_in_demand, $edited->weight, $edited->reorder_level, $edited->commission_rate]);
    }

    // ---- G-7: the Sell read ----

    public function test_g7_the_sell_read_returns_every_field_the_writer_accepts_and_the_extras_the_old_page_showed(): void
    {
        Storage::fake('digitalocean');
        $f = $this->both();
        DB::table('crm_products')->where('id', $f['crm'])->update(['image' => '7_abc.png']);

        $sell = $this->doPut($f['id'], ['about' => ['notes' => 'a write that answers with the whole record']])->assertOk()->json('data.sell');

        $crm = $this->crmRow($f['crm']);
        // Every field the writer accepts comes back with the value that was sent (enabled comes back as the state).
        $sent = $this->fullSell();
        $this->assertSame('sold', $sell['state']);
        unset($sent['enabled']);
        foreach ($sent as $key => $value) {
            $this->assertArrayHasKey($key, $sell, "the write accepts '{$key}' and the read must return it");
            $this->assertEquals($value, $sell[$key], "'{$key}' comes back as it was sent");
        }
        // assignedTo, alone: the response key itself.
        $this->assertArrayHasKey('assignedTo', $sell);
        $this->assertSame(99, $sell['assignedTo']);
        // The extras the old product page showed.
        $this->assertSame(Storage::disk('digitalocean')->url('public/hp_crm_product/7_abc.png'), $sell['imageUrl']);
        $this->assertSame('GST 18', $sell['taxRateName']);
        $this->assertSame($this->scholar(), $sell['productMasterId'], 'the Product the item sits under');
        $this->assertSame((int) $crm->created_by, $sell['createdBy']);
        $this->assertNotNull($sell['createdBy']);
        $this->assertSame([$crm->created_at, $crm->updated_at], [$sell['createdAt'], $sell['updatedAt']]);
        $this->assertNotNull($sell['createdAt']);
        $keys = array_keys($sell);
        sort($keys);
        $expected = ['assignedTo', 'category', 'commissionRate', 'costPrice', 'createdAt', 'createdBy', 'crmProductId', 'currency', 'descriptionOnQuotes', 'glAccount', 'imageUrl', 'isActive',
            'productMasterId', 'productNo', 'qtyInDemand', 'qtyInStock', 'reorderLevel', 'sku', 'state', 'taxRateId', 'taxRateName', 'type', 'unitPrice', 'updatedAt', 'usageUnit', 'vendor', 'weight'];
        sort($expected);
        $this->assertSame($expected, $keys, 'the Sell read has exactly these keys: a new one is added here on purpose');

        // The picture is the one the CRM page shows.
        $crmPage = $this->getJson('/api/crm/products/' . $f['crm'], $this->bearer(self::ADMIN))->assertOk();
        $this->assertSame($crmPage->json('data.imageUrl'), $sell['imageUrl']);
        $this->assertSame($crmPage->json('data.taxRateName'), $sell['taxRateName']);

        // The tax rate's name is the organisation's own: a row that somehow points at another organisation's rate never shows its name.
        DB::table('crm_products')->where('id', $f['crm'])->update(['tax_rate_id' => self::OTHER_TAX]);
        $this->assertNull($this->doPut($f['id'], ['about' => ['notes' => 'a second read']])->assertOk()->json('data.sell.taxRateName'));
    }

    public function test_g7_an_item_with_no_picture_and_no_tax_rate_reads_null_for_both(): void
    {
        Storage::fake('digitalocean');
        $res = $this->doPost(['kind' => 'sub_product', 'name' => 'Bare item', 'sell' => ['enabled' => true, 'type' => 'product', 'sku' => 'B-1']], self::SALES)->assertStatus(201);

        $sell = $res->json('data.sell');
        $this->assertNull($sell['imageUrl']);
        $this->assertNull($sell['taxRateName']);
        $this->assertNull($sell['productMasterId'], 'an item with no Product');
        $this->assertNotNull($sell['assignedTo'], 'the writer assigns the item to the caller when no one is named');
        $this->assertSame((int) DB::table('crm_products')->where('id', $sell['crmProductId'])->value('assigned_to'), $sell['assignedTo']);
    }

    /** The key a request field comes back under in the Sell read: the same, but for the one the old page called "description". */
    private const READ_KEY = ['description' => 'descriptionOnQuotes'];

    public function test_g7_every_column_of_the_map_and_every_shown_system_column_comes_back_in_the_sell_read(): void
    {
        Storage::fake('digitalocean');
        $numeric = ['unit_price', 'cost_price', 'commission_rate', 'tax_rate_id', 'qty_in_stock', 'qty_in_demand', 'reorder_level', 'weight', 'assigned_to', 'product_master_id', 'created_by', 'id'];
        $row = new \stdClass();
        $marker = [];
        foreach ([...CatalogueFields::columns(), ...array_keys(CatalogueFields::systemColumns())] as $i => $column) {
            $row->{$column} = in_array($column, $numeric, true) ? 1000 + $i : 'marker-' . $column;
            $marker[$column] = $row->{$column};
        }
        $row->item_type = 'service';
        $row->is_active = 0;
        $row->deleted_at = null;
        $row->image = 'marker.png';
        $row->created_at = '2026-01-02 03:04:05';
        $row->updated_at = '2026-01-03 04:05:06';
        $row->tax_rate_name = 'Marker tax';

        $sell = CatalogueFields::toSell($row);

        // Every request field of the map but the record's own name.
        foreach (CatalogueFields::MAP as $requestKey => $column) {
            if ($requestKey === 'name') {
                continue;
            }
            $readKey = self::READ_KEY[$requestKey] ?? $requestKey;
            if ($requestKey === 'isActive') {
                $this->assertFalse($sell['isActive'], 'is_active 0 reads as false');

                continue;
            }
            $this->assertArrayHasKey($readKey, $sell, "the map writes '{$column}' (request key '{$requestKey}') and the Sell read must return it as '{$readKey}'");
            $this->assertEquals($marker[$column], $sell[$readKey], "'{$readKey}' must carry the value of the column '{$column}'");
        }
        $this->assertSame('service', $sell['type'], 'the type column');
        // Every system column is either shown (under the key named here) or named as not shown: a new one fails until it is decided.
        $shown = ['id' => 'crmProductId', 'product_no' => 'productNo', 'created_by' => 'createdBy', 'created_at' => 'createdAt', 'updated_at' => 'updatedAt', 'deleted_at' => 'state', 'image' => 'imageUrl'];
        $notShown = ['legacy_id', 'sub_institute_id', 'updated_by', 'deleted_by'];
        $systemColumns = array_keys(CatalogueFields::systemColumns());
        sort($systemColumns);
        $decided = [...array_keys($shown), ...$notShown];
        sort($decided);
        $this->assertSame($systemColumns, $decided, 'every system column of CatalogueFields::systemColumns() is shown by the Sell read or named here as not shown');
        foreach ($shown as $column => $readKey) {
            $this->assertArrayHasKey($readKey, $sell, "the system column '{$column}' is shown as '{$readKey}'");
        }
        $this->assertEquals($marker['id'], $sell['crmProductId']);
        $this->assertSame('marker-product_no', $sell['productNo']);
        $this->assertEquals($marker['created_by'], $sell['createdBy']);
        $this->assertSame(['2026-01-02 03:04:05', '2026-01-03 04:05:06'], [$sell['createdAt'], $sell['updatedAt']]);
        $this->assertSame('Marker tax', $sell['taxRateName']);
        $this->assertSame(Storage::disk('digitalocean')->url('public/hp_crm_product/marker.png'), $sell['imageUrl']);
        $this->assertSame('paused', $sell['state']);
    }

    // ---- D-1: one switch ----

    public function test_d1_enabled_and_is_active_that_contradict_are_a_422_on_sell_is_active_and_nothing_is_written(): void
    {
        $f = $this->both();
        $before = $this->snapshot();
        $lines = count($this->events->lines);

        // Sold row: switching Sell off and offering it on new documents in one request says two things.
        $this->doPut($f['id'], ['sell' => ['enabled' => false, 'isActive' => true]])->assertStatus(422)->assertJsonStructure(['errors' => ['sell.isActive']]);
        // Sold row: Sell on and not offered is the other contradiction.
        $this->doPut($f['id'], ['sell' => ['enabled' => true, 'isActive' => false]])->assertStatus(422)->assertJsonStructure(['errors' => ['sell.isActive']]);
        // The text a form sends means the same.
        $this->doPut($f['id'], ['sell' => ['enabled' => 'false', 'isActive' => 'true']])->assertStatus(422)->assertJsonStructure(['errors' => ['sell.isActive']]);
        $this->doPut($f['id'], ['sell' => ['enabled' => '1', 'isActive' => '0']])->assertStatus(422)->assertJsonStructure(['errors' => ['sell.isActive']]);
        // The word "false" is no flag the boolean rule takes (it takes true, false, 1, 0, "1" and "0"): the same 422 on the same key, whichever rule says it.
        $this->doPut($f['id'], ['sell' => ['enabled' => true, 'isActive' => 'false']])->assertStatus(422)->assertJsonStructure(['errors' => ['sell.isActive']]);
        // The contradiction is the whole request's: the other fields it carries are not written either.
        $this->doPut($f['id'], ['name' => 'Renamed', 'sell' => ['enabled' => false, 'isActive' => true, 'sku' => 'NEW-SKU']])->assertStatus(422);

        $this->assertSame($before, $this->snapshot(), 'a contradictory request writes nothing in any store');
        $this->assertSame($lines, count($this->events->lines));
        $this->assertSame('sold', $this->doPut($f['id'], ['about' => ['notes' => 'read it']])->json('data.sell.state'));
    }

    public function test_d1_a_request_whose_two_flags_agree_is_accepted_and_is_active_alone_sets_the_state(): void
    {
        $f = $this->both();

        $this->assertSame('paused', $this->doPut($f['id'], ['sell' => ['enabled' => false, 'isActive' => false]])->assertOk()->json('data.sell.state'), 'off and not offered: paused');
        $this->assertSame('sold', $this->doPut($f['id'], ['sell' => ['enabled' => true, 'isActive' => true]])->assertOk()->json('data.sell.state'), 'on and offered: sold');
        $this->assertSame('paused', $this->doPut($f['id'], ['sell' => ['isActive' => false]])->assertOk()->json('data.sell.state'), 'isActive alone pauses');
        $this->assertSame(0, (int) $this->crmRow($f['crm'])->is_active);
        $this->assertSame('sold', $this->doPut($f['id'], ['sell' => ['isActive' => true]])->assertOk()->json('data.sell.state'), 'isActive alone resumes');
        $this->assertSame('paused', $this->doPut($f['id'], ['sell' => ['enabled' => false]])->assertOk()->json('data.sell.state'), 'enabled alone pauses');
        $this->assertSame('sold', $this->doPut($f['id'], ['sell' => ['enabled' => true]])->assertOk()->json('data.sell.state'), 'enabled alone resumes');
        $this->assertChecker('after the switch tests');
    }

    public function test_d1_a_paused_row_asked_to_be_switched_off_and_offered_is_the_same_contradiction(): void
    {
        $f = $this->both();
        $this->doPut($f['id'], ['sell' => ['isActive' => false]])->assertOk();
        $before = $this->snapshot();

        $this->doPut($f['id'], ['sell' => ['enabled' => false, 'isActive' => true]])->assertStatus(422)->assertJsonStructure(['errors' => ['sell.isActive']]);

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, (int) $this->crmRow($f['crm'])->is_active, 'it stays paused');
    }

    public function test_d1_a_create_with_the_sell_switch_off_and_the_row_offered_is_the_same_contradiction_and_a_create_that_is_on_and_not_offered_is_a_paused_row(): void
    {
        $before = $this->counts();
        $snapshot = $this->snapshot();

        $this->doPost($this->aboutBody(['sell' => $this->fullSell(['enabled' => false, 'isActive' => true])]))->assertStatus(422)->assertJsonStructure(['errors' => ['sell.isActive']]);
        $this->assertSame($before, $this->counts());
        $this->assertSame($snapshot, $this->snapshot());

        // Not a contradiction: creating the row is not "a sold row", and Offered on new documents may be off from the start.
        $res = $this->doPost($this->aboutBody(['sell' => $this->fullSell(['enabled' => true, 'isActive' => false])]))->assertStatus(201);
        $this->assertSame('paused', $res->json('data.sell.state'));
    }

    public function test_d1_the_contradiction_is_said_only_to_a_person_who_may_write_the_sell_section(): void
    {
        $f = $this->both();
        $before = $this->snapshot();

        // HR may not write the Sell section: a refusal first, so the 422 teaches HR nothing about the row.
        $this->doPut($f['id'], ['sell' => ['enabled' => false, 'isActive' => true]], self::HR)->assertStatus(403);
        // A person with the right is told.
        $this->doPut($f['id'], ['sell' => ['enabled' => false, 'isActive' => true]], self::SALES)->assertStatus(422);

        $this->assertSame($before, $this->snapshot());
    }

    // ---- D-3: often sold together ----

    public function test_d3_often_sold_together_must_name_offers_the_organisation_can_see_and_never_the_offer_itself(): void
    {
        $this->siblings();
        DB::table('g2g_product_offers')->insert(['sub_institute_id' => null, 'offer_id' => 'SHARED-2', 'name' => 'Shared offer', 'parent_product' => 'G2G', 'readiness_status' => 'Prototype']);
        $this->doPost(['kind' => 'sub_product', 'name' => 'Other organisation offer', 'productId' => $this->scholar(), 'offerId' => 'O2-1', 'readiness' => 'Prototype', 'about' => ['whatItDoes' => 'x']], 3)->assertStatus(201);
        $this->events->lines = [];
        $before = $this->counts();
        $snapshot = $this->snapshot();

        $cases = [
            'an offer that is not there' => [['P1', 'GHOST'], ['about.oftenSoldTogether.1']],
            'two bad ids in a list of three' => [['GHOST', 'P1', 'NOPE'], ['about.oftenSoldTogether.0', 'about.oftenSoldTogether.2']],
            'the offer itself (a new offer has the id P7)' => [['P1', 'P7'], ['about.oftenSoldTogether.1']],
            'an offer of another organisation' => [['O2-1'], ['about.oftenSoldTogether.0']],
            'an id in another case' => [['p1'], ['about.oftenSoldTogether.0']],
            'an id with only spaces' => [['  '], ['about.oftenSoldTogether.0']],
        ];
        foreach ($cases as $why => [$ids, $keys]) {
            $errors = array_keys($this->doPost($this->aboutBody(['about' => ['oftenSoldTogether' => $ids]]))->assertStatus(422)->json('errors'));
            sort($errors);
            $this->assertSame($keys, $errors, $why);
        }
        $this->assertSame($before, $this->counts(), 'a refused request writes nothing');
        $this->assertSame($snapshot, $this->snapshot());

        // The offer itself is told as such; an id that is not there and another organisation's offer get the very same words (nothing is said about the other organisation).
        $message = fn (array $ids): string => $this->doPost($this->aboutBody(['about' => ['oftenSoldTogether' => $ids]]))->assertStatus(422)->json('errors')['about.oftenSoldTogether.0'][0];
        $this->assertStringContainsString('itself', $message(['P7']));
        $this->assertStringNotContainsString('itself', $message(['GHOST']));
        $this->assertSame($message(['GHOST']), $message(['O2-1']));

        // The ids that are fine: the organisation's own, a shared one, in the order sent, none twice.
        $ok = $this->doPost($this->aboutBody(['about' => ['oftenSoldTogether' => ['P1', 'SHARED-2', ' P2 ', 'P1']]]))->assertStatus(201);
        $this->assertSame(['P1', 'SHARED-2', 'P2'], $ok->json('data.offer.about.oftenSoldTogether'));
        $offer = $this->offerRow((int) $this->masterRow((int) $ok->json('data.id'))->offer_row_id);
        $this->assertSame(['P1', 'SHARED-2', 'P2'], json_decode($offer->bundles_with, true), 'stored as offer ids, trimmed and listed once');

        // Stored as the ids the Portfolio's own show route reads back (PortfolioController::show, whereIn offer_id).
        $shown = $this->getJson('/api/portfolio/offers/' . $offer->id, $this->bearer(self::ADMIN))->assertOk();
        $names = array_column($shown->json('data.bundled_offer_details'), 'offer_id');
        sort($names);
        $this->assertSame(['P1', 'P2', 'SHARED-2'], $names);
        $this->assertChecker('after offers that are often sold together');
    }

    public function test_d3_on_an_update_only_a_list_that_is_sent_is_judged_and_the_offer_itself_is_the_one_stored(): void
    {
        $this->siblings();
        $f = $this->both(['about' => ['oftenSoldTogether' => ['P1']]]);
        $stored = fn (): array => json_decode((string) $this->offerRow($f['offer'])->bundles_with, true);
        $this->assertSame(['P1'], $stored());
        $before = $this->snapshot();

        $this->doPut($f['id'], ['about' => ['oftenSoldTogether' => ['P3', 'GHOST']]])->assertStatus(422)->assertJsonStructure(['errors' => ['about.oftenSoldTogether.1']]);
        $this->doPut($f['id'], ['about' => ['oftenSoldTogether' => ['P7']]])->assertStatus(422)->assertJsonStructure(['errors' => ['about.oftenSoldTogether.0']]);
        $this->assertSame($before, $this->snapshot(), 'a refused list changes nothing');

        $this->doPut($f['id'], ['about' => ['oftenSoldTogether' => ['P3', 'P2']]], self::HR)->assertOk();
        $this->assertSame(['P3', 'P2'], $stored());

        // A list that is not sent is not judged again: the Portfolio itself, or an older import, may have left an id that is gone.
        DB::table('g2g_product_offers')->where('id', $f['offer'])->update(['bundles_with' => json_encode(['GONE'])]);
        $this->doPut($f['id'], ['about' => ['notes' => 'the list is left alone']])->assertOk();
        $this->assertSame(['GONE'], $stored());

        $this->doPut($f['id'], ['about' => ['oftenSoldTogether' => []]])->assertOk();
        $this->assertSame([], $stored(), 'an empty list clears it');
    }

    public function test_d3_the_writer_itself_trims_the_ids_because_the_pair_review_and_the_repair_call_it_without_the_framework(): void
    {
        // TrimStrings and ConvertEmptyStringsToNull act on a request, so the HTTP tests above never see a padded id; the writer is also called directly.
        $this->siblings();
        $userId = (int) $this->h(self::T, self::ADMIN)['_user'];
        $identity = ['user' => \App\Models\auth\tbluserModel::find($userId), 'user_id' => $userId, 'sub_institute_id' => self::T];
        $writer = new \App\Domain\ProductMaster\ProductWriter;
        $body = fn (array $ids): array => ['kind' => 'sub_product', 'name' => 'Direct', 'productId' => $this->scholar(), 'offerId' => 'D7', 'readiness' => 'Prototype', 'about' => ['oftenSoldTogether' => $ids]];

        $row = $writer->create($identity, $body([" P2\t", ' P1 ', 'P2']));
        $this->assertSame(['P2', 'P1'], json_decode((string) $this->offerRow((int) $row->offer_row_id)->bundles_with, true), 'padding is taken off, and the id listed twice is stored once');

        try {
            $writer->create($identity, ['offerId' => 'D8'] + $body([' D8 ']));
            $this->fail('the offer itself, padded, is still the offer itself');
        } catch (\App\Domain\ProductMaster\ProductMasterInvalid $e) {
            $this->assertStringContainsString('itself', $e->errors['about.oftenSoldTogether.0'][0]);
        }
    }

    public function test_d3_an_about_added_to_an_item_is_judged_against_its_own_new_offer_id(): void
    {
        $this->siblings();
        $item = (int) $this->doPost(['kind' => 'item', 'name' => 'Sold on its own', 'sell' => ['type' => 'product']])->assertStatus(201)->json('data.id');
        $before = $this->snapshot();

        $self = $this->doPut($item, ['productId' => $this->scholar(), 'offerId' => 'P8', 'readiness' => 'Prototype', 'about' => ['oftenSoldTogether' => ['P8']]])->assertStatus(422)->assertJsonStructure(['errors' => ['about.oftenSoldTogether.0']]);
        $this->assertStringContainsString('itself', $self->json('errors')['about.oftenSoldTogether.0'][0], 'the new offer\'s own id is named as the offer itself');
        $this->doPut($item, ['productId' => $this->scholar(), 'offerId' => 'P8', 'readiness' => 'Prototype', 'about' => ['oftenSoldTogether' => ['P1', 'GHOST']]])->assertStatus(422)->assertJsonStructure(['errors' => ['about.oftenSoldTogether.1']]);
        $this->assertSame($before, $this->snapshot());

        $this->doPut($item, ['productId' => $this->scholar(), 'offerId' => 'P8', 'readiness' => 'Prototype', 'about' => ['oftenSoldTogether' => ['P1']]])->assertOk();
        $this->assertSame(['P1'], json_decode((string) $this->offerRow((int) $this->masterRow($item)->offer_row_id)->bundles_with, true));
    }
}
