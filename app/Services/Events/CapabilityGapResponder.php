<?php

namespace App\Services\Events;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyGap;
use App\Http\Controllers\Concerns\ResolvesEmployeeJobRole;
use App\Services\Competency\RemediationDueDate;
use App\Services\Events\Concerns\DrivesFromEventStore;
use App\Services\Lms\EnrolmentWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * REACTOR — a capability rating moved, so re-check the gap it might have
 * opened or closed, and either assign the course that closes it or tell HR
 * none exists. (kind = R)
 *
 * ── THE TWO EVENTS IT HANDLES ────────────────────────────────────────────────
 *
 *   competency.rating_changed  emitted by RatingWriter, once per (user,
 *                               competency) per write, from all 5 rating
 *                               paths it now covers.
 *   competency.course_mapped   emitted by CourseCompetencyMapController when a
 *                               course gets mapped to a competency - closes the
 *                               HR request AND assigns the course to everyone
 *                               who was still waiting on it.
 *
 * ── WHY THIS NEVER TRUSTS THE PAYLOAD'S OLD/NEW LEVEL ───────────────────────
 *
 * It does not carry one. RatingWriter deliberately never derives a level (that
 * is ProficiencyService::rollUp's job alone), so this reactor re-runs
 * ResolvesCompetencyGap::competencyGapFor() fresh, against the CURRENT state,
 * every time. That is the only honest way to answer "does this still matter" -
 * a guessed before/after in the event would be exactly the fabricated `from`
 * EventCatalogue's header warns against.
 *
 * ── THE TWO OUTCOMES OF A GAP ────────────────────────────────────────────────
 *
 *   course mapped (course_competency_map has a row)   -> assign it, with a due
 *       date resolved by RemediationDueDate, exactly as LearningAssigner
 *       assigns a role's or plan's courses - same idempotency guard, same
 *       "always enrol, even if the assignment already existed" rule.
 *   no course mapped                                  -> raise or update one
 *       row in capability_course_requests, grained per competency so the same
 *       missing course is one piece of work no matter how many people have the
 *       gap. Notifies HR only on a REAL transition (created, reopened after
 *       cooldown, re-superseded) - a re-raise of an already-open request
 *       updates bookkeeping and notifies nobody, or HR would be renotified on
 *       every ten-minute drain for as long as the gap persists.
 *
 * And the other direction: a rating that RAISES a competency past the
 * requirement (or CLEARS it back to unmeasured) removes that person from any
 * standing request's subject list - see resolveGapForUser(). Existing course
 * assignments are never revoked on a rise; somebody may still want to finish
 * what they started.
 */
class CapabilityGapResponder
{
    use DrivesFromEventStore;
    use ResolvesCompetencyGap;
    use ResolvesEmployeeJobRole;

    public const CONSUMER = 'capability_gap_responder';

    public const HANDLES = [
        'competency.rating_changed',
        'competency.course_mapped',
    ];

    public function __construct(
        private readonly EventRecorder $events,
        private readonly RemediationDueDate $dueDates,
        private readonly EnrolmentWriter $enrolments,
    ) {
    }

    public function handles(string $type): bool
    {
        return in_array($type, self::HANDLES, true);
    }

    /**
     * @throws \RuntimeException if called while replaying
     */
    public function dispatch(object $event): void
    {
        // FIRST LINE. Assigning a course and flagging HR are both things a
        // person sees and acts on; a rebuild must not re-issue them.
        ReplayMode::assertNotReplaying(self::CONSUMER);

        if (!$this->handles((string) $event->type)) {
            return;
        }

        $done = DB::table('g2g_event_delivery')
            ->where('event_id', (int) $event->id)
            ->where('consumer', self::CONSUMER)
            ->where('status', 'done')
            ->exists();

        if ($done) {
            return;
        }

        $tenant = (int) $event->sub_institute_id;
        $payload = $this->payload($event);

        if ((string) $event->type === 'competency.course_mapped') {
            $this->handleCourseMapped($event, $payload, $tenant);
        } else {
            $this->handleRatingChanged($event, $payload, $tenant);
        }
    }

    private function handleRatingChanged(object $event, array $payload, int $tenant): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $competencyId = (int) ($payload['competency_id'] ?? 0);

        if ($userId <= 0 || $competencyId <= 0) {
            $this->ledger($event, 'skipped', 'missing user or competency in payload');

            return;
        }

        $user = DB::table('tbluser')
            ->where('id', $userId)->where('sub_institute_id', $tenant)
            ->first(['id', 'jobtitle_id', 'allocated_standards']);

        if (!$user) {
            $this->ledger($event, 'skipped', 'employee not found');

            return;
        }

        $jobroleId = $this->resolveJobRoleId($user);

        if (!$jobroleId) {
            // Normal, not a failure - an employee with no job role has no
            // requirements to be short of yet.
            $this->ledger($event, 'skipped', 'employee has no job role');

            return;
        }

        $gap = $this->competencyGapFor($tenant, $userId, $jobroleId);
        $row = null;

        foreach ($gap['competencies'] as $candidate) {
            if ((int) $candidate['competency_id'] === $competencyId) {
                $row = $candidate;
                break;
            }
        }

        if ($row === null) {
            // This competency is not one the employee's role requires - the
            // rating moved, but there is nothing here to react to.
            $this->ledger($event, 'skipped', 'competency not required by role');

            return;
        }

        if ($row['state'] !== 'gap') {
            // 'met' (the rise closed it) or 'unmeasured' (a clearing) - either
            // way, this person no longer belongs on an open request.
            $this->resolveGapForUser($tenant, $userId, $competencyId);
            $this->ledger($event, 'done', null);

            return;
        }

        $competencyName = (string) ($payload['competency_name'] ?? $row['competency_name'] ?? '');
        $isMandatory = (bool) $row['is_mandatory'];
        $requiredProficiency = (int) $row['required_proficiency'];
        $measuredLevel = $row['measured_level'];

        $courseIds = DB::table('course_competency_map')
            ->where('sub_institute_id', $tenant)
            ->where('competency_id', $competencyId)
            ->orderByDesc('is_primary')
            ->pluck('course_id')
            ->unique()
            ->values();

        if ($courseIds->isEmpty()) {
            $this->raiseOrUpdateRequest(
                $tenant, $competencyId, $competencyName, $jobroleId,
                $requiredProficiency, $isMandatory, $userId,
                $measuredLevel, $row['gap'], (int) $event->id
            );
            $this->ledger($event, 'done', null);

            return;
        }

        foreach ($courseIds as $courseId) {
            $this->assignCourse(
                $event, $tenant, $userId, (int) $courseId, $competencyId, $competencyName,
                $jobroleId, $isMandatory, $requiredProficiency, $measuredLevel
            );
        }

        $this->ledger($event, 'done', null);
    }

    /**
     * A course got mapped to one or more competencies - close the matching HR
     * request (CourseCompetencyMapController::store() already flipped its
     * status before emitting this) and assign the course to everyone who was
     * still waiting on it, so HR creating the course is the thing that
     * actually gets it to people rather than waiting for their next rating.
     */
    private function handleCourseMapped(object $event, array $payload, int $tenant): void
    {
        $courseId = (int) ($payload['course_id'] ?? 0);
        $competencyIds = array_map('intval', (array) ($payload['competency_ids'] ?? []));

        if ($courseId <= 0 || $competencyIds === []) {
            $this->ledger($event, 'skipped', 'missing course or competencies in payload');

            return;
        }

        foreach ($competencyIds as $competencyId) {
            $request = DB::table('capability_course_requests')
                ->where('sub_institute_id', $tenant)
                ->where('competency_id', $competencyId)
                ->first();

            if (!$request) {
                continue;
            }

            $subjects = DB::table('capability_course_request_subjects')
                ->where('request_id', $request->id)
                ->get();

            foreach ($subjects as $subject) {
                $this->assignCourse(
                    $event,
                    $tenant,
                    (int) $subject->user_id,
                    $courseId,
                    $competencyId,
                    (string) ($request->competency_name ?? ''),
                    $request->jobrole_id ? (int) $request->jobrole_id : null,
                    (bool) $request->is_mandatory,
                    (int) ($subject->required_proficiency ?? $request->required_proficiency ?? 0),
                    $subject->measured_level !== null ? (float) $subject->measured_level : null
                );
            }

            // Resolved: everyone who was waiting now has the course. The
            // request row itself stays - CourseCompetencyMapController already
            // marked it course_created - this just empties the waiting list.
            DB::table('capability_course_request_subjects')->where('request_id', $request->id)->delete();
            DB::table('capability_course_requests')->where('id', $request->id)
                ->update(['affected_count' => 0, 'updated_at' => now()]);
        }

        $this->ledger($event, 'done', null);
    }

    /**
     * Assign one course to close one gap. Same idempotency guard and
     * enrolment rule as LearningAssigner: an insertOrIgnore skip still gets an
     * enrolment attempt, because the assignment may exist from an earlier run
     * while the enrolment does not.
     *
     * Emits `learning.auto_assigned` only when a row was genuinely newly
     * written - a skip is not a new fact to tell the learner about.
     */
    private function assignCourse(
        object $event,
        int $tenant,
        int $userId,
        int $courseId,
        int $competencyId,
        string $competencyName,
        ?int $jobroleId,
        bool $isMandatory,
        int $requiredProficiency,
        ?float $measuredLevel
    ): bool {
        $already = DB::table('lms_assignments')
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->whereIn('status', ['assigned', 'in-progress', 'pending'])
            ->exists();

        if ($already) {
            $this->enrolments->ensureEnrolment($userId, $courseId, $tenant);

            return false;
        }

        $due = $this->dueDates->resolveFor($tenant, $courseId, $jobroleId, $competencyId, $isMandatory);

        $inserted = DB::table('lms_assignments')->insertOrIgnore([
            'user_id' => $userId,
            'course_id' => $courseId,
            'assignment_type' => $isMandatory ? 'Mandatory' : 'Recommended',
            'status' => 'assigned',
            // Auto-assignment is not a request: it is already decided.
            'approval_status' => 'approved',
            'progress' => 0,
            'sub_institute_id' => $tenant,
            'competency_id' => $competencyId,
            'source' => 'capability_gap',
            'origin_event_id' => (int) $event->id,
            'due_date' => $due['due_date'],
            'due_date_basis' => $due['basis'],
            'assigned_by' => 'system',
            'assigned_by_id' => (int) ($event->actor_id ?: 0) ?: null,
            'assigned_on' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->enrolments->ensureEnrolment($userId, $courseId, $tenant);

        if ($inserted > 0) {
            $courseName = DB::table('sub_std_map')->where('id', $courseId)->value('display_name');

            $this->events->record(
                'learning.auto_assigned',
                $tenant,
                'lms_assignment',
                $courseId,
                null,
                [
                    'user_id' => $userId,
                    'course_id' => $courseId,
                    'course_name' => $courseName,
                    'competency_id' => $competencyId,
                    'competency_name' => $competencyName,
                    'due_date' => $due['due_date'],
                    'due_date_basis' => $due['basis'],
                    'assignment_type' => $isMandatory ? 'Mandatory' : 'Recommended',
                    'required_proficiency' => $requiredProficiency,
                    'measured_level' => $measuredLevel,
                ]
            );
        }

        return $inserted > 0;
    }

    /**
     * Raise a new HR request, or update the standing one - never a second
     * parent row, per the unique (tenant, competency) index.
     *
     * Notifies HR only when `$shouldNotify` ends up true: a brand new request,
     * or one reopened after its dismissal cooldown, or one that had fully
     * resolved (superseded) and now has a gap again. A re-raise against an
     * already-open request, or one still cooling down after a dismissal,
     * updates bookkeeping and notifies nobody - otherwise HR would be told
     * again on every ten-minute drain for as long as the gap persists.
     */
    private function raiseOrUpdateRequest(
        int $tenant,
        int $competencyId,
        string $competencyName,
        ?int $jobroleId,
        int $requiredProficiency,
        bool $isMandatory,
        int $userId,
        ?float $measuredLevel,
        ?float $gap,
        int $originEventId
    ): void {
        $now = now();

        $existing = DB::table('capability_course_requests')
            ->where('sub_institute_id', $tenant)
            ->where('competency_id', $competencyId)
            ->first();

        $cooldownDays = (int) ($this->dueDates->settings($tenant)['reflag_cooldown_days'] ?? 30);
        $shouldNotify = false;

        if (!$existing) {
            $requestId = DB::table('capability_course_requests')->insertGetId([
                'sub_institute_id' => $tenant,
                'competency_id' => $competencyId,
                'competency_name' => $competencyName,
                'jobrole_id' => $jobroleId,
                'required_proficiency' => $requiredProficiency,
                'is_mandatory' => $isMandatory ? 1 : 0,
                'affected_count' => 0,
                'status' => 'open',
                'origin_event_id' => $originEventId,
                'first_raised_at' => $now,
                'last_seen_at' => $now,
                'times_seen' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $shouldNotify = true;
        } else {
            $requestId = (int) $existing->id;

            $cooledDown = $existing->dismissed_at !== null
                && Carbon::parse($existing->dismissed_at)->addDays($cooldownDays)->isPast();

            $updates = [
                'last_seen_at' => $now,
                'times_seen' => DB::raw('times_seen + 1'),
                'updated_at' => $now,
            ];

            if ($existing->status === 'dismissed' && $cooledDown) {
                $updates['status'] = 'open';
                $updates['dismissed_at'] = null;
                $updates['dismissed_reason'] = null;
                $shouldNotify = true;
            } elseif ($existing->status === 'superseded') {
                // Had fully resolved - every subject had either got a course
                // or risen past the requirement - and now has a gap again.
                $updates['status'] = 'open';
                $shouldNotify = true;
            }
            // status === 'dismissed' and still cooling down, or already
            // 'open': recorded as seen again below, nobody is notified again.

            DB::table('capability_course_requests')->where('id', $requestId)->update($updates);
        }

        $subjectExists = DB::table('capability_course_request_subjects')
            ->where('request_id', $requestId)->where('user_id', $userId)->exists();

        if ($subjectExists) {
            DB::table('capability_course_request_subjects')
                ->where('request_id', $requestId)->where('user_id', $userId)
                ->update([
                    'required_proficiency' => $requiredProficiency,
                    'measured_level' => $measuredLevel,
                    'gap' => $gap,
                    'resolved_at' => null,
                    'updated_at' => $now,
                ]);
        } else {
            DB::table('capability_course_request_subjects')->insert([
                'request_id' => $requestId,
                'sub_institute_id' => $tenant,
                'user_id' => $userId,
                'required_proficiency' => $requiredProficiency,
                'measured_level' => $measuredLevel,
                'gap' => $gap,
                'added_at' => $now,
                'resolved_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $affectedCount = DB::table('capability_course_request_subjects')
            ->where('request_id', $requestId)->count();

        DB::table('capability_course_requests')->where('id', $requestId)
            ->update(['affected_count' => $affectedCount, 'updated_at' => $now]);

        if ($shouldNotify) {
            $this->events->record(
                'capability.course_gap_flagged',
                $tenant,
                'capability_course_request',
                $requestId,
                null,
                [
                    'request_id' => $requestId,
                    'competency_id' => $competencyId,
                    'competency_name' => $competencyName,
                    'required_proficiency' => $requiredProficiency,
                    'affected_count' => $affectedCount,
                    'is_mandatory' => $isMandatory,
                    'example_user_id' => $userId,
                ]
            );
        }
    }

    /**
     * A rating rose past the requirement, or was cleared back to unmeasured -
     * either way this person no longer belongs on a standing request. The
     * request row itself survives at zero (status 'superseded') rather than
     * being deleted: it is that work item's history, not a scratch counter.
     */
    private function resolveGapForUser(int $tenant, int $userId, int $competencyId): void
    {
        $request = DB::table('capability_course_requests')
            ->where('sub_institute_id', $tenant)
            ->where('competency_id', $competencyId)
            ->where('status', 'open')
            ->first(['id']);

        if (!$request) {
            return;
        }

        DB::table('capability_course_request_subjects')
            ->where('request_id', $request->id)->where('user_id', $userId)->delete();

        $affectedCount = DB::table('capability_course_request_subjects')
            ->where('request_id', $request->id)->count();

        $updates = ['affected_count' => $affectedCount, 'updated_at' => now()];

        if ($affectedCount === 0) {
            $updates['status'] = 'superseded';
        }

        DB::table('capability_course_requests')->where('id', $request->id)->update($updates);
    }

    private function payload(object $event): array
    {
        if (is_array($event->payload)) {
            return $event->payload;
        }

        $decoded = json_decode((string) ($event->payload ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function ledger(object $event, string $status, ?string $error): void
    {
        DB::table('g2g_event_delivery')->updateOrInsert(
            ['event_id' => (int) $event->id, 'consumer' => self::CONSUMER],
            [
                'status' => $status,
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => $error,
                'completed_at' => $status === 'done' ? now() : null,
            ]
        );
    }
}
