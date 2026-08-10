<?php

namespace App\Services\Events;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * X-12 — REACTOR. Puts courses in front of people. (kind = R)
 *
 * Absorbs `MandatoryLearningAssigner`: one class, two entry points, because the
 * two differed only in where the course list came from and splitting them meant
 * two places to get idempotency wrong.
 *
 *   development_plan.approved  ->  the plan's competencies -> courses
 *   employee.role_assigned     ->  the role's courses (mandatory)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ⚠ THE TWO ENTRY POINTS ARE IN COMPLETELY DIFFERENT HEALTH. MEASURED:
 *
 *   ROLE SIDE — WORKS TODAY, BY NAME
 *     course_jobrole_map .......... 0 rows          <- the KEY path: EMPTY
 *     sub_std_map.jobrole ......... 73 of 95 courses carry a role NAME
 *     ...of which .................. 74 join rows RESOLVE by (name, tenant)
 *     => the relationship EXISTS and is held by TEXT. G-DATA-06 exactly.
 *        Read key-first, text-fallback, and the source is reported per event.
 *
 *   PLAN SIDE — DOES NOT WORK, AND CANNOT YET
 *     s_competency_plan_actions ... 377 rows, 377 WITH a competency_id
 *     course_competency_map ....... 0 rows          <- the only bridge: EMPTY
 *     ...and s_competency_plan_actions has NO course_id COLUMN AT ALL
 *     => the plan knows which COMPETENCY needs work and cannot name a COURSE.
 *        There is no text fallback here: nothing anywhere links the two.
 *
 * MY FIRST VERSION OF THIS COMMENT SAID "ALMOST NOTHING TO ASSIGN" FOR BOTH.
 * That was written after reading the two bridge tables and before finding the
 * course table - which is called `sub_std_map`, a name no search for "course"
 * returns. R20 again: I had read the empty tables and stopped there.
 *
 * THE MECHANISM IS BUILT REGARDLESS, AND COVERAGE IS REPORTED AS A NUMBER rather
 * than claimed. What is NOT done is inventing bridge rows in a customer's data to
 * make a demo work - a fabricated mapping is a lie that survives the demo. The
 * real fix for the role side is a BACKFILL of course_jobrole_map from the text,
 * exactly as F-07b did for three other mappings; that is a bulk write and is
 * raised for decision, not taken here (R13).
 * ─────────────────────────────────────────────────────────────────────────────
 */
class LearningAssigner
{
    public const CONSUMER = 'learning_assigner';

    public const HANDLES = [
        'development_plan.approved',
        'employee.role_assigned',
    ];

    public function handles(string $type): bool
    {
        return in_array($type, self::HANDLES, true);
    }

    /**
     * @throws \RuntimeException if called while replaying
     */
    public function dispatch(object $event): void
    {
        // FIRST LINE. An assignment is something a person sees and acts on; a
        // rebuild must not re-issue 49 of them.
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

        [$userId, $courses, $source, $planId] = $event->type === 'development_plan.approved'
            ? $this->fromDevelopmentPlan($event, $payload, $tenant)
            : $this->fromJobRole($event, $payload, $tenant);

        if ($userId <= 0) {
            $this->ledger($event, 'skipped', 'no learner resolved');
            return;
        }

        // NOTHING TO ASSIGN IS A RESULT, NOT A FAILURE - and today it is the
        // NORMAL result, because both bridge tables are empty. Recorded as
        // `skipped` with the reason so that "the mapping is empty" and "the
        // assigner is broken" never look the same in the ledger.
        if ($courses === []) {
            $this->ledger($event, 'skipped', 'no course mapped (' . $source . ')');
            Log::channel('single')->info('learning.no_courses', [
                'event_id' => $event->id,
                'type'     => $event->type,
                'user_id'  => $userId,
                'tenant'   => $tenant,
                'reason'   => 'course_competency_map / course_jobrole_map is empty',
            ]);
            return;
        }

        $created = 0;
        $skipped = 0;

        foreach ($courses as $row) {
            $courseId = (int) $row->course_id;

            // WEAKER THAN AN INDEX, AND SAID SO. The unique index covers
            // (user, course, origin_event) - a retry of THIS event. It cannot
            // cover "assigned by some other event", because lms_assignments
            // already holds 4 rows that violate that stronger key and those rows
            // are not mine to remove.
            $already = DB::table('lms_assignments')
                ->where('user_id', $userId)
                ->where('course_id', $courseId)
                ->where('sub_institute_id', $tenant)
                ->whereNull('deleted_at')
                ->whereIn('status', ['assigned', 'in-progress', 'pending'])
                ->exists();

            if ($already) {
                $skipped++;
                continue;
            }

            $written = DB::table('lms_assignments')->insertOrIgnore([
                'user_id'             => $userId,
                'course_id'           => $courseId,
                'assignment_type'     => $event->type === 'employee.role_assigned' ? 'Mandatory' : 'Recommended',
                'status'              => 'assigned',
                // Auto-assignment is not a request: it is already decided.
                'approval_status'     => 'approved',
                'progress'            => 0,
                'sub_institute_id'    => $tenant,
                'development_plan_id' => $planId,
                'competency_id'       => $row->competency_id ?? null,
                'source'              => $source,
                'origin_event_id'     => (int) $event->id,
                'assigned_by'         => 'system',
                'assigned_by_id'      => (int) ($event->actor_id ?: 0) ?: null,
                'assigned_on'         => now(),
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            $written > 0 ? $created++ : $skipped++;
        }

        $this->ledger($event, 'done', null);

        Log::channel('single')->info('learning.assigned', [
            'event_id' => $event->id,
            'user_id'  => $userId,
            'created'  => $created,
            'skipped'  => $skipped,
            'source'   => $source,
        ]);
    }

    /**
     * A plan's competencies -> courses.
     *
     * 377 of 377 plan actions carry a competency_id, so the FIRST hop is complete.
     * The second hop reads course_competency_map, which is empty - the assigner is
     * one populated table away from working.
     *
     * @return array{0:int, 1:array, 2:string, 3:?int}
     */
    private function fromDevelopmentPlan(object $event, array $payload, int $tenant): array
    {
        $planId = (int) ($payload['plan_id'] ?? $event->entity_id ?? 0);

        $plan = DB::table('s_competency_development_plans')
            ->where('id', $planId)
            ->where('sub_institute_id', $tenant)
            ->first(['id', 'user_id', 'competency_id']);

        if (!$plan) {
            return [0, [], 'development_plan', null];
        }

        // The plan's own competency, plus every competency its actions name.
        $competencyIds = DB::table('s_competency_plan_actions')
            ->where('plan_id', $planId)
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->whereNotNull('competency_id')
            ->pluck('competency_id')
            ->push($plan->competency_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($competencyIds === []) {
            return [(int) $plan->user_id, [], 'development_plan', $planId];
        }

        $courses = DB::table('course_competency_map')
            ->where('sub_institute_id', $tenant)
            ->whereIn('competency_id', $competencyIds)
            ->get(['course_id', 'competency_id'])
            ->unique('course_id')
            ->values()
            ->all();

        return [(int) $plan->user_id, $courses, 'development_plan', $planId];
    }

    /**
     * A role's mandatory courses. course_jobrole_map is empty, so this returns
     * nothing today and will return everything the day it is populated.
     *
     * @return array{0:int, 1:array, 2:string, 3:?int}
     */
    private function fromJobRole(object $event, array $payload, int $tenant): array
    {
        $userId    = (int) ($payload['user_id'] ?? $event->entity_id ?? 0);
        $jobroleId = (int) ($payload['jobrole_id'] ?? 0);

        if ($jobroleId === 0 && $userId > 0) {
            // THE EMPLOYEE'S JOB ROLE LIVES IN `tbluser.allocated_standards`,
            // which holds an s_user_jobrole id (287 of 387 populated). Same path
            // CompetencyGapController uses. My first version here queried
            // `s_user_jobrole.user_id` - A COLUMN THAT DOES NOT EXIST on that
            // table; it is the tenant's job-role LIBRARY, not a user link.
            // Caught by reading the working caller instead of assuming the name.
            $jobroleId = (int) DB::table('tbluser')
                ->where('id', $userId)
                ->where('sub_institute_id', $tenant)
                ->value('allocated_standards');
        }

        if ($jobroleId === 0) {
            return [$userId, [], 'jobrole', null];
        }

        // ── KEY FIRST ───────────────────────────────────────────────────────
        $courses = DB::table('course_jobrole_map')
            ->where('sub_institute_id', $tenant)
            ->where('jobrole_id', $jobroleId)
            ->get(['course_id'])
            ->map(fn ($r) => (object) ['course_id' => $r->course_id, 'competency_id' => null])
            ->all();

        if ($courses !== []) {
            return [$userId, $courses, 'jobrole', null];
        }

        // ── TEXT FALLBACK, AND IT IS THE ONLY PATH THAT WORKS TODAY ─────────
        //
        // course_jobrole_map holds 0 rows. But `sub_std_map` - the course table,
        // named that for historical reasons - carries a `jobrole` TEXT column,
        // and 73 of 95 courses have one. 74 of those resolve to a job role by
        // (name, tenant).
        //
        // THIS IS G-DATA-06 EXACTLY: the relationship EXISTS, held by name rather
        // than by key. Refusing to read it would mean X-12 assigns nothing at all
        // while the data to assign from is sitting there.
        //
        // BOTH SIDES CARRY THE TENANT CONDITION, so this cannot match across
        // organisations - the failure mode L-11 was chasing. It is a text join
        // used knowingly, with the guard that makes it safe, and it is reported
        // as `jobrole_text` so nobody mistakes it for the key path.
        //
        // THE REAL FIX IS A BACKFILL of course_jobrole_map from this column,
        // exactly as F-07b did for the other three mappings. NOT DONE HERE: it is
        // a bulk write, and bulk writes are asked for, not assumed (R13).
        $courses = DB::table('sub_std_map as c')
            ->join('s_user_jobrole as j', function ($join) {
                $join->on('j.jobrole', '=', 'c.jobrole')
                    ->on('j.sub_institute_id', '=', 'c.sub_institute_id');
            })
            ->where('j.id', $jobroleId)
            ->where('c.sub_institute_id', $tenant)
            ->whereNull('c.deleted_at')
            ->whereNotNull('c.jobrole')
            ->where('c.jobrole', '!=', '')
            ->get(['c.id as course_id'])
            ->map(fn ($r) => (object) ['course_id' => $r->course_id, 'competency_id' => null])
            ->unique('course_id')
            ->values()
            ->all();

        return [$userId, $courses, 'jobrole_text', null];
    }

    /**
     * COVERAGE, AS A NUMBER RATHER THAN AS A CLAIM.
     *
     * Called by the smoke suite and by the X-12 proof. If someone populates the
     * bridge tables, this is what says so.
     *
     * @return array{competency_map:int, jobrole_map:int, plan_actions_with_competency:int}
     */
    public static function coverage(): array
    {
        return [
            // The KEY paths. Both empty today.
            'competency_map'               => DB::table('course_competency_map')->count(),
            'jobrole_map'                  => DB::table('course_jobrole_map')->count(),
            // The TEXT path that is carrying the whole role side, and the
            // backfill target it implies.
            'courses_with_jobrole_text'    => DB::table('sub_std_map')
                ->whereNull('deleted_at')->whereNotNull('jobrole')->where('jobrole', '!=', '')->count(),
            'jobrole_text_resolves'        => DB::table('sub_std_map as c')
                ->join('s_user_jobrole as j', function ($join) {
                    $join->on('j.jobrole', '=', 'c.jobrole')
                        ->on('j.sub_institute_id', '=', 'c.sub_institute_id');
                })
                ->whereNull('c.deleted_at')->whereNotNull('c.jobrole')->where('c.jobrole', '!=', '')->count(),
            'plan_actions_with_competency' => DB::table('s_competency_plan_actions')
                ->whereNotNull('competency_id')->count(),
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
