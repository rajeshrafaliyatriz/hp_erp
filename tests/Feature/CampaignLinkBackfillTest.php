<?php

namespace Tests\Feature;

use App\Domain\Campaigns\CampaignLinkBackfill;
use App\Domain\Campaigns\CampaignLinkBackfillBlocked;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCampaignsWorld;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * campaigns:backfill-links (slice 9): the product of campaigns (from the loose `product_id` through the Product Master's `crm_product_id`) and the first-touch
 * campaign of leads (from the free-text `campaign_text`, an EXACT name match within the organisation). The rules of every data update (DB-RULES.md): a dry run
 * writes nothing, --apply writes only into empty columns of the organisation's own rows, a second run changes nothing, an ambiguous name is reported and never
 * linked, and another organisation's rows are never read into a link. Dev holds no campaigns, so every proof here is on fixtures.
 */
class CampaignLinkBackfillTest extends TestCase
{
    use BuildsCampaignsWorld;
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    private const OLD = '2026-01-01 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCampaignsWorld();
    }

    /** A Product Master row that points at a catalogue item (crm_products.id) of the organisation. */
    private function masterFor(int $tenant, int $crmProductId, string $name): int
    {
        return $this->makeProductRow($tenant, $name, ['kind' => 'item', 'crm_product_id' => $crmProductId]);
    }

    /** @return array<int, array{product: ?int, updated_at: mixed}> */
    private function campaigns(): array
    {
        return DB::table('crm_campaigns')->orderBy('id')->get()->mapWithKeys(fn ($c) => [(int) $c->id => ['product' => $c->product_master_id === null ? null : (int) $c->product_master_id, 'updated_at' => $c->updated_at]])->all();
    }

    /** @return array<int, array{campaign: ?int, updated_at: mixed}> */
    private function leads(): array
    {
        return DB::table('crm_leads')->orderBy('id')->get()->mapWithKeys(fn ($l) => [(int) $l->id => ['campaign' => $l->campaign_id === null ? null : (int) $l->campaign_id, 'updated_at' => $l->updated_at]])->all();
    }

    /** One row of each kind the command meets, in organisation 1, and the other organisation's twins. @return array<string, int> */
    private function world(): array
    {
        $m1 = $this->masterFor(self::T, 11, 'Catalogue item 11');
        $m2 = $this->masterFor(self::T, 12, 'Catalogue item 12');
        $m3 = $this->masterFor(self::T2, 11, 'Their catalogue item 11');              // the same catalogue id in another organisation: its own master row

        $c = fn (array $over) => $this->makeCampaign(self::T, $over + ['updated_at' => self::OLD]);
        $ids = [
            'diwali' => $c(['name' => 'Diwali Outreach', 'product_id' => 11]),                                                  // product from master 1
            'noMaster' => $c(['name' => 'Spring Drive', 'product_id' => 99]),                                                    // the item has no master row
            'filled' => $c(['name' => 'Filled', 'product_id' => 12, 'product_master_id' => $m1]),                               // already has a (different) value
            'noProduct' => $c(['name' => 'No Product']),
            'binned' => $c(['name' => 'Binned', 'product_id' => 12, 'deleted_at' => now()]),                                     // in the Recycle Bin: still linked
            'openA' => $c(['name' => 'Open Day']),                                                                              // two live campaigns, one name
            'openB' => $c(['name' => '  open day ']),
            'ghost' => $c(['name' => 'Ghost Campaign', 'deleted_at' => now()]),                                                 // in the Recycle Bin: not a candidate for a lead
            'theirs' => $this->makeCampaign(self::T2, ['name' => 'Diwali Outreach', 'product_id' => 11, 'updated_at' => self::OLD]),
        ];
        $l = fn (int $tenant, array $over) => $this->makeLead($tenant, $over + ['updated_at' => self::OLD]);
        $ids += [
            'exact' => $l(self::T, ['campaign_text' => '  diwali OUTREACH ']),                                                   // case and surrounding spaces only
            'partial' => $l(self::T, ['campaign_text' => 'Diwali']),                                                              // a part of a name is not a match
            'ambiguous' => $l(self::T, ['campaign_text' => 'Open Day']),
            'alreadyLinked' => $l(self::T, ['campaign_text' => 'Spring Drive', 'campaign_id' => $ids['diwali']]),                // a value is there: never overwritten
            'noText' => $l(self::T, []),
            'ghostText' => $l(self::T, ['campaign_text' => 'Ghost Campaign']),
            'binnedLead' => $l(self::T, ['campaign_text' => 'Spring Drive', 'deleted_at' => now()]),                             // in the Recycle Bin: still linked
            'convertedLead' => $l(self::T, ['campaign_text' => 'Spring Drive', 'converted' => 1]),
            'noOrganisation' => $l(0, ['campaign_text' => 'Diwali Outreach', 'sub_institute_id' => null]),                          // never read
            'theirLead' => $l(self::T2, ['campaign_text' => 'Diwali Outreach']),
        ];

        return $ids + ['m1' => $m1, 'm2' => $m2, 'm3' => $m3];
    }

    // ── dry run, apply, re-run ────────────────────────────────────────────────

    public function test_a_dry_run_says_what_it_would_write_and_writes_nothing(): void
    {
        $this->world();
        $campaigns = $this->campaigns();
        $leads = $this->leads();

        $r = (new CampaignLinkBackfill)->run(self::T, false);

        $this->assertSame($campaigns, $this->campaigns());
        $this->assertSame($leads, $this->leads());
        $this->assertSame(2, $r['product_to_set'], 'the live one and the one in the Recycle Bin');
        $this->assertSame(3, $r['campaign_to_set'], 'the exact one, the lead in the Recycle Bin and the converted one');
        $this->assertSame(0, $r['written']);
    }

    public function test_apply_sets_each_link_by_its_rule_and_leaves_everything_else_alone(): void
    {
        $w = $this->world();

        $r = (new CampaignLinkBackfill)->run(self::T, true);

        $campaigns = $this->campaigns();
        $this->assertSame($w['m1'], $campaigns[$w['diwali']]['product'], 'through this organisation\'s master row of item 11, not the other organisation\'s');
        $this->assertSame($w['m2'], $campaigns[$w['binned']]['product'], 'a campaign in the Recycle Bin is linked too, so a restored one is whole');
        $this->assertNull($campaigns[$w['noMaster']]['product'], 'an item with no master row is left empty');
        $this->assertSame($w['m1'], $campaigns[$w['filled']]['product'], 'a value that is there is never overwritten (m2 would have been the match)');
        $this->assertNull($campaigns[$w['noProduct']]['product']);
        $this->assertNull($campaigns[$w['theirs']]['product'], 'the other organisation\'s campaign is not touched by organisation 1\'s run');

        $leads = $this->leads();
        $this->assertSame($w['diwali'], $leads[$w['exact']]['campaign'], 'exact apart from case and surrounding spaces');
        $this->assertNull($leads[$w['partial']]['campaign'], 'a part of a name is not a match');
        $this->assertNull($leads[$w['ambiguous']]['campaign'], 'two campaigns carry the name: not linked');
        $this->assertSame($w['diwali'], $leads[$w['alreadyLinked']]['campaign'], 'a value that is there is never overwritten (Spring Drive would have been the match)');
        $this->assertNull($leads[$w['noText']]['campaign']);
        $this->assertNull($leads[$w['ghostText']]['campaign'], 'a campaign in the Recycle Bin is not a candidate');
        $this->assertSame($w['noMaster'], $leads[$w['binnedLead']]['campaign'], 'a lead in the Recycle Bin is linked too');
        $this->assertSame($w['noMaster'], $leads[$w['convertedLead']]['campaign']);
        $this->assertNull($leads[$w['noOrganisation']]['campaign'], 'a lead with no organisation is never read');
        $this->assertNull($leads[$w['theirLead']]['campaign'], 'the other organisation\'s lead is not touched by organisation 1\'s run');

        $this->assertSame(5, $r['written'], '2 campaign products + 3 lead campaigns');
        foreach ([$campaigns, $leads] as $rows) {
            foreach ($rows as $row) {
                $this->assertSame(self::OLD, (string) $row['updated_at'], 'a backfill is not an edit by a person: updated_at stands');
            }
        }
    }

    public function test_the_ambiguous_name_is_reported_and_counted(): void
    {
        $this->world();

        $r = (new CampaignLinkBackfill)->run(self::T, false);

        $this->assertSame(1, $r['campaign_ambiguous']);
        $this->assertSame(['Open Day'], $r['ambiguous_names']);
        $this->assertSame(2, $r['campaign_no_match'], 'the partial text, and the text of a campaign that is in the Recycle Bin');
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->world();
        (new CampaignLinkBackfill)->run(self::T, true);
        $campaigns = $this->campaigns();
        $leads = $this->leads();

        $again = (new CampaignLinkBackfill)->run(self::T, true);

        $this->assertSame($campaigns, $this->campaigns());
        $this->assertSame($leads, $this->leads());
        $this->assertSame([0, 0, 0], [$again['product_to_set'], $again['campaign_to_set'], $again['written']]);
    }

    public function test_the_other_organisation_is_linked_by_its_own_run_through_its_own_rows(): void
    {
        $w = $this->world();

        (new CampaignLinkBackfill)->run(self::T2, true);

        $this->assertSame($w['m3'], $this->campaigns()[$w['theirs']]['product'], 'its own master row of item 11');
        $this->assertSame($w['theirs'], $this->leads()[$w['theirLead']]['campaign'], 'its own campaign of that name, not organisation 1\'s');
        $this->assertNull($this->campaigns()[$w['diwali']]['product'], 'and organisation 1 is untouched by it');
        $this->assertNull($this->leads()[$w['exact']]['campaign']);
    }

    // ── a value that appears while the plan is being made ────────────────────

    public function test_a_product_set_since_the_plan_was_made_is_not_overwritten(): void
    {
        $w = $this->world();
        $late = $this->masterFor(self::T, 77, 'Late Product');
        $fired = false;
        // The plan reads the master rows and then writes; a campaign that gets a product in between (a person, another request) must keep it.
        DB::listen(function ($query) use (&$fired, $w, $late) {
            if (! $fired && str_contains($query->sql, 'from "g2g_product_master"')) {
                $fired = true;
                DB::table('crm_campaigns')->where('id', $w['diwali'])->update(['product_master_id' => $late]);
            }
        });

        $r = (new CampaignLinkBackfill)->run(self::T, true);

        $this->assertTrue($fired);
        $this->assertSame($late, $this->campaigns()[$w['diwali']]['product'], 'the value written meanwhile stands');
        $this->assertSame(4, $r['written'], 'one of the five columns was no longer empty');
    }

    public function test_a_campaign_set_since_the_plan_was_made_is_not_overwritten(): void
    {
        $w = $this->world();
        $other = $this->makeCampaign(self::T, ['name' => 'Chosen by a person']);
        $fired = false;
        DB::listen(function ($query) use (&$fired, $w, $other) {
            if (! $fired && str_contains($query->sql, 'from "crm_leads"')) {
                $fired = true;
                DB::table('crm_leads')->where('id', $w['exact'])->update(['campaign_id' => $other]);
            }
        });

        $r = (new CampaignLinkBackfill)->run(self::T, true);

        $this->assertTrue($fired);
        $this->assertSame($other, $this->leads()[$w['exact']]['campaign'], 'the value written meanwhile stands');
        $this->assertSame(4, $r['written'], 'one of the five columns was no longer empty');
    }

    public function test_a_lead_that_moved_to_another_organisation_since_the_plan_is_not_linked_to_the_first_organisations_campaign(): void
    {
        $w = $this->world();
        $fired = false;
        // Between the read and the write the lead is moved to organisation 2 (a transfer, a merge elsewhere). The write names the organisation too, so the
        // campaign of organisation 1 is not written onto a row that is no longer organisation 1's.
        DB::listen(function ($query) use (&$fired, $w) {
            if (! $fired && str_contains($query->sql, 'from "crm_leads"')) {
                $fired = true;
                DB::table('crm_leads')->where('id', $w['exact'])->update(['sub_institute_id' => self::T2]);
            }
        });

        $r = (new CampaignLinkBackfill)->run(self::T, true);

        $this->assertTrue($fired);
        $this->assertNull($this->leads()[$w['exact']]['campaign'], 'organisation 1\'s campaign was not written onto the moved lead');
        $this->assertSame(4, $r['written']);
    }

    public function test_a_campaign_that_moved_to_another_organisation_since_the_plan_is_not_given_the_first_organisations_product(): void
    {
        $w = $this->world();
        $fired = false;
        DB::listen(function ($query) use (&$fired, $w) {
            if (! $fired && str_contains($query->sql, 'from "g2g_product_master"')) {
                $fired = true;
                DB::table('crm_campaigns')->where('id', $w['diwali'])->update(['sub_institute_id' => self::T2]);
            }
        });

        $r = (new CampaignLinkBackfill)->run(self::T, true);

        $this->assertTrue($fired);
        $this->assertNull($this->campaigns()[$w['diwali']]['product'], 'organisation 1\'s master row was not written onto the moved campaign');
        // 5 columns normally; the moved campaign's product is skipped, and the lead that matched its name no longer finds it among organisation 1's campaigns.
        $this->assertSame(3, $r['written']);
    }

    // ── a connection that cannot take it, and the command ────────────────────

    public function test_it_refuses_a_connection_that_lacks_the_columns(): void
    {
        (require base_path('database/migrations/2026_12_11_140200_add_campaign_id_to_crm_leads.php'))->down();

        $this->expectException(CampaignLinkBackfillBlocked::class);
        (new CampaignLinkBackfill)->run(self::T, true);
    }

    public function test_a_connection_without_the_product_master_links_the_leads_and_says_so(): void
    {
        $w = $this->world();
        \Illuminate\Support\Facades\Schema::drop('g2g_product_master');

        $r = (new CampaignLinkBackfill)->run(self::T, true);

        $this->assertNotEmpty($r['warnings']);
        $this->assertSame(0, $r['product_to_set']);
        $this->assertSame($w['diwali'], $this->leads()[$w['exact']]['campaign'], 'the lead part still runs');
    }

    public function test_the_command_is_a_dry_run_by_default_and_saves_its_summary(): void
    {
        $this->world();
        $campaigns = $this->campaigns();
        $leads = $this->leads();
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'campaignlinks_' . uniqid() . '.txt';

        $code = Artisan::call('campaigns:backfill-links', ['--tenant' => self::T, '--summary' => $file]);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('Dry run: nothing was written', $out);
        $this->assertStringContainsString('ambiguous name: Open Day', $out);
        $this->assertSame($campaigns, $this->campaigns());
        $this->assertSame($leads, $this->leads());
        $this->assertStringContainsString('campaign product to set', (string) file_get_contents($file));
        @unlink($file);
    }

    public function test_the_command_applies_and_a_second_apply_says_nothing_to_do(): void
    {
        $this->world();

        $this->assertSame(0, Artisan::call('campaigns:backfill-links', ['--tenant' => self::T, '--apply' => true]));
        $this->assertStringContainsString('5 columns written', Artisan::output());
        $this->assertSame(0, Artisan::call('campaigns:backfill-links', ['--tenant' => self::T, '--apply' => true]));
        $this->assertStringContainsString('Total: 0 campaign product link(s) and 0 lead campaign link(s) set (0 columns written)', Artisan::output());
    }

    public function test_the_command_with_all_covers_every_organisation_that_has_campaigns_or_a_campaign_text(): void
    {
        $w = $this->world();

        Artisan::call('campaigns:backfill-links', ['--all' => true, '--apply' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString('organisations: 1, 2', $out);
        $this->assertSame($w['m3'], $this->campaigns()[$w['theirs']]['product']);
        $this->assertSame($w['theirs'], $this->leads()[$w['theirLead']]['campaign']);
    }

    public function test_the_command_refuses_bad_arguments_and_a_connection_that_is_not_there(): void
    {
        $this->assertSame(2, Artisan::call('campaigns:backfill-links'));
        $this->assertSame(2, Artisan::call('campaigns:backfill-links', ['--tenant' => 1, '--all' => true]));
        $this->assertSame(2, Artisan::call('campaigns:backfill-links', ['--tenant' => 'abc']));
        $this->assertSame(2, Artisan::call('campaigns:backfill-links', ['--tenant' => 0]));
        $this->assertSame(2, Artisan::call('campaigns:backfill-links', ['--tenant' => 1, '--database' => 'no_such_connection']));
    }

    public function test_the_command_fails_clearly_on_a_connection_without_the_columns(): void
    {
        (require base_path('database/migrations/2026_12_11_140000_add_product_master_id_to_crm_campaigns.php'))->down();

        $this->assertSame(1, Artisan::call('campaigns:backfill-links', ['--tenant' => 1]));
        $this->assertStringContainsString('run migrations 2026_12_11_140000', Artisan::output());
    }

    public function test_an_empty_database_gives_an_empty_report(): void
    {
        $this->assertSame(0, Artisan::call('campaigns:backfill-links', ['--all' => true]));
        $out = Artisan::output();

        $this->assertStringContainsString('organisations: (none)', $out);
        $this->assertStringContainsString('Total: 0 campaign product link(s) and 0 lead campaign link(s) to set', $out);
    }

    /** Slice 9 sends nothing and schedules nothing (the standing rule of the effort): none of its new code may even reach for a mailer, a queue, a notification or the network. */
    public function test_nothing_this_slice_adds_can_send_or_schedule_anything(): void
    {
        $files = array_merge(
            glob(base_path('app/Domain/Campaigns/*.php')),
            [base_path('app/Console/Commands/CampaignsBackfillLinksCommand.php')],
            glob(base_path('database/migrations/2026_12_11_14*.php')),
        );
        $this->assertGreaterThanOrEqual(8, count($files));
        $forbidden = ['Mail::', 'Mailer', '->send(', '::send(', 'dispatch(', 'Queue::', '->schedule(', 'Notification', 'Http::', 'curl_', 'Bus::', 'event('];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach ($forbidden as $token) {
                $this->assertStringNotContainsString($token, $source, basename($file) . ' must not contain ' . $token);
            }
        }
    }

    public function test_no_two_commands_share_the_name(): void
    {
        $names = array_map(fn ($c) => $c->getName(), array_values(Artisan::all()));

        $this->assertSame(1, count(array_keys($names, 'campaigns:backfill-links', true)));
    }
}
