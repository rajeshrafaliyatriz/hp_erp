<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\ProductIntegrity;
use App\Domain\ProductMaster\ProductMerger;
use App\Domain\ProductMaster\ProductMasterInvalid;
use App\Domain\ProductMaster\ProductPointers;
use App\Services\Events\EventRecorder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsProductUnifyWorld;
use Tests\TestCase;

/**
 * The confirmed merge (slice 15, track P2-D): a catalogue item and a Sub-product that a person says are the same product become ONE record, without losing a quote line,
 * an order, an invoice, a price-book line or a signal. Built on the shape of the dev data (tests/Support/BuildsProductUnifyWorld.php), with a line in every one of the 12
 * columns that point at a catalogue row and a row in every one of the 9 columns that point at the item's master row.
 *
 * What it proves: the one record (names, pointers, husk, decision, event); the census of the line tables unchanged and the checker clean; nothing deleted; the nine
 * pointers moved and the one that names the Product left alone; claims that collide keep the survivor's; rows of another organisation untouched; idempotence; every refusal
 * writes nothing; a forced failure at EACH step, a checker error and a census difference take everything back.
 */
class ProductMergerTest extends TestCase
{
    use BuildsProductUnifyWorld;

    /** @var array{user: object, user_id: int, sub_institute_id: int} the administrator every merge is made by (made once, so a "nothing changed" comparison never sees a new user) */
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildUnifyWorld();
        $this->admin = $this->adminIdentity();
    }

    /** @return array{0: array<string, mixed>, 1: array{master: int, crm: int}, 2: array{master: int, offer: int}} the world, the "Scholar One ERP - Gujarat Edition" item and "Scholar School ERP" */
    private function pair(): array
    {
        $w = $this->scholarWorld();

        return [$w, $w['items']['gujarat'], $w['subs']['SCH-01']];
    }

    private function merger(): ProductMerger
    {
        return new ProductMerger;
    }

    /** @return array<string, mixed> */
    private function doMerge(array $item, array $sub, string $nameKept = 'portfolio', ?array $identity = null): array
    {
        return $this->merger()->merge($identity ?? $this->admin, $item['master'], $sub['master'], $nameKept);
    }

    private function masterRow(int $id): object
    {
        return DB::table('g2g_product_master')->where('id', $id)->first();
    }

    /** @return list<int> the ids of the rows of a table that hold a value in a column, in id order */
    private function holders(string $table, string $column, int $value): array
    {
        return DB::table($table)->where($column, $value)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return array<string, mixed> */
    private function check(int $tenant = self::T): array
    {
        return (new ProductIntegrity(null, $tenant))->check();
    }

    private function failAfter(string $pattern): void
    {
        $armed = true;
        DB::listen(function ($q) use (&$armed, $pattern) {
            if ($armed && preg_match($pattern, $q->sql) === 1) {
                $armed = false;
                throw new \RuntimeException('forced failure after: ' . $q->sql);
            }
        });
    }

    // ------------------------------------------------------------------ the world is clean, so any error after a merge is the merge's

    public function test_the_world_the_merge_tests_run_on_is_clean(): void
    {
        $this->scholarWorld();
        $r = $this->check();

        $this->assertSame(['errors' => 0, 'warnings' => 0], ['errors' => $r['errors'], 'warnings' => $r['warnings']], json_encode($r['findings']));
        $this->assertSame(9, $r['checked']['catalogueItems']);
        $this->assertGreaterThanOrEqual(16, $r['checked']['masterRows']);
    }

    // ------------------------------------------------------------------ the one record

    public function test_a_merge_makes_one_record_and_loses_no_line_no_sum_and_no_pointer(): void
    {
        [$w, $g, $s] = $this->pair();
        $crmRefs = $this->pointAtCrm($g['crm']);
        $huskRefs = $this->pointAtMaster($g['master']);
        $survivorRefs = $this->pointAtMaster($s['master'], self::T, 'The survivor says its own thing');
        $integrity = new ProductIntegrity(null, self::T);
        $before = $integrity->census();
        $crmBefore = DB::table('crm_products')->where('id', $g['crm'])->first();
        $line = $crmBefore->product_master_id;
        $this->assertSame(0, $this->check()['errors'], 'clean before');

        $result = $this->doMerge($g, $s);

        $this->assertSame('merged', $result['result']);
        // 1. the census of every pointer table is exactly what it was: no line lost, none added, no quantity or total moved
        $this->assertSame([], ProductIntegrity::differences($before, $integrity->census()));
        // 2. the checker is clean
        $after = $this->check();
        $this->assertSame(['errors' => 0, 'warnings' => 0], ['errors' => $after['errors'], 'warnings' => $after['warnings']], json_encode($after['findings']));

        // 3. the husk: archived, merged, no pointer, still there, still named what it was
        $husk = $this->masterRow($g['master']);
        $this->assertSame(['archived', 'merged', 'item'], [$husk->status, $husk->origin, $husk->kind]);
        $this->assertNull($husk->crm_product_id);
        $this->assertNull($husk->offer_row_id);
        $this->assertSame('Scholar One ERP - Gujarat Edition', $husk->name);
        // 4. the survivor holds BOTH pointers, under its own name
        $sub = $this->masterRow($s['master']);
        $this->assertSame([$s['offer'], $g['crm'], 'sub_product', 'active', 'Scholar School ERP'], [$sub->offer_row_id, $sub->crm_product_id, $sub->kind, $sub->status, $sub->name]);
        $this->assertSame($w['product'], (int) $sub->parent_id);
        // 5. the CRM row is not deleted and not replaced; it carries the one name and still sits under the Product
        $crm = DB::table('crm_products')->where('id', $g['crm'])->first();
        $this->assertNotNull($crm);
        $this->assertNull($crm->deleted_at);
        $this->assertSame('Scholar School ERP', $crm->name, 'the Portfolio name survives');
        $this->assertSame($line, $crm->product_master_id, 'crm_products.product_master_id names the Product and is never repointed');
        $this->assertSame($w['product'], (int) $crm->product_master_id);
        $this->assertSame(120000.0, (float) $crm->unit_price, 'what it costs is the catalogue row\'s, untouched');
        $this->assertSame('SCH-01-GUJ', $crm->sku);
        $this->assertSame('Scholar School ERP', DB::table('g2g_product_offers')->where('id', $s['offer'])->value('name'));
        // 6. the 12 catalogue pointers still hold the same catalogue row (that is why nothing is lost)
        foreach ($crmRefs as $key => $id) {
            [$table, $column] = explode('.', $key);
            $this->assertSame($g['crm'], (int) DB::table($table)->where('id', $id)->value($column), $key);
        }
        // 7. the nine pointers at the master row moved to the survivor, one row each, and nothing holds the husk any more
        $this->assertCount(9, $huskRefs);
        foreach ($huskRefs as $key => $id) {
            [$table, $column] = explode('.', $key);
            $this->assertSame($s['master'], (int) DB::table($table)->where('id', $id)->value($column), "{$key} now points at the survivor");
            $this->assertSame([], $this->holders($table, $column, $g['master']), "nothing in {$table} holds the husk any more");
            $this->assertSame([$id, $survivorRefs[$key]], $this->holders($table, $column, $s['master']), "the survivor kept its own row and took the husk's");
            $this->assertSame(1, $result['moved'][$key], $key);
        }
        $this->assertSame(9, count($result['moved']));
        $this->assertSame(['moved' => 1, 'retired' => 0, 'retiredApproved' => 0], $result['claims']);
        // 8. the decision is the alias of the husk
        $d = DB::table('g2g_product_match_decisions')->first();
        $this->assertSame([self::T, $g['master'], $s['master'], 'same', 'portfolio'], [(int) $d->sub_institute_id, (int) $d->item_master_id, (int) $d->survivor_master_id, $d->decision, $d->name_kept]);
        $this->assertEquals(98.0, (float) $d->score);
        $this->assertSame('category +40, sku +30, words +12, text +16', $d->signals);
        $this->assertNotNull($d->decided_at);
        $this->assertSame($result['decisionId'], (int) $d->id);
        // 9. one audit event, after the commit, by the person
        $this->assertCount(1, $this->events);
        $e = $this->events[0];
        $this->assertSame(['product.merged', self::T, 'g2g_product_master', $s['master']], [$e['type'], $e['tenant'], $e['entityType'], $e['entityId']]);
        $this->assertSame('recorded', $result['audit']);
        $this->assertSame($g['master'], $e['payload']['itemMasterId']);
        $this->assertSame(['portfolio' => 'Scholar School ERP', 'catalogue' => 'Scholar One ERP - Gujarat Edition'], $e['payload']['previous']);
        $this->assertStringStartsWith('product.merged:' . self::T . ':' . $g['master'] . ':' . $s['master'] . ':', (string) $e['key']);
        // 10. nothing was deleted: the same number of rows in every table but the decision table
        $this->assertSame(1, DB::table('g2g_product_match_decisions')->count());
    }

    public function test_the_catalogue_name_can_be_kept_and_it_is_the_one_name_of_all_three_copies(): void
    {
        [$w, $g, $s] = $this->pair();

        $result = $this->doMerge($g, $s, 'catalogue');

        $this->assertSame('Scholar One ERP - Gujarat Edition', $result['name']);
        $this->assertSame('Scholar One ERP - Gujarat Edition', $this->masterRow($s['master'])->name);
        $this->assertSame('Scholar One ERP - Gujarat Edition', DB::table('g2g_product_offers')->where('id', $s['offer'])->value('name'));
        $this->assertSame('Scholar One ERP - Gujarat Edition', DB::table('crm_products')->where('id', $g['crm'])->value('name'));
        $this->assertSame('catalogue', DB::table('g2g_product_match_decisions')->value('name_kept'));
        $this->assertSame(0, $this->check()['errors']);
        $this->assertSame($w['product'], (int) $this->masterRow($s['master'])->parent_id);
    }

    public function test_an_item_with_no_product_takes_the_sub_products_product_and_a_service_merges_when_a_person_says_so(): void
    {
        $w = $this->scholarWorld();
        $stray = $this->item('Loose catalogue item', ['product_master_id' => null]);
        $sub = $this->subProduct('SCH-90', 'Parent Portal');
        $this->merger()->merge($this->admin, $stray['master'], $sub['master']);

        $this->assertSame($w['product'], (int) DB::table('crm_products')->where('id', $stray['crm'])->value('product_master_id'), 'the line of the item is now the Sub-product\'s Product');
        $this->assertSame(0, $this->check()['errors']);

        // The suggester never proposes it, but a person is the authority: a service item merges into a Sub-product like any other.
        $svc = $w['items']['implementation'];
        $sub2 = $this->subProduct('SCH-91', 'Rollout Kit');
        $this->assertSame('merged', $this->doMerge($svc, $sub2)['result']);
        $this->assertSame('service', DB::table('crm_products')->where('id', $svc['crm'])->value('item_type'), 'the catalogue row keeps its type');
        $this->assertSame(0, $this->check()['errors']);
    }

    public function test_rows_of_another_organisation_that_carry_the_husks_id_are_never_touched(): void
    {
        [, $g, $s] = $this->pair();
        $mine = $this->pointAtMaster($g['master']);
        $theirs = $this->pointAtMaster($g['master'], self::T2);   // damage the checker would call I1T for organisation 2; this organisation's merge must not see it
        $theirDocs = $this->pointAtCrm($g['crm'], self::T2);

        $this->doMerge($g, $s);

        foreach ($mine as $key => $id) {
            [$table, $column] = explode('.', $key);
            $this->assertSame($s['master'], (int) DB::table($table)->where('id', $id)->value($column), "{$key}: mine moved");
        }
        foreach ($theirs as $key => $id) {
            [$table, $column] = explode('.', $key);
            $this->assertSame($g['master'], (int) DB::table($table)->where('id', $id)->value($column), "{$key}: theirs did not");
        }
        foreach ($theirDocs as $key => $id) {
            [$table, $column] = explode('.', $key);
            $this->assertSame($g['crm'], (int) DB::table($table)->where('id', $id)->value($column), "{$key}: their lines are untouched");
        }
    }

    // ------------------------------------------------------------------ claims

    public function test_claims_that_collide_keep_the_survivors_and_retire_the_duplicate_and_nothing_is_deleted(): void
    {
        [, $g, $s] = $this->pair();
        $survivorClaim = $this->claim($s['master'], 'Cuts paperwork by 60%', 'proposed');
        $duplicate = $this->claim($g['master'], 'cuts paperwork by 60%  ', 'approved');   // the same sentence once normalised: the same claim hash
        $other = $this->claim($g['master'], 'Used by 300 schools', 'approved');
        $theirs = $this->claim($g['master'], 'Cuts paperwork by 60%', 'approved', self::T2);   // another organisation's row holding the husk's id: not ours to touch
        $claimsBefore = DB::table('g2g_product_claims')->count();
        $this->assertSame(0, $this->check()['errors']);

        $result = $this->doMerge($g, $s);

        $this->assertSame(['moved' => 1, 'retired' => 1, 'retiredApproved' => 1], $result['claims']);
        $this->assertSame($claimsBefore, DB::table('g2g_product_claims')->count(), 'no claim was deleted');
        $kept = DB::table('g2g_product_claims')->where('id', $survivorClaim)->first();
        $this->assertSame([$s['master'], 'proposed'], [(int) $kept->product_master_id, $kept->status], 'the survivor\'s own claim is exactly as it was');
        $retired = DB::table('g2g_product_claims')->where('id', $duplicate)->first();
        $this->assertSame([$g['master'], 'retired'], [(int) $retired->product_master_id, $retired->status], 'the duplicate stays at the husk, retired');
        $moved = DB::table('g2g_product_claims')->where('id', $other)->first();
        $this->assertSame([$s['master'], 'approved'], [(int) $moved->product_master_id, $moved->status]);
        $this->assertSame($g['master'], (int) DB::table('g2g_product_claims')->where('id', $theirs)->value('product_master_id'), 'another organisation\'s claim is untouched');
        $this->assertSame(0, $this->check()['errors'], 'a retired claim at the husk is a valid pointer');
        $this->assertSame(1, $this->events[0]['payload']['claims']['retiredApproved'], 'the audit event says an approval was not carried over');
    }

    // ------------------------------------------------------------------ idempotence and "different"

    public function test_merging_a_pair_that_is_already_merged_writes_nothing(): void
    {
        [, $g, $s] = $this->pair();
        $this->pointAtMaster($g['master']);
        $this->doMerge($g, $s);
        $events = count($this->events);
        $before = $this->dumpEverything();

        $again = $this->doMerge($g, $s);

        $this->assertSame('already_merged', $again['result']);
        $this->assertSame($s['master'], $again['survivorMasterId']);
        $this->assertSame($before, $this->dumpEverything(), 'every row of every table is as it was');
        $this->assertCount($events, $this->events, 'and no second event');
    }

    public function test_an_item_that_was_merged_into_one_sub_product_cannot_be_merged_into_another(): void
    {
        [, $g, $s] = $this->pair();
        $this->doMerge($g, $s);
        $other = $this->subProduct('SCH-92', 'Another Sub-product');
        $before = $this->dumpEverything();

        $e = $this->refused(fn () => $this->doMerge($g, $other), 409);

        $this->assertStringContainsString('already merged into Sub-product ' . $s['master'], $e->getMessage());
        $this->assertSame($before, $this->dumpEverything());
    }

    public function test_different_writes_only_the_decision_row_and_the_pair_is_not_offered_again(): void
    {
        [, $g, $s] = $this->pair();
        $before = $this->dumpEverything();

        $result = $this->merger()->decideDifferent($this->admin, $g['master'], $s['master']);

        $this->assertSame('recorded', $result['result']);
        $after = $this->dumpEverything();
        unset($after['g2g_product_match_decisions'], $before['g2g_product_match_decisions'], $after['tbluser'], $before['tbluser']);
        $this->assertSame($before, $after, 'nothing else changed: the item, the Sub-product, the catalogue row, every pointer');
        $d = DB::table('g2g_product_match_decisions')->first();
        $this->assertSame([self::T, $g['master'], $s['master'], 'different', null], [(int) $d->sub_institute_id, (int) $d->item_master_id, (int) $d->survivor_master_id, $d->decision, $d->name_kept]);
        $this->assertEquals(98.0, (float) $d->score, 'the evidence is logged, so a person can see what was thought of the pair');
        $this->assertCount(1, $this->events);
        $this->assertSame('product.match_decided', $this->events[0]['type']);
        $this->assertNull($this->masterRow($s['master'])->crm_product_id);

        // a second answer is a no-op
        $this->assertSame('already_decided', $this->merger()->decideDifferent($this->admin, $g['master'], $s['master'])['result']);
        $this->assertSame(1, DB::table('g2g_product_match_decisions')->count());
        $this->assertCount(1, $this->events);

        // and the suggester no longer offers it
        $row = collect((new \App\Domain\ProductMaster\ProductMatchSuggester)->suggest(self::T)['items'])->firstWhere('itemMasterId', $g['master']);
        $this->assertNotSame($s['master'], $row['candidate']['masterId'] ?? 0);
    }

    public function test_a_person_may_change_their_mind_from_different_to_same_and_the_decision_row_is_the_same_row(): void
    {
        [, $g, $s] = $this->pair();
        $first = $this->merger()->decideDifferent($this->admin, $g['master'], $s['master']);

        $result = $this->doMerge($g, $s);

        $this->assertSame('merged', $result['result']);
        $this->assertSame($first['decisionId'], $result['decisionId']);
        $this->assertSame(1, DB::table('g2g_product_match_decisions')->count());
        $this->assertSame('same', DB::table('g2g_product_match_decisions')->value('decision'));
    }

    public function test_a_merged_pair_cannot_be_called_different(): void
    {
        [, $g, $s] = $this->pair();
        $this->doMerge($g, $s);
        $before = $this->dumpEverything();

        $e = $this->refused(fn () => $this->merger()->decideDifferent($this->admin, $g['master'], $s['master']), 409);

        $this->assertStringContainsString('already merged', $e->getMessage());
        $this->assertSame($before, $this->dumpEverything());
    }

    // ------------------------------------------------------------------ every refusal writes nothing

    /** @param \Closure(): mixed $do */
    private function refused(\Closure $do, int $status): ProductMasterInvalid
    {
        try {
            $do();
        } catch (ProductMasterInvalid $e) {
            $this->assertSame($status, $e->status, $e->getMessage());

            return $e;
        }
        $this->fail("expected a refusal with status {$status}");
    }

    public function test_a_pair_that_is_not_one_of_the_organisations_is_not_found_and_a_pair_that_is_not_a_pair_is_refused_and_nothing_is_written(): void
    {
        [$w, $g, $s] = $this->pair();
        $theirSub = $this->subProduct('SCH-01', 'Scholar School ERP', [], self::T2);
        $theirItem = $this->item('Their item', [], self::T2);
        $other = $this->subProduct('SCH-93', 'Another');
        $before = $this->dumpEverything();
        $id = $this->admin;
        $m = $this->merger();

        // not found: another organisation's rows, a shared row, a row that does not exist
        $this->refused(fn () => $m->merge($id, $theirItem['master'], $s['master']), 404);
        $this->refused(fn () => $m->merge($id, $g['master'], $theirSub['master']), 404);
        $this->refused(fn () => $m->merge($id, $g['master'], $w['product']), 404);
        $this->refused(fn () => $m->merge($id, $w['product'], $s['master']), 404);
        $this->refused(fn () => $m->merge($id, $g['master'], 987654), 404);
        $this->refused(fn () => $m->decideDifferent($id, $theirItem['master'], $s['master']), 404);
        $this->refused(fn () => $m->decideDifferent($id, $g['master'], $theirSub['master']), 404);
        // not a pair: an item is expected first and a Sub-product second, for "same" and for "different" alike
        $this->assertSame('That is not a catalogue item.', $this->refused(fn () => $m->merge($id, $s['master'], $other['master']), 422)->getMessage());
        $this->assertSame('That is not a Sub-product.', $this->refused(fn () => $m->merge($id, $g['master'], $w['items']['core']['master']), 422)->getMessage());
        $this->assertSame('That is not a catalogue item.', $this->refused(fn () => $m->decideDifferent($id, $s['master'], $other['master']), 422)->getMessage());
        $this->assertSame('That is not a Sub-product.', $this->refused(fn () => $m->decideDifferent($id, $g['master'], $w['items']['core']['master']), 422)->getMessage());
        $this->refused(fn () => $m->merge($id, $g['master'], $g['master']), 422);
        $this->refused(fn () => $m->merge($id, 0, $s['master']), 422);
        $this->refused(fn () => $m->merge($id, $g['master'], $s['master'], 'both'), 422);
        $this->refused(fn () => $m->merge(['sub_institute_id' => 0], $g['master'], $s['master']), 422);

        $this->assertSame($before, $this->dumpEverything(), 'not one row changed');
        $this->assertSame([], $this->events);
    }

    public function test_an_item_or_a_sub_product_that_cannot_be_merged_is_refused_with_the_reason(): void
    {
        [$w, $g, $s] = $this->pair();
        $id = $this->admin;
        $m = $this->merger();

        // the Sub-product already holds a catalogue item
        $sold = $this->subProduct('SCH-94', 'Sold already');
        DB::table('g2g_product_master')->where('id', $w['items']['core']['master'])->update(['crm_product_id' => null, 'status' => 'archived']);
        DB::table('g2g_product_master')->where('id', $sold['master'])->update(['crm_product_id' => $w['items']['core']['crm']]);
        $e = $this->refused(fn () => $m->merge($id, $g['master'], $sold['master']), 409);
        $this->assertStringContainsString('already sold through catalogue item ' . $w['items']['core']['crm'], $e->getMessage());

        // the item is in the Recycle Bin / archived / has no catalogue row; the Sub-product is archived / has no offer / has no Product
        DB::table('crm_products')->where('id', $w['items']['fees']['crm'])->update(['deleted_at' => now()]);
        $this->assertStringContainsString('Recycle Bin', $this->refused(fn () => $m->merge($id, $w['items']['fees']['master'], $s['master']), 422)->getMessage());
        DB::table('g2g_product_master')->where('id', $w['items']['lms']['master'])->update(['status' => 'archived']);
        $this->assertStringContainsString('archived', $this->refused(fn () => $m->merge($id, $w['items']['lms']['master'], $s['master']), 422)->getMessage());
        DB::table('g2g_product_master')->where('id', $w['items']['exam']['master'])->update(['crm_product_id' => null]);
        $this->assertStringContainsString('no catalogue row', $this->refused(fn () => $m->merge($id, $w['items']['exam']['master'], $s['master']), 422)->getMessage());
        $archived = $this->subProduct('SCH-95', 'Archived one');
        DB::table('g2g_product_master')->where('id', $archived['master'])->update(['status' => 'archived']);
        $this->assertStringContainsString('archived', $this->refused(fn () => $m->merge($id, $g['master'], $archived['master']), 422)->getMessage());
        $noOffer = $this->subProduct('SCH-96', 'No offer');
        DB::table('g2g_product_master')->where('id', $noOffer['master'])->update(['offer_row_id' => null]);
        $this->assertStringContainsString('no Portfolio offer', $this->refused(fn () => $m->merge($id, $g['master'], $noOffer['master']), 422)->getMessage());
        $noProduct = $this->subProduct('SCH-97', 'No product');
        DB::table('g2g_product_master')->where('id', $noProduct['master'])->update(['parent_id' => null]);
        $this->assertStringContainsString('no Product', $this->refused(fn () => $m->merge($id, $g['master'], $noProduct['master']), 422)->getMessage());

        // nothing merges across Products, and an item whose two product pointers disagree is not merged until the checker is satisfied
        $eb = $this->subProduct('EB-90', 'Brain thing', ['parent_product' => 'EB']);
        $e = $this->refused(fn () => $m->merge($id, $g['master'], $eb['master']), 422);
        $this->assertStringContainsString('different products', $e->getMessage());
        DB::table('crm_products')->where('id', $w['items']['attendance']['crm'])->update(['product_master_id' => $w['other']]);
        $this->assertStringContainsString('two product pointers disagree', $this->refused(fn () => $m->merge($id, $w['items']['attendance']['master'], $s['master']), 409)->getMessage());

        $this->assertNull($this->masterRow($s['master'])->crm_product_id, 'the Sub-product was never given a catalogue row');
        $this->assertSame(0, DB::table('g2g_product_match_decisions')->count());
        $this->assertSame([], $this->events);
    }

    public function test_only_an_administrator_can_merge_or_decide_and_the_command_has_no_person(): void
    {
        [, $g, $s] = $this->pair();
        $employee = (int) DB::table('tbluser')->insertGetId(['user_name' => 'emp', 'sub_institute_id' => self::T, 'user_profile_id' => 2]);
        $identity = ['user' => DB::table('tbluser')->where('id', $employee)->first(), 'user_id' => $employee, 'sub_institute_id' => self::T];
        $before = $this->dumpEverything();

        $this->refused(fn () => $this->merger()->merge($identity, $g['master'], $s['master']), 403);
        $this->refused(fn () => $this->merger()->decideDifferent($identity, $g['master'], $s['master']), 403);
        $this->assertSame($before, $this->dumpEverything());

        // the command applies the owner's map: no person, and the actor of the rows is empty
        $result = $this->merger()->merge(['sub_institute_id' => self::T], $g['master'], $s['master']);
        $this->assertSame('merged', $result['result']);
        $this->assertNull($this->masterRow($s['master'])->updated_by);
        $this->assertNull(DB::table('g2g_product_match_decisions')->value('decided_by'));
        $this->assertNull($this->events[0]['actor']);
        $this->assertSame('command', $this->events[0]['payload']['via']);
    }

    // ------------------------------------------------------------------ the checker and the census, before and after

    public function test_a_merge_is_refused_when_the_checker_already_finds_an_error_and_nothing_is_written(): void
    {
        [, $g, $s] = $this->pair();
        DB::table('crm_leads')->insert(['product_master_id' => 987654, 'sub_institute_id' => self::T, 'updated_at' => self::STAMP]);   // a lead that points at a product that is not there
        $this->assertGreaterThan(0, $this->check()['errors']);
        $before = $this->dumpEverything();

        $e = $this->refused(fn () => $this->doMerge($g, $s), 409);

        $this->assertStringContainsString('so nothing was merged', $e->getMessage());
        $this->assertArrayHasKey('integrity', $e->errors);
        $this->assertSame($before, $this->dumpEverything());
        $this->assertSame([], $this->events);
    }

    public function test_the_merge_is_taken_back_when_the_checker_finds_an_error_after_it(): void
    {
        [, $g, $s] = $this->pair();
        $this->pointAtMaster($g['master']);
        $before = $this->dumpEverything();
        // After the decision row is written, someone (here: the test) renames the catalogue row, so its name disagrees with the master row and the offer: invariant I5.
        $done = false;
        DB::listen(function ($q) use (&$done, $g) {
            if (! $done && str_starts_with($q->sql, 'insert into "g2g_product_match_decisions"')) {
                $done = true;
                DB::table('crm_products')->where('id', $g['crm'])->update(['name' => 'Tampered']);
            }
        });

        $e = $this->refused(fn () => $this->doMerge($g, $s), 409);

        $this->assertTrue($done, 'the tamper ran inside the merge');
        $this->assertStringContainsString('The merge was taken back: the product checker found', $e->getMessage());
        $this->assertSame($before, $this->dumpEverything(), 'every write of the merge, and the tamper with it, is gone');
        $this->assertSame([], $this->events, 'no audit event for a merge that did not happen');
        $this->assertNull($this->masterRow($s['master'])->crm_product_id);
    }

    public function test_the_merge_is_taken_back_when_a_line_count_or_sum_would_change(): void
    {
        [, $g, $s] = $this->pair();
        $refs = $this->pointAtCrm($g['crm']);
        $before = $this->dumpEverything();
        $done = false;
        DB::listen(function ($q) use (&$done, $refs) {
            if (! $done && str_starts_with($q->sql, 'insert into "g2g_product_match_decisions"')) {
                $done = true;
                DB::table('crm_quote_line_items')->where('id', $refs['crm_quote_line_items.product_id'])->update(['line_total' => 999.99]);
            }
        });

        $e = $this->refused(fn () => $this->doMerge($g, $s), 409);

        $this->assertTrue($done);
        $this->assertStringContainsString('a count or a sum', $e->getMessage());
        $this->assertStringContainsString('crm_quote_line_items', $e->getMessage());
        $this->assertSame($before, $this->dumpEverything());
        $this->assertSame([], $this->events);
    }

    /** @return array<string, array{0: string}> the statement after which a failure is forced, by step */
    public static function steps(): array
    {
        return [
            'the husk is freed' => ['/^update "g2g_product_master" set "status" = \?, "origin" = \?/'],
            'the survivor takes the catalogue row' => ['/^update "g2g_product_master" set "crm_product_id" = \?, "updated_at" = \?/'],
            'the catalogue row is renamed (the mirror)' => ['/^update "crm_products" set "name" = \?/'],
            'the first pointer moves' => ['/^update "crm_leads" set "product_master_id" = \?/'],
            'the claims move' => ['/^update "g2g_product_claims" set "product_master_id" = \?/'],
            'the last pointer moves' => ['/^update "gtm_playbooks" set "product_master_id" = \?/'],
            'the decision is written' => ['/^insert into "g2g_product_match_decisions"/'],
        ];
    }

    #[DataProvider('steps')]
    public function test_a_failure_at_any_step_takes_every_earlier_step_back(string $pattern): void
    {
        [, $g, $s] = $this->pair();
        $this->pointAtCrm($g['crm']);
        $this->pointAtMaster($g['master']);
        $before = $this->dumpEverything();
        $this->failAfter($pattern);

        try {
            $this->doMerge($g, $s);
            $this->fail('the forced failure did not reach the merge');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('forced failure after: ', $e->getMessage());
        }

        $this->assertSame($before, $this->dumpEverything(), 'every row of every table is as it was: the merge is all or nothing');
        $this->assertSame([], $this->events);

        // and the world is intact: the same merge, run again, works
        $this->assertSame('merged', $this->doMerge($g, $s)['result']);
        $this->assertSame(0, $this->check()['errors']);
    }

    // ------------------------------------------------------------------ the guarded pointer, the plan, the audit trail

    public function test_a_name_to_keep_that_is_empty_is_refused_with_its_own_reason_and_nothing_is_written(): void
    {
        [, $g, $s] = $this->pair();
        DB::table('crm_products')->where('id', $g['crm'])->update(['name' => '   ']);
        $before = $this->dumpEverything();

        $e = $this->refused(fn () => $this->doMerge($g, $s, 'catalogue'), 422);

        $this->assertStringContainsString('The name to keep is empty', $e->getMessage());
        $this->assertSame($before, $this->dumpEverything());
        $this->assertSame([], $this->events);
    }

    public function test_the_audit_event_is_written_through_the_real_event_recorder_and_a_repeated_key_would_be_lost(): void
    {
        // Every other test of this file uses a recorder that remembers; this one uses the real one, on the real table, so a mistake in the call itself shows.
        (require base_path('database/migrations/2026_08_10_140000_create_g2g_event_store.php'))->up();
        $this->app->instance(EventRecorder::class, new EventRecorder);
        [, $g, $s] = $this->pair();
        $this->pointAtMaster($g['master']);

        $result = $this->doMerge($g, $s);

        $this->assertSame('recorded', $result['audit']);
        $rows = DB::table('g2g_event')->get();
        $this->assertCount(1, $rows);
        $e = $rows[0];
        $this->assertSame(['product.merged', self::T, 'g2g_product_master', $s['master'], $this->admin['user_id']], [$e->type, (int) $e->sub_institute_id, $e->entity_type, (int) $e->entity_id, (int) $e->actor_id]);
        $payload = json_decode((string) $e->payload, true);
        $this->assertSame([$g['master'], $s['master'], $g['crm'], 'portfolio', 'Scholar School ERP', 'screen'], [$payload['itemMasterId'], $payload['survivorMasterId'], $payload['crmProductId'], $payload['nameKept'], $payload['name'], $payload['via']]);
        $this->assertSame(9, count($payload['moved']));
        $this->assertStringStartsWith('product.merged:' . self::T . ':' . $g['master'] . ':' . $s['master'] . ':', (string) $e->idempotency_key);

        // The store refuses a second event with the same key for the same organisation. That is why every key carries a unique stamp: a pair merged, undone and merged
        // again must not lose its second event inside the try/catch.
        $again = $this->merger()->emit(ProductMerger::EVENT_MERGED, self::T, null, $s['master'], ['x' => 1], (string) $e->idempotency_key);
        $this->assertStringStartsWith('failed:', $again);
        $this->assertSame(1, DB::table('g2g_event')->count());
        $this->assertSame('recorded', $this->merger()->emit(ProductMerger::EVENT_UNDONE, self::T, null, $s['master'], ['x' => 1], 'product.merge_undone:fresh-key'));
        $this->assertSame(2, DB::table('g2g_event')->count());
    }

    public function test_the_merge_moves_the_nine_pointers_and_leaves_the_one_that_names_the_product(): void
    {
        $registry = array_map(fn (array $e): string => $e['table'] . '.' . $e['column'], ProductPointers::toMaster());
        $moved = ProductMerger::repointed();

        $this->assertCount(10, $registry);
        $this->assertCount(9, $moved);
        $this->assertSame(array_values(array_diff($registry, ['crm_products.product_master_id'])), $moved);
        $this->assertArrayHasKey('crm_products.product_master_id', ProductMerger::leftAlone());
        $this->assertSame(['g2g_product_claims.product_master_id' => ['sub_institute_id', 'claim_hash']], ProductMerger::uniqueKeys());
    }

    public function test_the_plan_lists_every_row_the_merge_touches_without_writing_and_never_the_product_pointer(): void
    {
        [, $g, $s] = $this->pair();
        $this->pointAtCrm($g['crm']);
        $refs = $this->pointAtMaster($g['master']);
        // A catalogue row whose Product pointer holds the husk's id is damage; the merge must not see it as one of its pointers (and the checker, not the merge, reports it).
        $damaged = $this->crm(self::T, 'Damaged row', $g['master']);
        $before = $this->dumpEverything();
        $statements = [];
        DB::listen(function ($q) use (&$statements) {
            $statements[] = $q->sql;
        });

        $plan = $this->merger()->plan(self::T, $g['master'], $s['master']);

        $this->assertSame($before, $this->dumpEverything());
        foreach ($statements as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)\b/i', $sql);
        }
        $this->assertSame([$g['master'], $s['master']], $plan['touched']['g2g_product_master']);
        $this->assertSame([$g['crm']], $plan['touched']['crm_products'], 'the item\'s own catalogue row, not the damaged row that carries a product pointer');
        $this->assertNotContains($damaged, $plan['touched']['crm_products']);
        $this->assertSame([$s['offer']], $plan['touched']['g2g_product_offers']);
        foreach ($refs as $key => $id) {
            [$table] = explode('.', $key);
            $this->assertSame([$id], $plan['touched'][$table], $key);
        }
        $this->assertSame('Scholar School ERP', $plan['name']);
        $this->assertFalse($plan['alreadyMerged']);
        $this->assertNull($plan['decisionId']);

        // the plan refuses what the merge refuses
        $this->refused(fn () => $this->merger()->plan(self::T2, $g['master'], $s['master']), 404);
        $this->refused(fn () => $this->merger()->plan(self::T, $g['master'], $s['master'], 'neither'), 422);
        // the damage is repaired by a person; only then is the merge allowed (the checker would refuse it with that row there)
        $this->refused(fn () => $this->doMerge($g, $s), 409);
        DB::table('crm_products')->where('id', $damaged)->delete();
        $this->doMerge($g, $s);
        $this->assertTrue($this->merger()->plan(self::T, $g['master'], $s['master'])['alreadyMerged']);
    }

    public function test_a_merge_that_cannot_record_its_audit_event_is_still_done_and_says_so(): void
    {
        [, $g, $s] = $this->pair();
        $this->app->instance(EventRecorder::class, new class extends EventRecorder {
            public function record(string $type, int $subInstituteId, string $entityType, ?int $entityId, ?int $actorId, array $payload, ?array $metadata = null, ?string $idempotencyKey = null, ?string $correlationId = null, ?string $causationId = null, ?int $actingForId = null, ?string $occurredAt = null): int
            {
                throw new \RuntimeException('the event store is down');
            }
        });

        $result = $this->doMerge($g, $s);

        $this->assertSame('merged', $result['result']);
        $this->assertSame('failed: the event store is down', $result['audit']);
        $this->assertSame($s['master'], (int) DB::table('g2g_product_master')->where('crm_product_id', $g['crm'])->value('id'), 'the merge is a fact: it is not undone for want of a log line');
    }

    public function test_every_merge_of_the_same_pair_gets_its_own_event_key_so_a_merge_after_an_undo_is_not_lost(): void
    {
        [, $g, $s] = $this->pair();
        // The unique index on g2g_event.idempotency_key would silently drop the second event of a pair that was merged, undone and merged again, if the key named only the pair.
        $stamp = new \ReflectionMethod(ProductMerger::class, 'stamp');
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[$stamp->invoke($this->merger())] = true;
        }
        $this->assertCount(200, $seen, '200 stamps, 200 different values');

        $this->doMerge($g, $s);
        $key = (string) $this->events[0]['key'];
        $this->assertMatchesRegularExpression('/^product\.merged:' . self::T . ':' . $g['master'] . ':' . $s['master'] . ':\d{12,}[0-9a-f]{4}$/', $key);
        $this->assertLessThanOrEqual(120, strlen($key), 'it fits the idempotency column');

        $this->assertSame('recorded', $this->merger()->emit(ProductMerger::EVENT_UNDONE, self::T, null, $s['master'], ['x' => 1], str_repeat('k', 300)));
        $this->assertSame(120, strlen((string) $this->events[1]['key']), 'a longer key is cut, not refused');
    }
}
