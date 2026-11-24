<?php

namespace App\Domain\Intelligence\Detectors\Hr;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Support\PeopleScope;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Database\Query\Builder;

/**
 * ATTENDANCE HEALTH: how complete the attendance record is for the people the caller may see.
 *
 * It measures only what the data supports:
 *   participation  - people in scope with at least one attendance row in the window / people in scope
 *   missing punch-out - rows of PAST days with no punch-out (today is excluded: a person still at work has none yet)
 *   duplicates     - user-days with more than one row (the table has no unique (user, day))
 *
 * It deliberately does NOT say who was late or absent. "Late" needs a verified roster rule and "absent" is derived (expected working days minus present
 * minus leave), not stored; reporting either from raw punches would be an invention. It also never lists people: the figures are aggregates.
 *
 * Source: hrms_attendances (user_id, day, punchin_time, punchout_time, sub_institute_id, deleted_at). Thresholds attendance_* (PROVISIONAL, P-1/P-2).
 */
final class AttendanceHealthDetector extends AbstractHrDetector
{
    private const DEEP_LINK = '/dashboard/attendance';

    public function id(): string
    {
        return 'hr.attendance_health';
    }

    public function title(): string
    {
        return 'Attendance record health';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_HR_DASHBOARD, Taxonomy::SURFACE_ATTENDANCE];
    }

    /** A person's own attendance completeness is not an insight (one person, no group): managers and above only. */
    public function authorizes(IntelligenceCaller $caller): bool
    {
        return $caller->hasRole('reporting_manager', 'department_head', 'hr_executive', 'hr_manager', 'administrator', 'executive', 'auditor');
    }

    protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $asOf = $clock->asOf();
        if (! $this->canRead('hrms_attendances', ['user_id', 'day', 'punchout_time', 'sub_institute_id', 'deleted_at'])) {
            return DetectorResult::dataUnavailable($this, $asOf, 'Attendance records are not available for this organisation.');
        }

        $window = $this->thresholds->int('attendance_window_days');
        $start = $clock->daysAgo($window);
        $people = PeopleScope::count($this->db, $caller);

        $base = fn (): Builder => $this->db->table('hrms_attendances as a')
            ->where('a.sub_institute_id', $caller->tenantId)
            ->whereNull('a.deleted_at')
            ->where('a.day', '>=', $start)
            ->where('a.day', '<=', $clock->today()) // a future-dated row is not recorded attendance
            ->whereIn('a.user_id', PeopleScope::userIds($this->db, $caller));

        $all = $base()->selectRaw('COUNT(*) AS rows_n, COUNT(DISTINCT a.user_id) AS people_with, COUNT(DISTINCT a.day) AS days_n')->first();
        $rows = (int) ($all->rows_n ?? 0);
        $peopleWith = (int) ($all->people_with ?? 0);
        $days = (int) ($all->days_n ?? 0);

        $minPeople = $this->thresholds->int('attendance_min_employees');
        $minDays = $this->thresholds->int('attendance_min_days');
        $basis = sprintf('people in scope with at least one attendance row in the last %d days', $window);

        if ($people < $minPeople) {
            return DetectorResult::insufficient($this, new Coverage($peopleWith, $people, $basis, false,
                sprintf('Only %d people are in scope; at least %d are needed before attendance is assessed.', $people, $minPeople)), $asOf);
        }
        if ($days < $minDays) {
            return DetectorResult::insufficient($this, new Coverage($peopleWith, $people, $basis, false,
                sprintf('Attendance was recorded on only %d day(s) in the last %d; at least %d are needed.', $days, $window, $minDays)), $asOf);
        }

        $coverage = new Coverage($peopleWith, $people, $basis, true, null);
        $closed = $base()->where('a.day', '<', $clock->today())
            ->selectRaw('COUNT(*) AS closed_rows, SUM(CASE WHEN a.punchout_time IS NULL THEN 1 ELSE 0 END) AS missing,'
                . ' COUNT(DISTINCT CASE WHEN a.punchout_time IS NULL THEN a.user_id END) AS missing_people')->first();
        $closedRows = (int) ($closed->closed_rows ?? 0);
        $missing = (int) ($closed->missing ?? 0);
        // POPULATION = the people the FINDING is about (those with a missing punch-out / those in a duplicate group), never everyone who has any row:
        // "one person with duplicate punches in a team of 12" is a finding about one person, and the small-group rule must see 1, not 12.
        $missingPeople = (int) ($closed->missing_people ?? 0);

        $perDay = $base()->selectRaw('a.user_id, a.day, COUNT(*) AS c')->groupBy('a.user_id', 'a.day');
        $dup = $this->db->table('hrms_attendances')->fromSub($perDay, 'd')->where('d.c', '>', 1)
            ->selectRaw('COUNT(*) AS dup_days, COALESCE(SUM(d.c - 1), 0) AS surplus, COUNT(DISTINCT d.user_id) AS dup_people')->first();
        $dupPeople = (int) ($dup->dup_people ?? 0);
        $dupDays = (int) ($dup->dup_days ?? 0);
        $surplus = (int) ($dup->surplus ?? 0);

        $window_ev = Evidence::window('Period reviewed', $start, $clock->today());
        $insights = [];

        $participation = $this->pct($peopleWith, $people);
        if ($participation < $this->thresholds->float('attendance_participation_pct')) {
            $insights[] = new Insight(
                key: $this->keyFor('participation', $caller, $clock),
                detector: $this->id(), domain: $this->domain(), category: 'attendance_participation',
                severity: $participation < 40 ? 'high' : 'attention',
                title: 'Attendance is recorded for only part of the group',
                whatHappened: sprintf('%d of %d people (%s%%) have any attendance recorded in the last %d days.', $peopleWith, $people, $participation, $window),
                whyItMatters: 'Attendance and leave figures built on an incomplete record understate absence and cannot be trusted for planning.',
                evidence: [
                    Evidence::metric('People in scope', $people, 'people', 'users'),
                    Evidence::metric('People with attendance recorded', $peopleWith, 'people', 'hrms_attendances'),
                    Evidence::metric('Share with attendance recorded', $participation, '%', 'hrms_attendances'),
                    $window_ev,
                ],
                coverage: $coverage, asOf: $asOf,
                suggestedAction: 'Check whether the people without attendance are expected to punch in, and whether punching is set up for them.',
                deepLink: self::DEEP_LINK, confidence: Taxonomy::CONFIDENCE_RULE_BASED, audience: $caller->scope(),
                surfaces: $this->surfaces(), population: $people,
            );
        }

        $missingPct = $this->pct($missing, $closedRows);
        if ($closedRows > 0 && $missingPct >= $this->thresholds->float('attendance_missing_punchout_pct')) {
            $insights[] = new Insight(
                key: $this->keyFor('missing_punchout', $caller, $clock),
                detector: $this->id(), domain: $this->domain(), category: 'attendance_missing_punch_out',
                severity: $missingPct >= 30 ? 'high' : 'attention',
                title: 'Many attendance records have no punch-out',
                whatHappened: sprintf('%d of %d attendance records for past days (%s%%) have no punch-out time.', $missing, $closedRows, $missingPct),
                whyItMatters: 'Hours worked cannot be calculated from a record with no punch-out, so working-time and overtime figures are unreliable.',
                evidence: [
                    Evidence::metric('Past-day records with no punch-out', $missing, 'records', 'hrms_attendances'),
                    Evidence::metric('Past-day records reviewed', $closedRows, 'records', 'hrms_attendances'),
                    Evidence::metric('Share without punch-out', $missingPct, '%', 'hrms_attendances'),
                    $window_ev,
                ],
                coverage: $coverage, asOf: $asOf,
                suggestedAction: 'Remind people to punch out, or use attendance regularisation to correct the missing times.',
                deepLink: self::DEEP_LINK, confidence: Taxonomy::CONFIDENCE_RULE_BASED, audience: $caller->scope(),
                surfaces: $this->surfaces(), population: $missingPeople,
            );
        }

        $dupPct = $this->pct($surplus, $rows);
        if ($surplus > 0 && $dupPct >= $this->thresholds->float('attendance_duplicate_pct')) {
            $insights[] = new Insight(
                key: $this->keyFor('duplicates', $caller, $clock),
                detector: $this->id(), domain: $this->domain(), category: 'attendance_duplicates',
                severity: 'attention',
                title: 'Some days have more than one attendance record',
                whatHappened: sprintf('%d person-days have duplicate attendance rows (%d surplus rows, %s%% of all rows).', $dupDays, $surplus, $dupPct),
                whyItMatters: 'Duplicate rows inflate counts and can make the same day look like two different punches.',
                evidence: [
                    Evidence::metric('Person-days with duplicate rows', $dupDays, 'person-days', 'hrms_attendances'),
                    Evidence::metric('Surplus rows', $surplus, 'rows', 'hrms_attendances'),
                    Evidence::metric('Share of all rows', $dupPct, '%', 'hrms_attendances'),
                    $window_ev,
                ],
                coverage: $coverage, asOf: $asOf,
                suggestedAction: 'Review the duplicate days in the attendance report and remove or merge the extra rows.',
                deepLink: self::DEEP_LINK, confidence: Taxonomy::CONFIDENCE_MEASURED, audience: $caller->scope(),
                surfaces: $this->surfaces(), population: $dupPeople,
            );
        }

        return DetectorResult::ok($this, $insights, $coverage, $asOf);
    }
}
