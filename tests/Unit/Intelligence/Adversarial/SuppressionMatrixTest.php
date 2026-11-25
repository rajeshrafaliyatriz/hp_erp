<?php

namespace Tests\Unit\Intelligence\Adversarial;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 9. SMALL-GROUP SUPPRESSION (decision D-8 default: minimum 5, provisional), end to end through the real detectors and the real service.
 *
 * A tenant of 12 people reporting to one manager, all in department 3, each holding one certificate; N of them have an expired one. The certificate insight is
 * "drawn from N people". Who may be told, for N below, at and above the minimum?
 *
 *   team / department scope and the aggregate-only roles (executive, auditor): NOT told when N < 5; told when N >= 5.
 *   self, and HR / administrators at organisation scope:                       always told (their own data / they may open the records anyway).
 * A withheld insight is only COUNTED: neither its title, its numbers nor its key may appear anywhere in what the caller receives.
 */
class SuppressionMatrixTest extends AdversarialTestCase
{
    private const WITHHELD_TITLE = 'Certifications have expired';

    private function seedCertificates(int $lapsedHolders): void
    {
        $t = self::T1;
        $this->insertUser(self::B1 + 10, $t, ['department_id' => 3, 'user_profile_id' => 7]);
        foreach ($this->people(12, $t, self::B1 + 100, self::B1 + 10, 3) as $i => $p) {
            $this->insertRow('s_competency_certifications', ['sub_institute_id' => $t, 'user_id' => $p, 'status' => 'valid', 'expiry_date' => $i < $lapsedHolders ? '2026-10-01' : '2027-09-01']);
        }
    }

    /** @return array{shown: bool, suppressed: int, json: string} */
    private function lapsedAs(string $role): array
    {
        // Narrowed to the certification detector: the figure under test is ITS withheld insight. Other detectors (e.g. organisation structure, which also
        // reaches the HR executive and the aggregate-only roles) have their own suppressed insights, which would change the count this test asserts.
        $run = $this->service()->run($this->callerFor($role, self::T1, self::B1, []), ['detector' => 'hr.certification_expiry']);
        $shown = array_values(array_filter($run['insights'], static fn ($i) => $i->category === 'certification_lapsed'));
        $json = json_encode(['i' => array_map(fn ($i) => $i->toArray(), $run['insights']), 'r' => array_map(fn ($r) => $r->summary(), $run['results']), 'm' => $run['meta']]);

        return ['shown' => $shown !== [], 'suppressed' => $run['suppressed'], 'json' => $json, 'meta' => $run['meta']];
    }

    /** @return iterable<string, array{int, array<string, bool>}> */
    public static function thresholdCases(): iterable
    {
        $subject = ['reporting_manager', 'department_head', 'hr_executive', 'executive', 'auditor'];
        $entitled = ['hr_manager', 'administrator'];
        foreach ([[3, false], [4, false], [5, true], [8, true]] as [$n, $shownToSubject]) {
            $expect = [];
            foreach ($subject as $r) {
                $expect[$r] = $shownToSubject;
            }
            foreach ($entitled as $r) {
                $expect[$r] = true;
            }
            yield "$n people with an expired certificate" => [$n, $expect];
        }
    }

    #[DataProvider('thresholdCases')]
    public function test_the_minimum_group_size_decides_who_is_told(int $lapsedHolders, array $expectShown): void
    {
        $this->seedCertificates($lapsedHolders);

        foreach ($expectShown as $role => $shown) {
            $r = $this->lapsedAs($role);
            $this->assertSame($shown, $r['shown'], "$role with $lapsedHolders holders: " . ($shown ? 'should be told' : 'must not be told'));
            if (! $shown) {
                $this->assertSame(1, $r['suppressed'], "$role: the withheld insight is counted internally, once");
                // A caller under the rule is not told THAT something was withheld, nor how many: only a fixed sentence.
                $this->assertNull($r['meta']['suppressed_count'], "$role: the number withheld must not be disclosed");
                $this->assertSame(\App\Domain\Intelligence\InsightService::SUPPRESSION_NOTE, $r['meta']['suppression_note']);
                $this->assertStringNotContainsString('"suppressed_count":1', $r['json']);
                $this->assertStringNotContainsString(self::WITHHELD_TITLE, $r['json'], "$role: the withheld title is in the response");
                $this->assertStringNotContainsString('certification_lapsed', $r['json'], "$role: the withheld category is in the response");
                $this->assertStringNotContainsString(':lapsed:', $r['json'], "$role: the withheld key is in the response");
                $this->assertStringNotContainsString("$lapsedHolders person", $r['json'], "$role: the size of the group is in the response");
            } else {
                $this->assertSame(0, $r['suppressed'], $role);
                $this->assertNull($r['meta']['suppression_note'], "$role: nothing was withheld, so no note");
            }
        }
    }

    public function test_self_is_never_suppressed_even_for_a_group_of_one(): void
    {
        $this->seedCertificates(3);   // person B1+100 is one of the 3 expired holders
        $own = $this->service()->run($this->callerFor('employee', self::T1, self::B1, []));
        $this->assertSame(['certification_lapsed'], array_values(array_map(fn ($i) => $i->category, array_filter($own['insights'], fn ($i) => $i->detector === 'hr.certification_expiry' && $i->category === 'certification_lapsed'))));
        $this->assertSame(0, $own['suppressed']);
    }

    public function test_hr_at_organisation_scope_is_told_even_about_one_person(): void
    {
        $this->seedCertificates(1);
        foreach (['hr_manager', 'administrator'] as $role) {
            $r = $this->lapsedAs($role);
            $this->assertTrue($r['shown'], $role);
            $this->assertSame(0, $r['suppressed'], $role);
        }
    }

    public function test_insights_that_are_not_about_people_are_never_suppressed(): void
    {
        $this->seedWorld(self::T1, self::B1);
        foreach (['reporting_manager', 'department_head', 'hr_executive', 'executive', 'auditor'] as $role) {
            $run = $this->service()->run($this->callerFor($role, self::T1, self::B1));
            $commercial = array_filter($run['insights'], fn ($i) => $i->domain === 'commercial');
            $this->assertNotEmpty($commercial, "$role holds the commercial rights and must still see deals, invoices and signals however small the people group");
        }
    }

    public function test_every_withheld_insight_is_counted_across_detectors_and_nothing_else_is(): void
    {
        $this->seedWorld(self::T1, self::B1);   // cert holders: lapsed 4, due 3, soon 2: all below 5 for a team
        $manager = $this->service()->run($this->callerFor('reporting_manager', self::T1, self::B1, []));
        $hr = $this->service()->run($this->callerFor('hr_manager', self::T1, self::B1, []));

        $withheldKeys = array_diff(array_map(fn ($i) => $i->key, $hr['insights']), array_map(fn ($i) => $i->key, $manager['insights']));
        $this->assertNotEmpty($withheldKeys);
        $this->assertNull($manager['meta']['suppressed_count'], 'a team manager is not told how many were withheld');
        $this->assertSame(\App\Domain\Intelligence\InsightService::SUPPRESSION_NOTE, $manager['meta']['suppression_note']);
        $this->assertSame($hr['suppressed'], $hr['meta']['suppressed_count'], 'HR at organisation scope is not under the rule and sees the plain count (0)');
        // Everything the manager lacks compared with HR is either suppressed or simply a different scope (different key): the suppressed count never exceeds
        // what was actually withheld from the manager's own scope.
        $this->assertGreaterThanOrEqual(1, $manager['suppressed']);
        foreach ($manager['insights'] as $i) {
            $this->assertNotContains($i->category, ['certification_lapsed', 'certification_due', 'certification_soon'], 'groups of 4, 3 and 2 people are all below the minimum');
        }
    }

    public function test_the_minimum_can_be_raised_but_never_lowered_below_the_floor(): void
    {
        // The thresholds object is the only way to configure the rule. A raised value is honoured; an attempt to switch the protection off is not.
        $this->seedCertificates(6);
        $manager = $this->callerFor('reporting_manager', self::T1, self::B1, []);
        $lapsed = fn (array $run) => array_values(array_filter($run['insights'], fn ($i) => $i->category === 'certification_lapsed'));

        $this->assertSame([], $lapsed($this->service(\App\Domain\Intelligence\Support\Thresholds::with(['min_group_size' => 8]))->run($manager)), '6 holders are below a raised minimum of 8');
        $this->assertNotEmpty($lapsed($this->service()->run($manager)), '6 holders reach the default minimum of 5');

        foreach ([0, 1, -5, '', 'abc'] as $attempt) {
            $effective = \App\Domain\Intelligence\Support\SmallGroupGuard::fromThresholds(\App\Domain\Intelligence\Support\Thresholds::with(['min_group_size' => $attempt]))->minimum();
            $this->assertGreaterThanOrEqual(3, $effective, 'min_group_size=' . var_export($attempt, true) . ' must not switch the small-group rule off');
        }
    }

    public function test_a_group_of_two_stays_hidden_even_when_the_minimum_is_configured_to_zero(): void
    {
        $this->seedCertificates(2);
        $service = $this->service(\App\Domain\Intelligence\Support\Thresholds::with(['min_group_size' => 0]));

        $run = $service->run($this->callerFor('reporting_manager', self::T1, self::B1, []));

        $this->assertSame([], array_values(array_filter($run['insights'], fn ($i) => $i->category === 'certification_lapsed')));
        $this->assertSame(1, $run['suppressed']);
    }

    public function test_coverage_numbers_of_a_small_group_are_hidden_from_callers_under_the_rule_but_not_from_hr(): void
    {
        $t = self::T1;
        $this->insertUser(self::B1 + 10, $t, ['department_id' => 3, 'user_profile_id' => 7]);
        $this->people(2, $t, self::B1 + 100, self::B1 + 10, 3);   // a team of 2: attendance is insufficient ("Only 2 people are in scope ...")

        $team = $this->callerFor('reporting_manager', $t, self::B1, []);
        $hr = $this->callerFor('hr_manager', $t, self::B1, []);
        $service = $this->service();
        $forTeam = $service->detectorSummaries($team, $service->run($team)['results']);
        $forHr = $service->detectorSummaries($hr, $service->run($hr)['results']);

        $attendance = fn (array $rows) => array_values(array_filter($rows, fn ($r) => $r['detector'] === 'hr.attendance_health'))[0];
        $this->assertNull($attendance($forTeam)['coverage']['observed']);
        $this->assertNull($attendance($forTeam)['coverage']['expected']);
        $this->assertSame(\App\Domain\Intelligence\InsightService::GENERIC_COVERAGE_REASON, $attendance($forTeam)['reason']);
        $this->assertStringNotContainsString('Only 2', json_encode($forTeam), 'the size of the small team must not appear');

        $this->assertSame(3, $attendance($forHr)['coverage']['expected'] ?? null, 'HR keeps the exact numbers (the organisation has 3 active people: the manager and two reports)');
    }
}
