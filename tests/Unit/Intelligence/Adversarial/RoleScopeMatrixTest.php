<?php

namespace Tests\Unit\Intelligence\Adversarial;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 2. ROLE / SCOPE MATRIX. Which detectors each role may run, and WHOSE records each role's evidence may point at.
 *
 * Two extra people are planted per tenant to catch scope that is too wide:
 *   base+40  reports to P[0] (a report OF a report) in department 3
 *   base+41  reports to the administrator (another manager's report) in department 4
 * each with an expired certificate, a pending leave and an onboarding journey.
 */
class RoleScopeMatrixTest extends AdversarialTestCase
{
    private int $deepReport;
    private int $otherDeptReport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld(self::T1, self::B1);
        $this->seedWorld(self::T2, self::B2, ['manager' => self::B1 + 10]);   // the other tenant's people also name tenant 71's manager

        $this->deepReport = self::B1 + 40;
        $this->otherDeptReport = self::B1 + 41;
        $this->insertUser($this->deepReport, self::T1, ['reporting_manager_id' => self::B1 + 100, 'department_id' => 3]);
        $this->insertUser($this->otherDeptReport, self::T1, ['reporting_manager_id' => self::B1 + 23, 'department_id' => 4]);
        foreach ([$this->deepReport, $this->otherDeptReport] as $u) {
            $this->insertRow('s_competency_certifications', ['sub_institute_id' => self::T1, 'user_id' => $u, 'status' => 'valid', 'expiry_date' => '2026-10-01']);
            $this->step(self::T1, $this->leave(self::T1, $u, '2026-11-17 08:00:00'), ['status' => 'pending', 'pending_since' => '2026-11-18 10:00:00']);
            $this->insertRow('talent_onboarding_journeys', ['sub_institute_id' => self::T1, 'employee_id' => $u, 'manager_id' => self::B1 + 10, 'probation_end' => '2026-12-05',
                'confirmation_status' => 'pending', 'status' => 'in-progress']);
        }
    }

    /** The user a people-record belongs to, for the entity types that have one. */
    private function ownerOf(string $type, int $id): ?int
    {
        $map = ['certification' => ['s_competency_certifications', 'user_id'], 'lms_certificate' => ['lms_certificates', 'user_id'],
            'leave_request' => ['hrms_emp_leaves', 'user_id'], 'onboarding_journey' => ['talent_onboarding_journeys', 'employee_id'],
            'employee' => ['tbluser', 'id']];
        if (! isset($map[$type])) {
            return null;
        }
        $v = $this->db()->table($map[$type][0])->where('id', $id)->value($map[$type][1]);

        return $v === null ? null : (int) $v;
    }

    /**
     * @param  list<string>|null  $types  restrict to these entity types (null = all people-record types)
     * @param  list<string>  $except  entity types to leave out
     * @return list<int> owners of the people-records the snapshot points at
     */
    private function owners(array $snapshot, ?array $types = null, array $except = []): array
    {
        $out = [];
        foreach ($this->entities($snapshot) as [$type, $id]) {
            if (($types !== null && ! in_array($type, $types, true)) || in_array($type, $except, true)) {
                continue;
            }
            $owner = $this->ownerOf($type, $id);
            if ($owner !== null) {
                $out[] = $owner;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The detectors that RAN for this role. Since the access_restricted state exists, a detector the role may not use is still LISTED, as a bare access_restricted
     * entry that was never run; "runs" in every assertion below therefore means "listed with any other state".
     *
     * @return list<string>
     */
    private function detectorIds(string $role, array $links = []): array
    {
        $results = $this->service()->run($this->callerFor($role, self::T1, self::B1, $links))['results'];
        $ids = array_map(static fn ($r) => $r->detector, array_filter($results, static fn ($r) => $r->state !== 'access_restricted'));
        sort($ids);

        return array_values($ids);
    }

    /** @return list<string> the detectors listed as access_restricted for this role */
    private function restrictedIds(string $role, array $links = []): array
    {
        $results = $this->service()->run($this->callerFor($role, self::T1, self::B1, $links))['results'];
        $ids = array_map(static fn ($r) => $r->detector, array_filter($results, static fn ($r) => $r->state === 'access_restricted'));
        sort($ids);

        return array_values($ids);
    }

    public static function authorisedHrDetectors(): iterable
    {
        // The three people-centred detectors every person-scoped role runs, plus the three central ones (tasks, learning, capability) that follow the same
        // role set; `hr.organisation_structure` is HR/administrator/executive/auditor only (structure data of others is not shown to employees or managers).
        $central = ['hr.task_workflow', 'hr.learning_assignments', 'hr.capability_development'];
        $people = array_merge(['hr.leave_attention', 'hr.certification_expiry', 'hr.onboarding_probation'], $central);
        $withAttendance = array_merge(['hr.attendance_health'], $people);
        $withRecruitment = array_merge($withAttendance, ['hr.recruitment_funnel']);
        $withStructure = array_merge($withRecruitment, ['hr.organisation_structure']);
        yield 'employee' => ['employee', $people];
        yield 'reporting_manager' => ['reporting_manager', $withAttendance];
        yield 'department_head' => ['department_head', $withAttendance];
        yield 'hr_executive' => ['hr_executive', array_merge($withAttendance, ['hr.organisation_structure'])];   // department scope: applicants belong to no department, so no recruitment
        yield 'hr_manager' => ['hr_manager', $withStructure];
        yield 'administrator' => ['administrator', $withStructure];
        yield 'executive' => ['executive', $withStructure];
        yield 'auditor' => ['auditor', $withStructure];
        yield 'recruiter' => ['recruiter', ['hr.recruitment_funnel']];
        yield 'unknown role' => ['wizard', []];
    }

    #[DataProvider('authorisedHrDetectors')]
    public function test_each_role_runs_exactly_the_hr_detectors_it_is_entitled_to(string $role, array $expected): void
    {
        $hr = array_values(array_filter($this->detectorIds($role), static fn ($id) => str_starts_with($id, 'hr.')));
        sort($expected);

        $this->assertSame($expected, $hr);
    }

    public function test_without_menu_rights_commercial_detectors_appear_only_through_the_signals_role_fallback(): void
    {
        $flag = (bool) config('signals.permissions.use_menu_rights');
        foreach (self::ROLES as $role) {
            $commercial = array_values(array_filter($this->detectorIds($role, []), static fn ($id) => str_starts_with($id, 'commercial.')));
            $fallback = ! $flag && in_array($role, ['administrator', 'hr_manager', 'hr_executive', 'reporting_manager'], true);
            $this->assertSame($fallback ? ['commercial.signal_evidence_quality', 'commercial.signal_yield'] : [], $commercial,
                "$role with no menu rights (signals use_menu_rights=" . var_export($flag, true) . ')');
        }
    }

    public function test_commercial_detectors_follow_the_menu_rights_the_caller_holds_and_nothing_else(): void
    {
        // A recruiter holding ONLY the invoices screen right sees the invoice detector and no other commercial detector.
        $ids = array_filter($this->detectorIds('recruiter', ['/module/crm/sales/invoices']), static fn ($id) => str_starts_with($id, 'commercial.'));
        $this->assertSame(['commercial.invoice_attention'], array_values($ids));

        // Holding the GTM deals screen opens the two detectors about deals (deal health and pipeline completeness) and nothing about invoices or renewals.
        $ids = array_filter($this->detectorIds('employee', ['/gtm/deals']), static fn ($id) => str_starts_with($id, 'commercial.'));
        $this->assertSame(['commercial.deal_health', 'commercial.pipeline_attention'], array_values($ids));
    }

    public function test_employee_only_ever_sees_own_records(): void
    {
        $snapshot = $this->snapshot($this->callerFor('employee', self::T1, self::B1, []));

        $this->assertNotEmpty($snapshot['insights']);
        $this->assertSame([self::B1 + 100], $this->owners($snapshot), 'an employee may only be pointed at their own records');
        foreach ($snapshot['insights'] as $i) {
            $this->assertSame('self', $i['audience']);
        }
    }

    public function test_reporting_manager_sees_direct_reports_only(): void
    {
        // Every detector EXCEPT onboarding is strictly direct-reports-only (see the next test for onboarding).
        $owners = $this->owners($this->snapshot($this->callerFor('reporting_manager', self::T1, self::B1, [])), null, ['onboarding_journey']);

        $this->assertNotEmpty($owners);
        $direct = $this->db()->table('tbluser')->where('sub_institute_id', self::T1)->where('reporting_manager_id', self::B1 + 10)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([], array_values(array_diff($owners, $direct)), 'only people whose reporting_manager_id is the caller');
        $this->assertNotContains($this->deepReport, $owners, 'a report of a report is not a direct report');
        $this->assertNotContains($this->otherDeptReport, $owners, "another manager's report is not visible");
        $this->assertNotContains(self::B1 + 10, $owners, 'the manager is not their own report');
    }

    /**
     * FINDING (scope wider than the D-7 default, by design of the onboarding detector): a journey is shown to its NAMED MANAGER (`manager_id`) as well as to the
     * employee's reporting manager. So a manager is pointed at a joiner who is not their direct report when an onboarding journey names them. The record is in
     * the manager's own tenant and the manager is the one the journey assigns, so it is not a leak, but it is wider than "direct reports only". Pinned here so
     * the behaviour cannot change silently; whether to narrow it is a decision (D-7).
     */
    public function test_onboarding_also_reaches_joiners_whose_journey_names_the_manager_beyond_direct_reports(): void
    {
        $owners = $this->owners($this->snapshot($this->callerFor('reporting_manager', self::T1, self::B1, [])), ['onboarding_journey']);

        $this->assertContains($this->deepReport, $owners);
        $this->assertContains($this->otherDeptReport, $owners);
        foreach ($owners as $u) {
            $this->assertSame(self::T1, (int) $this->db()->table('tbluser')->where('id', $u)->value('sub_institute_id'), 'still never another tenant');
        }
    }

    public function test_department_head_and_hr_executive_see_their_own_department_only(): void
    {
        foreach (['department_head', 'hr_executive'] as $role) {
            $owners = $this->owners($this->snapshot($this->callerFor($role, self::T1, self::B1, [])));
            $this->assertNotEmpty($owners, $role);
            $this->assertNotContains($this->otherDeptReport, $owners, "$role must not see department 4");
            foreach ($owners as $u) {
                $this->assertSame(3, (int) $this->db()->table('tbluser')->where('id', $u)->value('department_id'), "$role saw a person outside department 3");
                $this->assertSame(self::T1, (int) $this->db()->table('tbluser')->where('id', $u)->value('sub_institute_id'));
            }
        }
        // The department head DOES reach the report of a report, because that person works in the same department.
        $this->assertContains($this->deepReport, $this->owners($this->snapshot($this->callerFor('department_head', self::T1, self::B1, []))));
    }

    public function test_organisation_roles_reach_every_department_but_only_their_own_tenant(): void
    {
        foreach (['hr_manager', 'administrator'] as $role) {
            $owners = $this->owners($this->snapshot($this->callerFor($role, self::T1, self::B1, [])));
            $this->assertContains($this->otherDeptReport, $owners, "$role should reach department 4");
            foreach ($owners as $u) {
                $this->assertSame(self::T1, (int) $this->db()->table('tbluser')->where('id', $u)->value('sub_institute_id'));
            }
        }
    }

    public function test_executive_and_auditor_never_receive_any_entity_reference_from_any_detector(): void
    {
        foreach (['executive', 'auditor'] as $role) {
            $snapshot = $this->snapshot($this->callerFor($role, self::T1, self::B1));   // ALL commercial rights too
            $this->assertNotEmpty($snapshot['insights'], $role);
            $this->assertSame([], $this->entities($snapshot), "$role is aggregate-only");
        }
    }

    public function test_recruiter_sees_nothing_outside_recruitment(): void
    {
        $run = $this->service()->run($this->callerFor('recruiter', self::T1, self::B1, []));

        $this->assertSame(['hr.recruitment_funnel'], array_values(array_map(static fn ($r) => $r->detector, array_filter($run['results'], static fn ($r) => $r->state !== 'access_restricted'))));
        foreach ($run['insights'] as $i) {
            $this->assertSame('hr.recruitment_funnel', $i->detector);
        }
    }

    public function test_a_caller_without_a_department_sees_no_department_data(): void
    {
        $caller = $this->callerFor('department_head', self::T1, self::B1, [], null);
        $run = $this->service()->run($caller);

        $people = array_filter($run['insights'], static fn ($i) => $i->domain === 'hr');
        $this->assertSame([], array_values($people), 'no people insight at all when the caller belongs to no department');
        $this->assertSame([], $this->owners($this->snapshot($caller)));
    }

    public function test_a_role_that_cannot_be_resolved_is_treated_as_self_and_gets_no_people_insight(): void
    {
        $caller = $this->callerFor('wizard', self::T1, self::B1, []);

        $this->assertNull($caller->roleKey);
        $this->assertSame('self', $caller->scope());
        $this->assertFalse($caller->mayReferenceIndividuals() && $caller->aggregateOnly());
        $this->assertSame([], $this->service()->run($caller)['insights'], 'least privilege: an unresolvable role is shown nothing without an explicit right');
    }
    public function test_every_detector_a_role_does_not_run_is_listed_restricted_and_the_two_sets_partition_the_registry(): void
    {
        $all = array_map(static fn ($d) => $d->id(), $this->detectors());
        sort($all);
        foreach (self::ROLES as $role) {
            $ran = $this->detectorIds($role);
            $restricted = $this->restrictedIds($role);
            $this->assertSame([], array_values(array_intersect($ran, $restricted)), $role);
            $merged = array_merge($ran, $restricted);
            sort($merged);
            $this->assertSame($all, $merged, "$role: every detector is either run or listed as access_restricted, none is hidden");
        }
    }

    public function test_the_platform_detectors_follow_exactly_the_menu_rights_the_caller_holds_even_for_an_administrator(): void
    {
        $platform = static fn (array $ids) => array_values(array_filter($ids, static fn ($id) => str_starts_with($id, 'platform.') || str_starts_with($id, 'agentic.')));

        // No rights: no platform or agentic detector runs, whatever the role (there is no administrator bypass).
        foreach (['administrator', 'hr_manager', 'executive', 'employee'] as $role) {
            $this->assertSame([], $platform($this->detectorIds($role, [])), $role);
        }
        $this->assertSame(['platform.event_delivery'], $platform($this->detectorIds('employee', ['/platform-services/event-bus'])));
        $this->assertSame(['platform.ai_usage'], $platform($this->detectorIds('employee', ['/ai/usage-cost'])));
        $this->assertSame(['platform.governance'], $platform($this->detectorIds('employee', ['/ai/policies'])));
        $this->assertSame(['agentic.run_health'], $platform($this->detectorIds('employee', ['/module/agentic-ai/analytics'])));
        $this->assertSame(['platform.integration_status', 'platform.scheduler_reliability'], $platform($this->detectorIds('recruiter', ['/platform-services/workflow?module=crm'])));
        // The Event bus row does NOT open the scheduler or integrations, and the module row does NOT open the event bus.
        $this->assertNotContains('platform.scheduler_reliability', $platform($this->detectorIds('administrator', ['/platform-services/event-bus'])));
        $this->assertNotContains('platform.event_delivery', $platform($this->detectorIds('administrator', ['/platform-services/workflow?module=hrms'])));
    }

    public function test_executive_and_auditor_receive_platform_insights_without_any_record_reference(): void
    {
        foreach (['executive', 'auditor'] as $role) {
            $snapshot = $this->snapshot($this->callerFor($role, self::T1, self::B1));
            $platform = array_filter($snapshot['insights'], static fn ($i) => $i['domain'] === 'platform');
            $this->assertNotEmpty($platform, "$role must receive platform insights, otherwise the check below proves nothing");
            foreach ($platform as $i) {
                $this->assertSame([], array_filter($i['evidence'], static fn ($e) => $e['kind'] === 'entity'), "$role / {$i['key']}");
            }
        }
    }
}