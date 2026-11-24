<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductIntegrity;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsProductUnifyWorld;
use Tests\TestCase;

/**
 * The pair review's API (slice 15, track P2-D): GET /api/product-master/matches and POST /api/product-master/matches/decide, ADMINISTRATORS ONLY, on the shape of the dev
 * data. What it proves: the suggestions come with the reasons and the answers already given and never a cost price or a commission; a non-administrator, a person without
 * the Products right and a request with no token are refused on both routes and nothing is written; an administrator of another organisation gets 404 for these ids; the
 * organisation comes from the token, never from the request; "same" merges and "different" writes only the decision row.
 */
class ProductMatchApiTest extends TestCase
{
    use BuildsProductUnifyWorld {
        dumpEverything as private dumpAllTables;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUnifyWorld();
    }

    /** Every row of every table except the tokens' own bookkeeping: Sanctum stamps a token (last_used_at, expires_at) the first time it is used, which is not a product row. */
    protected function dumpEverything(): array
    {
        $all = $this->dumpAllTables();
        unset($all['personal_access_tokens']);

        return $all;
    }

    /** @return array<string, mixed> */
    private function admin(int $tenant = self::T): array
    {
        return $this->h($tenant, $tenant === self::T ? 1 : 3);
    }

    /** @return array<string, mixed> an employee of organisation 1 (profile 2: not an administrator, but holding every right on Products in this fixture) */
    private function employee(): array
    {
        return $this->h(self::T, 2);
    }

    /** @param array<string, mixed> $body @param array<string, mixed> $h */
    private function decide(array $body, array $h): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/product-master/matches/decide', $body, $this->headers($h));
    }

    // ------------------------------------------------------------------ who may call it

    public function test_nobody_but_an_administrator_with_a_token_can_read_or_decide_and_nothing_is_written(): void
    {
        $w = $this->scholarWorld();
        $body = ['itemMasterId' => $w['items']['gujarat']['master'], 'survivorMasterId' => $w['subs']['SCH-01']['master'], 'decision' => 'same'];
        $employee = $this->employee();
        $admin = $this->admin();   // made now, so the comparison below never sees a new user
        $before = $this->dumpEverything();

        // no token
        $this->getJson('/api/product-master/matches')->assertStatus(401);
        $this->postJson('/api/product-master/matches/decide', $body)->assertStatus(401);
        // a bad token
        $this->getJson('/api/product-master/matches', ['Authorization' => 'Bearer nope'])->assertStatus(401);
        // an employee of the organisation, with every right on Products, is not an administrator
        $this->getJson('/api/product-master/matches', $this->headers($employee))->assertStatus(403)->assertJsonPath('status', 0);
        $this->decide($body, $employee)->assertStatus(403)->assertJsonPath('status', 0);
        $this->decide(['decision' => 'different'] + $body, $employee)->assertStatus(403);

        $this->assertSame($before, $this->dumpEverything(), 'not one row changed');
        $this->assertSame([], $this->events);
        // the administrator can
        $this->getJson('/api/product-master/matches', $this->headers($admin))->assertOk();
    }

    public function test_a_person_with_no_right_on_the_products_screen_is_stopped_at_the_route_even_if_an_administrator(): void
    {
        $w = $this->scholarWorld();
        // an administrator profile of organisation 1 that has had every Products right taken away
        DB::table('tbluserprofilemaster')->insert(['id' => 9, 'name' => 'Admin without Products', 'role_key' => 'administrator', 'sub_institute_id' => self::T]);
        $h = $this->h(self::T, 9);
        $before = $this->dumpEverything();

        $this->getJson('/api/product-master/matches', $this->headers($h))->assertStatus(403);
        $this->decide(['itemMasterId' => $w['items']['gujarat']['master'], 'survivorMasterId' => $w['subs']['SCH-01']['master'], 'decision' => 'same'], $h)->assertStatus(403);
        $this->assertSame($before, $this->dumpEverything());
    }

    // ------------------------------------------------------------------ the suggestions

    public function test_the_suggestions_carry_the_reasons_the_answers_given_and_no_cost_price_or_commission(): void
    {
        $w = $this->scholarWorld();
        $admin = $this->admin();
        $this->pointAtCrm($w['items']['gujarat']['crm']);
        DB::table('crm_products')->where('id', $w['items']['gujarat']['crm'])->update(['cost_price' => 7777.77, 'commission_rate' => 3.5]);
        DB::table('g2g_product_match_decisions')->insert(['sub_institute_id' => self::T, 'item_master_id' => $w['items']['lms']['master'], 'survivor_master_id' => $w['subs']['SCH-03']['master'], 'decision' => 'different', 'score' => 12, 'signals' => 'words +12', 'decided_by' => 1, 'decided_at' => '2026-11-13 10:00:00', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);

        $r = $this->getJson('/api/product-master/matches', $this->headers($admin))->assertOk();

        $r->assertJsonPath('status', 1);
        $data = $r->json('data');
        $this->assertCount(9, $data['matches']);
        $this->assertEquals(['floor' => 10, 'high' => 55, 'margin' => 15, 'editionMin' => 35], $data['thresholds']);
        $this->assertSame(['MATCH-HIGH' => 0, 'EDITION' => 2, 'AMBIGUOUS' => 2, 'SERVICE' => 2, 'NO-MATCH' => 3, 'STRAY' => 0, 'JUNK?' => 0], $data['counts']['byVerdict']);
        $g = collect($data['matches'])->firstWhere('itemMasterId', $w['items']['gujarat']['master']);
        $this->assertSame('EDITION', $g['verdict']);
        $this->assertSame($w['subs']['SCH-01']['master'], $g['candidate']['masterId']);
        $this->assertEquals(98, $g['score']);
        $this->assertSame('SCH-01-GUJ', $g['sku']);
        $this->assertEquals(120000, $g['price']);
        $this->assertNotEmpty($g['reasons']);
        $this->assertSame(1, $g['usage']['quotes']);
        $this->assertSame([$w['items']['core']['master']], $g['sharedWith']);
        // the answers already given, with the names
        $this->assertCount(1, $data['decisions']);
        $this->assertSame(['different', 'Scholar LMS & Digital Classroom', 'LMS-PAL (AI question generation)'], [$data['decisions'][0]['decision'], $data['decisions'][0]['itemName'], $data['decisions'][0]['survivorName']]);
        $this->assertNotEmpty($data['unmatchedSubProducts']);
        // never a cost price or a commission, anywhere in the answer
        $text = (string) $r->getContent();
        $this->assertStringNotContainsString('cost', strtolower($text));
        $this->assertStringNotContainsString('commission', strtolower($text));
        $this->assertStringNotContainsString('7777', $text);
    }

    public function test_the_organisation_is_the_tokens_never_the_requests(): void
    {
        $this->scholarWorld();
        $this->item('Only theirs', [], self::T2);
        $theirAdmin = $this->admin(self::T2);

        // organisation 2's administrator asks for organisation 1's suggestions by saying so: the request is ignored
        $r = $this->getJson('/api/product-master/matches?sub_institute_id=' . self::T, $this->headers($theirAdmin))->assertOk();

        $names = array_column($r->json('data.matches'), 'name');
        $this->assertSame(['Only theirs'], $names);
    }

    // ------------------------------------------------------------------ the answer

    public function test_same_merges_the_pair_into_one_record_and_a_second_answer_changes_nothing(): void
    {
        $w = $this->scholarWorld();
        $g = $w['items']['gujarat'];
        $s = $w['subs']['SCH-01'];
        $this->pointAtCrm($g['crm']);
        $this->pointAtMaster($g['master']);
        $admin = $this->admin();
        $body = ['itemMasterId' => $g['master'], 'survivorMasterId' => $s['master'], 'decision' => 'same', 'nameKept' => 'portfolio'];

        $r = $this->decide($body, $admin)->assertOk();

        $r->assertJsonPath('status', 1);
        $r->assertJsonPath('data.decision', 'same');
        $r->assertJsonPath('data.result', 'merged');
        $r->assertJsonPath('data.merged', true);
        $r->assertJsonPath('data.survivorMasterId', $s['master']);
        $r->assertJsonPath('data.crmProductId', $g['crm']);
        $r->assertJsonPath('data.name', 'Scholar School ERP');
        $r->assertJsonPath('data.audit', 'recorded');
        $this->assertSame($g['crm'], (int) DB::table('g2g_product_master')->where('id', $s['master'])->value('crm_product_id'));
        $this->assertSame('merged', DB::table('g2g_product_master')->where('id', $g['master'])->value('origin'));
        $this->assertSame(0, (new ProductIntegrity(null, self::T))->check()['errors']);
        $this->assertSame('product.merged', $this->events[0]['type']);
        $this->assertSame($admin['_user'], $this->events[0]['actor'], 'the event names the person of the token');
        $this->assertSame($admin['_user'], (int) DB::table('g2g_product_match_decisions')->value('decided_by'));
        $this->assertSame('screen', $this->events[0]['payload']['via']);

        // the same answer again is a no-op, not an error
        $before = $this->dumpEverything();
        $again = $this->decide($body, $admin)->assertOk();
        $again->assertJsonPath('data.result', 'already_merged');
        $again->assertJsonPath('data.merged', false);
        $this->assertSame($before, $this->dumpEverything());
        $this->assertCount(1, $this->events);

        // and the pair is gone from the suggestions, listed among the answers
        $list = $this->getJson('/api/product-master/matches', $this->headers($admin))->json('data');
        $this->assertNotContains($g['master'], array_column($list['matches'], 'itemMasterId'));
        $this->assertSame(['same', 'portfolio'], [$list['decisions'][0]['decision'], $list['decisions'][0]['nameKept']]);
        $this->assertSame(8, $list['counts']['items']);
    }

    public function test_the_catalogue_name_can_be_kept(): void
    {
        $w = $this->scholarWorld();
        $admin = $this->admin();

        $r = $this->decide(['itemMasterId' => $w['items']['gujarat']['master'], 'survivorMasterId' => $w['subs']['SCH-01']['master'], 'decision' => 'same', 'nameKept' => 'catalogue'], $admin)->assertOk();

        $r->assertJsonPath('data.name', 'Scholar One ERP - Gujarat Edition');
        $this->assertSame('Scholar One ERP - Gujarat Edition', DB::table('g2g_product_offers')->where('id', $w['subs']['SCH-01']['offer'])->value('name'));
    }

    public function test_different_writes_only_the_decision_row_and_the_pair_is_not_offered_again(): void
    {
        $w = $this->scholarWorld();
        $admin = $this->admin();
        $before = $this->dumpEverything();
        $body = ['itemMasterId' => $w['items']['gujarat']['master'], 'survivorMasterId' => $w['subs']['SCH-01']['master'], 'decision' => 'different'];

        $r = $this->decide($body, $admin)->assertOk();

        $r->assertJsonPath('data.decision', 'different');
        $r->assertJsonPath('data.result', 'recorded');
        $r->assertJsonPath('data.merged', false);
        $after = $this->dumpEverything();
        foreach (['g2g_product_match_decisions', 'personal_access_tokens'] as $table) {
            unset($before[$table], $after[$table]);
        }
        $this->assertSame($before, $after, 'nothing but the decision row (and the token\'s own bookkeeping)');
        $this->assertSame('different', DB::table('g2g_product_match_decisions')->value('decision'));

        $list = $this->getJson('/api/product-master/matches', $this->headers($admin))->json('data');
        $g = collect($list['matches'])->firstWhere('itemMasterId', $w['items']['gujarat']['master']);
        $this->assertSame([$w['subs']['SCH-01']['master']], $g['rejected']);
        $this->assertNotSame($w['subs']['SCH-01']['master'], $g['candidate']['masterId'] ?? 0);
        $this->assertSame('already_decided', $this->decide($body, $admin)->json('data.result'));
    }

    public function test_a_wrong_request_is_refused_with_the_reason_and_writes_nothing(): void
    {
        $w = $this->scholarWorld();
        $admin = $this->admin();
        $ok = ['itemMasterId' => $w['items']['gujarat']['master'], 'survivorMasterId' => $w['subs']['SCH-01']['master'], 'decision' => 'same'];
        $before = $this->dumpEverything();

        $this->decide([], $admin)->assertStatus(422)->assertJsonPath('status', 0);
        $this->decide(['itemMasterId' => 'x'] + $ok, $admin)->assertStatus(422);
        // the controller refuses a name that is not offered itself, before the domain is asked (its own validator's words, not the domain's)
        $this->decide(['nameKept' => 'both'] + $ok, $admin)->assertStatus(422)->assertJsonPath('errors.nameKept.0', 'The selected name kept is invalid.');
        $this->decide(['decision' => 'maybe'] + $ok, $admin)->assertStatus(422)->assertJsonPath('errors.decision.0', 'The selected decision is invalid.');
        foreach ([
            ['itemMasterId' => 0], ['itemMasterId' => -4], ['itemMasterId' => 1.5], ['survivorMasterId' => null], ['decision' => 'maybe'], ['decision' => 'merge'], ['nameKept' => 'both'],
            ['survivorMasterId' => $w['items']['gujarat']['master']],   // the same id twice
        ] as $change) {
            $r = $this->decide($change + $ok, $admin);
            $r->assertStatus(422);
            $this->assertNotSame('', (string) $r->json('message'));
        }
        // not an item and a Sub-product, in that order
        $this->decide(['itemMasterId' => $w['subs']['SCH-01']['master'], 'survivorMasterId' => $w['subs']['SCH-02']['master']] + $ok, $admin)->assertStatus(422)->assertJsonPath('errors.itemMasterId.0', 'That is not a catalogue item.');
        $this->decide(['survivorMasterId' => $w['items']['core']['master']] + $ok, $admin)->assertStatus(422)->assertJsonPath('errors.survivorMasterId.0', 'That is not a Sub-product.');
        // a pair under two different Products
        $this->decide(['survivorMasterId' => $w['subs']['EB-01']['master']] + $ok, $admin)->assertStatus(422)->assertJsonPath('status', 0);

        $this->assertSame($before, $this->dumpEverything());
        $this->assertSame([], $this->events);
    }

    public function test_another_organisations_ids_are_not_found_whatever_the_request_says(): void
    {
        $w = $this->scholarWorld();
        $theirSub = $this->subProduct('SCH-01', 'Scholar School ERP', [], self::T2);
        $theirItem = $this->item('Their item', [], self::T2);
        $theirAdmin = $this->admin(self::T2);
        $myAdmin = $this->admin();
        $leftover = $this->product('leftover', 'Leftover', 'Leftover', 0);   // a row of organisation 0: nobody's since the isolation of 24 Nov 2026
        $before = $this->dumpEverything();

        // organisation 2's administrator names organisation 1's rows, saying they are organisation 1's
        $this->decide(['itemMasterId' => $w['items']['gujarat']['master'], 'survivorMasterId' => $w['subs']['SCH-01']['master'], 'decision' => 'same', 'sub_institute_id' => self::T], $theirAdmin)->assertStatus(404);
        $this->decide(['itemMasterId' => $w['items']['gujarat']['master'], 'survivorMasterId' => $theirSub['master'], 'decision' => 'same'], $theirAdmin)->assertStatus(404);
        $this->decide(['itemMasterId' => $w['items']['gujarat']['master'], 'survivorMasterId' => $w['subs']['SCH-01']['master'], 'decision' => 'different'], $theirAdmin)->assertStatus(404);
        // organisation 1's administrator names organisation 2's rows
        $this->decide(['itemMasterId' => $theirItem['master'], 'survivorMasterId' => $w['subs']['SCH-01']['master'], 'decision' => 'same'], $myAdmin)->assertStatus(404);
        $this->decide(['itemMasterId' => $w['items']['gujarat']['master'], 'survivorMasterId' => $theirSub['master'], 'decision' => 'different'], $myAdmin)->assertStatus(404);
        // a leftover row of organisation 0 is nobody's to merge
        $this->decide(['itemMasterId' => $w['items']['gujarat']['master'], 'survivorMasterId' => $leftover, 'decision' => 'same'], $myAdmin)->assertStatus(404);

        $after = $this->dumpEverything();
        unset($before['personal_access_tokens'], $after['personal_access_tokens']);
        $this->assertSame($before, $after, 'not one row of either organisation changed');
        $this->assertSame([], $this->events);
    }

    public function test_a_merge_the_checker_does_not_like_is_refused_with_409_and_taken_back(): void
    {
        $w = $this->scholarWorld();
        $admin = $this->admin();
        DB::table('crm_leads')->insert(['product_master_id' => 987654, 'sub_institute_id' => self::T, 'updated_at' => self::STAMP]);   // unrelated damage in this organisation
        $before = $this->dumpEverything();

        $r = $this->decide(['itemMasterId' => $w['items']['gujarat']['master'], 'survivorMasterId' => $w['subs']['SCH-01']['master'], 'decision' => 'same'], $admin);

        $r->assertStatus(409);
        $r->assertJsonPath('status', 0);
        $this->assertStringContainsString('so nothing was merged', (string) $r->json('message'));
        $this->assertNotEmpty($r->json('errors.integrity'));
        $after = $this->dumpEverything();
        unset($before['personal_access_tokens'], $after['personal_access_tokens']);
        $this->assertSame($before, $after);
    }
}
