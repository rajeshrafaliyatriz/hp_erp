<?php

namespace Tests\Feature;

use App\Domain\Crm\Leads\LeadLinkBackfill;
use App\Domain\Crm\Leads\LeadLinkBackfillBlocked;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFactsWorld;
use Tests\Support\BuildsLeadsWorld;
use Tests\TestCase;

/**
 * leads:backfill-links (slice 6, task 8): the product and the organisation account of leads made before those columns existed. The rules of every data
 * update (DB-RULES.md): a dry run writes nothing, --apply writes only into empty columns of the organisation's own leads, a second run changes nothing, and
 * another organisation's rows are never read into a link.
 */
class LeadLinkBackfillTest extends TestCase
{
    use BuildsFactsWorld;
    use BuildsLeadsWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLeadsWorld();
        $this->buildSignalsWorld();
    }

    private function product(int $tenant, string $key, string $signalKey, string $name): int
    {
        return (int) DB::table('g2g_product_master')->insertGetId(['sub_institute_id' => $tenant, 'kind' => 'product', 'product_key' => $key, 'signal_key' => $signalKey, 'portfolio_name' => $name, 'name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function identity(int $tenant, string $name, ?int $companyId = null, ?int $crmOrganizationId = null): int
    {
        return (int) DB::table('g2g_org_accounts')->insertGetId(['sub_institute_id' => $tenant, 'name' => $name, 'normalized_name' => strtolower($name), 'lifecycle' => 'discovered', 'g2g_company_id' => $companyId,
            'crm_organization_id' => $crmOrganizationId, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<int, array{product: ?int, account: ?int, updated_at: mixed}> */
    private function links(): array
    {
        return DB::table('crm_leads')->orderBy('id')->get()->mapWithKeys(fn ($l) => [(int) $l->id => ['product' => $l->product_master_id === null ? null : (int) $l->product_master_id,
            'account' => $l->org_account_id === null ? null : (int) $l->org_account_id, 'updated_at' => $l->updated_at]])->all();
    }

    /** One lead of each kind the command meets, all in organisation 1, and one of organisation 2's. @return array<string, int> */
    private function world(): array
    {
        $scholar = $this->product(0, 'scholarclone', 'scholar', 'ScholarClone');
        $own = $this->product(self::T, 'own_product', 'own', 'Own Product');
        $signalA = $this->makeSignal(self::T, ['business_fit' => 'scholar']);                       // product from its stored fit
        $signalB = $this->makeSignal(self::T, ['product_master_id' => $own]);                       // product from its own column
        $signalC = $this->makeSignal(self::T, ['business_fit' => 'multiple']);                     // no product resolves
        $signalD = $this->makeSignal(self::T2, ['business_fit' => 'scholar']);                      // another organisation's signal
        $companyIdentity = $this->identity(self::T, 'Company A', $signalA->company_id);
        $orgX = $this->makeOrganization(self::T);
        $orgIdentity = $this->identity(self::T, 'Org X', null, $orgX);
        $this->identity(self::T2, 'Theirs', $signalD->company_id);

        $ids = [
            'fromSignalWithCompany' => $this->makeLead(self::T, ['source_company_opportunity_id' => $signalA->id]),
            'fromSignalWithOwnProduct' => $this->makeLead(self::T, ['source_company_opportunity_id' => $signalB->id]),
            'fromSignalNothingResolves' => $this->makeLead(self::T, ['source_company_opportunity_id' => $signalC->id]),
            'convertedIntoOrg' => $this->makeLead(self::T, ['converted' => 1, 'converted_organization_id' => $orgX]),
            'convertedAndSignal' => $this->makeLead(self::T, ['converted' => 1, 'converted_organization_id' => $orgX, 'source_company_opportunity_id' => $signalA->id]),
            'alreadyFilled' => $this->makeLead(self::T, ['source_company_opportunity_id' => $signalA->id, 'product_master_id' => $own, 'org_account_id' => $orgIdentity]),
            'plainLead' => $this->makeLead(self::T),
            'plantedForeignSignal' => $this->makeLead(self::T, ['source_company_opportunity_id' => $signalD->id]),
            'theirs' => $this->makeLead(self::T2, ['source_company_opportunity_id' => $signalD->id]),
        ];

        return $ids + ['scholar' => $scholar, 'own' => $own, 'companyIdentity' => $companyIdentity, 'orgIdentity' => $orgIdentity];
    }

    public function test_a_dry_run_says_what_it_would_write_and_writes_nothing(): void
    {
        $this->world();
        $before = $this->links();
        $events = DB::table('g2g_event')->count();

        $r = (new LeadLinkBackfill)->run(self::T, false);

        $this->assertSame($before, $this->links(), 'not one column changed');
        $this->assertSame($events, DB::table('g2g_event')->count());
        $this->assertSame(0, $r['written']);
        $this->assertSame(['leads' => 8, 'product_to_set' => 3, 'account_to_set' => 3], ['leads' => $r['leads'], 'product_to_set' => $r['product_to_set'], 'account_to_set' => $r['account_to_set']]);
    }

    public function test_apply_sets_each_link_by_its_rule_and_leaves_everything_else_alone(): void
    {
        $w = $this->world();
        $before = $this->links();

        $r = (new LeadLinkBackfill)->run(self::T, true);

        $after = $this->links();
        $this->assertSame($w['scholar'], $after[$w['fromSignalWithCompany']]['product'], 'the signal\'s stored fit resolves to the shared product');
        $this->assertSame($w['companyIdentity'], $after[$w['fromSignalWithCompany']]['account'], 'the identity that holds the signal\'s company');
        $this->assertSame($w['own'], $after[$w['fromSignalWithOwnProduct']]['product'], 'the signal\'s own product column wins');
        $this->assertNull($after[$w['fromSignalWithOwnProduct']]['account'], 'no identity holds this company: none is made');
        $this->assertSame([null, null], [$after[$w['fromSignalNothingResolves']]['product'], $after[$w['fromSignalNothingResolves']]['account']], '"multiple" resolves to nothing');
        $this->assertSame([null, $w['orgIdentity']], [$after[$w['convertedIntoOrg']]['product'], $after[$w['convertedIntoOrg']]['account']], 'the identity of the CRM organisation it was converted into');
        $this->assertSame($w['orgIdentity'], $after[$w['convertedAndSignal']]['account'], 'when both routes answer, the converted organisation wins');
        $this->assertSame($w['scholar'], $after[$w['convertedAndSignal']]['product']);
        $this->assertSame([$w['own'], $w['orgIdentity']], [$after[$w['alreadyFilled']]['product'], $after[$w['alreadyFilled']]['account']], 'a filled value is never overwritten');
        $this->assertSame([null, null], [$after[$w['plainLead']]['product'], $after[$w['plainLead']]['account']]);
        $this->assertSame([null, null], [$after[$w['plantedForeignSignal']]['product'], $after[$w['plantedForeignSignal']]['account']], 'a pointer to another organisation\'s signal links nothing');
        $this->assertSame($before[$w['theirs']], $after[$w['theirs']], 'another organisation\'s lead is not touched');
        foreach ($after as $id => $row) {
            $this->assertEquals($before[$id]['updated_at'], $row['updated_at'], 'a backfill is not an edit: updated_at stays');
        }

        $this->assertEquals(
            ['product_to_set' => 3, 'account_to_set' => 3, 'account_by_converted_organisation' => 2, 'account_by_signal_company' => 1, 'account_disagreements' => 1, 'product_unresolved' => 1, 'signal_not_found' => 1,
                'product_filled' => 1, 'account_filled' => 1, 'written' => 6],
            array_intersect_key($r, array_flip(['product_to_set', 'account_to_set', 'account_by_converted_organisation', 'account_by_signal_company', 'account_disagreements', 'product_unresolved', 'signal_not_found', 'product_filled', 'account_filled', 'written']))
        );
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->world();
        (new LeadLinkBackfill)->run(self::T, true);
        $after = $this->links();

        $again = (new LeadLinkBackfill)->run(self::T, true);

        $this->assertSame($after, $this->links());
        $this->assertSame([0, 0, 0], [$again['product_to_set'], $again['account_to_set'], $again['written']]);
    }

    public function test_a_value_written_since_the_plan_was_made_is_not_overwritten(): void
    {
        $w = $this->world();
        $other = $this->product(self::T, 'late', 'late', 'Late Product');
        // The plan is made inside run(); a lead that gets a product between the read and the write (a person, another request) must keep it:
        // the update itself says "where it is still NULL". Here the product is set by a listener the moment the plan has read the lead.
        $fired = false;
        DB::listen(function ($query) use (&$fired, $w, $other) {
            if (! $fired && str_contains($query->sql, 'from "g2g_company_opportunities"')) {
                $fired = true;
                DB::table('crm_leads')->where('id', $w['fromSignalWithCompany'])->update(['product_master_id' => $other]);
            }
        });

        $r = (new LeadLinkBackfill)->run(self::T, true);

        $this->assertTrue($fired);
        $this->assertSame($other, $this->links()[$w['fromSignalWithCompany']]['product'], 'the value written meanwhile stands');
        $this->assertSame(5, $r['written'], 'one of the six columns was no longer empty');
    }

    public function test_an_account_that_is_no_longer_a_live_identity_is_re_resolved_and_a_live_one_is_left_alone(): void
    {
        $orgX = $this->makeOrganization(self::T);
        $live = $this->identity(self::T, 'Org X', null, $orgX);
        $merged = $this->identity(self::T, 'Merged Away');
        DB::table('g2g_org_accounts')->where('id', $merged)->update(['deleted_at' => now()]);
        $theirs = $this->identity(self::T2, 'Theirs');
        $otherLive = $this->identity(self::T, 'Somebody Else');
        $onMerged = $this->makeLead(self::T, ['converted' => 1, 'converted_organization_id' => $orgX, 'org_account_id' => $merged]);
        $onTheirs = $this->makeLead(self::T, ['converted' => 1, 'converted_organization_id' => $orgX, 'org_account_id' => $theirs]);
        $onLive = $this->makeLead(self::T, ['converted' => 1, 'converted_organization_id' => $orgX, 'org_account_id' => $otherLive]);
        $deadAndUnknown = $this->makeLead(self::T, ['org_account_id' => $merged]);

        $dry = (new LeadLinkBackfill)->run(self::T, false);
        $this->assertSame([2, 1, 1], [$dry['account_repaired'], $dry['account_dangling_left'], $dry['account_filled']]);
        $this->assertSame($merged, $this->links()[$onMerged]['account'], 'a dry run changes nothing');

        $r = (new LeadLinkBackfill)->run(self::T, true);

        $links = $this->links();
        $this->assertSame($live, $links[$onMerged]['account'], 'a dead identity is replaced by the converted organisation\'s');
        $this->assertSame($live, $links[$onTheirs]['account'], 'so is another organisation\'s identity');
        $this->assertSame($otherLive, $links[$onLive]['account'], 'a live identity is never touched, even if the rule would say another');
        $this->assertSame($merged, $links[$deadAndUnknown]['account'], 'a dead pointer that cannot be re-resolved is left as it is');
        $this->assertSame(2, $r['written']);
        $this->assertSame(0, (new LeadLinkBackfill)->run(self::T, true)['written'], 'and a second run changes nothing');
    }

    public function test_a_dead_account_replaced_since_the_plan_was_read_is_not_overwritten(): void
    {
        $orgX = $this->makeOrganization(self::T);
        $live = $this->identity(self::T, 'Org X', null, $orgX);
        $merged = $this->identity(self::T, 'Merged Away');
        DB::table('g2g_org_accounts')->where('id', $merged)->update(['deleted_at' => now()]);
        $chosen = $this->identity(self::T, 'Chosen By A Person');
        $lead = $this->makeLead(self::T, ['converted' => 1, 'converted_organization_id' => $orgX, 'org_account_id' => $merged]);
        $fired = false;
        DB::listen(function ($query) use (&$fired, $lead, $chosen) {
            if (! $fired && str_contains($query->sql, 'from "g2g_org_accounts"') && str_contains($query->sql, 'where "g2g_company_id"') === false && str_contains($query->sql, '"crm_organization_id"')) {
                $fired = true;
                DB::table('crm_leads')->where('id', $lead)->update(['org_account_id' => $chosen]);
            }
        });

        (new LeadLinkBackfill)->run(self::T, true);

        $this->assertTrue($fired);
        $this->assertSame($chosen, $this->links()[$lead]['account'], 'what a person set while the command was reading stands');
        $this->assertNotSame($live, $this->links()[$lead]['account']);
    }

    public function test_merged_identities_move_their_leads_to_the_survivor_in_their_own_organisation_only(): void
    {
        $from = $this->identity(self::T, 'Loser');
        $to = $this->identity(self::T, 'Survivor');
        $a = $this->makeLead(self::T, ['org_account_id' => $from]);
        $b = $this->makeLead(self::T, ['org_account_id' => $to]);
        $theirs = $this->makeLead(self::T2, ['org_account_id' => $from]);                // a stray pointer into the same id from another organisation

        $moved = \App\Domain\Crm\Leads\LeadLinks::repointAccount(self::T, $from, $to);

        $this->assertSame(1, $moved);
        $this->assertSame([$to, $to, $from], [$this->links()[$a]['account'], $this->links()[$b]['account'], $this->links()[$theirs]['account']]);
        $this->assertSame(0, \App\Domain\Crm\Leads\LeadLinks::repointAccount(self::T, $from, $to), 'idempotent');
        $this->assertSame(0, \App\Domain\Crm\Leads\LeadLinks::repointAccount(self::T, $to, $to));
        $this->assertSame(0, \App\Domain\Crm\Leads\LeadLinks::repointAccount(0, $from, $to));
    }

    public function test_soft_deleted_and_converted_leads_are_linked_too_so_a_restored_lead_is_whole(): void
    {
        $signal = $this->makeSignal(self::T, ['business_fit' => 'scholar']);
        $this->product(0, 'scholarclone', 'scholar', 'ScholarClone');
        $gone = $this->makeLead(self::T, ['source_company_opportunity_id' => $signal->id, 'deleted_at' => now()]);

        (new LeadLinkBackfill)->run(self::T, true);

        $this->assertNotNull($this->links()[$gone]['product']);
    }

    public function test_it_refuses_a_connection_that_lacks_the_columns(): void
    {
        (require base_path('database/migrations/2026_12_11_100100_add_product_and_org_account_to_crm_leads.php'))->down();

        $this->expectException(LeadLinkBackfillBlocked::class);
        (new LeadLinkBackfill)->run(self::T, true);
    }

    public function test_a_connection_without_the_product_master_or_identities_links_what_it_can_and_says_so(): void
    {
        $w = $this->world();
        \Illuminate\Support\Facades\Schema::drop('g2g_org_accounts');
        \Illuminate\Support\Facades\Schema::drop('g2g_org_link_rejections');

        $r = (new LeadLinkBackfill)->run(self::T, true);

        $this->assertSame(0, $r['account_to_set']);
        $this->assertNotEmpty($r['warnings']);
        $this->assertSame($w['scholar'], $this->links()[$w['fromSignalWithCompany']]['product'], 'the product part still runs');
    }

    // ── the command ──────────────────────────────────────────────────────────

    public function test_the_command_is_a_dry_run_by_default_and_saves_its_summary(): void
    {
        $this->world();
        $before = $this->links();
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'leadlinks_' . uniqid() . '.txt';

        $code = Artisan::call('leads:backfill-links', ['--tenant' => self::T, '--summary' => $file]);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('Dry run: nothing was written', $out);
        $this->assertSame($before, $this->links());
        $this->assertStringContainsString('product to set', (string) file_get_contents($file));
        @unlink($file);
    }

    public function test_the_command_applies_and_a_second_apply_says_nothing_to_do(): void
    {
        $this->world();

        $this->assertSame(0, Artisan::call('leads:backfill-links', ['--tenant' => self::T, '--apply' => true]));
        $this->assertStringContainsString('6 columns written', Artisan::output());
        $this->assertSame(0, Artisan::call('leads:backfill-links', ['--tenant' => self::T, '--apply' => true]));
        $this->assertStringContainsString('Total: 0 product link(s) and 0 account link(s) set (0 columns written)', Artisan::output());
    }

    public function test_the_command_with_all_covers_every_organisation_and_each_in_its_own_run(): void
    {
        $w = $this->world();

        Artisan::call('leads:backfill-links', ['--all' => true, '--apply' => true]);

        $this->assertStringContainsString('Organisation 1', Artisan::output());
        $this->assertSame($w['scholar'], $this->links()[$w['theirs']]['product'] ?? null, 'organisation 2\'s own lead is linked through organisation 2\'s own signal (its stored fit resolves to the shared product)');
    }

    public function test_the_command_refuses_bad_arguments_and_a_connection_that_is_not_there(): void
    {
        $this->assertSame(2, Artisan::call('leads:backfill-links'));
        $this->assertSame(2, Artisan::call('leads:backfill-links', ['--tenant' => 1, '--all' => true]));
        $this->assertSame(2, Artisan::call('leads:backfill-links', ['--tenant' => 'abc']));
        $this->assertSame(2, Artisan::call('leads:backfill-links', ['--tenant' => 0]));
        $this->assertSame(2, Artisan::call('leads:backfill-links', ['--tenant' => 1, '--database' => 'no_such_connection']));
    }

    public function test_the_command_fails_clearly_on_a_connection_without_the_columns(): void
    {
        (require base_path('database/migrations/2026_12_11_100100_add_product_and_org_account_to_crm_leads.php'))->down();

        $this->assertSame(1, Artisan::call('leads:backfill-links', ['--tenant' => 1]));
        $this->assertStringContainsString('run migration 2026_12_11_100100 first', Artisan::output());
    }

    public function test_no_two_commands_share_the_name(): void
    {
        $names = array_map(fn ($c) => $c->getName(), array_values(Artisan::all()));

        $this->assertSame(1, count(array_keys($names, 'leads:backfill-links', true)));
    }
}
