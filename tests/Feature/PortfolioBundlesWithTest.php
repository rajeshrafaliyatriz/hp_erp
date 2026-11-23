<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCrmProductApiWorld;
use Tests\TestCase;

/**
 * The old Portfolio form can no longer store "often sold together" ids that are no offer (slice 15, P2-E2a; the defect P2-F carried: `PortfolioController` store and update
 * took any list for `bundles_with`).
 *
 * Judged like the one-record writer judges `about.oftenSoldTogether` (both call App\Domain\ProductMaster\OftenSoldTogether): every id must be an offer the organisation can
 * see (its own or a shared one), never the offer itself; a 422 names the position (`bundles_with.N`) and NOTHING is written. Only a list that is SENT is judged, so a stale id
 * the form left behind earlier never blocks an unrelated edit. The list is stored trimmed, in the order sent, each id once.
 */
class PortfolioBundlesWithTest extends TestCase
{
    use BuildsCrmProductApiWorld;

    private const T = 1;

    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrmProductApiWorld();
    }

    private function body(array $over = []): array
    {
        return $over + ['offer_id' => 'N1', 'name' => 'New one', 'parent_product' => 'Scholar K-12', 'readiness_status' => 'Prototype'];
    }

    private function store(array $over = [])
    {
        return $this->postJson('/api/portfolio/offers', $this->body($over), $this->headers($this->h()));
    }

    private function update(int $id, array $body)
    {
        return $this->putJson("/api/portfolio/offers/{$id}", $body, $this->headers($this->h()));
    }

    private function stored(int $id): array
    {
        return (array) json_decode((string) DB::table('g2g_product_offers')->where('id', $id)->value('bundles_with'), true);
    }

    public function test_store_with_an_id_that_is_no_offer_is_a_422_naming_the_position_and_nothing_is_written(): void
    {
        $this->offerCreate('P1', 'Mine');
        $before = DB::table('g2g_product_offers')->count();

        $r = $this->store(['bundles_with' => ['P1', 'NOPE']])->assertStatus(422);

        $this->assertSame(['bundles_with.1'], array_keys($r->json('errors')), 'only the second id is wrong');
        $this->assertSame($before, DB::table('g2g_product_offers')->count(), 'no offer was made');
        $this->assertSame(0, DB::table('g2g_product_master')->where('product_key', 'like', '%/n1')->count());
    }

    public function test_store_with_the_offers_own_id_is_a_422(): void
    {
        $r = $this->store(['offer_id' => 'N1', 'bundles_with' => ['N1']])->assertStatus(422);

        $this->assertSame(['bundles_with.0'], array_keys($r->json('errors')));
        $this->assertSame('An offer cannot be often sold together with itself.', $r->json('errors')['bundles_with.0'][0]);
        $this->assertSame(0, DB::table('g2g_product_offers')->where('offer_id', 'N1')->count());
    }

    public function test_store_with_my_own_and_a_shared_offer_stores_the_list_trimmed_in_order_and_once(): void
    {
        $this->offerCreate('P1', 'Mine');
        DB::table('g2g_product_offers')->insert(['sub_institute_id' => null, 'offer_id' => 'SH1', 'name' => 'Shared one', 'parent_product' => 'Scholar K-12', 'readiness_status' => 'Prototype']);

        $id = (int) $this->store(['bundles_with' => [' SH1', 'P1', 'SH1 ']])->assertStatus(201)->json('data.id');

        $this->assertSame(['SH1', 'P1'], $this->stored($id));
    }

    public function test_another_organisations_offer_is_not_an_offer_i_can_name(): void
    {
        $this->offerCreate('Q1', 'Theirs', 'Scholar K-12', self::T2);

        $r = $this->store(['bundles_with' => ['Q1']])->assertStatus(422);

        $this->assertSame(['bundles_with.0'], array_keys($r->json('errors')));
    }

    public function test_a_list_item_that_is_not_text_is_a_422_too(): void
    {
        $this->store(['bundles_with' => [['P1']]])->assertStatus(422);
        $this->assertSame(0, DB::table('g2g_product_offers')->where('offer_id', 'N1')->count());
    }

    public function test_update_with_an_id_that_is_no_offer_is_a_422_and_the_offer_is_untouched(): void
    {
        $this->offerCreate('P1', 'Mine');
        $id = $this->offerCreate('P2', 'Two');
        $before = (array) DB::table('g2g_product_offers')->where('id', $id)->first();

        $r = $this->update($id, ['name' => 'Renamed', 'bundles_with' => ['P1', 'GHOST']])->assertStatus(422);

        $this->assertSame(['bundles_with.1'], array_keys($r->json('errors')));
        $this->assertSame($before, (array) DB::table('g2g_product_offers')->where('id', $id)->first(), 'not even the name changed');
    }

    public function test_update_with_the_offers_own_id_is_a_422(): void
    {
        $id = $this->offerCreate('P2', 'Two');

        $this->update($id, ['bundles_with' => ['P2']])->assertStatus(422)->assertJsonStructure(['errors' => ['bundles_with.0']]);
        $this->assertSame([], $this->stored($id));
    }

    public function test_update_with_real_offers_is_stored_and_read_back_by_the_show_route(): void
    {
        $this->offerCreate('P1', 'Mine');
        $id = $this->offerCreate('P2', 'Two');

        $this->update($id, ['bundles_with' => ['P1']])->assertOk();

        $this->assertSame(['P1'], $this->stored($id));
        $this->getJson("/api/portfolio/offers/{$id}", $this->headers($this->h()))->assertOk();
    }

    public function test_an_edit_that_does_not_send_the_list_is_never_blocked_by_a_stale_id_the_old_form_left_behind(): void
    {
        $id = $this->offerCreate('P2', 'Two');
        DB::table('g2g_product_offers')->where('id', $id)->update(['bundles_with' => json_encode(['STALE'])]);

        $this->update($id, ['name' => 'Two renamed'])->assertOk();

        $this->assertSame(['STALE'], $this->stored($id), 'the list was not sent, so it is not judged and not touched');
    }

    public function test_an_empty_list_clears_the_list(): void
    {
        $this->offerCreate('P1', 'Mine');
        $id = $this->offerCreate('P2', 'Two');
        $this->update($id, ['bundles_with' => ['P1']])->assertOk();

        $this->update($id, ['bundles_with' => []])->assertOk();

        $this->assertSame([], $this->stored($id));
    }

    public function test_a_null_list_is_allowed_as_before(): void
    {
        $id = $this->offerCreate('P2', 'Two');

        $this->update($id, ['bundles_with' => null])->assertOk();
    }
}
