<?php

namespace Tests\Feature;

use App\Domain\Signals\Icp\IcpPack;
use App\Domain\Signals\Icp\IcpResearchPlanner;
use App\Domain\Signals\Icp\TargetAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What one research run searches for. Read from the stored history of ScholarClone's runs: the slice of searches depended only on the date (so
 * repeat runs on a day repeated each other), and every school searched by name had no website and never produced a signal. Nothing here calls a
 * provider; the planner only reads the database.
 */
class IcpPlannerTest extends TestCase
{
    private const T = 1;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables', '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind', '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields', '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', '2026_10_13_100000_create_g2g_target_accounts_table',
        ] as $m) {
            (require base_path("database/migrations/{$m}.php"))->up();
        }
        Carbon::setTestNow('2026-10-16 10:00:00');
        config(['signals.opportunities.max_queries' => 8, 'signals.icp.batch_shift' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pack(): IcpPack
    {
        $queries = [];
        foreach ([['news', 2], ['job', 4], ['tender', 2], ['leadership', 2], ['other', 1], ['regulation', 1]] as [$source, $n]) {
            for ($i = 1; $i <= $n; $i++) {
                $queries[] = ['id' => "{$source}-{$i}", 'source' => $source, 'topic' => 'news', 'days' => 30, 'q' => "{$source} query {$i}"];
            }
        }

        return new IcpPack('scholar_k12', ['queries' => $queries, 'per_run' => ['catalogue' => 3, 'accounts' => 3], 'account_query' => '"{name}" {city}', 'account_days' => 30, 'account_recheck_days' => 7], 1, 'ScholarClone');
    }

    private function account(string $name, ?string $website, int $priority = 2): TargetAccount
    {
        return TargetAccount::create(['sub_institute_id' => self::T, 'pack' => 'scholar_k12', 'account_key' => strtolower($name) . '|x', 'name' => $name, 'city' => 'Ahmedabad',
            'website' => $website, 'watch_priority' => $priority, 'icp_status' => 'unscored']);
    }

    private function run_(string $status = 'success', string $product = 'scholarclone', string $date = '2026-10-16', int $tenant = self::T): void
    {
        DB::table('g2g_research_runs')->insert(['sub_institute_id' => $tenant, 'report_date' => $date, 'trigger' => 'manual', 'status' => $status, 'product_key' => $product,
            'started_at' => $date . ' 09:00:00', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return list<string> */
    private function ids(array $plan): array
    {
        return array_column($plan['specs'], 'id');
    }

    public function test_a_school_without_a_website_is_not_searched_by_name_and_its_slot_goes_to_the_catalogue(): void
    {
        foreach (['New Age High School', 'Varahi Shala', 'Shraddha High School'] as $n) {
            $this->account($n, null);
        }

        $plan = (new IcpResearchPlanner())->plan(self::T, $this->pack(), null, 'scholarclone');

        $this->assertSame([], array_filter($this->ids($plan), fn ($id) => str_starts_with($id, 'account-')), 'no name searches for schools without a website');
        $this->assertSame([], $plan['account_ids']);
        $this->assertSame(3, $plan['skipped_without_website'], 'the run can say how many were left out');
        $this->assertCount(6, $plan['specs'], 'the three freed slots search the catalogue: the same six searches as before, never more');
    }

    public function test_a_school_with_a_website_keeps_its_slot_and_an_unused_slot_is_not_turned_into_extra_cost(): void
    {
        $a = $this->account('Anand Niketan', 'https://anandniketan.example.edu', 3);
        $this->account('No Site School', null, 1);

        $plan = (new IcpResearchPlanner())->plan(self::T, $this->pack(), null, 'scholarclone');

        $this->assertSame([$a->id], $plan['account_ids']);
        $this->assertContains('account-' . $a->id, $this->ids($plan));
        $this->assertSame(1, $plan['skipped_without_website']);
        $this->assertCount(5, $plan['specs'], '1 school + 3 catalogue + 1 freed by the skipped school');
    }

    public function test_with_no_target_schools_at_all_the_run_is_exactly_the_configured_catalogue_size(): void
    {
        $plan = (new IcpResearchPlanner())->plan(self::T, $this->pack(), null, 'scholarclone');

        $this->assertCount(3, $plan['specs']);
        $this->assertSame(0, $plan['skipped_without_website']);
    }

    public function test_the_old_behaviour_comes_back_when_the_switch_is_turned_off(): void
    {
        config(['signals.icp.account_search_needs_website' => false]);
        $this->account('No Site School', null);

        $plan = (new IcpResearchPlanner())->plan(self::T, $this->pack(), null, 'scholarclone');

        $this->assertCount(1, $plan['account_ids']);
        $this->assertCount(4, $plan['specs']);
    }

    public function test_a_repeat_run_on_the_same_day_takes_the_next_slice_instead_of_repeating_the_searches(): void
    {
        $first = $this->ids((new IcpResearchPlanner())->plan(self::T, $this->pack(), null, 'scholarclone'));
        $this->run_('success');
        $second = $this->ids((new IcpResearchPlanner())->plan(self::T, $this->pack(), null, 'scholarclone'));
        $this->run_('partial');
        $third = $this->ids((new IcpResearchPlanner())->plan(self::T, $this->pack(), null, 'scholarclone'));

        $this->assertCount(3, $first);
        $this->assertSame([], array_intersect($first, $second), 'the second run searches different things');
        $this->assertSame([], array_intersect($second, $third));
        $this->assertSame($first, $this->ids((new IcpResearchPlanner())->plan(self::T, $this->pack(), null, null)), 'without a product the plan is the date slice, as before (the preview)');
    }

    public function test_only_this_products_finished_runs_today_move_the_slice(): void
    {
        $first = $this->ids((new IcpResearchPlanner())->plan(self::T, $this->pack(), null, 'scholarclone'));
        $this->run_('failed');                                        // a failed run found nothing to learn from
        $this->run_('skipped');
        $this->run_('success', 'enterprise_brain');                   // another product
        $this->run_('success', 'scholarclone', '2026-10-15');         // another day
        $this->run_('success', 'scholarclone', '2026-10-16', 2);      // another organisation

        $this->assertSame($first, $this->ids((new IcpResearchPlanner())->plan(self::T, $this->pack(), null, 'scholarclone')));
    }

    public function test_the_rotation_can_be_switched_off(): void
    {
        config(['signals.icp.rotate_repeat_runs' => false]);
        $first = $this->ids((new IcpResearchPlanner())->plan(self::T, $this->pack(), null, 'scholarclone'));
        $this->run_('success');

        $this->assertSame($first, $this->ids((new IcpResearchPlanner())->plan(self::T, $this->pack(), null, 'scholarclone')));
    }
}
