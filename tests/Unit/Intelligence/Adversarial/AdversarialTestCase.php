<?php

namespace Tests\Unit\Intelligence\Adversarial;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\DetectorRegistry;
use App\Domain\Intelligence\InsightDetector;
use App\Domain\Intelligence\InsightService;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Support\SmallGroupGuard;
use App\Domain\Intelligence\Support\Thresholds;
use Illuminate\Database\Events\QueryExecuted;
use Tests\Intelligence\Fixtures\AllTables;
use Tests\Intelligence\IntelligenceTestCase;

/**
 * Shared machinery of the adversarial tests: a rich, deliberately hostile two-tenant world, role callers, a service factory and a query recorder.
 *
 * THE WORLD. `seedWorld()` fills one tenant with data that makes every one of the 11 detectors produce insights for a suitable caller (thresholds are the
 * provisional defaults). It also plants rows that MUST NOT count: inactive, terminated, soft-deleted and NULL-status people, soft-deleted records, revoked
 * certificates, NULL-tenant rows. `seedCrossReferences()` then plants the nasty part: rows of ANOTHER tenant that point at this tenant's ids (a manager id,
 * a leave id, a deal id, the same account numbers, the same department number) the way corrupt or hostile data would. A correct detector ignores all of it.
 *
 * Tenant ids 71 and 92 are used on purpose: the number 1 is also a `status = 1` binding in the active-employee rule, which would make a tenant-binding
 * assertion meaningless.
 */
abstract class AdversarialTestCase extends IntelligenceTestCase
{
    use AllTables;

    protected const T1 = 71;
    protected const T2 = 92;
    protected const B1 = 1000;   // first user id of tenant 71
    protected const B2 = 5000;   // first user id of tenant 92

    /** Every menu link a commercial detector looks for. Granting all of them gives the caller the MAXIMUM exposure, which is what an isolation test wants. */
    protected const ALL_LINKS = [
        '/signals', '/signal-review', '/gtm/deals', '/gtm/command-center', '/gtm/customers',
        '/module/crm/sales/opportunities', '/module/crm/sales/invoices',
        // platform services, AI governance and Agentic AI
        '/platform-services/workflow?module=organization', '/platform-services/workflow?module=hrms', '/platform-services/workflow?module=talent',
        '/platform-services/workflow?module=lms', '/platform-services/workflow?module=competency', '/platform-services/workflow?module=task', '/platform-services/workflow?module=crm',
        '/platform-services/event-bus', '/ai/usage-cost', '/ai/policies',
        '/module/agentic-ai/run-log', '/module/agentic-ai/analytics', '/module/agentic-ai/agent-dashboard', '/module/agentic-ai/reflection',
    ];

    protected const ROLES = ['employee', 'reporting_manager', 'department_head', 'hr_executive', 'hr_manager', 'administrator', 'executive', 'auditor', 'recruiter', 'wizard'];

    /** role => offset of the caller's own user id inside the tenant's id block */
    protected const ROLE_USER = [
        'reporting_manager' => 10, 'department_head' => 20, 'hr_executive' => 21, 'hr_manager' => 22, 'administrator' => 23, 'executive' => 24,
        'auditor' => 25, 'recruiter' => 26, 'wizard' => 27, 'employee' => 100,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAllTables();
    }

    protected function thresholds(): Thresholds
    {
        return Thresholds::defaults();
    }

    protected function fixedClock(): DetectionClock
    {
        return DetectionClock::at('2026-11-21 10:00:00');
    }

    /** @return list<InsightDetector> */
    protected function detectors(): array
    {
        return DetectorRegistry::forConnection($this->db(), $this->thresholds());
    }

    protected function service(?Thresholds $thresholds = null): InsightService
    {
        $t = $thresholds ?? $this->thresholds();

        return new InsightService(DetectorRegistry::forConnection($this->db(), $t), SmallGroupGuard::fromThresholds($t), $this->fixedClock());
    }

    protected function callerFor(string $role, int $tenant, int $base, array $links = self::ALL_LINKS, ?int $department = 3): IntelligenceCaller
    {
        return $this->callerAs($role, $tenant, $base + self::ROLE_USER[$role], $department, $links);
    }

    /** The whole run for one caller, reduced to a comparable, serialisable shape. */
    protected function snapshot(IntelligenceCaller $caller): array
    {
        $run = $this->service()->run($caller);

        return [
            'insights' => array_map(static fn ($i) => $i->toArray(), $run['insights']),
            'results' => array_map(static fn ($r) => $r->summary(), $run['results']),
            'meta' => $run['meta'],
            'suppressed' => $run['suppressed'],
        ];
    }

    /**
     * Run `$fn` and return every statement the connection executed meanwhile, as ['sql' => ..., 'bindings' => [...]].
     *
     * @return list<array{sql: string, bindings: array}>
     */
    protected function recordQueries(callable $fn): array
    {
        $log = [];
        $listener = static function (QueryExecuted $q) use (&$log): void {
            $log[] = ['sql' => $q->sql, 'bindings' => $q->bindings];
        };
        $this->app['events']->listen(QueryExecuted::class, $listener);
        try {
            $fn();
        } finally {
            $this->app['events']->forget(QueryExecuted::class);
        }

        return $log;
    }

    // ---------------------------------------------------------------------------------------------------------------------------------------------------
    // THE WORLD
    // ---------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * @param array{manager?: int, sentinel?: bool} $o  `manager`: the manager id the tenant's 12 people report to (default: the tenant's own manager). A manager
     *        id belonging to ANOTHER tenant is the cross-tenant reference case. `sentinel`: use values that would be unmistakable if they leaked.
     * @return array{manager: int, people: list<int>, excluded: list<int>, leaves: list<int>, deals: list<int>}
     */
    protected function seedWorld(int $t, int $b, array $o = []): array
    {
        $sentinel = (bool) ($o['sentinel'] ?? false);
        $ownManager = $b + 10;
        $manager = (int) ($o['manager'] ?? $ownManager);

        // --- people -------------------------------------------------------------------------------------------------------------------------------------
        $this->insertUser($ownManager, $t, ['department_id' => 3, 'user_profile_id' => 7]);
        foreach ([20 => 'head', 21 => 'hrx', 22 => 'hrm', 23 => 'adm', 24 => 'exe', 25 => 'aud', 26 => 'rec', 27 => 'wiz'] as $off => $_) {
            $this->insertUser($b + $off, $t, ['department_id' => 3, 'user_profile_id' => 7]);
        }
        $people = [];
        for ($i = 0; $i < 12; $i++) {
            $id = $b + 100 + $i;
            $people[] = $id;
            $this->insertUser($id, $t, ['reporting_manager_id' => $manager, 'department_id' => 3, 'user_profile_id' => 7]);
        }
        // People who must never be counted, each reporting to the same manager in the same department, each with data planted below.
        $excluded = [];
        $this->insertUser($b + 30, $t, ['status' => 0, 'reporting_manager_id' => $manager, 'department_id' => 3]);                         // inactive
        $this->insertUser($b + 31, $t, ['terminated_date' => '2026-06-01', 'reporting_manager_id' => $manager, 'department_id' => 3]);     // terminated
        $this->insertUser($b + 32, $t, ['deleted_at' => '2026-06-01 00:00:00', 'reporting_manager_id' => $manager, 'department_id' => 3]); // soft-deleted
        $this->insertUser($b + 33, $t, ['status' => null, 'reporting_manager_id' => $manager, 'department_id' => 3]);                      // NULL status
        foreach ([30, 31, 32, 33] as $off) {
            $excluded[] = $b + $off;
        }

        // --- attendance: 6 of 12 people punch on 15 past days; every third day has no punch-out; P[0] has 3 duplicated days -------------------------------
        for ($d = 0; $d < 15; $d++) {
            $day = sprintf('2026-11-%02d', 6 + $d);
            foreach (array_slice($people, 0, 6) as $p) {
                $this->insertRow('hrms_attendances', ['user_id' => $p, 'day' => $day, 'punchin_time' => "$day 09:00:00",
                    'punchout_time' => $d % 3 === 0 ? null : "$day 17:30:00", 'status' => 1, 'sub_institute_id' => $t]);
            }
            if ($d < 3) {
                $this->insertRow('hrms_attendances', ['user_id' => $people[0], 'day' => $day, 'punchin_time' => "$day 09:05:00", 'punchout_time' => null, 'status' => 1, 'sub_institute_id' => $t]);
            }
            foreach ($excluded as $x) {   // rows that must not count
                $this->insertRow('hrms_attendances', ['user_id' => $x, 'day' => $day, 'punchin_time' => "$day 09:00:00", 'punchout_time' => null, 'status' => 1, 'sub_institute_id' => $t]);
            }
        }
        $this->insertRow('hrms_attendances', ['user_id' => $people[1], 'day' => '2026-11-10', 'punchin_time' => '2026-11-10 09:00:00', 'punchout_time' => null, 'status' => 1,
            'sub_institute_id' => $t, 'deleted_at' => '2026-11-11 00:00:00']);                                                               // soft-deleted
        $this->insertRow('hrms_attendances', ['user_id' => $people[2], 'day' => '2026-11-10', 'punchin_time' => '2026-11-10 09:00:00', 'punchout_time' => null, 'status' => 1,
            'sub_institute_id' => null]);                                                                                                    // NULL tenant

        // --- leave: 6 pending approvals waiting 72h, 1 with no pending_since, 1 decided, 1 on a deleted leave, 1 NULL-tenant step ---------------------------
        $leaves = [];
        foreach (array_slice($people, 0, 6) as $p) {
            $leaves[] = $this->leave($t, $p, '2026-11-17 08:00:00');
            $this->step($t, end($leaves), ['status' => 'pending', 'pending_since' => '2026-11-18 10:00:00']);
        }
        $leaves[] = $l = $this->leave($t, $people[6], '2026-11-17 08:00:00');
        $this->step($t, $l, ['status' => 'pending', 'pending_since' => null]);
        $leaves[] = $l = $this->leave($t, $people[7], '2026-11-01 08:00:00');
        $this->step($t, $l, ['status' => 'approved', 'pending_since' => '2026-11-01 09:00:00', 'decided_at' => '2026-11-02 09:00:00']);
        $l = $this->leave($t, $people[8], '2026-11-17 08:00:00', ['deleted_at' => '2026-11-19 00:00:00']);
        $this->step($t, $l, ['status' => 'pending', 'pending_since' => '2026-11-18 10:00:00']);
        $this->insertRow('hrms_leave_approval_steps', ['leave_id' => $leaves[0], 'sub_institute_id' => 0, 'status' => 'pending', 'pending_since' => '2026-11-01 10:00:00']);
        foreach ($excluded as $x) {
            $l = $this->leave($t, $x, '2026-11-17 08:00:00');
            $this->step($t, $l, ['status' => 'pending', 'pending_since' => '2026-11-18 10:00:00']);
        }

        // --- certifications: 12 holders (4 lapsed, 3 due, 2 soon, 3 far), plus rows that must not count ------------------------------------------------------
        foreach ($people as $i => $p) {
            $expiry = $i < 4 ? '2026-10-01' : ($i < 7 ? '2026-12-01' : ($i < 9 ? '2027-01-10' : '2027-09-01'));
            $this->insertRow('s_competency_certifications', ['sub_institute_id' => $t, 'user_id' => $p, 'status' => 'valid', 'expiry_date' => $expiry]);
        }
        $this->insertRow('s_competency_certifications', ['sub_institute_id' => $t, 'user_id' => $people[0], 'status' => 'revoked', 'expiry_date' => '2026-10-02']);
        $this->insertRow('s_competency_certifications', ['sub_institute_id' => $t, 'user_id' => null, 'status' => 'valid', 'expiry_date' => '2026-10-02']);
        $this->insertRow('s_competency_certifications', ['sub_institute_id' => $t, 'user_id' => $people[3], 'status' => 'valid', 'expiry_date' => '2026-10-02', 'deleted_at' => '2026-10-03 00:00:00']);
        foreach ($excluded as $x) {
            $this->insertRow('s_competency_certifications', ['sub_institute_id' => $t, 'user_id' => $x, 'status' => 'valid', 'expiry_date' => '2026-10-02']);
        }
        $this->insertRow('lms_certificates', ['user_id' => $people[0], 'status' => 'active', 'expires_at' => '2026-12-01 00:00:00', 'sub_institute_id' => $t]);
        $this->insertRow('lms_certificates', ['user_id' => $people[1], 'status' => 'active', 'expires_at' => '2026-12-01 00:00:00', 'sub_institute_id' => $t, 'superseded_by' => 99]);
        $this->insertRow('lms_certificates', ['user_id' => $people[1], 'status' => 'active', 'expires_at' => '2026-12-01 00:00:00', 'sub_institute_id' => null]);

        // --- recruitment: 20 applications, 8 old pending reviews (mixed case), 3 offers sent and past expiry ---------------------------------------------------
        $statuses = ['Pending Review', 'pending review', 'Shortlisted', 'Hired', 'PENDING REVIEW', 'Under Review', 'hired', null];
        for ($i = 0; $i < 20; $i++) {
            $this->insertRow('talent_job_applications', ['sub_institute_id' => $t, 'job_id' => 1 + ($i % 3), 'status' => $statuses[$i % count($statuses)],
                'applied_date' => $i < 10 ? '2026-10-01' : '2026-11-15']);
        }
        $this->insertRow('talent_job_applications', ['sub_institute_id' => $t, 'job_id' => 1, 'status' => 'Pending Review', 'applied_date' => '2026-10-01', 'deleted_at' => '2026-10-02 00:00:00']);
        for ($i = 1; $i <= 3; $i++) {
            $this->insertRow('talent_offers', ['application_id' => $i, 'status' => 'sent', 'sent_at' => '2026-10-20 00:00:00', 'expires_at' => '2026-11-10', 'sub_institute_id' => $t]);
        }

        // --- onboarding: 6 journeys (4 probation ending, 2 overdue), 6 overdue tasks, plus exclusions -----------------------------------------------------------
        $journeys = [];
        foreach (array_slice($people, 0, 6) as $i => $p) {
            $this->insertRow('talent_onboarding_journeys', ['sub_institute_id' => $t, 'employee_id' => $p, 'manager_id' => $manager, 'joining_date' => '2026-08-01',
                'probation_end' => $i < 4 ? '2026-12-05' : '2026-11-01', 'confirmation_status' => 'pending', 'status' => 'in-progress']);
            $journeys[] = (int) $this->db()->getPdo()->lastInsertId();
            $this->insertRow('talent_onboarding_tasks', ['journey_id' => end($journeys), 'sub_institute_id' => $t, 'due_date' => '2026-11-10', 'status' => 'pending', 'completed_at' => null]);
        }
        $this->insertRow('talent_onboarding_journeys', ['sub_institute_id' => $t, 'employee_id' => $people[7], 'manager_id' => $manager, 'probation_end' => '2026-12-05',
            'confirmation_status' => 'pending', 'status' => 'in-progress', 'deleted_at' => '2026-10-01 00:00:00']);

        // --- signals ------------------------------------------------------------------------------------------------------------------------------------------
        for ($i = 0; $i < 25; $i++) {
            $this->addSignal($t, ['fetch_level' => $i < 14 ? 'search_snippet' : 'page_text', 'event_date' => $i < 8 ? null : '2026-11-01',
                'source_published_at' => null, 'review_status' => $i < 5 ? 'Reviewed' : 'New']);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->addSignal($t, ['import_source' => 'demo_fixture_x']);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->addRun($t, ['report_date' => sprintf('2026-11-%02d', 10 + $i), 'opportunities_qualified' => $i < 5 ? 0 : 2, 'status' => $i === 0 ? 'failed' : 'success']);
        }

        // --- GTM: accounts 1..12 exist in EVERY tenant under the same numbers (composite key); account 30 is deleted -----------------------------------------
        for ($a = 1; $a <= 12; $a++) {
            $this->insertRow('gtm_accounts', ['id' => $a, 'sub_institute_id' => $t]);
        }
        $this->insertRow('gtm_accounts', ['id' => 30, 'sub_institute_id' => $t, 'deleted_at' => '2026-11-01 00:00:00']);
        for ($d = 14; $d <= 21; $d++) {   // a deleted account's snapshots must never count
            $this->insertRow('gtm_health_snapshots', ['sub_institute_id' => $t, 'account_id' => 30, 'snapshot_date' => sprintf('2026-11-%02d', $d), 'score' => 10, 'band' => 'at_risk']);
        }
        // --- GTM: 12 open deals on accounts 1..12 (the SAME account numbers in every tenant) ------------------------------------------------------------------
        $deals = [];
        for ($i = 1; $i <= 12; $i++) {
            $this->addDeal($t, ['account_id' => $i, 'next_step' => $i <= 4 ? '' : 'call', 'expected_close_date' => $i > 10 ? null : '2026-12-30',
                'stage_changed_at' => $i > 9 ? '2026-09-01 00:00:00' : '2026-11-15 00:00:00', 'amount' => $i <= 10 ? ($sentinel ? 987654 : 1000) : null,
                'currency' => $i <= 10 ? ($sentinel ? 'ZZZ' : 'USD') : null]);
            $deals[] = (int) $this->db()->getPdo()->lastInsertId();
            if ($i <= 5) {
                $this->insertRow('gtm_activities', ['sub_institute_id' => $t, 'account_id' => $i, 'deal_id' => end($deals), 'type' => 'call', 'occurred_at' => '2026-11-15 00:00:00']);
            }
            if ($i <= 4) {
                $this->insertRow('gtm_contacts', ['sub_institute_id' => $t, 'account_id' => $i, 'role_in_deal' => 'decision_maker']);
            }
        }
        $this->addDeal($t, ['account_id' => 13, 'deleted_at' => '2026-11-01 00:00:00']);
        for ($i = 1; $i <= 6; $i++) {
            $this->insertRow('gtm_renewals', ['sub_institute_id' => $t, 'account_id' => $i, 'renewal_date' => $i <= 3 ? '2026-12-10' : '2026-11-01', 'status' => 'upcoming']);
        }
        for ($d = 14; $d <= 21; $d++) {
            for ($a = 1; $a <= 3; $a++) {
                $this->insertRow('gtm_health_snapshots', ['sub_institute_id' => $t, 'account_id' => $a, 'snapshot_date' => sprintf('2026-11-%02d', $d), 'score' => 30, 'band' => 'at_risk']);
            }
        }
        for ($i = 0; $i < 12; $i++) {
            $this->addCrmOpp($t, ['updated_at' => '2026-10-01 00:00:00', 'closing_date' => $i < 6 ? '2026-11-01' : '2026-12-30']);
        }
        for ($i = 0; $i < 12; $i++) {
            $this->addInvoice($t, ['due_date' => '2026-10-01', 'total' => $sentinel ? 987654 : 100, 'currency' => $i < 8 ? ($sentinel ? 'ZZZ' : 'USD') : 'EUR']);
        }

        $this->seedPlatform($t, $sentinel);

        // --- rows with NO tenant at all, sized to qualify if they were counted --------------------------------------------------------------------------------
        for ($i = 0; $i < 15; $i++) {
            $this->insertRow('s_competency_certifications', ['sub_institute_id' => 0, 'user_id' => $people[$i % 12], 'status' => 'valid', 'expiry_date' => '2026-10-02']);
        }

        return ['manager' => $manager, 'people' => $people, 'excluded' => $excluded, 'leaves' => $leaves, 'deals' => $deals];
    }

    /**
     * Rows of `$other` that REFERENCE ids of `$primary`: the corruption a leak would feed on. None of them may change what a `$primary` caller is told.
     *
     * @param array{people: list<int>, leaves: list<int>, deals: list<int>, manager: int} $primaryWorld
     */
    protected function seedCrossReferences(int $other, array $primaryWorld, int $primary): void
    {
        foreach ($primaryWorld['deals'] as $dealId) {   // activity on the primary's deals, logged under the other tenant
            $this->insertRow('gtm_activities', ['sub_institute_id' => $other, 'account_id' => null, 'deal_id' => $dealId, 'type' => 'call', 'occurred_at' => '2026-11-20 00:00:00']);
        }
        for ($a = 1; $a <= 12; $a++) {                  // the same account numbers, with activity, a decision maker and a healthy score
            $this->insertRow('gtm_activities', ['sub_institute_id' => $other, 'account_id' => $a, 'deal_id' => null, 'type' => 'meeting', 'occurred_at' => '2026-11-20 00:00:00']);
            $this->insertRow('gtm_contacts', ['sub_institute_id' => $other, 'account_id' => $a, 'role_in_deal' => 'decision_maker']);
        }
        for ($a = 1; $a <= 3; $a++) {
            $this->insertRow('gtm_health_snapshots', ['sub_institute_id' => $other, 'account_id' => $a, 'snapshot_date' => '2026-11-21', 'score' => 95, 'band' => 'healthy']);
            $this->insertRow('gtm_health_snapshots', ['sub_institute_id' => $other, 'account_id' => $a, 'snapshot_date' => '2026-11-22', 'score' => 95, 'band' => 'healthy']);
        }
        for ($a = 1; $a <= 6; $a++) {
            $this->insertRow('gtm_renewals', ['sub_institute_id' => $other, 'account_id' => $a, 'renewal_date' => '2026-12-10', 'status' => 'renewed']);
        }
        for ($d = 14; $d <= 22; $d++) {   // snapshots of the other tenant for an account number that only the PRIMARY tenant owns (orphans): the tenant-checked join drops them
            $this->insertRow('gtm_health_snapshots', ['sub_institute_id' => $other, 'account_id' => 40, 'snapshot_date' => sprintf('2026-11-%02d', $d), 'score' => 99, 'band' => 'healthy']);
        }
        $this->insertRow('gtm_accounts', ['id' => 40, 'sub_institute_id' => $primary]);   // owned by the primary
        foreach ($primaryWorld['leaves'] as $leaveId) {  // approval steps of the other tenant that name the primary's leaves
            $this->step($other, $leaveId, ['status' => 'pending', 'pending_since' => '2026-11-01 00:00:00']);
        }
        foreach ($primaryWorld['people'] as $p) {        // the primary's PEOPLE appearing in the other tenant's records
            $this->insertRow('s_competency_certifications', ['sub_institute_id' => $other, 'user_id' => $p, 'status' => 'valid', 'expiry_date' => '2026-10-01']);
            $this->insertRow('hrms_attendances', ['user_id' => $p, 'day' => '2026-11-12', 'punchin_time' => '2026-11-12 09:00:00', 'punchout_time' => null, 'status' => 1, 'sub_institute_id' => $other]);
            $this->leave($other, $p, '2026-11-17 08:00:00');
            $this->insertRow('talent_onboarding_journeys', ['sub_institute_id' => $other, 'employee_id' => $p, 'manager_id' => $primaryWorld['manager'], 'probation_end' => '2026-11-01',
                'confirmation_status' => 'pending', 'status' => 'in-progress']);
        }
        $this->seedPlatformCrossReferences($other, $primary);
        // a department head / manager of the OTHER tenant who is the primary's manager id as far as the reporting column is concerned: see seedWorld(manager:)
    }

    /**
     * Platform / AI / agentic data for one tenant, sized so every one of the six platform detectors has something to say. The INSTALLATION-WIDE scheduler runs
     * (NULL tenant: one shared ledger) are inserted once however many tenants are seeded, because they are shared by design and doubling them would be a fixture
     * error, not a leak. With `$sentinel` the values are unmistakable if they leak into another tenant's answer.
     */
    protected function seedPlatform(int $t, bool $sentinel): void
    {
        if (! $this->db()->table('g2g_platform_task_runs')->whereNull('sub_institute_id')->exists()) {
            for ($i = 0; $i < 12; $i++) {
                $this->addTaskRun('events:project', null, 'ok', sprintf('2026-11-21 09:%02d:00', $i));
            }
            $this->addTaskRun('leave:escalate', null, 'failed', '2026-11-21 09:30:00');
        }
        $this->db()->table('g2g_platform_scheduled_tasks')->insert(['sub_institute_id' => $t, 'task_key' => $sentinel ? 'zzz.sentinel.task' : 'leave.escalate', 'disabled' => 1]);

        $this->addIntegration($t, $sentinel ? 'zzz-sentinel' : 'sms', 'error');
        $this->addIntegration($t, 'smtp', 'configured');

        for ($i = 0; $i < 12; $i++) {
            $this->addEventWithDelivery($t, 'done', '2026-11-20 08:00:00', $sentinel ? 'zzz_consumer' : 'audit_projector');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->addEventWithDelivery($t, 'failed', '2026-11-20 09:00:00', $sentinel ? 'zzz_consumer' : 'workflow_runner');
        }

        $module = $sentinel ? 'zzz.module' : 'lms.course_builder';
        for ($i = 0; $i < 25; $i++) {
            $this->addAiCall($t, $module, 'success', '2026-11-15 08:00:00', ['input_tokens' => 400, 'output_tokens' => 100,
                'estimated_cost' => $sentinel ? 987654 : 1.0, 'cost_currency' => $sentinel ? 'ZZZ' : 'USD']);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->addAiCall($t, $sentinel ? 'zzz.other' : 'agents.chat', 'failed', '2026-11-16 08:00:00');
        }
        $this->addAiCall($t, $module, 'refused', '2026-11-17 08:00:00');
        $this->addQuota($t, null, 'month', 1000);

        $assigned = $this->addPolicy($t);
        $this->assignPolicy($assigned, $t);
        $this->addPolicy($t, 1, 1);
        $this->addPolicy($t);
        $this->addPolicy(null);

        $agent = $this->addAgent($t);
        $this->addAgent($t);
        $this->addAgent($t);
        for ($i = 0; $i < 6; $i++) {
            $this->addAgentRun($t, $agent, 'success', '2026-11-15 08:00:00', 1.0);
            $this->addAgentRun($t, $agent, 'error', '2026-11-15 08:00:00', $sentinel ? 987654.0 : 1.0);
        }
        $this->addAgentRun($t, $agent, 'running', '2026-11-18 08:00:00');
    }

    /** Rows of `$other` that point at the primary's ids or numbers: none may change what a primary caller is told. */
    protected function seedPlatformCrossReferences(int $other, int $primary): void
    {
        // the other tenant's policies are assigned under the PRIMARY's tenant id, and the primary's own policies get assignments naming the other tenant
        foreach ($this->db()->table('ai_policies')->where('sub_institute_id', $primary)->pluck('id') as $policyId) {
            $this->assignPolicy((int) $policyId, $other);
        }
        // the other tenant's agent runs name the primary's agent ids
        foreach ($this->db()->table('agentic_agents')->where('sub_institute_id', $primary)->pluck('id') as $agentId) {
            for ($i = 0; $i < 12; $i++) {
                $this->addAgentRun($other, (int) $agentId, 'error', '2026-11-16 08:00:00', 987654.0);
            }
        }
        // the other tenant's failing scheduler runs under the same task keys as the shared ones
        for ($i = 0; $i < 12; $i++) {
            $this->addTaskRun('events:project', $other, 'failed', '2026-11-21 09:45:00');
        }
        $this->addTaskRun('zzz:other-tenant-task', $other, 'failed', '2026-11-21 09:46:00');
    }

    protected function leave(int $tenant, int $user, string $createdAt, array $extra = []): int
    {
        $this->insertRow('hrms_emp_leaves', array_merge(['user_id' => $user, 'sub_institute_id' => $tenant, 'from_date' => '2026-11-25', 'to_date' => '2026-11-26',
            'status' => 'pending', 'created_at' => $createdAt], $extra));

        return (int) $this->db()->getPdo()->lastInsertId();
    }

    protected function step(int $tenant, int $leaveId, array $extra = []): void
    {
        $this->insertRow('hrms_leave_approval_steps', array_merge(['leave_id' => $leaveId, 'sub_institute_id' => $tenant, 'step_order' => 1, 'approver_role' => 'manager',
            'status' => 'pending', 'pending_since' => '2026-11-18 10:00:00', 'created_at' => '2026-11-18 10:00:00'], $extra));
    }

    /** All entity references (kind, id) found in a snapshot's insights. @return list<array{0: string, 1: int}> */
    protected function entities(array $snapshot): array
    {
        $out = [];
        foreach ($snapshot['insights'] as $insight) {
            foreach ($insight['evidence'] as $e) {
                if ($e['kind'] === 'entity') {
                    $out[] = [$e['entity_type'], (int) $e['entity_id']];
                }
            }
        }

        return $out;
    }
}
