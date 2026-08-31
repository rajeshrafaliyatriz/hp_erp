<?php

namespace App\Services\Events;

use App\Services\Events\Concerns\DrivesFromEventStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * X-13 — REACTOR. When something lapses, say what would fix it. (kind = R)
 *
 * Unblocked by the trigger sweep: `course_competency_map` reached 56 rows, so a
 * competency can finally be turned into a list of courses. Before that this class
 * would have recommended nothing, which is why it was scheduled rather than built.
 *
 * ⚠ CORRECTED 2026-08-12 - "UNBLOCKED" OVERSTATED IT. THE BRIDGE HAS NO WRITER.
 *
 *   `course_competency_map` has exactly TWO references in the whole application:
 *   this class and LearningAssigner. BOTH ARE READS. No controller, route,
 *   service or screen writes it; course creation writes `sub_std_map` and
 *   nothing else. The 56 rows are seed and there is no path by which a customer
 *   adds a 57th.
 *
 *   So the sentence above is true of the SEED and false of the product. This
 *   class recommends courses for the tenants the seed happened to cover, and
 *   nothing a customer does will ever extend that. It is not blocked on volume;
 *   it is blocked on a writer that does not exist.
 *
 *   Kept rather than rewritten, because the overstatement is the useful part:
 *   "56 rows arrived" was read as "the mechanism works now", and rows arriving
 *   by seed look identical to rows arriving by use until you ask who wrote them.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ONE OF ITS TWO ENTRY POINTS CANNOT FIRE, AND IT IS WIRED ANYWAY.
 *
 *   certification.expiring .. 38 certifications expire within 90 days. REAL.
 *   capability.flag_raised .. THERE IS NO `capability_flag` TABLE. Nothing emits
 *                             it and nothing can until that exists.
 *
 * The handler for the second is present and will work the day the event arrives -
 * the cost is a `match` arm, and leaving it out would mean rediscovering this
 * chain later. But it is NOT counted as working: `coverage()` reports it, and
 * the catalogue's NOT_NOTIFIED already records that flags have no manager to
 * notify either (G-ORG-02).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IT RECOMMENDS FOR A FLAG. IT ASSIGNS FOR A RENEWAL. (changed 2026-08-31)
 *
 * This class used to refuse to assign under any circumstances, and argued for it:
 *
 *   > "an assignment is a decision someone made and a recommendation is not.
 *   >  Writing recommendations into `lms_assignments` would turn 'you might want
 *   >  this' into 'you must do this' with no one having chosen it."
 *
 * That reasoning is kept because it is still right about `capability.flag_raised`.
 * It was wrong about `certification.expiring`, and the contract said so all along
 * — `05-data-flow-contracts.md:243` specifies this consumer "assigns the renewal
 * course (golden thread 8)". The code and the spec had disagreed since the day it
 * was written, and only this docblock recorded which one had won.
 *
 * WHY THE RENEWAL IS DIFFERENT. Nobody chooses to hold a certification that
 * lapses. The obligation was accepted when the certification was required of the
 * role, and its expiry date was known from the moment it was issued. A renewal is
 * not a new demand being invented by a machine; it is the one already agreed
 * falling due. The premise "with no one having chosen it" simply does not hold.
 *
 * A flag is the opposite: it is this system forming an opinion that somebody may
 * be weak at something. Turning that into a mandatory enrolment is exactly the
 * overreach the original note guarded against, and it still recommends.
 *
 * WHAT THIS COSTS. Course coverage decides whether a renewal can assign anything
 * at all: `course_competency_map` reaches 23-38 of the 135 competencies that
 * certifications name, so of the certifications expiring within 90 days only
 * 3 of 19 (dev) and 4 of 20 (live) resolve to a course. The rest ledger as
 * `skipped - no course maps to that competency`, and NotificationDispatcher tells
 * the holder anyway because `certification.expiring` is in its NOTIFIES list.
 * So the honest description of this feature today is "it notifies about four
 * lapses in five and assigns for the fifth" - which is the correct behaviour, and
 * a long way from an assignment engine. Filling that map is domain work: knowing
 * which course actually restores which competency is not something this class can
 * infer.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class RemediationRecommender
{
    use DrivesFromEventStore;

    public const CONSUMER = 'remediation_recommender';

    public const HANDLES = ['certification.expiring', 'capability.flag_raised'];

    public function handles(string $type): bool
    {
        return in_array($type, self::HANDLES, true);
    }

    /**
     * @throws \RuntimeException if called while replaying
     */
    public function dispatch(object $event): void
    {
        // A recommendation is visible to a person. A rebuild must not re-create it.
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

        $tenant  = (int) $event->sub_institute_id;
        $payload = $this->payload($event);

        [$userId, $competencyIds] = match ($event->type) {
            'certification.expiring'  => $this->fromCertification($event, $payload, $tenant),
            'capability.flag_raised'  => $this->fromCapabilityFlag($event, $payload, $tenant),
            default                   => [0, []],
        };

        if ($userId <= 0 || $competencyIds === []) {
            $this->ledger($event, 'skipped', 'no learner or no competency resolved');
            return;
        }

        // The competency -> course bridge. Empty for most tenants; that is a
        // coverage fact, not a failure, and it is recorded as `skipped`.
        $courses = DB::table('course_competency_map')
            ->where('sub_institute_id', $tenant)
            ->whereIn('competency_id', $competencyIds)
            ->distinct()
            ->pluck('course_id')
            ->all();

        if ($courses === []) {
            $this->ledger($event, 'skipped', 'no course maps to that competency');
            return;
        }

        // See the class docblock: a lapsing certification is an obligation already
        // agreed, a raised flag is this system's opinion. Only the first assigns.
        $assigns = $event->type === 'certification.expiring';

        $written = 0;
        foreach ($courses as $courseId) {
            // ALREADY ENROLLED OR ALREADY ASSIGNED IS NOT A RECOMMENDATION.
            // Telling someone to take a course they are sitting in is how a
            // recommender teaches people to ignore it.
            $busy = DB::table('lms_course_enroll')
                ->where('user_id', $userId)->where('course_id', $courseId)
                ->where('sub_institute_id', $tenant)->whereNull('deleted_at')
                ->where('status', '!=', 'completed')->exists()
                || DB::table('lms_assignments')
                    ->where('user_id', $userId)->where('course_id', $courseId)
                    ->where('sub_institute_id', $tenant)->whereNull('deleted_at')->exists();

            if ($busy) {
                continue;
            }

            $written += $assigns
                ? $this->assign($event, $userId, (int) $courseId, $tenant)
                : $this->suggest($event, $userId, (int) $courseId, $tenant);
        }

        $this->ledger($event, 'done', null);

        Log::channel('single')->info($assigns ? 'remediation.assigned' : 'remediation.recommended', [
            'event_id' => $event->id,
            'type'     => $event->type,
            'user_id'  => $userId,
            'courses'  => $written,
        ]);
    }

    /**
     * The renewal path: a real assignment, mandatory, attributed to the event.
     *
     * Shaped to match LearningAssigner's insert deliberately — same table, same
     * columns, same `origin_event_id` provenance — so `lms_assignments` has one
     * vocabulary rather than two dialects depending on which reactor wrote a row.
     * `insertOrIgnore` because the unique key over (user, course, origin_event)
     * is what makes a retry of this event safe.
     *
     * @return int 1 if a row was written, 0 if one already existed
     */
    private function assign(object $event, int $userId, int $courseId, int $tenant): int
    {
        $written = DB::table('lms_assignments')->insertOrIgnore([
            'user_id'          => $userId,
            'course_id'        => $courseId,
            // A certification the role requires is not optional, and the renewal
            // of one is not either.
            'assignment_type'  => 'Mandatory',
            'status'           => 'assigned',
            // Auto-assignment is not a request: it is already decided.
            'approval_status'  => 'approved',
            'progress'         => 0,
            'sub_institute_id' => $tenant,
            'competency_id'    => (int) ($this->payload($event)['competency_id'] ?? 0) ?: null,
            'source'           => 'certification_renewal',
            'origin_event_id'  => (int) $event->id,
            'assigned_by'      => 'system',
            // No actor: the scheduler that noticed the expiry is not a person,
            // and naming one would put a fiction in the assignment's history.
            'assigned_by_id'   => (int) ($event->actor_id ?: 0) ?: null,
            'assigned_on'      => now(),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return $written > 0 ? 1 : 0;
    }

    /**
     * The flag path: unchanged. A suggestion nobody is obliged to take.
     *
     * @return int 1 if a row was written, 0 if one already existed
     */
    private function suggest(object $event, int $userId, int $courseId, int $tenant): int
    {
        $exists = DB::table('suggested_course')
            ->where('employee_id', $userId)->where('course_id', $courseId)
            ->where('sub_institute_id', $tenant)->whereNull('deleted_at')->exists();

        if ($exists) {
            return 0;
        }

        $title = DB::table('sub_std_map')->where('id', $courseId)
            ->where('sub_institute_id', $tenant)->value('display_name');

        DB::table('suggested_course')->insert([
            'employee_id'      => $userId,
            'course_id'        => $courseId,
            'course_name'      => $title,
            'sub_institute_id' => $tenant,
            'created_by'       => (int) ($event->actor_id ?: 0) ?: null,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return 1;
    }

    /** The holder, and the competency their lapsing certification covers. */
    private function fromCertification(object $event, array $payload, int $tenant): array
    {
        $row = DB::table('s_competency_certifications')
            ->where('id', (int) ($payload['certification_id'] ?? $event->entity_id))
            ->where('sub_institute_id', $tenant)
            ->first(['user_id', 'competency_id']);

        if (!$row || !$row->user_id || !$row->competency_id) {
            return [0, []];
        }

        return [(int) $row->user_id, [(int) $row->competency_id]];
    }

    /**
     * NOT REACHABLE TODAY - there is no `capability_flag` table, so nothing emits
     * this event. Written from the payload so it works the day one exists.
     */
    private function fromCapabilityFlag(object $event, array $payload, int $tenant): array
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $comp   = (int) ($payload['competency_id'] ?? 0);

        return $userId > 0 && $comp > 0 ? [$userId, [$comp]] : [0, []];
    }

    /**
     * What this recommender can actually reach, as numbers rather than a claim.
     */
    public static function coverage(): array
    {
        return [
            'course_competency_map'      => DB::table('course_competency_map')->count(),
            'certifications_expiring_90' => DB::table('s_competency_certifications')
                ->whereNotNull('expiry_date')
                ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(90)->toDateString()])
                ->count(),
            // NOT Schema::hasTable() — it throws outright on live (MariaDB
            // 10.1.48), so the one method whose job is to report coverage
            // honestly would have been the one that crashed there.
            'capability_flag_table'      => (int) (DB::selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                ['capability_flag']
            )->c ?? 0) > 0,
        ];
    }

    private function payload(object $event): array
    {
        if (is_array($event->payload)) {
            return $event->payload;
        }
        $d = json_decode((string) ($event->payload ?? ''), true);

        return is_array($d) ? $d : [];
    }

    private function ledger(object $event, string $status, ?string $error): void
    {
        DB::table('g2g_event_delivery')->updateOrInsert(
            ['event_id' => (int) $event->id, 'consumer' => self::CONSUMER],
            [
                'status'       => $status,
                'attempts'     => DB::raw('attempts + 1'),
                'last_error'   => $error,
                'completed_at' => $status === 'done' ? now() : null,
            ]
        );
    }
}
