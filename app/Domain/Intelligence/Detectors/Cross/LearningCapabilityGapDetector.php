<?php

namespace App\Domain\Intelligence\Detectors\Cross;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Detectors\Hr\CapabilityDevelopmentDetector;
use App\Domain\Intelligence\Detectors\Hr\LearningAssignmentsDetector;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Support\PeopleScope;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Database\Query\Builder;

/**
 * OVERDUE LEARNING AND OPEN CAPABILITY-GAP REQUESTS (cross.learning_capability_gap).
 *
 * THE RELATIONSHIP (every key read from a migration, not guessed):
 *   lms_assignments.user_id        = capability_course_request_subjects.user_id         (the same person)
 *   lms_assignments.competency_id  = capability_course_requests.competency_id           (the same competency; column added by 2026_07_29_100300)
 *   capability_course_request_subjects.request_id = capability_course_requests.id       (2026_10_14_100100)
 * and THE ORGANISATION IS ENFORCED ON ALL THREE TABLES: the assignment, the request and the subject row must each carry the caller's `sub_institute_id`. A
 * request of another organisation that names the same competency, or a subject row that names a request of another organisation, never forms a link.
 *
 * THE TWO SETS (definitions are the source detectors', reused, not copied):
 *   OVERDUE LEARNING: approved, not deleted, not completed (LearningAssignmentsDetector::applyNotCompleted: the one shared "not completed" rule), due date before
 *     today, and carrying a competency. An assignment with no competency cannot be linked and is NOT assessed (the coverage basis says so). The approved /
 *     tenant / people-in-scope base is the same four conditions as in LearningAssignmentsDetector::assess (re-stated here because that method keeps them in a
 *     local closure).
 *   OPEN CAPABILITY GAP: a subject row (a person who currently has the gap) of a request whose status is `open`, that is not deleted, and the subject not resolved.
 *     The migration deletes resolved subjects; `resolved_at` is also honoured as a second signal.
 *
 * AUTHORISATION: both hr.learning_assignments and hr.capability_development must authorise the caller (they share the people-role list). The capability-gap
 * request tables have no detector of their own, so hr.capability_development stands in as their source check.
 *
 * PEOPLE: the person on the assignment must be in the caller's PeopleScope, exactly as in the source HR detector; the small-group rule then applies to the
 * number of distinct linked people (`population`); executives and auditors receive aggregates and no record references.
 *
 * OUTPUT: counts only. It reads no course name, competency name, request note, reviewer or any other text.
 */
final class LearningCapabilityGapDetector extends AbstractCrossDetector
{
    private const SELF_LINK = '/module/lms/learning/my-learning';
    private const TEAM_LINK = '/module/lms/training-and-records/assignments';

    public function id(): string
    {
        return 'cross.learning_capability_gap';
    }

    public function title(): string
    {
        return 'Overdue learning and open capability-gap requests';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_LEARNING, Taxonomy::SURFACE_CAPABILITY];
    }

    protected function sources(): array
    {
        return [new LearningAssignmentsDetector($this->db, $this->thresholds), new CapabilityDevelopmentDetector($this->db, $this->thresholds)];
    }

    protected function assess(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $asOf = $clock->asOf();
        if (! $this->canRead('lms_assignments', ['id', 'user_id', 'course_id', 'competency_id', 'due_date', 'status', 'approval_status', 'sub_institute_id', 'deleted_at'])
            || ! $this->canRead('capability_course_requests', ['id', 'competency_id', 'status', 'sub_institute_id', 'deleted_at'])
            || ! $this->canRead('capability_course_request_subjects', ['id', 'request_id', 'user_id', 'sub_institute_id', 'resolved_at'])) {
            return DetectorResult::dataUnavailable($this, $asOf, 'Learning assignments or capability-gap requests are not available for this organisation.');
        }
        $hasEnrol = $this->canRead('lms_course_enroll', ['id', 'user_id', 'course_id', 'status', 'sub_institute_id', 'deleted_at']);

        $tenant = $caller->tenantId;
        $today = $clock->today();
        $self = $caller->scope() === 'self';

        $overdue = fn (): Builder => LearningAssignmentsDetector::applyNotCompleted(
            $this->db->table('lms_assignments as a')
                ->where('a.sub_institute_id', $tenant)
                ->whereNull('a.deleted_at')
                ->where('a.approval_status', 'approved')
                ->whereIn('a.user_id', PeopleScope::userIds($this->db, $caller)),
            $tenant,
            $hasEnrol,
        )->whereNotNull('a.competency_id')->whereNotNull('a.due_date')->where('a.due_date', '<', $today);

        $openGaps = fn (): Builder => $this->db->table('capability_course_request_subjects as s')
            ->join('capability_course_requests as r', 'r.id', '=', 's.request_id')
            ->where('s.sub_institute_id', $tenant)
            ->where('r.sub_institute_id', $tenant)
            ->whereNull('r.deleted_at')
            ->whereNull('s.resolved_at')
            ->whereRaw("LOWER(COALESCE(r.status, '')) = 'open'");

        $eligible = (int) $overdue()->count();
        $gapRecords = (int) $openGaps()->whereIn('s.user_id', PeopleScope::userIds($this->db, $caller))->count();

        if ($self && $eligible === 0) {
            return DetectorResult::notApplicable($this, $asOf, 'You have no overdue learning that is tied to a competency.');
        }
        if ($self && $gapRecords === 0) {
            return DetectorResult::notApplicable($this, $asOf, 'You have no open capability-gap request.');
        }
        $minimum = $self ? 0 : $this->thresholds->int('cross_learning_min_eligible');
        $basis = 'overdue, not-completed learning assignments tied to a competency, for the people in scope (an assignment with no competency cannot be linked and is not assessed)';
        if ($eligible < $minimum) {
            return DetectorResult::insufficient($this, Coverage::of($eligible, null, $basis, $minimum), $asOf);
        }
        if ($gapRecords === 0) {
            return DetectorResult::insufficient($this, new Coverage(0, null, 'open capability-gap records for the people in scope', false,
                'No open capability-gap request is recorded for the people in scope, so overdue learning has nothing to be compared with.'), $asOf);
        }

        // THE LINK: the same person AND the same competency, organisation enforced on the request and on the subject row (the assignment's is in $overdue).
        $linked = fn (): Builder => $overdue()->whereExists(function ($sub) use ($tenant): void {
            $sub->selectRaw('1')->from('capability_course_request_subjects as s')
                ->join('capability_course_requests as r', 'r.id', '=', 's.request_id')
                ->whereColumn('s.user_id', 'a.user_id')
                ->whereColumn('r.competency_id', 'a.competency_id')
                ->where('s.sub_institute_id', $tenant)
                ->where('r.sub_institute_id', $tenant)
                ->whereNull('r.deleted_at')
                ->whereNull('s.resolved_at')
                ->whereRaw("LOWER(COALESCE(r.status, '')) = 'open'");
        });

        $row = $linked()->selectRaw('COUNT(*) AS n, COUNT(DISTINCT a.user_id) AS people')->first();
        $linkedN = (int) ($row->n ?? 0);
        $people = (int) ($row->people ?? 0);
        $coverage = new Coverage($linkedN, $eligible, $basis . '; linked means the same person has an open capability-gap request for the same competency', true, null);
        if ($linkedN === 0) {
            return DetectorResult::ok($this, [], $coverage, $asOf);
        }

        $evidence = [
            Evidence::metric('Overdue learning assignments tied to a competency (not completed)', $eligible, 'assignments', 'lms_assignments'),
            Evidence::metric('Of these, with an open capability-gap request for the same person and the same competency', $linkedN, 'assignments', 'capability_course_request_subjects'),
            Evidence::metric('Open capability-gap records for the people in scope', $gapRecords, 'records', 'capability_course_request_subjects'),
            Evidence::metric('Link used', 'same person and same competency, with the organisation matched on every table'),
            Evidence::window('Due before', '1000-01-01', $today),
        ];
        if ($caller->mayReferenceIndividuals()) {
            foreach ($linked()->orderBy('a.due_date')->orderBy('a.id')->limit(10)->pluck('a.id') as $id) {
                $evidence[] = Evidence::entity('Learning assignment in the overlap', 'learning_assignment', (int) $id);
            }
        }

        $share = $this->pct($linkedN, $eligible);

        return DetectorResult::ok($this, [new Insight(
            key: $this->keyFor('learning_gap', $caller, $clock),
            detector: $this->id(), domain: $this->domain(), category: 'cross_learning_capability',
            severity: $share >= $this->thresholds->float('cross_attention_pct') ? 'attention' : 'info',
            title: $self ? 'Overdue learning of yours and an open capability-gap request cover the same competency' : 'Overdue learning and open capability-gap requests cover the same competency',
            whatHappened: $self
                ? sprintf('%d of your %d overdue learning assignment(s) tied to a competency are for a competency where you also have an open capability-gap request.', $linkedN, $eligible)
                : sprintf('%d of %d overdue learning assignment(s) tied to a competency (%s%%) belong to %d person(s) who also have an open capability-gap request for the same competency.', $linkedN, $eligible, $this->fmt($share), $people),
            whyItMatters: 'This is a possible relationship requiring verification, not a finding. The two records are joined only by the same person and the same competency. ' . self::NO_CAUSE,
            evidence: $evidence, coverage: $coverage, asOf: $asOf,
            suggestedAction: $self
                ? 'Open My Learning and check, for each overdue course shown, whether it is the course that is meant to close your open capability-gap request.'
                : 'Open Assignments and, for each competency in the overlap, check in the capability-gap requests whether the overdue assignment and the open request are about the same need.',
            deepLink: $self ? self::SELF_LINK : self::TEAM_LINK, confidence: Taxonomy::CONFIDENCE_RULE_BASED, audience: $caller->scope(),
            surfaces: $this->surfaces(), population: $people,
        )], $coverage, $asOf);
    }

    private function fmt(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 1, '.', ''), '0'), '.');
    }
}
