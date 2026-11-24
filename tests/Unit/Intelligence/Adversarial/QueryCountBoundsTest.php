<?php

namespace Tests\Unit\Intelligence\Adversarial;

/**
 * 6. QUERY-COUNT / N+1 BOUNDS.
 *
 * A detector that loops over records and queries inside the loop is invisible with 12 rows and ruinous with 12,000 on a remote database. The test measures what
 * the connection actually executed for each detector on a moderately large tenant (300 people, 2,000 attendance rows, 500 leaves, 200 certificates,
 * 150 deals, 100 invoices, 60 research runs, 120 signals ...) and then on DOUBLE that data in the same tenant:
 *
 *   - the statement count must be at most MAX_QUERIES_PER_DETECTOR (a small constant), and
 *   - it must be IDENTICAL on double the data (no per-record queries), and
 *   - the introspection statements are counted separately: they are constant per optional source and cost nothing against data.
 *
 * Timings are printed to stderr for information only; no assertion depends on a clock.
 */
class QueryCountBoundsTest extends AdversarialTestCase
{
    /**
     * The real maximum observed on the first run was 13 data statements (CustomerRenewalDetector: renewal counts and buckets, the latest snapshot day, band
     * counts, trend look-ups); DealHealthDetector needed 9. 20 leaves room for one more aggregate without letting a per-record loop through: with 300+ rows
     * per source, a loop would show 150+.
     */
    private const MAX_QUERIES_PER_DETECTOR = 20;

    private function bulk(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 40) as $chunk) {
            $this->db()->table($table)->insert($chunk);
        }
    }

    /** One slice of the large tenant: 300 people, 2,000 attendance rows, 500 leaves ... Call twice for double the data. */
    private function seedSlice(int $round): void
    {
        $t = self::T1;
        $u = 20000 + $round * 1000;
        $users = $attendance = $leaves = $steps = $certs = $lms = $apps = $offers = $journeys = $tasks = [];
        for ($i = 0; $i < 300; $i++) {
            $users[] = ['id' => $u + $i, 'sub_institute_id' => $t, 'status' => 1, 'department_id' => 1 + ($i % 5), 'reporting_manager_id' => $i < 10 ? null : $u + ($i % 10), 'user_profile_id' => 7];
        }
        $this->bulk('tbluser', $users);
        for ($i = 0; $i < 2000; $i++) {
            $day = sprintf('2026-11-%02d', 1 + ($i % 20));
            $attendance[] = ['user_id' => $u + ($i % 300), 'day' => $day, 'punchin_time' => "$day 09:00:00", 'punchout_time' => $i % 4 === 0 ? null : "$day 17:00:00", 'status' => 1, 'sub_institute_id' => $t];
        }
        $this->bulk('hrms_attendances', $attendance);
        $firstLeave = (int) $this->db()->table('hrms_emp_leaves')->max('id');
        for ($i = 0; $i < 500; $i++) {
            $leaves[] = ['user_id' => $u + ($i % 300), 'sub_institute_id' => $t, 'from_date' => '2026-11-25', 'to_date' => '2026-11-26', 'status' => 'pending', 'created_at' => '2026-11-10 00:00:00'];
        }
        $this->bulk('hrms_emp_leaves', $leaves);
        for ($i = 1; $i <= 500; $i++) {
            $steps[] = ['leave_id' => $firstLeave + $i, 'sub_institute_id' => $t, 'step_order' => 1, 'approver_role' => 'manager', 'status' => 'pending',
                'pending_since' => '2026-11-12 10:00:00', 'created_at' => '2026-11-12 10:00:00'];
        }
        $this->bulk('hrms_leave_approval_steps', $steps);
        for ($i = 0; $i < 200; $i++) {
            $certs[] = ['sub_institute_id' => $t, 'user_id' => $u + ($i % 300), 'status' => 'valid', 'expiry_date' => $i % 3 === 0 ? '2026-10-01' : ($i % 3 === 1 ? '2026-12-01' : '2027-09-01')];
        }
        $this->bulk('s_competency_certifications', $certs);
        for ($i = 0; $i < 50; $i++) {
            $lms[] = ['user_id' => $u + $i, 'status' => 'active', 'expires_at' => '2026-12-01 00:00:00', 'sub_institute_id' => $t];
        }
        $this->bulk('lms_certificates', $lms);
        for ($i = 0; $i < 150; $i++) {
            $apps[] = ['sub_institute_id' => $t, 'job_id' => 1 + ($i % 7), 'status' => ['Pending Review', 'Shortlisted', 'Hired', 'Under Review'][$i % 4], 'applied_date' => $i % 2 ? '2026-10-01' : '2026-11-15'];
        }
        $this->bulk('talent_job_applications', $apps);
        for ($i = 0; $i < 50; $i++) {
            $offers[] = ['application_id' => $i + 1, 'status' => 'sent', 'sent_at' => '2026-10-20 00:00:00', 'expires_at' => '2026-11-10', 'sub_institute_id' => $t];
        }
        $this->bulk('talent_offers', $offers);
        $firstJourney = (int) $this->db()->table('talent_onboarding_journeys')->max('id');
        for ($i = 0; $i < 60; $i++) {
            $journeys[] = ['sub_institute_id' => $t, 'employee_id' => $u + $i, 'manager_id' => $u + ($i % 10), 'joining_date' => '2026-08-01', 'probation_end' => '2026-12-05', 'confirmation_status' => 'pending', 'status' => 'in-progress'];
        }
        $this->bulk('talent_onboarding_journeys', $journeys);
        for ($i = 1; $i <= 120; $i++) {
            $tasks[] = ['journey_id' => $firstJourney + (($i - 1) % 60) + 1, 'sub_institute_id' => $t, 'due_date' => '2026-11-10', 'status' => 'pending', 'completed_at' => null];
        }
        $this->bulk('talent_onboarding_tasks', $tasks);

        $signals = $runs = $deals = $activities = $contacts = $renewals = $snapshots = $opps = $invoices = [];
        for ($i = 0; $i < 120; $i++) {
            $signals[] = ['sub_institute_id' => $t, 'fetch_level' => $i % 2 ? 'search_snippet' : 'page_text', 'event_date' => $i % 3 ? '2026-11-01' : null, 'first_discovered_at' => '2026-11-10 00:00:00',
                'review_status' => 'New', 'import_source' => null, 'is_sample' => 0];
        }
        $this->bulk('g2g_company_opportunities', $signals);
        for ($i = 0; $i < 60; $i++) {
            $runs[] = ['sub_institute_id' => $t, 'report_date' => sprintf('2026-11-%02d', 1 + ($i % 20)), 'status' => $i % 5 ? 'success' : 'failed', 'opportunities_qualified' => $i % 3, 'queries_failed' => 0];
        }
        $this->bulk('g2g_research_runs', $runs);
        $firstDeal = (int) $this->db()->table('gtm_deals')->max('id');
        for ($i = 0; $i < 150; $i++) {
            $account = $round * 1000 + $i + 1;   // distinct accounts, so the chunked look-ups are exercised
            $deals[] = ['sub_institute_id' => $t, 'account_id' => $account, 'stage' => 'proposal', 'amount' => $i % 5 ? 100 : null, 'currency' => $i % 5 ? 'USD' : null,
                'expected_close_date' => $i % 7 ? '2026-12-30' : null, 'next_step' => $i % 4 ? 'call' : '', 'next_step_due' => '2026-12-01', 'stage_changed_at' => '2026-09-01 00:00:00', 'created_at' => '2026-09-01 00:00:00'];
            $activities[] = ['sub_institute_id' => $t, 'account_id' => $account, 'deal_id' => $firstDeal + $i + 1, 'type' => 'call', 'occurred_at' => '2026-11-10 00:00:00'];
            $contacts[] = ['sub_institute_id' => $t, 'account_id' => $account, 'role_in_deal' => 'decision_maker'];
        }
        $this->bulk('gtm_deals', $deals);
        $this->bulk('gtm_activities', $activities);
        $this->bulk('gtm_contacts', $contacts);
        for ($i = 0; $i < 60; $i++) {
            $renewals[] = ['sub_institute_id' => $t, 'account_id' => $round * 1000 + $i + 1, 'renewal_date' => $i % 2 ? '2026-12-10' : '2026-11-01', 'status' => 'upcoming'];
        }
        $this->bulk('gtm_renewals', $renewals);
        for ($d = 10; $d <= 20; $d++) {
            for ($a = 1; $a <= 25; $a++) {
                $snapshots[] = ['sub_institute_id' => $t, 'account_id' => $round * 1000 + $a, 'snapshot_date' => sprintf('2026-11-%02d', $d), 'score' => 40, 'band' => 'at_risk'];
            }
        }
        $this->bulk('gtm_health_snapshots', $snapshots);
        for ($i = 0; $i < 100; $i++) {
            $opps[] = ['sub_institute_id' => $t, 'sales_stage' => 'Prospecting', 'closing_date' => $i % 2 ? '2026-11-01' : '2026-12-30', 'updated_at' => '2026-10-01 00:00:00'];
            $invoices[] = ['sub_institute_id' => $t, 'due_date' => '2026-10-01', 'total' => 100, 'amount_paid' => 0, 'currency' => $i % 3 ? 'USD' : 'EUR'];
        }
        $this->bulk('crm_opportunities', $opps);
        $this->bulk('crm_invoices', $invoices);
        $this->seedPlatformSlice($round);
    }

    /** The platform / AI / agentic sources, sized so that a per-record query would show: runs, events, AI calls, policies and agent runs all double with the round. */
    private function seedPlatformSlice(int $round): void
    {
        $t = self::T1;
        $runs = $integrations = $events = $deliveries = $calls = $policies = $assignments = $agents = $agentRuns = [];
        for ($i = 0; $i < 200; $i++) {
            $runs[] = ['task_key' => $i % 25 === 0 ? "k{$round}x{$i}" : 'events:project', 'sub_institute_id' => null, 'started_at' => sprintf('2026-11-21 08:%02d:00', $i % 60),
                'finished_at' => '2026-11-21 08:59:00', 'status' => $i % 25 === 0 ? 'failed' : 'ok'];
        }
        $this->bulk('g2g_platform_task_runs', $runs);
        for ($i = 0; $i < 10; $i++) {
            $integrations[] = ['sub_institute_id' => $t, 'provider_key' => "p{$round}x{$i}", 'status' => ['error', 'configured', 'not_configured', 'unknown'][$i % 4], 'last_tested_at' => null];
        }
        $this->bulk('g2g_integration_credentials', $integrations);
        $firstEvent = (int) $this->db()->table('g2g_event')->max('id');
        for ($i = 1; $i <= 100; $i++) {
            $events[] = ['sub_institute_id' => $t, 'recorded_at' => '2026-11-20 08:00:00'];
            $deliveries[] = ['event_id' => $firstEvent + $i, 'consumer' => 'c' . ($i % 5), 'status' => ['done', 'failed', 'pending', 'done'][$i % 4]];
        }
        $this->bulk('g2g_event', $events);
        $this->bulk('g2g_event_delivery', $deliveries);
        for ($i = 0; $i < 100; $i++) {
            $calls[] = ['ai_module' => 'm' . ($i % 7), 'outcome' => ['success', 'failed', 'refused', 'success'][$i % 4], 'sub_institute_id' => $t, 'created_at' => '2026-11-15 08:00:00',
                'input_tokens' => 100, 'output_tokens' => 50, 'estimated_cost' => 1.5, 'cost_currency' => $i % 3 ? 'USD' : 'INR'];
        }
        $this->bulk('ai_usage_events', $calls);
        $this->db()->table('ai_usage_quotas')->insert(['sub_institute_id' => $t, 'ai_module' => null, 'period' => 'month', 'token_limit' => 1000]);
        $firstPolicy = (int) $this->db()->table('ai_policies')->max('id');
        for ($i = 1; $i <= 20; $i++) {
            $policies[] = ['sub_institute_id' => $t, 'status' => 1, 'require_acknowledgement' => $i % 2];
            if ($i % 2) {
                $assignments[] = ['policy_id' => $firstPolicy + $i, 'sub_institute_id' => $t, 'status' => 1];
            }
        }
        $this->bulk('ai_policies', $policies);
        $this->bulk('ai_policy_assignments', $assignments);
        $firstAgent = (int) $this->db()->table('agentic_agents')->max('id');
        for ($i = 1; $i <= 10; $i++) {
            $agents[] = ['sub_institute_id' => $t, 'status' => 'deployed'];
        }
        $this->bulk('agentic_agents', $agents);
        for ($i = 0; $i < 100; $i++) {
            $agentRuns[] = ['sub_institute_id' => $t, 'agent_id' => $firstAgent + 1 + ($i % 5), 'status' => ['success', 'error', 'running', 'success'][$i % 4], 'created_at' => '2026-11-15 08:00:00',
                'cost' => $i % 3 ? 1.0 : null];
        }
        $this->bulk('agentic_agent_runs', $agentRuns);
    }

    /** @return array<string, array{total: int, data: int, ms: float}> detector id => counts */
    private function measure(): array
    {
        $caller = $this->callerFor('administrator', self::T1, self::B1);
        $out = [];
        foreach ($this->detectors() as $d) {
            $this->assertTrue($d->authorizes($caller), $d->id());
            $start = microtime(true);
            $queries = $this->recordQueries(fn () => $d->detect($caller, $this->fixedClock()));
            $ms = (microtime(true) - $start) * 1000;
            $data = count(array_filter($queries, fn ($q) => ! preg_match('/sqlite_master|pragma_table_xinfo/i', $q['sql'])));
            $out[$d->id()] = ['total' => count($queries), 'data' => $data, 'ms' => round($ms, 1)];
        }

        return $out;
    }

    public function test_queries_per_detector_are_a_small_constant_that_does_not_grow_with_the_data(): void
    {
        $this->seedSlice(0);
        $small = $this->measure();
        $this->seedSlice(1);
        $large = $this->measure();

        $this->assertSame(600, $this->db()->table('tbluser')->count(), 'double the people');
        $this->assertSame(4000, $this->db()->table('hrms_attendances')->count(), 'double the attendance rows');
        $this->assertSame(300, $this->db()->table('gtm_deals')->count(), 'double the deals');

        $report = [];
        foreach ($small as $id => $s) {
            $l = $large[$id];
            $report[] = sprintf('%-38s data queries %2d -> %2d (+%d introspection)   %7.1f ms -> %7.1f ms', $id, $s['data'], $l['data'], $s['total'] - $s['data'], $s['ms'], $l['ms']);
            $this->assertLessThanOrEqual(self::MAX_QUERIES_PER_DETECTOR, $s['data'], "$id: too many queries");
            $this->assertSame($s['data'], $l['data'], "$id: the number of queries grew when the data doubled (an N+1)");
            $this->assertSame($s['total'], $l['total'], "$id: the total number of statements grew with the data");
        }
        fwrite(STDERR, "\n--- query counts on 300 people / 2,000 attendance rows / 500 leaves / 200 certs / 150 deals / 100 invoices (then doubled) ---\n" . implode("\n", $report) . "\n");
        $this->assertCount(23, $small);
    }

    public function test_the_whole_service_run_is_bounded_and_flat_too(): void
    {
        $this->seedSlice(0);
        $caller = $this->callerFor('administrator', self::T1, self::B1);
        $one = count($this->recordQueries(fn () => $this->service()->run($caller)));
        $this->seedSlice(1);
        $two = count($this->recordQueries(fn () => $this->service()->run($caller)));

        $this->assertSame($one, $two, 'the whole run issues the same number of statements on double the data');
        // The bound is detectors x the per-detector maximum (it was written as 11 x 20 when there were 11 detectors and was never raised as detectors were added).
        $this->assertLessThan(count($this->detectors()) * self::MAX_QUERIES_PER_DETECTOR, $one);
        fwrite(STDERR, "\nwhole service run (all 23 detectors, administrator): $one statements on both sizes\n");
    }

    public function test_every_entity_list_is_capped_at_ten_however_much_data_there_is(): void
    {
        $this->seedSlice(0);
        $this->seedSlice(1);
        $snapshot = $this->snapshot($this->callerFor('administrator', self::T1, self::B1));

        foreach ($snapshot['insights'] as $insight) {
            $entities = array_filter($insight['evidence'], static fn ($e) => $e['kind'] === 'entity');
            $this->assertLessThanOrEqual(10, count($entities), $insight['key'] . ' lists more than 10 record references');
        }
        $this->assertNotEmpty(array_filter($snapshot['insights'], fn ($i) => count(array_filter($i['evidence'], fn ($e) => $e['kind'] === 'entity')) === 10), 'at least one list should have hit the cap');
    }
}
