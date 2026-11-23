<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCrmProductApiWorld;
use Tests\TestCase;

/**
 * One smoke test for each of the nine product routes that no test called before (audit C of the products parity audit, Docs/agentic-crm/_evidence/s15/products-parity-audit-1-fields-endpoints-2026-11-13.md;
 * slice 15, track WPA2-H2, 23 Nov 2026): through the real route, with a valid token, on a fixture from BuildsCrmProductApiWorld, asserting the status code and one key of the body.
 * Tests\Support\ProductRouteMap names this file as the test of each of them, and ProductRouteMapScanTest fails if the file stops naming one of the paths.
 *
 * Smoke only: who may call each route (the rights matrix) and every refusal are not under test here.
 *
 *   GET    /api/crm/products/{id}/bundle-items            the components of a bundle
 *   POST   /api/crm/products/{id}/bundle-items            add a component
 *   DELETE /api/crm/products/{id}/bundle-items/{rowId}    remove a component
 *   POST   /api/crm/products/{id}/image                   upload the picture
 *   GET    /api/crm/products/export                       the CSV file
 *   GET    /api/crm/products/duplicates                   same name or same SKU
 *   POST   /api/crm/products/bulk/assign                  change the owner of several items
 *   POST   /api/portfolio/offers/{id}/readiness           confirm a Sub-product's readiness (administrators)
 *   GET    /api/portfolio/offers/{id}/readiness-log       who confirmed it and when
 *   GET    /api/signals/icp/products/{product}/dependencies   what depends on a Product (the delete dialog's read; a tenth route, added by the caller)
 */
class CrmProductRouteSmokeTest extends TestCase
{
    use BuildsCrmProductApiWorld;

    private const T = 1;

    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrmProductApiWorld();
        // The two tables the world leaves out: the bundle components, and the readiness history (with the readiness columns of the offers).
        if (! Schema::hasTable('crm_product_bundle_items')) {
            (require base_path('database/migrations/2026_11_24_081500_create_crm_product_bundle_items_table.php'))->up();
        }
        if (! Schema::hasTable('g2g_offer_readiness_log')) {
            (require base_path('database/migrations/2026_10_08_120000_add_readiness_confirmation_to_g2g_product_offers.php'))->up();
        }
    }

    // ---- bundle components ----

    public function test_bundle_items_list_answers_200_with_the_components(): void
    {
        $h = $this->h();
        $parent = $this->crmCreate('Starter kit');
        $component = $this->crmCreate('Spare cable');
        DB::table('crm_product_bundle_items')->insert(['parent_product_id' => $parent, 'component_product_id' => $component, 'quantity' => 3, 'created_at' => now()]);

        $this->getJson('/api/crm/products/' . $parent . '/bundle-items', $this->headers($h))
            ->assertStatus(200)
            ->assertJsonPath('status', 1)
            ->assertJsonPath('data.0.productId', (string) $component);
    }

    public function test_bundle_item_add_answers_200_and_stores_the_component(): void
    {
        $h = $this->h();
        $parent = $this->crmCreate('Starter kit');
        $component = $this->crmCreate('Spare cable');

        $this->postJson('/api/crm/products/' . $parent . '/bundle-items', ['componentProductId' => $component, 'quantity' => 2], $this->headers($h))
            ->assertStatus(200)
            ->assertJsonPath('message', 'Component added.');

        $this->assertSame(1, DB::table('crm_product_bundle_items')->where('parent_product_id', $parent)->where('component_product_id', $component)->count());
    }

    public function test_bundle_item_remove_answers_200_and_deletes_the_row(): void
    {
        $h = $this->h();
        $parent = $this->crmCreate('Starter kit');
        $component = $this->crmCreate('Spare cable');
        $row = (int) DB::table('crm_product_bundle_items')->insertGetId(['parent_product_id' => $parent, 'component_product_id' => $component, 'quantity' => 1, 'created_at' => now()]);

        $this->deleteJson('/api/crm/products/' . $parent . '/bundle-items/' . $row, [], $this->headers($h))
            ->assertStatus(200)
            ->assertJsonPath('message', 'Component removed.');

        $this->assertSame(0, DB::table('crm_product_bundle_items')->where('id', $row)->count());
    }

    // ---- image, export, duplicates, bulk assign ----

    public function test_image_upload_answers_200_and_stores_the_file_name(): void
    {
        Storage::fake('digitalocean');
        $h = $this->h();
        $id = $this->crmCreate('Starter kit');
        // a real 1x1 PNG, so the `image` rule judges real bytes (no GD needed)
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAHnOcQAAAAABJRU5ErkJggg==');
        $file = UploadedFile::fake()->createWithContent('kit.png', $png);

        $this->post('/api/crm/products/' . $id . '/image', ['image' => $file], $this->headers($h) + ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJsonPath('message', 'Image uploaded.');

        $this->assertNotEmpty(DB::table('crm_products')->where('id', $id)->value('image'), 'the file name is kept on the item');
    }

    public function test_csv_export_answers_200_with_the_header_row_and_the_item(): void
    {
        $h = $this->h();
        $this->crmCreate('Exported kit');

        $response = $this->get('/api/crm/products/export?itemType=product', $this->headers($h));
        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringStartsWith('Name,SKU,Category', $csv);
        $this->assertStringContainsString('Exported kit', $csv);
    }

    public function test_duplicates_answers_200_with_the_group_of_two_items_of_the_same_name(): void
    {
        $h = $this->h();
        $this->crmCreate('Twin kit');
        $this->crmCreate('Twin kit');
        $this->crmCreate('Single kit');

        $this->getJson('/api/crm/products/duplicates?itemType=product', $this->headers($h))
            ->assertStatus(200)
            ->assertJsonPath('status', 1)
            ->assertJsonPath('data.0.reason', 'Same name')
            ->assertJsonCount(2, 'data.0.rows');
    }

    public function test_bulk_assign_answers_200_and_moves_the_item_to_the_new_owner(): void
    {
        $h = $this->h();
        $id = $this->crmCreate('Starter kit');
        $newOwner = $this->h()['_user'];

        $this->postJson('/api/crm/products/bulk/assign', ['ids' => [$id], 'itemType' => 'product', 'assignedTo' => $newOwner], $this->headers($h))
            ->assertStatus(200)
            ->assertJsonPath('data.summary.succeeded', 1);

        $this->assertSame($newOwner, (int) DB::table('crm_products')->where('id', $id)->value('assigned_to'));
    }

    // ---- the delete dialog's read (a tenth route: the map's last debt) ----

    public function test_a_products_dependencies_answer_200_with_the_counts_the_delete_dialog_shows(): void
    {
        $h = $this->h();

        $response = $this->getJson('/api/signals/icp/products/scholarclone/dependencies', $this->headers($h));

        $response->assertStatus(200)->assertJsonPath('status', 1);
        $this->assertIsArray($response->json('data'));
    }

    // ---- readiness of a Sub-product (Portfolio-only routes) ----

    public function test_readiness_confirm_answers_200_and_writes_a_history_line(): void
    {
        $h = $this->h();
        $offer = $this->offerCreate('P9', 'Scholar Nine');

        $this->postJson('/api/portfolio/offers/' . $offer . '/readiness', ['confirmed' => true, 'note' => 'Checked by the owner'], $this->headers($h))
            ->assertStatus(200)
            ->assertJsonPath('message', 'Readiness confirmed.');

        $this->assertSame('confirmed', DB::table('g2g_offer_readiness_log')->where('offer_row_id', $offer)->value('action'));
    }

    public function test_readiness_log_answers_200_with_the_history_of_the_offer(): void
    {
        $h = $this->h();
        $offer = $this->offerCreate('P9', 'Scholar Nine');
        DB::table('g2g_offer_readiness_log')->insert([
            'sub_institute_id' => self::T, 'offer_row_id' => $offer, 'offer_id' => 'P9', 'action' => 'confirmed', 'readiness_status' => 'Prototype',
            'actor_id' => $h['_user'], 'note' => 'Checked by the owner', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson('/api/portfolio/offers/' . $offer . '/readiness-log', $this->headers($h))
            ->assertStatus(200)
            ->assertJsonPath('status', 1)
            ->assertJsonPath('data.0.action', 'confirmed');
    }
}
