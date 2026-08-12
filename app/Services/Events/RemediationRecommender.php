<?php

namespace App\Services\Events;

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
 * IT RECOMMENDS. IT DOES NOT ASSIGN. LearningAssigner (X-12) assigns, because an
 * assignment is a decision someone made and a recommendation is not. Writing
 * recommendations into `lms_assignments` would turn "you might want this" into
 * "you must do this" with no one having chosen it.
 */
class RemediationRecommender
{
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

            $exists = DB::table('suggested_course')
                ->where('employee_id', $userId)->where('course_id', $courseId)
                ->where('sub_institute_id', $tenant)->whereNull('deleted_at')->exists();

            if ($exists) {
                continue;
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
            $written++;
        }

        $this->ledger($event, 'done', null);

        Log::channel('single')->info('remediation.recommended', [
            'event_id' => $event->id,
            'type'     => $event->type,
            'user_id'  => $userId,
            'courses'  => $written,
        ]);
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
            'capability_flag_table'      => \Illuminate\Support\Facades\Schema::hasTable('capability_flag'),
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
