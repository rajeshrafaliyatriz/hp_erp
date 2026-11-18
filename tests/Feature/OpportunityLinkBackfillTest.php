<?php

namespace Tests\Feature;

use App\Domain\Crm\Sales\OpportunityLinkBackfill;
use App\Domain\Crm\Sales\OpportunityLinkBackfillBlocked;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\Support\BuildsPipelineWorld;
use Tests\TestCase;

/**
 * opportunities:backfill-links (slice 11, task 5): the product and the coded loss reason of opportunities made before those columns existed. The rules of every
 * data update (DB-RULES.md): a dry run writes nothing, --apply writes only into empty columns of the organisation's own opportunities, a second run changes
 * nothing, a filled value is never overwritten, and another organisation's rows are never read into a link. The loss-reason mapping is an exact match only.
 */
class OpportunityLinkBackfillTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;
    use BuildsPipelineWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPipelineWorld();
    }

    private function item(int $tenant, string $name): int
    {
        return (int) DB::table('crm_products')->insertGetId(['item_type' => 'product', 'product_no' => 'P-' . uniqid(), 'name' => $name, 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function tag(int $opportunity, int $item): void
    {
        DB::table('crm_opportunity_products')->insert(['opportunity_id' => $opportunity, 'product_id' => $item, 'quantity' => 1, 'created_at' => now()]);
    }

    /** @return array<string, array{product: ?int, reason: ?string, updated_at: mixed}> */
    private function links(): array
    {
        return DB::table('crm_opportunities')->orderBy('id')->get()->mapWithKeys(fn ($o) => [(int) $o->id => ['product' => $o->product_master_id === null ? null : (int) $o->product_master_id,
            'reason' => $o->loss_reason_code, 'updated_at' => $o->updated_at]])->all();
    }

    /** One opportunity of each kind the command meets, in organisation 1, and the other organisation's. @return array<string, int> */
    private function world(): array
    {
        $scholar = $this->makeProduct(0, 'scholarclone', 'ScholarClone', ['signal_key' => 'scholar']);
        $own = $this->makeProduct(self::T, 'own_product', 'Own Product');
        $second = $this->makeProduct(self::T, 'second_product', 'Second Product');
        $itemA = $this->item(self::T, 'Item A');
        $itemB = $this->item(self::T, 'Item B');
        $masterA = $this->makeProduct(self::T, 'item_a', 'Item A', ['crm_product_id' => $itemA, 'kind' => 'item']);
        $masterB = $this->makeProduct(self::T, 'item_b', 'Item B', ['crm_product_id' => $itemB, 'kind' => 'item']);
        $theirItem = $this->item(self::T2, 'Their item');
        $theirMaster = $this->makeProduct(self::T2, 'their_item', 'Their item', ['crm_product_id' => $theirItem, 'kind' => 'item']);
        $signal = $this->makeSignal(self::T, ['business_fit' => 'scholar']);
        $noFitSignal = $this->makeSignal(self::T, ['business_fit' => 'multiple']);

        $o = [];
        $o['byTags'] = $this->makeOpportunity(self::T, ['name' => 'byTags']);
        $this->tag($o['byTags'], $itemA);
        $o['ambiguous'] = $this->makeOpportunity(self::T, ['name' => 'ambiguous']);
        $this->tag($o['ambiguous'], $itemA);
        $this->tag($o['ambiguous'], $itemB);
        $o['sameTwice'] = $this->makeOpportunity(self::T, ['name' => 'sameTwice']);   // two tags, one product: not ambiguous
        $this->tag($o['sameTwice'], $itemB);
        $this->tag($o['sameTwice'], $itemB);
        $o['foreignTag'] = $this->makeOpportunity(self::T, ['name' => 'foreignTag']);   // planted: a tag on another organisation's catalogue item
        $this->tag($o['foreignTag'], $theirItem);
        // planted: a tag on another organisation's catalogue item whose Product Master row is a SHARED one (so the visibility of the master alone would let it through)
        $sharedOverTheirs = $this->makeProduct(0, 'shared_over_theirs', 'Shared over theirs', ['crm_product_id' => $theirItem, 'kind' => 'item']);
        $o['foreignSharedTag'] = $this->makeOpportunity(self::T, ['name' => 'foreignSharedTag']);
        $this->tag($o['foreignSharedTag'], $theirItem);
        $o['byLead'] = $this->makeOpportunity(self::T, ['name' => 'byLead']);
        $this->makeLead(self::T, ['converted' => 1, 'converted_opportunity_id' => $o['byLead'], 'product_master_id' => $own]);
        $o['bySignal'] = $this->makeOpportunity(self::T, ['name' => 'bySignal']);
        $this->makeLead(self::T, ['converted' => 1, 'converted_opportunity_id' => $o['bySignal'], 'source_company_opportunity_id' => $signal->id]);
        $o['signalNoFit'] = $this->makeOpportunity(self::T, ['name' => 'signalNoFit']);
        $this->makeLead(self::T, ['converted' => 1, 'converted_opportunity_id' => $o['signalNoFit'], 'source_company_opportunity_id' => $noFitSignal->id]);
        $o['leadForeignProduct'] = $this->makeOpportunity(self::T, ['name' => 'leadForeignProduct']);   // planted: the lead points at another organisation's product
        $this->makeLead(self::T, ['converted' => 1, 'converted_opportunity_id' => $o['leadForeignProduct'], 'product_master_id' => $theirMaster]);
        $o['filled'] = $this->makeOpportunity(self::T, ['name' => 'filled', 'product_master_id' => $second]);   // already has a product; a lead and a tag would say otherwise
        $this->tag($o['filled'], $itemA);
        $this->makeLead(self::T, ['converted' => 1, 'converted_opportunity_id' => $o['filled'], 'product_master_id' => $own]);
        $o['nothing'] = $this->makeOpportunity(self::T, ['name' => 'nothing']);
        $o['deleted'] = $this->makeOpportunity(self::T, ['name' => 'deleted', 'deleted_at' => now()]);
        $this->tag($o['deleted'], $itemA);

        $lost = fn (string $name, ?string $text, array $over = []) => $this->makeOpportunity(self::T, ['name' => $name, 'sales_stage' => 'Closed Lost', 'close_reason' => $text] + $over);
        $o['lostLabel'] = $lost('lostLabel', 'Price too high');
        $o['lostKey'] = $lost('lostKey', 'no_budget');
        $o['lostMessy'] = $lost('lostMessy', "  CHOSE   a Competitor \n");
        $o['lostSentence'] = $lost('lostSentence', 'They said it was too expensive');
        $o['lostNearMiss'] = $lost('lostNearMiss', 'Price');   // "price" IS a code; the case does not matter
        $o['lostNothingWritten'] = $lost('lostNothingWritten', null);
        $o['lostCoded'] = $lost('lostCoded', 'Price too high', ['loss_reason_code' => 'timing']);
        $o['openWithText'] = $this->makeOpportunity(self::T, ['name' => 'openWithText', 'sales_stage' => 'Prospecting', 'close_reason' => 'Price too high']);

        $o['theirs'] = $this->makeOpportunity(self::T2, ['name' => 'theirs', 'sales_stage' => 'Closed Lost', 'close_reason' => 'Price too high']);
        $this->tag($o['theirs'], $theirItem);

        return $o + ['own' => $own, 'second' => $second, 'scholar' => $scholar, 'masterA' => $masterA, 'masterB' => $masterB, 'theirMaster' => $theirMaster];
    }

    public function test_a_dry_run_says_what_it_would_write_and_writes_nothing(): void
    {
        $this->world();
        $before = $this->links();

        $r = (new OpportunityLinkBackfill)->run(self::T, false);

        $this->assertSame($before, $this->links());
        $this->assertSame(0, $r['written']);
        // counts for organisation 1: 19 live opportunities (the deleted one is not read)
        $this->assertSame(['opportunities' => 19, 'product_filled' => 1, 'product_to_set' => 4, 'product_by_tags' => 2, 'product_by_lead' => 1, 'product_by_signal' => 1,
            'product_ambiguous' => 1, 'product_unresolved' => 13, 'lost' => 7], array_intersect_key($r, array_flip(['opportunities', 'product_filled', 'product_to_set', 'product_by_tags',
                'product_by_lead', 'product_by_signal', 'product_ambiguous', 'product_unresolved', 'lost'])));
        $this->assertEquals(['reason_to_set' => 4, 'reason_filled' => 1, 'reason_unmatched' => 1, 'reason_none' => 1], array_intersect_key($r, array_flip(['reason_to_set', 'reason_filled', 'reason_unmatched', 'reason_none'])));
    }

    public function test_apply_sets_each_product_by_the_documented_order_and_leaves_the_rest(): void
    {
        $o = $this->world();

        $r = (new OpportunityLinkBackfill)->run(self::T, true);

        $links = $this->links();
        $this->assertSame($o['masterA'], $links[$o['byTags']]['product'], 'its own tag');
        $this->assertSame($o['masterB'], $links[$o['sameTwice']]['product'], 'two tags that lead to the same product are not ambiguous');
        $this->assertSame($o['own'], $links[$o['byLead']]['product'], 'the converted lead\'s product');
        $this->assertSame($o['scholar'], $links[$o['bySignal']]['product'], "the lead's signal, through the resolver (a shared product)");
        $this->assertNull($links[$o['ambiguous']]['product'], 'two different products: a person chooses');
        $this->assertNull($links[$o['foreignTag']]['product'], "another organisation's catalogue item is not a product of this one");
        $this->assertNull($links[$o['foreignSharedTag']]['product'], 'not even through a shared Product Master row that points at it');
        $this->assertNull($links[$o['leadForeignProduct']]['product'], "a lead pointing at another organisation's product gives nothing");
        $this->assertNull($links[$o['signalNoFit']]['product'], 'a signal the resolver cannot place gives nothing');
        $this->assertNull($links[$o['nothing']]['product']);
        $this->assertNull($links[$o['deleted']]['product'], 'a deleted opportunity is not touched');
        $this->assertSame($o['second'], $links[$o['filled']]['product'], 'a product that is there is never replaced');
        $this->assertNull($links[$o['theirs']]['product'], "organisation 2's opportunity is not read (its own tag would give it a product)");
        $this->assertSame(4 + 4, $r['written'], 'four products and four reasons');
    }

    public function test_apply_maps_a_loss_reason_only_on_an_exact_documented_match(): void
    {
        $o = $this->world();

        (new OpportunityLinkBackfill)->run(self::T, true);

        $links = $this->links();
        $this->assertSame('price', $links[$o['lostLabel']]['reason'], 'the label');
        $this->assertSame('no_budget', $links[$o['lostKey']]['reason'], 'the key');
        $this->assertSame('competitor', $links[$o['lostMessy']]['reason'], 'case, spaces and line breaks do not matter');
        $this->assertSame('price', $links[$o['lostNearMiss']]['reason'], 'the code "price" itself');
        $this->assertNull($links[$o['lostSentence']]['reason'], 'a sentence is not guessed at: it stays free text');
        $this->assertNull($links[$o['lostNothingWritten']]['reason']);
        $this->assertSame('timing', $links[$o['lostCoded']]['reason'], 'a code that is there is never replaced, whatever the text says');
        $this->assertNull($links[$o['openWithText']]['reason'], 'only a lost deal has a loss reason');
        $this->assertNull($links[$o['theirs']]['reason'], "organisation 2's lost deal is not read");
        $this->assertSame('They said it was too expensive', DB::table('crm_opportunities')->find($o['lostSentence'])->close_reason, 'and the free text is never edited');
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->world();
        (new OpportunityLinkBackfill)->run(self::T, true);
        $afterFirst = $this->links();

        $r = (new OpportunityLinkBackfill)->run(self::T, true);

        $this->assertSame($afterFirst, $this->links());
        $this->assertSame(0, $r['written']);
        $this->assertSame(0, $r['product_to_set'] + $r['reason_to_set']);
    }

    public function test_it_never_touches_updated_at_or_the_history(): void
    {
        $o = $this->world();
        DB::table('crm_opportunities')->update(['updated_at' => '2026-01-02 03:04:05']);
        $historyBefore = DB::table('crm_opportunity_stage_history')->count();

        (new OpportunityLinkBackfill)->run(self::T, true);

        $this->assertSame(['2026-01-02 03:04:05'], array_values(array_unique(array_column($this->links(), 'updated_at'))), 'this is not an edit by a person');
        $this->assertSame($historyBefore, DB::table('crm_opportunity_stage_history')->count(), 'and nothing about the deal changed');
        $this->assertNotNull($o);
    }

    public function test_a_value_that_appears_after_the_plan_was_read_is_not_overwritten(): void
    {
        $o = $this->world();
        $backfill = new OpportunityLinkBackfill;
        [$counts, $plan] = $backfill->plan(self::T);
        $this->assertSame($o['masterA'], $plan[$o['byTags']]['product_master_id']);
        $this->assertSame('price', $plan[$o['lostLabel']]['loss_reason_code']);

        // A person fills both in between the plan and the write.
        DB::table('crm_opportunities')->where('id', $o['byTags'])->update(['product_master_id' => $o['second']]);
        DB::table('crm_opportunities')->where('id', $o['lostLabel'])->update(['loss_reason_code' => 'timing']);
        $written = $backfill->write(self::T, $plan);

        $links = $this->links();
        $this->assertSame($o['second'], $links[$o['byTags']]['product'], "the person's choice stands");
        $this->assertSame('timing', $links[$o['lostLabel']]['reason']);
        $this->assertSame(8 - 2, $written, 'everything else was written');
        $this->assertGreaterThan(0, $counts['opportunities']);
    }

    public function test_a_plan_cannot_write_another_organisations_rows(): void
    {
        $o = $this->world();
        $backfill = new OpportunityLinkBackfill;

        $written = $backfill->write(self::T, [$o['theirs'] => ['loss_reason_code' => 'price', 'product_master_id' => $o['own']]]);

        $this->assertSame(0, $written);
        $this->assertNull($this->links()[$o['theirs']]['reason']);
        $this->assertNull($this->links()[$o['theirs']]['product']);
    }

    public function test_a_label_two_codes_share_maps_to_neither(): void
    {
        config(['crm_sales.loss_reasons' => ['price' => 'Too much', 'cost' => 'Too much', 'timing' => 'Timing']]);
        $shared = $this->makeOpportunity(self::T, ['sales_stage' => 'Closed Lost', 'close_reason' => 'Too much']);
        $clear = $this->makeOpportunity(self::T, ['sales_stage' => 'Closed Lost', 'close_reason' => 'timing']);

        (new OpportunityLinkBackfill)->run(self::T, true);

        $this->assertNull($this->links()[$shared]['reason'], 'ambiguous text is not guessed');
        $this->assertSame('timing', $this->links()[$clear]['reason']);
    }

    public function test_the_command_dry_runs_by_default_applies_on_request_and_repeats_to_nothing(): void
    {
        $o = $this->world();
        $before = $this->links();

        $code = Artisan::call('opportunities:backfill-links', ['--tenant' => self::T]);
        $dry = Artisan::output();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('DRY RUN', $dry);
        $this->assertMatchesRegularExpression('/product to set\s+4\b/', $dry);
        $this->assertMatchesRegularExpression('/coded loss reason to set\s+4\b/', $dry);
        $this->assertSame($before, $this->links());

        Artisan::call('opportunities:backfill-links', ['--tenant' => self::T, '--apply' => true]);
        $applied = Artisan::output();
        $this->assertMatchesRegularExpression('/columns written\s+8\b/', $applied);
        $this->assertSame($o['masterA'], $this->links()[$o['byTags']]['product']);

        $afterFirst = $this->links();
        Artisan::call('opportunities:backfill-links', ['--tenant' => self::T, '--apply' => true]);
        $this->assertMatchesRegularExpression('/columns written\s+0\b/', Artisan::output());
        $this->assertSame($afterFirst, $this->links());
    }

    public function test_the_command_keeps_organisations_apart_and_checks_its_options(): void
    {
        $o = $this->world();

        Artisan::call('opportunities:backfill-links', ['--tenant' => self::T2, '--apply' => true]);

        $links = $this->links();
        $this->assertSame('price', $links[$o['theirs']]['reason'], 'organisation 2 is repaired when it is asked for');
        $this->assertNull($links[$o['lostLabel']]['reason'], 'and organisation 1 is not touched by that');
        $this->assertNull($links[$o['byTags']]['product']);

        $this->assertSame(2, Artisan::call('opportunities:backfill-links'), 'neither --tenant nor --all');
        $this->assertSame(2, Artisan::call('opportunities:backfill-links', ['--tenant' => self::T, '--all' => true]));
        $this->assertSame(2, Artisan::call('opportunities:backfill-links', ['--tenant' => '0']));
        $this->assertSame(2, Artisan::call('opportunities:backfill-links', ['--tenant' => self::T, '--database' => 'no_such_connection']));
    }

    public function test_it_says_so_when_the_columns_are_not_on_this_connection(): void
    {
        (require base_path('database/migrations/2026_12_11_130000_add_product_and_loss_reason_to_crm_opportunities.php'))->down();

        $this->expectException(OpportunityLinkBackfillBlocked::class);
        (new OpportunityLinkBackfill)->run(self::T, false);
    }
}
