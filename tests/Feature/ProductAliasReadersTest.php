<?php

namespace Tests\Feature;

use App\Domain\OutreachGate\Checks\UnapprovedClaimCheck;
use App\Domain\OutreachGate\ClaimBook;
use App\Domain\OutreachGate\Draft;
use App\Domain\ProductMaster\ProductMerger;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsProductUnifyWorld;
use Tests\TestCase;

/**
 * G-E4 of the products parity audit (slice 15, track WPB): "a retired id has a reader". After a pair merge the catalogue item's master row is a HUSK (archived, origin
 * `merged`, no pointers) and g2g_product_match_decisions says husk -> survivor. Until this track nothing read that alias: the outreach gate's product-by-name scan still
 * returned the husk, and the read API answered a husk like a live record.
 *
 * What is proven here, on the shape of the dev data (tests/Support/BuildsProductUnifyWorld.php, the Scholar world):
 *   - a draft that names the retired item is checked against the SURVIVOR's approved claims (and the husk is never returned as itself);
 *   - the alias is read through the organisation's own husk and its own decision row only (another organisation's decision is never read);
 *   - an item that is not a husk is never mapped, whatever a decision row says; an archived product that is not a husk still counts (unchanged);
 *   - GET /api/product-master/{id} and /by-crm/{crmId} answer `mergedInto: {id, key, name}` for a husk and null for everything else.
 */
class ProductAliasReadersTest extends TestCase
{
    use BuildsProductUnifyWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUnifyWorld();
    }

    /** Merges the Gujarat edition into Scholar School ERP through the real merger and returns [world, husk master id, survivor master id]. */
    private function mergedWorld(): array
    {
        $w = $this->scholarWorld();
        $husk = $w['items']['gujarat']['master'];
        $survivor = $w['subs']['SCH-01']['master'];
        $this->assertSame('merged', (new ProductMerger)->merge($this->adminIdentity(), $husk, $survivor)['result']);
        $row = DB::table('g2g_product_master')->where('id', $husk)->first();
        $this->assertSame(['archived', 'merged'], [$row->status, $row->origin], 'the merge made the husk');

        return [$w, $husk, $survivor];
    }

    private function claimCheck(): UnapprovedClaimCheck
    {
        return new UnapprovedClaimCheck(new ClaimBook, (array) config('outreach_gate.claim_markers'));
    }

    private function draft(string $body, int $tenant = self::T): Draft
    {
        return new Draft(Draft::GTM, 1, $tenant, 'Hello', $body);
    }

    // ------------------------------------------------------------------ the outreach gate's product-by-name scan

    public function test_a_draft_that_names_the_retired_item_is_about_the_survivor_never_the_husk(): void
    {
        [, $husk, $survivor] = $this->mergedWorld();
        $book = new ClaimBook;

        $this->assertSame([$survivor], $book->productsNamedIn(self::T, 'We can show Scholar One ERP - Gujarat Edition next week.'), 'the old name maps to the survivor');
        $this->assertSame([$survivor], $book->productsNamedIn(self::T, 'Scholar One ERP - Gujarat Edition and Scholar School ERP are the same thing.'), 'a text naming both gives the survivor once');
        $this->assertSame([$survivor], $book->productsNamedIn(self::T, 'Try Scholar School ERP.'), 'the survivor by its own name is unchanged');
        $this->assertNotContains($husk, $book->productsNamedIn(self::T, 'Scholar One ERP - Gujarat Edition, Scholar School ERP'), 'the husk is never returned as itself');
    }

    public function test_a_claim_approved_for_the_survivor_covers_a_draft_that_names_the_retired_item(): void
    {
        [, , $survivor] = $this->mergedWorld();
        $this->claim($survivor, 'Scholar School ERP cuts paperwork by 60% for schools', 'approved');

        // The draft names only the OLD item; its claim sentence is a run of the survivor's approved claim.
        $result = $this->claimCheck()->run($this->draft('Scholar One ERP - Gujarat Edition is our flagship. Cuts paperwork by 60% for schools.'));
        $this->assertTrue($result->passed, 'the survivor\'s approved claim is the one the draft rests on: ' . $result->detail);

        // The same sentence about a product nobody approved it for still fails.
        $other = $this->claimCheck()->run($this->draft('Scholar Core School Management is our flagship. Cuts paperwork by 60% for schools.'));
        $this->assertFalse($other->passed);
    }

    public function test_a_husk_with_no_readable_alias_names_nothing(): void
    {
        $w = $this->scholarWorld();
        $husk = $w['items']['gujarat']['master'];
        // A husk row (archived, merged) with no decision row at all: not attached, and not mapped to anything.
        DB::table('g2g_product_master')->where('id', $husk)->update(['status' => 'archived', 'origin' => 'merged', 'crm_product_id' => null]);

        $this->assertSame([], (new ClaimBook)->productsNamedIn(self::T, 'Scholar One ERP - Gujarat Edition'));
        // A "different" decision is not an alias either.
        DB::table('g2g_product_match_decisions')->insert(['sub_institute_id' => self::T, 'item_master_id' => $husk, 'survivor_master_id' => $w['subs']['SCH-01']['master'], 'decision' => 'different', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([], (new ClaimBook)->productsNamedIn(self::T, 'Scholar One ERP - Gujarat Edition'));
    }

    public function test_another_organisations_decision_is_never_read(): void
    {
        $w = $this->scholarWorld();
        $husk = $w['items']['gujarat']['master'];
        $stranger = $w['subs']['SCH-02']['master'];   // organisation 1's own Sub-product the husk was never merged into
        DB::table('g2g_product_master')->where('id', $husk)->update(['status' => 'archived', 'origin' => 'merged', 'crm_product_id' => null]);
        // Organisation 2 holds a decision row that names organisation 1's husk. It must not be read for organisation 1.
        DB::table('g2g_product_match_decisions')->insert(['sub_institute_id' => self::T2, 'item_master_id' => $husk, 'survivor_master_id' => $stranger, 'decision' => 'same', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame([], (new ClaimBook)->productsNamedIn(self::T, 'Scholar One ERP - Gujarat Edition'));
        $this->assertSame([], \App\Domain\ProductMaster\ProductAlias::survivorsOf(self::T, [$husk]));
        // Organisation 1's own decision for the same husk is read.
        DB::table('g2g_product_match_decisions')->insert(['sub_institute_id' => self::T, 'item_master_id' => $husk, 'survivor_master_id' => $w['subs']['SCH-01']['master'], 'decision' => 'same', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([$w['subs']['SCH-01']['master']], (new ClaimBook)->productsNamedIn(self::T, 'Scholar One ERP - Gujarat Edition'));
    }

    public function test_a_survivor_the_organisation_may_not_see_is_never_named(): void
    {
        $w = $this->scholarWorld();
        $husk = $w['items']['gujarat']['master'];
        $foreign = $this->subProduct('T2-ONE', 'Their Secret Sub-product', [], self::T2)['master'];
        DB::table('g2g_product_master')->where('id', $husk)->update(['status' => 'archived', 'origin' => 'merged', 'crm_product_id' => null]);
        // Organisation 1's own decision, pointing at a row of organisation 2 (never written by the merger, but a bad row must not leak a name).
        DB::table('g2g_product_match_decisions')->insert(['sub_institute_id' => self::T, 'item_master_id' => $husk, 'survivor_master_id' => $foreign, 'decision' => 'same', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame([], \App\Domain\ProductMaster\ProductAlias::survivorsOf(self::T, [$husk]));
        $this->assertSame([], (new ClaimBook)->productsNamedIn(self::T, 'Scholar One ERP - Gujarat Edition'));
        $r = $this->getJson('/api/product-master/' . $husk, $this->headers($this->h(self::T, 1)))->assertOk();
        $r->assertJsonPath('data.mergedInto', null);
        $this->assertStringNotContainsString('Their Secret Sub-product', $r->getContent());
    }

    public function test_when_a_merge_was_undone_and_made_again_the_newest_decision_wins(): void
    {
        $w = $this->scholarWorld();
        $husk = $w['items']['gujarat']['master'];
        DB::table('g2g_product_master')->where('id', $husk)->update(['status' => 'archived', 'origin' => 'merged', 'crm_product_id' => null]);
        $old = $w['subs']['SCH-02']['master'];
        $new = $w['subs']['SCH-01']['master'];
        foreach ([$old, $new] as $survivor) {
            DB::table('g2g_product_match_decisions')->insert(['sub_institute_id' => self::T, 'item_master_id' => $husk, 'survivor_master_id' => $survivor, 'decision' => 'same', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->assertSame([$husk => $new], \App\Domain\ProductMaster\ProductAlias::survivorsOf(self::T, [$husk]));
    }

    public function test_an_item_that_is_not_a_husk_is_never_mapped_and_an_archived_product_still_counts(): void
    {
        $w = $this->scholarWorld();
        $item = $w['items']['gujarat']['master'];
        $survivor = $w['subs']['SCH-01']['master'];
        // A decision row left over from a rolled-back merge: the item is active again and is itself.
        DB::table('g2g_product_match_decisions')->insert(['sub_institute_id' => self::T, 'item_master_id' => $item, 'survivor_master_id' => $survivor, 'decision' => 'same', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([$item], (new ClaimBook)->productsNamedIn(self::T, 'Scholar One ERP - Gujarat Edition'));
        $this->assertSame([], \App\Domain\ProductMaster\ProductAlias::survivorsOf(self::T, [$item]));

        // An archived (paused) product that was not merged away keeps counting: a claim about it is checked against its own approved claims.
        DB::table('g2g_product_master')->where('id', $survivor)->update(['status' => 'archived']);
        $this->assertSame([$survivor], (new ClaimBook)->productsNamedIn(self::T, 'Scholar School ERP'));
    }

    // ------------------------------------------------------------------ the read API

    public function test_show_of_a_husk_names_the_survivor_and_every_other_record_answers_null(): void
    {
        [$w, $husk, $survivor] = $this->mergedWorld();
        $h = $this->headers($this->h(self::T, 1));

        $r = $this->getJson('/api/product-master/' . $husk, $h)->assertOk();
        $r->assertJsonPath('data.mergedInto.id', $survivor)->assertJsonPath('data.mergedInto.name', 'Scholar School ERP');
        $this->assertSame(DB::table('g2g_product_master')->where('id', $survivor)->value('product_key'), $r->json('data.mergedInto.key'));
        $this->assertSame(['id', 'key', 'name'], array_keys($r->json('data.mergedInto')));

        foreach ([$survivor, $w['items']['core']['master'], $w['product']] as $id) {
            $answer = $this->getJson('/api/product-master/' . $id, $h)->assertOk()->json('data');
            $this->assertArrayHasKey('mergedInto', $answer, 'the key is always there');
            $this->assertNull($answer['mergedInto']);
        }
    }

    public function test_show_never_reads_another_organisations_decision(): void
    {
        $w = $this->scholarWorld();
        $husk = $w['items']['gujarat']['master'];
        DB::table('g2g_product_master')->where('id', $husk)->update(['status' => 'archived', 'origin' => 'merged', 'crm_product_id' => null]);
        DB::table('g2g_product_match_decisions')->insert(['sub_institute_id' => self::T2, 'item_master_id' => $husk, 'survivor_master_id' => $w['subs']['SCH-02']['master'], 'decision' => 'same', 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson('/api/product-master/' . $husk, $this->headers($this->h(self::T, 1)))->assertOk()->assertJsonPath('data.mergedInto', null);
        // Organisation 2 cannot open organisation 1's husk at all.
        $this->getJson('/api/product-master/' . $husk, $this->headers($this->h(self::T2, 3)))->assertStatus(404);
    }

    public function test_by_crm_answers_the_survivor_after_a_merge_and_names_a_husk_that_still_holds_a_pointer(): void
    {
        [$w, $husk, $survivor] = $this->mergedWorld();
        $h = $this->headers($this->h(self::T, 1));
        $crm = $w['items']['gujarat']['crm'];

        // The merge moved the catalogue pointer to the survivor: the old catalogue id leads to it, and it is not a husk.
        $this->getJson('/api/product-master/by-crm/' . $crm, $h)->assertOk()->assertExactJson(['status' => 1, 'data' => ['id' => $survivor, 'kind' => 'sub_product', 'mergedInto' => null]]);

        // A husk that (by some old data) still holds a catalogue id says where it went.
        DB::table('g2g_product_master')->where('id', $survivor)->update(['crm_product_id' => null]);
        DB::table('g2g_product_master')->where('id', $husk)->update(['crm_product_id' => $crm]);
        $this->getJson('/api/product-master/by-crm/' . $crm, $h)->assertOk()->assertJsonPath('data.id', $husk)->assertJsonPath('data.mergedInto.id', $survivor)->assertJsonPath('data.mergedInto.name', 'Scholar School ERP');
    }
}
