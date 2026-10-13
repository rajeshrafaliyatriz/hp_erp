<?php

namespace Tests\Feature;

use App\Domain\Signals\Icp\AccountEvidence;
use App\Domain\Signals\Icp\IcpPack;
use App\Domain\Signals\Icp\IcpScorer;
use App\Domain\Signals\Icp\TargetAccount;
use App\Domain\Signals\Market\OpportunityEvent;
use App\Domain\Signals\Market\SectionAssigner;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\OpportunityResearcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The counted evidence points come from the school's OWN recent signals, not from anyone typing them, and a school kept on
 * WATCH never has a lead in "act now".
 */
class AccountEvidenceTest extends TestCase
{
    private const T = 6;

    private array $pack;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables', '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind', '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields', '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_10_13_100000_create_g2g_target_accounts_table',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->pack = IcpPack::fromTemplate('scholar_k12')->config();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function company(): int
    {
        return (int) DB::table('g2g_companies')->insertGetId([
            'sub_institute_id' => self::T, 'name' => 'Podar World School', 'normalized_name' => 'podar world school', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function account(int $companyId, array $attr = [], string $status = 'candidate'): TargetAccount
    {
        return TargetAccount::create([
            'sub_institute_id' => self::T, 'pack' => 'scholar_k12', 'account_key' => 'podar world school|surat', 'name' => 'Podar World School', 'city' => 'Surat',
            'state' => 'Gujarat', 'company_id' => $companyId, 'attributes' => $attr, 'icp_status' => $status,
        ]);
    }

    private function opportunity(int $companyId, string $trigger, string $eventDate, array $o = []): int
    {
        return (int) DB::table('g2g_company_opportunities')->insertGetId($o + [
            'sub_institute_id' => self::T, 'company_id' => $companyId, 'research_run_id' => 1, 'title' => 'x', 'category' => 'c', 'observed_event' => 'e',
            'why_indicates_need' => 'w', 'product_fit' => 'p', 'recommended_action' => 'a', 'priority' => 'High', 'qualification' => 'q', 'confidence' => 'High',
            'review_status' => 'New', 'sources' => '[]', 'fingerprint' => md5($trigger . $eventDate . random_int(0, 99999)),
            'trigger_type' => $trigger, 'event_date' => $eventDate, 'feed_section' => SectionAssigner::ACT_NOW,
            'first_discovered_at' => now(), 'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_two_different_recent_triggers_and_their_parts_earn_the_counted_points(): void
    {
        $c = $this->company();
        $this->opportunity($c, 'new_campus', '2026-10-10');         // school_erp
        $this->opportunity($c, 'academic', '2026-10-05');           // lms_pal
        $r = (new AccountEvidence())->compute($this->account($c), $this->pack);

        $this->assertSame(['active_triggers' => 20, 'need_across_parts' => 10], $r['evidence']);
        $this->assertEqualsCanonicalizing(['new_campus', 'academic'], $r['triggers']);
        $this->assertEqualsCanonicalizing(['school_erp', 'lms_pal'], $r['parts']);
    }

    public function test_the_same_trigger_twice_counts_once_and_one_part_earns_the_first_step(): void
    {
        $c = $this->company();
        $this->opportunity($c, 'new_campus', '2026-10-10');
        $this->opportunity($c, 'new_campus', '2026-10-12');
        $r = (new AccountEvidence())->compute($this->account($c), $this->pack);

        $this->assertSame(['active_triggers' => 10, 'need_across_parts' => 5], $r['evidence']);
    }

    public function test_old_dismissed_and_context_signals_do_not_count(): void
    {
        $c = $this->company();
        $this->opportunity($c, 'new_campus', '2026-06-01');                                          // older than 90 days
        $this->opportunity($c, 'academic', '2026-10-10', ['review_status' => 'Dismissed']);
        $this->opportunity($c, 'leadership', '2026-10-10', ['feed_section' => SectionAssigner::MARKET]);
        $r = (new AccountEvidence())->compute($this->account($c), $this->pack);

        $this->assertSame(['active_triggers' => 0, 'need_across_parts' => 0], $r['evidence']);
    }

    public function test_judged_points_set_by_a_person_are_added_but_never_above_their_maximum(): void
    {
        $c = $this->company();
        $acc = $this->account($c, ['evidence' => ['leadership_willingness' => 999, 'financial_capacity' => 10, 'not_a_criterion' => 50]]);
        $r = (new AccountEvidence())->compute($acc, $this->pack);

        $this->assertSame(999, $r['evidence']['leadership_willingness']);          // passed on...
        $this->assertSame(20 + 10, $r['score']['evidence_points']);                // ...but the scorer caps it at 20
        $this->assertArrayNotHasKey('not_a_criterion', $r['evidence']);
    }

    public function test_refresh_stores_the_result_on_the_account_without_losing_other_notes(): void
    {
        $c = $this->company();
        $this->opportunity($c, 'new_campus', '2026-10-10');
        $acc = $this->account($c);
        $acc->forceFill(['icp_notes' => ['known' => ['students' => 2400]]])->save();

        (new AccountEvidence())->refresh($acc, $this->pack);
        $notes = $acc->fresh()->icp_notes;

        $this->assertSame(['students' => 2400], $notes['known']);
        $this->assertSame(10, $notes['evidence']['points']['active_triggers']);
        $this->assertSame(['new_campus'], $notes['evidence']['triggers']);
        $this->assertArrayHasKey('total', $notes['evidence']);
    }

    public function test_an_account_with_no_company_has_no_evidence(): void
    {
        $acc = TargetAccount::create(['sub_institute_id' => self::T, 'pack' => 'scholar_k12', 'account_key' => 'x|y', 'name' => 'X', 'icp_status' => 'unscored']);
        $r = (new AccountEvidence(new IcpScorer()))->compute($acc, $this->pack);

        $this->assertSame([], $r['triggers']);
        $this->assertSame(0, $r['evidence']['active_triggers']);
    }

    public function test_a_lead_for_a_school_on_watch_is_moved_out_of_immediate_action_and_the_move_is_recorded(): void
    {
        $c = $this->company();
        $watch = $this->account($c, [], 'watch');
        $opp = $this->opportunity($c, 'system_change', '2026-10-15');

        $this->link(IcpPack::fromTemplate('scholar_k12'), 'Podar World School', $opp);

        $this->assertSame(SectionAssigner::WATCHING, CompanyOpportunity::find($opp)->feed_section);
        $event = OpportunityEvent::where('opportunity_id', $opp)->where('event_type', 'capped_by_watch')->first();
        $this->assertNotNull($event);
        $this->assertSame('account is on watch', $event->changes['reason']);
        $this->assertSame('watch', $watch->fresh()->icp_status);
    }

    public function test_a_lead_for_a_candidate_school_stays_in_immediate_action(): void
    {
        $c = $this->company();
        $this->account($c, [], 'candidate');
        $opp = $this->opportunity($c, 'system_change', '2026-10-15');

        $this->link(IcpPack::fromTemplate('scholar_k12'), 'Podar World School', $opp);

        $this->assertSame(SectionAssigner::ACT_NOW, CompanyOpportunity::find($opp)->feed_section);
        $this->assertSame(0, OpportunityEvent::where('event_type', 'capped_by_watch')->count());
    }

    /** linkAccounts is private on purpose: it only runs inside a research run. */
    private function link(IcpPack $pack, string $buyer, int $oppId): void
    {
        $researcher = $this->app->make(OpportunityResearcher::class);
        $m = new \ReflectionMethod($researcher, 'linkAccounts');
        $m->setAccessible(true);
        $m->invoke($researcher, self::T, $pack, [0 => ['buyer' => ['name' => $buyer]]], [['index' => 0, 'id' => $oppId, 'outcome' => 'created']]);
    }
}
