<?php

namespace Tests\Unit\Intelligence;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Access\IntelligenceDenied;
use App\Domain\Intelligence\Access\RightsChecker;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\Support\ActiveEmployees;
use App\Domain\Intelligence\Support\PeopleScope;
use App\Domain\Intelligence\Support\SmallGroupGuard;
use App\Domain\Intelligence\Support\Thresholds;
use App\Domain\Intelligence\Taxonomy;
use InvalidArgumentException;
use Tests\Intelligence\IntelligenceTestCase;

/** The shared contract: value objects, the fail-closed caller, the active-employee rule, people scope and the small-group rule. */
class ContractsTest extends IntelligenceTestCase
{
    private function rights(array $granted = []): RightsChecker
    {
        return new class($granted) implements RightsChecker {
            public function __construct(private array $granted)
            {
            }

            public function can(int $profileId, ?int $tenantId, string $accessLink, string $action = 'view'): bool
            {
                return in_array($accessLink, $this->granted, true);
            }
        };
    }

    private function caller(string $role, int $tenant = 1, int $user = 10, ?int $dept = 3, array $granted = []): IntelligenceCaller
    {
        return new IntelligenceCaller($tenant, $user, 7, $role, $dept, $this->rights($granted));
    }

    private function insight(array $override = []): Insight
    {
        $args = array_merge([
            'key' => 'k', 'detector' => 'hr.x', 'domain' => 'hr', 'category' => 'c', 'severity' => 'attention', 'title' => 't', 'whatHappened' => 'w',
            'whyItMatters' => 'y', 'evidence' => [Evidence::metric('n', 3)], 'coverage' => Coverage::of(3, 5, 'b', 1), 'asOf' => '2026-11-21T00:00:00Z',
            'suggestedAction' => 'a', 'deepLink' => '/dashboard', 'confidence' => 'measured', 'audience' => 'organization',
        ], $override);

        return new Insight(...$args);
    }

    public function test_insight_serialises_without_population_and_marks_itself_provisional(): void
    {
        $array = $this->insight(['population' => 4])->toArray();

        $this->assertArrayNotHasKey('population', $array);
        $this->assertTrue($array['provisional']);
        $this->assertSame('open', $array['status']);
    }

    public function test_insight_rejects_missing_evidence_unknown_values_and_external_links(): void
    {
        foreach ([['evidence' => []], ['severity' => 'catastrophic'], ['confidence' => 'guess'], ['domain' => 'payroll'], ['deepLink' => 'https://evil.example/x'], ['deepLink' => '//evil.example'], ['surfaces' => ['nowhere']]] as $bad) {
            try {
                $this->insight($bad);
                $this->fail('Expected rejection for ' . json_encode(array_keys($bad)));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid insight', $e->getMessage());
            }
        }
    }

    public function test_coverage_percent_is_null_when_expectation_unknown_and_capped_at_100(): void
    {
        $this->assertNull(Coverage::of(5, null, 'b', 1)->percent());
        $this->assertSame(100.0, Coverage::of(9, 6, 'b', 1)->percent());
        $this->assertFalse(Coverage::of(2, 10, 'b', 5)->sufficient);
        $this->assertNotNull(Coverage::of(2, 10, 'b', 5)->reason);
    }

    public function test_caller_fails_closed_without_an_organisation(): void
    {
        foreach ([[0, 5], [-1, 5], [1, 0]] as [$tenant, $user]) {
            try {
                new IntelligenceCaller($tenant, $user, 7, 'administrator', null, $this->rights());
                $this->fail('A caller without an organisation or a user must be refused.');
            } catch (IntelligenceDenied) {
                $this->assertTrue(true);
            }
        }

        $this->expectException(IntelligenceDenied::class);
        IntelligenceCaller::fromUser((object) ['id' => 5, 'sub_institute_id' => null, 'user_profile_id' => 7], 'administrator', $this->rights());
    }

    public function test_scope_follows_role_and_unknown_roles_get_least_privilege(): void
    {
        $this->assertSame('self', $this->caller('employee')->scope());
        $this->assertSame('team', $this->caller('reporting_manager')->scope());
        $this->assertSame('department', $this->caller('department_head')->scope());
        $this->assertSame('department', $this->caller('hr_executive')->scope());
        $this->assertSame('organization', $this->caller('hr_manager')->scope());
        $this->assertSame('self', (new IntelligenceCaller(1, 10, 7, null, null, $this->rights()))->scope());
        $this->assertSame('self', (new IntelligenceCaller(1, 10, 7, 'platform_owner_unknown', null, $this->rights()))->scope());
    }

    public function test_executive_and_auditor_are_aggregate_only(): void
    {
        $this->assertTrue($this->caller('executive')->aggregateOnly());
        $this->assertFalse($this->caller('executive')->mayReferenceIndividuals());
        $this->assertFalse($this->caller('auditor')->mayReferenceIndividuals());
        $this->assertTrue($this->caller('hr_manager')->mayReferenceIndividuals());
    }

    public function test_can_any_reads_only_the_listed_links_and_never_for_an_empty_list(): void
    {
        $c = $this->caller('administrator', granted: ['/gtm/deals']);

        $this->assertTrue($c->canAny(['/x', '/gtm/deals']));
        $this->assertFalse($c->canAny(['/x']));
        $this->assertFalse($c->canAny([]));
    }

    public function test_active_employees_applies_the_canonical_rule_and_the_tenant(): void
    {
        $this->createPeopleTables();
        $this->insertUser(1, 1);                                              // active
        $this->insertUser(2, 1, ['status' => 0]);                             // inactive
        $this->insertUser(3, 1, ['status' => null]);                          // NULL status is NOT active
        $this->insertUser(4, 1, ['terminated_date' => '2026-01-01']);         // terminated
        $this->insertUser(5, 1, ['deleted_at' => '2026-01-01 00:00:00']);     // soft-deleted
        $this->insertUser(6, 2);                                              // another tenant
        $this->insertUser(7, null);                                           // no tenant

        $ids = ActiveEmployees::query($this->db(), 1)->orderBy('tbluser.id')->pluck('tbluser.id')->all();

        $this->assertSame([1], $ids);
    }

    public function test_people_scope_per_role_and_fail_closed_department(): void
    {
        $this->createPeopleTables();
        $this->insertUser(10, 1, ['department_id' => 3]);                                   // the caller
        $this->insertUser(11, 1, ['reporting_manager_id' => 10, 'department_id' => 3]);     // direct report
        $this->insertUser(12, 1, ['reporting_manager_id' => 11, 'department_id' => 3]);     // report of a report (NOT in team scope)
        $this->insertUser(13, 1, ['department_id' => 4]);                                   // other department
        $this->insertUser(14, 2, ['reporting_manager_id' => 10, 'department_id' => 3]);     // another tenant, same manager id
        $this->insertUser(15, 1, ['status' => 0, 'reporting_manager_id' => 10]);            // inactive direct report

        $ids = fn (IntelligenceCaller $c) => PeopleScope::userIds($this->db(), $c)->orderBy('tbluser.id')->pluck('tbluser.id')->all();

        $this->assertSame([10], $ids($this->caller('employee')));
        $this->assertSame([11], $ids($this->caller('reporting_manager')));
        $this->assertSame([10, 11, 12], $ids($this->caller('department_head')));
        $this->assertSame([10, 11, 12, 13], $ids($this->caller('hr_manager')));
        $this->assertSame([], $ids($this->caller('department_head', dept: null)), 'a caller with no department sees nobody');
        $this->assertSame(1, PeopleScope::count($this->db(), $this->caller('reporting_manager')));
    }

    public function test_small_group_rule_applies_to_team_department_and_aggregate_only_but_not_to_self_or_hr_org_scope(): void
    {
        $guard = new SmallGroupGuard(5);

        $this->assertTrue($guard->suppresses($this->caller('reporting_manager'), 4));
        $this->assertFalse($guard->suppresses($this->caller('reporting_manager'), 5));
        $this->assertTrue($guard->suppresses($this->caller('department_head'), 1));
        $this->assertTrue($guard->suppresses($this->caller('executive'), 3));
        $this->assertTrue($guard->suppresses($this->caller('auditor'), 3));
        $this->assertFalse($guard->suppresses($this->caller('employee'), 1), 'own data is never suppressed');
        $this->assertFalse($guard->suppresses($this->caller('hr_manager'), 1), 'HR may open the individual records anyway');
        $this->assertFalse($guard->suppresses($this->caller('reporting_manager'), null), 'an insight not about people is never suppressed');
    }

    public function test_thresholds_are_provisional_defaults_and_ignore_unknown_or_non_numeric_overrides(): void
    {
        $t = Thresholds::with(['min_group_size' => 8, 'nope' => 3, 'deals_quiet_days' => 'abc']);

        $this->assertSame(8, $t->int('min_group_size'));
        $this->assertSame(14, $t->int('deals_quiet_days'));
        $this->assertSame(5, Thresholds::defaults()->int('min_group_size'));
        $this->assertSame(['info', 'attention', 'high', 'critical'], Taxonomy::SEVERITIES);
    }

    public function test_thresholds_cannot_be_configured_into_disabling_privacy_or_sufficiency_gates(): void
    {
        $t = Thresholds::with([
            'min_group_size' => '',              // an unset environment variable
            'attendance_min_employees' => '0',   // a typo / deliberate zero
            'signals_min_runs' => -4,            // negative
            'deals_min_open' => 'abc',
            'invoices_min' => 0,
        ]);

        $this->assertSame(5, $t->int('min_group_size'), 'empty is ignored, so the default stays');
        $this->assertSame(Thresholds::MIN_GROUP_SIZE_FLOOR, Thresholds::with(['min_group_size' => 1])->int('min_group_size'));
        $this->assertSame(3, Thresholds::with(['min_group_size' => 0])->int('min_group_size'));
        $this->assertSame(1, $t->int('attendance_min_employees'), 'a minimum-records gate is never below 1');
        $this->assertSame(Thresholds::DEFAULTS['signals_min_runs'], $t->int('signals_min_runs'));
        $this->assertSame(Thresholds::DEFAULTS['deals_min_open'], $t->int('deals_min_open'));
        $this->assertSame(1, $t->int('invoices_min'));
        $this->assertSame(8, Thresholds::with(['min_group_size' => 8])->int('min_group_size'));
    }

    public function test_the_isolated_environment_is_in_memory_sqlite(): void
    {
        $this->assertSame('sqlite', $this->app['config']->get('database.default'));
        $this->assertSame(':memory:', $this->app['config']->get('database.connections.sqlite.database'));
        $this->assertSame('sqlite', $this->db()->getDriverName());
    }
}
