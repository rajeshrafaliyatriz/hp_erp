<?php

namespace Tests\Unit\Intelligence\Adversarial;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 1. TENANT ISOLATION MATRIX over all 23 detectors, every role, both directions.
 *
 * The property tested is NON-INTERFERENCE: what a caller of tenant A is told must be byte-for-byte the same whether or not tenant B's data exists in the same
 * database, even when B's rows are built to collide with A's (same department number, same account numbers, B's people reporting to A's manager id, B's
 * activity/steps/certificates/attendance naming A's deal, leave and user ids). A leak that only shows up when the two tenants overlap cannot hide from it.
 *
 * A baseline that is empty would make equality meaningless, so each case also asserts the caller actually received something.
 */
class TenantIsolationMatrixTest extends AdversarialTestCase
{
    /** @return iterable<string, array{string, int, int, int, int}> */
    public static function matrix(): iterable
    {
        foreach (self::ROLES as $role) {
            yield "$role as 71, other tenant 92" => [$role, self::T1, self::B1, self::T2, self::B2];
            yield "$role as 92, other tenant 71" => [$role, self::T2, self::B2, self::T1, self::B1];
        }
    }

    #[DataProvider('matrix')]
    public function test_the_other_tenants_data_changes_nothing_a_caller_is_told(string $role, int $tenant, int $base, int $other, int $otherBase): void
    {
        $world = $this->seedWorld($tenant, $base);
        $caller = $this->callerFor($role, $tenant, $base);

        $alone = $this->snapshot($caller);
        $this->assertNotEmpty($alone['insights'], "$role must receive insights from its own tenant, otherwise this test proves nothing");

        // The other tenant arrives: its people report to THIS tenant's manager id, it uses the same department number and account numbers, its rows name this
        // tenant's leave/deal/user ids, and its values are unmistakable if they leak.
        $this->seedWorld($other, $otherBase, ['manager' => $base + 10, 'sentinel' => true]);
        $this->seedCrossReferences($other, $world, $tenant);

        $crowded = $this->snapshot($caller);

        $this->assertSame($alone, $crowded, "$role: another tenant's rows changed this caller's answer");

        $json = json_encode($crowded);
        $this->assertStringNotContainsString('ZZZ', $json, 'the other tenant\'s currency leaked');
        $this->assertStringNotContainsString('987654', $json, 'the other tenant\'s amount leaked');
    }

    #[DataProvider('matrix')]
    public function test_every_entity_reference_belongs_to_the_callers_own_tenant(string $role, int $tenant, int $base, int $other, int $otherBase): void
    {
        $world = $this->seedWorld($tenant, $base);
        $this->seedWorld($other, $otherBase, ['manager' => $base + 10, 'sentinel' => true]);
        $this->seedCrossReferences($other, $world, $tenant);

        // type => [table, whether the table carries the tenant column]. `gtm_account` ids have no table in the fixtures (accounts are identified by number
        // only); they are checked against the tenant's own renewals / health snapshots instead.
        $tables = [
            'certification' => 's_competency_certifications', 'lms_certificate' => 'lms_certificates', 'leave_request' => 'hrms_emp_leaves',
            'onboarding_journey' => 'talent_onboarding_journeys', 'job_application' => 'talent_job_applications', 'job_offer' => 'talent_offers',
            'gtm_deal' => 'gtm_deals', 'gtm_renewal' => 'gtm_renewals', 'crm_opportunity' => 'crm_opportunities', 'crm_invoice' => 'crm_invoices',
            // the four central detectors (organisation structure, tasks, learning, capability)
            'employee' => 'tbluser', 'department' => 'hrms_departments', 'task' => 'task', 'learning_assignment' => 'lms_assignments', 'course_enrolment' => 'lms_course_enroll',
            'development_plan' => 's_competency_development_plans', 'competency_assessment' => 's_competency_assessments',
            // the six platform / AI governance / agentic detectors
            'event' => 'g2g_event', 'ai_policy' => 'ai_policies', 'agentic_agent' => 'agentic_agents',
        ];

        $snapshot = $this->snapshot($this->callerFor($role, $tenant, $base));
        $entities = $this->entities($snapshot);
        if (in_array($role, ['executive', 'auditor'], true)) {
            $this->assertSame([], $entities, "$role is aggregate-only and must receive no record references");
        } else {
            $this->assertNotEmpty($entities, "$role should receive record references, otherwise the tenant check below proves nothing");
        }
        foreach ($entities as [$type, $id]) {
            if ($type === 'gtm_account') {
                $known = $this->db()->table('gtm_health_snapshots')->where('sub_institute_id', $tenant)->where('account_id', $id)->exists()
                    || $this->db()->table('gtm_renewals')->where('sub_institute_id', $tenant)->where('account_id', $id)->exists();
                $this->assertTrue($known, "$role: account $id referenced but unknown to tenant $tenant");

                continue;
            }
            $this->assertArrayHasKey($type, $tables, "unexpected entity type $type");
            $row = $this->db()->table($tables[$type])->where('id', $id)->first();
            $this->assertNotNull($row, "$role: $type $id does not exist");
            $this->assertSame($tenant, (int) $row->sub_institute_id, "$role: $type $id belongs to another tenant");
        }
    }

    public function test_the_hostile_data_really_is_hostile(): void
    {
        // CONTROL: without this, a passing matrix could just mean the other tenant's rows never overlapped with the caller's.
        $world = $this->seedWorld(self::T1, self::B1);
        $this->seedWorld(self::T2, self::B2, ['manager' => self::B1 + 10, 'sentinel' => true]);
        $this->seedCrossReferences(self::T2, $world, self::T1);
        $db = $this->db();

        $this->assertSame(2, $db->table('tbluser')->where('reporting_manager_id', self::B1 + 10)->distinct()->count('sub_institute_id'), 'one manager id is referenced from both tenants');
        $this->assertGreaterThan(0, $db->table('gtm_activities')->where('sub_institute_id', self::T2)->whereIn('deal_id', $world['deals'])->count(), "the other tenant logs activity on this tenant's deals");
        $this->assertTrue($db->table('gtm_renewals')->where('sub_institute_id', self::T1)->whereIn('account_id', [1, 2, 3])->exists() && $db->table('gtm_renewals')->where('sub_institute_id', self::T2)->whereIn('account_id', [1, 2, 3])->exists(), 'the same account numbers exist in both tenants');
        $this->assertGreaterThan(0, $db->table('s_competency_certifications')->where('sub_institute_id', self::T2)->whereIn('user_id', $world['people'])->count(), "the other tenant holds certificates for this tenant's people");
        $this->assertGreaterThan(0, $db->table('hrms_leave_approval_steps')->where('sub_institute_id', self::T2)->whereIn('leave_id', $world['leaves'])->count(), "the other tenant has approval steps on this tenant's leaves");
        $this->assertSame(2, $db->table('tbluser')->where('department_id', 3)->distinct()->count('sub_institute_id'), 'the same department number is used by both tenants');
    }

    public function test_all_detectors_are_exercised_by_the_matrix(): void
    {
        $world = $this->seedWorld(self::T1, self::B1);
        $this->assertNotEmpty($world['people']);
        $ids = [];
        foreach (self::ROLES as $role) {
            foreach ($this->service()->run($this->callerFor($role, self::T1, self::B1))['results'] as $result) {
                if ($result->state !== 'access_restricted') {
                    $ids[$result->detector] = true;
                }
            }
        }
        $this->assertCount(23, $this->detectors());
        $this->assertSame(23, count($ids), 'across the ten roles every one of the 23 detectors must have been authorised and run at least once: ' . implode(',', array_keys($ids)));
    }
}
