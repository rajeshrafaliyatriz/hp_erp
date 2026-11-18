<?php

namespace Tests\Feature;

use App\Domain\Crm\Sales\GtmResync;
use App\Domain\Crm\Sales\OpportunityGtmSyncBridge;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\Support\BuildsPipelineWorld;
use Tests\Support\ThrowingGtmBridge;
use Tests\TestCase;

/**
 * opportunities:resync-gtm (defect D17, slice 11): the repair for the pairs a failed sync left out of step. The rules of every data update (DB-RULES.md): a dry
 * run writes nothing, --apply repairs only what the sync itself would have written, a second run changes nothing, and another organisation's rows are never
 * read or written. The one judgement call the command makes (a deal edited after the opportunity was last saved is left alone) is pinned too.
 */
class OpportunityResyncCommandTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;
    use BuildsPipelineWorld;

    /** @var array<string, int> */
    private array $w = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPipelineWorld();
    }

    /**
     * One pair of every kind the command meets, in organisation 1, and organisation 2's unlinked opportunity.
     *
     * @return array<string, int>
     */
    private function world(): array
    {
        $old = '2026-11-01 10:00:00';
        $new = '2026-11-02 10:00:00';

        // in step: made by the real sync
        $okOpp = $this->makeOpportunity(self::T, ['name' => 'In step', 'sales_stage' => 'Prospecting', 'amount' => '1000.00', 'currency' => 'INR']);
        OpportunityGtmSyncBridge::syncOpportunityToGtm(self::T, $okOpp);
        // no deal at all (the sync failed when it was made, or it came from the import)
        $unlinked = $this->makeOpportunity(self::T, ['name' => 'No deal yet', 'sales_stage' => 'Qualification', 'amount' => '2000.00', 'currency' => 'INR']);
        // stale: the opportunity was saved after the deal last changed and now differs
        $staleOpp = $this->makeOpportunity(self::T, ['name' => 'Stale deal', 'sales_stage' => 'Prospecting', 'amount' => '3000.00', 'currency' => 'INR']);
        OpportunityGtmSyncBridge::syncOpportunityToGtm(self::T, $staleOpp);
        $staleDeal = (int) DB::table('crm_opportunities')->find($staleOpp)->converted_from_gtm_deal_id;
        DB::table('gtm_deals')->where('id', $staleDeal)->update(['updated_at' => $old]);
        DB::table('crm_opportunities')->where('id', $staleOpp)->update(['amount' => '3500.00', 'sales_stage' => 'Negotiation/Review', 'updated_at' => $new]);
        // deal newer: somebody edited the deal in GTM after the opportunity was last saved
        $newerOpp = $this->makeOpportunity(self::T, ['name' => 'Deal edited later', 'sales_stage' => 'Prospecting', 'amount' => '4000.00', 'currency' => 'INR']);
        OpportunityGtmSyncBridge::syncOpportunityToGtm(self::T, $newerOpp);
        $newerDeal = (int) DB::table('crm_opportunities')->find($newerOpp)->converted_from_gtm_deal_id;
        DB::table('crm_opportunities')->where('id', $newerOpp)->update(['updated_at' => $old]);
        DB::table('gtm_deals')->where('id', $newerDeal)->update(['name' => 'Renamed in GTM', 'amount' => '4400.00', 'updated_at' => $new]);
        // deal removed on purpose
        $removedDeal = $this->makeDeal(self::T, ['deleted_at' => $new]);
        $removedOpp = $this->makeOpportunity(self::T, ['name' => 'Deal was deleted', 'converted_from_gtm_deal_id' => $removedDeal]);
        // a deleted opportunity is nobody's business
        $deleted = $this->makeOpportunity(self::T, ['name' => 'Deleted', 'deleted_at' => $new]);
        // a GTM deal with no opportunity
        $orphanDeal = $this->makeDeal(self::T, ['name' => 'GTM only', 'stage' => 'qualification', 'amount' => '800.00']);
        // organisation 2
        $theirs = $this->makeOpportunity(self::T2, ['name' => 'Theirs', 'sales_stage' => 'Prospecting']);
        $theirOrphanDeal = $this->makeDeal(self::T2, ['name' => 'Their GTM only']);

        return $this->w = compact('okOpp', 'unlinked', 'staleOpp', 'staleDeal', 'newerOpp', 'newerDeal', 'removedOpp', 'removedDeal', 'deleted', 'orphanDeal', 'theirs', 'theirOrphanDeal');
    }

    /** @return array<string, mixed> every row the command could touch, in both tables, history included */
    private function snapshot(): array
    {
        $rows = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return ['opportunities' => $rows('crm_opportunities'), 'deals' => $rows('gtm_deals'), 'history' => $rows('crm_opportunity_stage_history'), 'accounts' => $rows('gtm_accounts')];
    }

    private function resync(array $args): array
    {
        $code = Artisan::call('opportunities:resync-gtm', $args);

        return [$code, Artisan::output()];
    }

    public function test_a_dry_run_says_what_it_would_repair_and_writes_nothing(): void
    {
        $this->world();
        $before = $this->snapshot();

        [$code, $out] = $this->resync(['--tenant' => self::T]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertMatchesRegularExpression('/in step with their GTM deal \(left alone\)\s+1\b/', $out);
        $this->assertMatchesRegularExpression('/with no GTM deal \(deal would be made\)\s+1\b/', $out);
        $this->assertMatchesRegularExpression('/whose deal is stale \(deal would be updated\)\s+1\b/', $out);
        $this->assertMatchesRegularExpression('/whose deal is NEWER than the opportunity .*\s+1\b/', $out);
        $this->assertMatchesRegularExpression('/whose linked deal was removed \(left alone\)\s+1\b/', $out);
        $this->assertStringContainsString('Dry run: nothing was written', $out);
        $this->assertSame($before, $this->snapshot(), 'and nothing was written');
    }

    public function test_the_dry_run_counts_match_the_world(): void
    {
        $this->world();
        $before = $this->snapshot();

        $r = (new GtmResync)->run(self::T, false, true);

        $this->assertSame(['opportunities' => 5, 'in_step' => 1, 'unlinked' => 1, 'stale' => 1, 'deal_newer' => 1, 'deal_removed' => 1, 'deals_without_opportunity' => 1], array_intersect_key($r, array_flip(['opportunities', 'in_step', 'unlinked', 'stale', 'deal_newer', 'deal_removed', 'deals_without_opportunity'])));
        $this->assertSame(0, $r['repaired'] + $r['failed'] + $r['deals_repaired'] + $r['deals_failed'], 'nothing was done');
        $this->assertSame($before, $this->snapshot(), 'and nothing was written');
    }

    public function test_apply_repairs_the_unlinked_and_the_stale_and_leaves_the_rest(): void
    {
        $w = $this->world();
        $before = $this->snapshot();

        [$code] = $this->resync(['--tenant' => self::T, '--apply' => true]);
        $this->assertSame(0, $code);

        // the unlinked opportunity now has a deal with its values
        $opp = DB::table('crm_opportunities')->find($w['unlinked']);
        $this->assertNotNull($opp->converted_from_gtm_deal_id);
        $deal = DB::table('gtm_deals')->find($opp->converted_from_gtm_deal_id);
        $this->assertSame(['No deal yet', 'qualification', 2000.0], [$deal->name, $deal->stage, (float) $deal->amount]);

        // the stale deal follows the opportunity
        $stale = DB::table('gtm_deals')->find($w['staleDeal']);
        $this->assertSame(['negotiation', 3500.0], [$stale->stage, (float) $stale->amount]);

        // the deal edited later in GTM keeps its edit (the opportunity was NOT pushed over it)
        $newer = DB::table('gtm_deals')->find($w['newerDeal']);
        $this->assertSame(['Renamed in GTM', 4400.0], [$newer->name, (float) $newer->amount]);

        // in step, removed deal, deleted opportunity, the GTM-only deal: untouched
        $after = $this->snapshot();
        foreach (['okOpp', 'newerOpp', 'removedOpp', 'deleted', 'theirs'] as $key) {
            $this->assertSame($this->row($before['opportunities'], $w[$key]), $this->row($after['opportunities'], $w[$key]), "opportunity {$key} is untouched");
        }
        $this->assertSame($this->row($before['deals'], $w['orphanDeal']), $this->row($after['deals'], $w['orphanDeal']), 'a GTM-only deal is untouched without --include-deals');
        $this->assertSame($this->row($before['deals'], $w['theirOrphanDeal']), $this->row($after['deals'], $w['theirOrphanDeal']));
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->world();
        $this->resync(['--tenant' => self::T, '--apply' => true, '--include-deals' => true]);
        $afterFirst = $this->snapshot();

        [$code, $out] = $this->resync(['--tenant' => self::T, '--apply' => true, '--include-deals' => true]);

        $this->assertSame(0, $code);
        $this->assertSame($afterFirst, $this->snapshot(), 'the second run wrote nothing');
        $this->assertMatchesRegularExpression('/with no GTM deal \(deal made\)\s+0\b/', $out);
        $this->assertMatchesRegularExpression('/whose deal was stale \(deal updated\)\s+0\b/', $out);
        $this->assertMatchesRegularExpression('/repairs done\s+0\b/', $out);
    }

    public function test_include_deals_makes_the_opportunity_for_a_deal_that_has_none_with_its_first_history_row(): void
    {
        $w = $this->world();

        [$code, $out] = $this->resync(['--tenant' => self::T, '--apply' => true, '--include-deals' => true]);

        $this->assertSame(0, $code, 'no repair failed');
        $this->assertMatchesRegularExpression('/GTM deals with no opportunity \(opportunity made\)\s+1\b/', $out, "only this organisation's own deal was found");
        $opp = DB::table('crm_opportunities')->where('sub_institute_id', self::T)->where('converted_from_gtm_deal_id', $w['orphanDeal'])->first();
        $this->assertNotNull($opp, 'the GTM-only deal got its opportunity');
        $this->assertSame(['GTM only', 'Qualification'], [$opp->name, $opp->sales_stage]);
        $history = DB::table('crm_opportunity_stage_history')->where('opportunity_id', $opp->id)->get();
        $this->assertCount(1, $history, 'and the first history row');
        $this->assertSame([null, 'Qualification'], [$history[0]->from_stage, $history[0]->to_stage]);
        $this->assertSame(0, DB::table('crm_opportunities')->where('sub_institute_id', self::T2)->where('converted_from_gtm_deal_id', $w['theirOrphanDeal'])->count(), "organisation 2's deal got nothing");
    }

    public function test_it_never_reads_or_writes_another_organisations_rows(): void
    {
        $w = $this->world();
        $theirsBefore = [DB::table('crm_opportunities')->find($w['theirs']), DB::table('gtm_deals')->find($w['theirOrphanDeal'])];

        [$code, $out] = $this->resync(['--tenant' => self::T, '--apply' => true, '--include-deals' => true]);

        $this->assertSame(0, $code, 'no repair failed (a repair that reached for organisation 2 would have been refused by the sync and counted as failed)');
        $this->assertMatchesRegularExpression('/\n\s+opportunities\s+5\b/', $out, "organisation 1's five live opportunities, not organisation 2's");
        $this->assertEquals($theirsBefore, [DB::table('crm_opportunities')->find($w['theirs']), DB::table('gtm_deals')->find($w['theirOrphanDeal'])]);
        $this->assertSame(0, DB::table('gtm_deals')->where('sub_institute_id', self::T2)->whereNotNull('id')->where('name', 'Theirs')->count(), "no deal was made for organisation 2's opportunity");
        $this->assertNull(DB::table('crm_opportunities')->find($w['theirs'])->converted_from_gtm_deal_id);
        $this->assertMatchesRegularExpression('/Organisation 1\b/', $out);
        $this->assertStringNotContainsString('Organisation 2', $out);

        // and for organisation 2 it works on organisation 2 only
        $this->resync(['--tenant' => self::T2, '--apply' => true]);
        $this->assertNotNull(DB::table('crm_opportunities')->find($w['theirs'])->converted_from_gtm_deal_id);
        $this->assertSame(self::T2, (int) DB::table('gtm_deals')->find(DB::table('crm_opportunities')->find($w['theirs'])->converted_from_gtm_deal_id)->sub_institute_id);
    }

    public function test_a_sync_that_keeps_failing_is_counted_and_does_not_stop_the_run(): void
    {
        $this->world();
        $this->bindThrowingBridge();

        [$code, $out] = $this->resync(['--tenant' => self::T, '--apply' => true]);

        $this->assertSame(1, $code, 'a failed repair is a failed run');
        $this->assertMatchesRegularExpression('/repairs that failed .*\s+2\b/', $out);
        $this->assertMatchesRegularExpression('/repairs done\s+0\b/', $out);
        $this->assertCount(2, ThrowingGtmBridge::$calls, 'both repairs were tried');
    }

    public function test_a_pair_the_healthy_sync_would_leave_alone_is_not_reported(): void
    {
        // The opportunity has no amount: the sync does not clear the deal's amount, so the pair is in step even though the numbers differ.
        $opp = $this->makeOpportunity(self::T, ['name' => 'Same', 'sales_stage' => 'Prospecting', 'currency' => 'INR']);
        $deal = $this->makeDeal(self::T, ['name' => 'Same', 'stage' => 'discovery', 'amount' => '999.00', 'currency' => 'INR']);
        DB::table('crm_opportunities')->where('id', $opp)->update(['converted_from_gtm_deal_id' => $deal]);

        $this->assertFalse(GtmResync::differs(DB::table('crm_opportunities')->find($opp), DB::table('gtm_deals')->find($deal)));
        $r = (new GtmResync)->run(self::T, false);
        $this->assertSame(1, $r['in_step']);

        // A real difference is reported.
        DB::table('crm_opportunities')->where('id', $opp)->update(['name' => 'Renamed']);
        $this->assertTrue(GtmResync::differs(DB::table('crm_opportunities')->find($opp), DB::table('gtm_deals')->find($deal)));
    }

    public function test_the_options_are_checked(): void
    {
        $this->assertSame(2, $this->resync([])[0], 'neither --tenant nor --all');
        $this->assertSame(2, $this->resync(['--tenant' => self::T, '--all' => true])[0], 'both');
        $this->assertSame(2, $this->resync(['--tenant' => '0'])[0]);
        $this->assertSame(2, $this->resync(['--tenant' => 'abc'])[0]);
        $this->assertSame(2, $this->resync(['--tenant' => self::T, '--database' => 'no_such_connection'])[0]);
    }

    public function test_it_says_so_when_the_gtm_tables_are_not_on_this_connection(): void
    {
        Schema::drop('gtm_deals');

        [$code, $out] = $this->resync(['--tenant' => self::T]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('gtm_deals is not on this connection', $out);
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function row(array $rows, int $id): array
    {
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        return [];
    }
}
