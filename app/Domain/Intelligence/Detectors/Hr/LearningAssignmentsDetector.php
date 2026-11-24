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
 * LEARNING ASSIGNMENTS: course assignments past their due date and not completed, and courses a learner started but has not touched for a while,
 * for the people the caller may see.
 *
 * Sources: `lms_assignments` (the assignment and its due date), `lms_course_enroll` (the signal "completed" is read from) and `lms_content_progress`
 * (the learner's last recorded activity inside a course).
 *
 * DEFINITIONS follow the product, not a guess:
 *   - An assignment counts only once it is APPROVED (`approval_status = 'approved'`): a self-request awaiting approval, or a rejected one, is not an obligation
 *     (ScanLearningAssignmentsDue applies the same filter).
 *   - COMPLETED is read from `lms_course_enroll.status = 'completed'` (compared lower-case, as HrDashboardService::learningProgress does), because the
 *     assignment's own `status`/`progress` are written at assignment time and not reliably kept in step (documented in ScanLearningAssignmentsDue). As a
 *     second, conservative signal an assignment whose own status already reads `completed` is also left out: that can only UNDER-state overdue work, never
 *     invent it. Where `lms_course_enroll` is not available on a host, only the assignment's own status is used.
 *   - OVERDUE is `due_date` earlier than today. An open assignment with no due date cannot be aged: it is counted and reported as "not measured".
 *   - STALLED is an enrolment whose status is `in-progress`, that was last updated before the provisional window AND for which `lms_content_progress` holds no
 *     row updated inside the window. "Recorded" activity only: learning done offline leaves no row.
 * The stalled measure is reported only alongside a sufficient assignment base, so the detector has one honest coverage.
 *
 * It never reads a course name, an assigning person, a review note or any other free text. Certificates are the certification detector's business.
 */
final class LearningAssignmentsDetector extends AbstractHrDetector
{
    private const SELF_LINK = '/module/lms/learning/my-learning';
    private const TEAM_LINK = '/module/lms/training-and-records/assignments';

    public function id(): string
    {
        return 'hr.learning_assignments';
    }

    public function title(): string
    {
        return 'Learning assignments';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_LEARNING, Taxonomy::SURFACE_HR_DASHBOARD, Taxonomy::SURFACE_ME_DASHBOARD];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return $caller->hasRole(...self::PEOPLE_ROLES);
    }

    /**
     * THE "not completed" rule, shared with the cross-functional detector so there is one definition: neither the assignment's own status nor a completed
     * enrolment of the same person and course (same tenant) says it is done. Applied to a builder aliased `a` on `lms_assignments`.
     */
    public static function applyNotCompleted(Builder $query, int $tenantId, bool $hasEnrol): Builder
    {
        $query->whereRaw("LOWER(COALESCE(a.status, '')) <> 'completed'");
        if ($hasEnrol) {
            $query->whereNotExists(function ($sub) use ($tenantId): void {
                $sub->selectRaw('1')->from('lms_course_enroll as e')
                    ->whereColumn('e.user_id', 'a.user_id')
                    ->whereColumn('e.course_id', 'a.course_id')
                    ->where('e.sub_institute_id', $tenantId)
                    ->whereNull('e.deleted_at')
                    ->whereRaw("LOWER(COALESCE(e.status, '')) = 'completed'");
            });
        }

        return $query;
    }

    protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $asOf = $clock->asOf();
        if (! $this->canRead('lms_assignments', ['id', 'user_id', 'course_id', 'due_date', 'status', 'approval_status', 'sub_institute_id', 'deleted_at'])) {
            return DetectorResult::dataUnavailable($this, $asOf, 'Learning assignments are not available for this organisation.');
        }
        $hasEnrol = $this->canRead('lms_course_enroll', ['id', 'user_id', 'course_id', 'status', 'sub_institute_id', 'deleted_at', 'updated_at']);

        $self = $caller->scope() === 'self';
        $today = $clock->today();

        $base = fn (): Builder => $this->db->table('lms_assignments as a')
            ->where('a.sub_institute_id', $caller->tenantId)
            ->whereNull('a.deleted_at')
            ->where('a.approval_status', 'approved')
            ->whereIn('a.user_id', PeopleScope::userIds($this->db, $caller));

        $total = (int) $base()->count();
        if ($self && $total === 0) {
            return DetectorResult::notApplicable($this, $asOf, 'No learning is assigned to you.');
        }
        $minimum = $self ? 0 : $this->thresholds->int('learning_min_assignments');
        $basis = 'approved learning assignments for the people in scope';
        if ($total < $minimum) {
            return DetectorResult::insufficient($this, Coverage::of($total, null, $basis, $minimum), $asOf);
        }
        $coverage = new Coverage($total, null, $basis, true, null);

        // Not completed: neither the assignment's own status nor a completed enrolment says it is done.
        $notDone = fn (): Builder => self::applyNotCompleted($base(), $caller->tenantId, $hasEnrol);

        $stats = $notDone()->selectRaw(
            'COUNT(*) AS open_n,'
            . ' SUM(CASE WHEN a.due_date IS NULL THEN 1 ELSE 0 END) AS undated,'
            . ' SUM(CASE WHEN a.due_date IS NOT NULL AND a.due_date < ? THEN 1 ELSE 0 END) AS overdue_n,'
            . ' COUNT(DISTINCT CASE WHEN a.due_date IS NOT NULL AND a.due_date < ? THEN a.user_id END) AS owners,'
            . ' MIN(CASE WHEN a.due_date < ? THEN a.due_date END) AS oldest',
            [$today, $today, $today],
        )->first();

        $openN = (int) ($stats->open_n ?? 0);
        $undated = (int) ($stats->undated ?? 0);
        $overdueN = (int) ($stats->overdue_n ?? 0);
        $owners = (int) ($stats->owners ?? 0);
        $link = $self ? self::SELF_LINK : self::TEAM_LINK;
        $insights = [];

        if ($overdueN > 0) {
            $evidence = [
                Evidence::metric('Assignments past their due date and not completed', $overdueN, 'assignments', 'lms_assignments'),
                Evidence::metric('Assignments not yet completed', $openN, 'assignments', 'lms_assignments'),
            ];
            if ($stats->oldest) {
                $evidence[] = Evidence::metric('Oldest due date', substr((string) $stats->oldest, 0, 10), null, 'lms_assignments');
            }
            if ($undated > 0) {
                $evidence[] = Evidence::metric('Open assignments with no due date (not measured)', $undated, 'assignments', 'lms_assignments');
            }
            $evidence[] = Evidence::window('Due before', '1000-01-01', $today);
            if ($caller->mayReferenceIndividuals()) {
                $refs = $notDone()->whereNotNull('a.due_date')->where('a.due_date', '<', $today)->orderBy('a.due_date')->orderBy('a.id')->limit(10)->pluck('a.id');
                foreach ($refs as $id) {
                    $evidence[] = Evidence::entity('Learning assignment', 'learning_assignment', (int) $id);
                }
            }
            $share = $this->pct($overdueN, $openN);
            $insights[] = new Insight(
                key: $this->keyFor('overdue', $caller, $clock),
                detector: $this->id(), domain: $this->domain(), category: 'learning_overdue',
                severity: $share >= $this->thresholds->float('learning_overdue_pct') ? 'high' : 'attention',
                title: $self ? 'Learning assigned to you is past its due date' : 'Learning assignments are past their due date',
                whatHappened: $self
                    ? sprintf('%d of your assigned course(s) are past their due date and not completed.', $overdueN)
                    : sprintf('%d learning assignment(s) for %d person(s) are past their due date and not completed.', $overdueN, $owners),
                whyItMatters: 'Assigned learning that passes its due date unfinished leaves the skill or compliance requirement behind it open.',
                evidence: $evidence, coverage: $coverage, asOf: $asOf,
                suggestedAction: $self ? 'Open My Learning and finish the course, or ask for a new due date.' : 'Open Assignments and follow up with the learners, or move the due dates that are no longer realistic.',
                deepLink: $link, confidence: Taxonomy::CONFIDENCE_RULE_BASED, audience: $caller->scope(),
                surfaces: $this->surfaces(), population: $owners,
            );
        }

        $stalled = $hasEnrol ? $this->stalled($caller, $clock, $link) : null;
        if ($stalled !== null) {
            $insights[] = $stalled;
        }

        return DetectorResult::ok($this, $insights, $coverage, $asOf);
    }

    /** Courses started and then left: in progress, no enrolment update and no recorded content activity inside the window. */
    private function stalled(IntelligenceCaller $caller, DetectionClock $clock, string $link): ?Insight
    {
        if (! $this->canRead('lms_content_progress', ['user_id', 'course_id', 'updated_at', 'sub_institute_id', 'deleted_at'])) {
            return null;
        }
        $days = $this->thresholds->int('learning_stalled_days');
        $cutoff = $clock->dateTimeDaysAgo($days);
        $self = $caller->scope() === 'self';
        $inProgress = fn (): Builder => $this->db->table('lms_course_enroll as e')
            ->where('e.sub_institute_id', $caller->tenantId)
            ->whereNull('e.deleted_at')
            ->whereRaw("LOWER(COALESCE(e.status, '')) = 'in-progress'")
            ->whereIn('e.user_id', PeopleScope::userIds($this->db, $caller));
        $stalled = fn (): Builder => $inProgress()->where('e.updated_at', '<', $cutoff)
            ->whereNotExists(function ($sub) use ($caller, $cutoff): void {
                $sub->selectRaw('1')->from('lms_content_progress as p')
                    ->whereColumn('p.user_id', 'e.user_id')
                    ->whereColumn('p.course_id', 'e.course_id')
                    ->where('p.sub_institute_id', $caller->tenantId)
                    ->whereNull('p.deleted_at')
                    ->where('p.updated_at', '>=', $cutoff);
            });

        $started = (int) $inProgress()->count();
        $row = $stalled()->selectRaw('COUNT(*) AS n, COUNT(DISTINCT e.user_id) AS people')->first();
        $n = (int) ($row->n ?? 0);
        $people = (int) ($row->people ?? 0);
        if ($n === 0) {
            return null;
        }

        $evidence = [
            Evidence::metric(sprintf('Started courses with no recorded activity in %d days', $days), $n, 'courses', 'lms_course_enroll'),
            Evidence::metric('Courses in progress', $started, 'courses', 'lms_course_enroll'),
            Evidence::window('No activity since', substr($cutoff, 0, 10), $clock->today()),
        ];
        if ($caller->mayReferenceIndividuals()) {
            foreach ($stalled()->orderBy('e.updated_at')->orderBy('e.id')->limit(10)->pluck('e.id') as $id) {
                $evidence[] = Evidence::entity('Course enrolment', 'course_enrolment', (int) $id);
            }
        }

        return new Insight(
            key: $this->keyFor('stalled', $caller, $clock),
            detector: $this->id(), domain: $this->domain(), category: 'learning_stalled', severity: 'info',
            title: $self ? 'A course you started has gone quiet' : 'Started courses have gone quiet',
            whatHappened: $self
                ? sprintf('%d course(s) you started show no recorded activity in the last %d days.', $n, $days)
                : sprintf('%d started course(s) for %d person(s) show no recorded activity in the last %d days.', $n, $people, $days),
            whyItMatters: 'A course left half-finished does not build the skill it was taken for; learning done outside the platform leaves no record here.',
            evidence: $evidence, coverage: new Coverage($started, null, 'courses in progress for the people in scope', true, null), asOf: $clock->asOf(),
            suggestedAction: $self ? 'Pick the course up again from My Learning.' : 'Check with the learners whether they need time or help to finish.',
            deepLink: $link, confidence: Taxonomy::CONFIDENCE_RULE_BASED, audience: $caller->scope(),
            surfaces: $this->surfaces(), population: $people,
        );
    }
}
