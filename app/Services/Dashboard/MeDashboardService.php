<?php

namespace App\Services\Dashboard;

use App\Services\Competency\ProficiencyService;
use Illuminate\Support\Facades\DB;

/**
 * EVERY NUMBER ON THE EMPLOYEE'S OWN DASHBOARD.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * ONE RULE ABOVE ALL OTHERS: $me IS NEVER A PARAMETER FROM THE REQUEST
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Every method here takes $me as an int and filters on it. The controller
 * obtains it from the token and from nowhere else, so there is no id in the
 * request to tamper with — the same structural argument MyCapabilityController
 * makes, and for the same reason: an endpoint that accepts no subject cannot be
 * made to return somebody else's data.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * WHAT THE MEASURED DATA FORCED
 * ═══════════════════════════════════════════════════════════════════════
 *
 * These are not defensive habits, they are responses to what the live tables
 * actually contain. Each is recorded where it is applied.
 *
 *   123 of tenant 6's 611 tasks have a BLANK status. It becomes a named
 *   "Not started" bucket, and it is kept OUT of the overdue count.
 *
 *   Only 6 of those 611 tasks carry a jobrole_task_id, so an ESO lookup keyed
 *   on the assigned task finds nothing. The job-role path finds 35 rows for one
 *   role. The join goes through the ROLE.
 *
 *   Tenant 6's 9 course_competency_map rows name competencies that do not
 *   exist. The recommendation query reports the break instead of returning [].
 *
 *   hrms_leave_allocation.employee_id is NULL on all 12 tenant-6 rows —
 *   allocation is by DEPARTMENT there. Both shapes are read, and the answer
 *   says which one it used.
 *
 * NULL IS NOT ZERO, everywhere. A measurement that could not be taken returns
 * null with a reason beside it; 0 is reserved for a real count of nothing.
 */
class MeDashboardService
{
    /**
     * The statuses that mean "someone has this in hand".
     *
     * Vocabulary and normalisation copied from MyTasksController so the tile and
     * the task list cannot disagree — the raw column holds both 'completed' and
     * 'COMPLETED', and UPPER(TRIM(...)) is the only comparison that sees them as
     * one value.
     */
    private const STARTED = ['PENDING', 'IN-PROGRESS', 'IN PROGRESS', 'ON HOLD'];

    /**
     * The ONE proficiency roll-up. Injected rather than reimplemented — see
     * capabilityGap(), which used to take an unweighted mean and disagree with
     * every other capability screen for any competency whose KASBA items carry
     * different weights.
     */
    public function __construct(private ProficiencyService $proficiency)
    {
    }

    private const STATUS_SQL = "UPPER(TRIM(COALESCE(task.status,'')))";

    /* ══════════════════════════════════════════════════════════════════
     * TASKS
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * The task tile set and both task charts.
     *
     * OVERDUE EXCLUDES TASKS NOBODY EVER STARTED, and that is the single most
     * consequential decision in this file. WorkforceMetricsService::overdueTasks
     * treats a blank status as PENDING, which is right for an organisation-wide
     * hygiene figure. Applied to one person it reads as an accusation: on live,
     * user 43 has 138 tasks of which 24 have no status at all, and the
     * undifferentiated rule reports 103 of them overdue.
     *
     * So the blank ones are counted and named separately. `overdue` is work in
     * progress that has run late; `past_due_untracked` is a record-keeping gap.
     * Presenting them as one number would be a bug wearing a number.
     */
    public function taskSummary(int $sid, string $syear, int $me): array
    {
        $base = fn () => DB::table('task')
            ->where('task.sub_institute_id', $sid)
            ->where('task.SYEAR', $syear)
            ->whereNull('task.deleted_at')
            ->where('task.task_allocated_to', $me);

        $today = date('Y-m-d');

        // One grouped scan for the whole status picture rather than five COUNTs.
        $byStatus = $base()
            ->groupBy(DB::raw(self::STATUS_SQL))
            ->selectRaw(self::STATUS_SQL . ' as normalised, COUNT(*) as n')
            ->pluck('n', 'normalised');

        $total = (int) $byStatus->sum();

        $bucket = function (array $keys) use ($byStatus) {
            $n = 0;
            foreach ($keys as $k) {
                $n += (int) ($byStatus[$k] ?? 0);
            }
            return $n;
        };

        $notStarted = $bucket(['']);
        $completed  = $bucket(['COMPLETED']);
        $inProgress = $bucket(['IN-PROGRESS', 'IN PROGRESS']);
        $onHold     = $bucket(['ON HOLD']);
        $pending    = $bucket(['PENDING']);

        // Anything the vocabulary does not name. Reported rather than dropped:
        // a tenant that invents a status should see it, not lose the rows.
        $known = ['', 'COMPLETED', 'IN-PROGRESS', 'IN PROGRESS', 'ON HOLD', 'PENDING'];
        $other = 0;
        foreach ($byStatus as $status => $n) {
            if (!in_array((string) $status, $known, true)) {
                $other += (int) $n;
            }
        }

        $overdue = (int) $base()
            ->whereDate('task.task_date', '<', $today)
            ->whereIn(DB::raw(self::STATUS_SQL), self::STARTED)
            ->count();

        $pastDueUntracked = (int) $base()
            ->whereDate('task.task_date', '<', $today)
            ->whereRaw(self::STATUS_SQL . " = ''")
            ->count();

        $dueSoon = (int) $base()
            ->whereDate('task.task_date', '>=', $today)
            ->whereDate('task.task_date', '<=', date('Y-m-d', strtotime('+7 days')))
            ->whereRaw(self::STATUS_SQL . " <> 'COMPLETED'")
            ->count();

        // task_type is the priority vocabulary (High/Medium/Low). It is the more
        // useful second chart for a tenant whose every task shares one status —
        // on live it is populated on 488 of 611 tenant-6 rows and on all 1005
        // tenant-7 rows, where the status donut would be a single wedge.
        $byPriority = $base()
            ->groupBy(DB::raw("UPPER(TRIM(COALESCE(task.task_type,'')))"))
            ->selectRaw("UPPER(TRIM(COALESCE(task.task_type,''))) as p, COUNT(*) as n")
            ->pluck('n', 'p');

        $priority = [];
        foreach (['HIGH', 'MEDIUM', 'LOW'] as $label) {
            $priority[] = ['label' => ucfirst(strtolower($label)), 'value' => (int) ($byPriority[$label] ?? 0)];
        }
        $prioritySet = array_sum(array_column($priority, 'value'));
        if ($total > $prioritySet) {
            $priority[] = ['label' => 'Unset', 'value' => $total - $prioritySet];
        }

        $completionRate = $total > 0 ? round(($completed / $total) * 100, 1) : null;

        return [
            'total'      => $total,
            'completed'  => $completed,
            'in_progress'=> $inProgress,
            'on_hold'    => $onHold,
            'pending'    => $pending,
            'not_started'=> $notStarted,
            'other'      => $other,

            'overdue'            => $overdue,
            'past_due_untracked' => $pastDueUntracked,
            'due_next_7_days'    => $dueSoon,

            // null, not 0, when there is nothing to compute a rate from.
            'completion_rate' => $completionRate,

            'status_breakdown' => array_values(array_filter([
                ['label' => 'Completed',   'value' => $completed,  'key' => 'completed'],
                ['label' => 'In progress', 'value' => $inProgress, 'key' => 'in_progress'],
                ['label' => 'Pending',     'value' => $pending,    'key' => 'pending'],
                ['label' => 'On hold',     'value' => $onHold,     'key' => 'on_hold'],
                // NEVER an unlabelled slice. This is a fifth of tenant 6's rows.
                ['label' => 'Not started',  'value' => $notStarted, 'key' => 'not_started'],
                ['label' => 'Other',       'value' => $other,      'key' => 'other'],
            ], fn ($s) => $s['value'] > 0)),

            'priority_breakdown' => array_values(array_filter($priority, fn ($p) => $p['value'] > 0)),

            'untracked_note' => $notStarted > 0
                ? $notStarted . ' of your tasks have no status recorded, so they are shown as Not started and are not counted as overdue.'
                : null,
        ];
    }

    /** The next few things actually due, newest deadline first. */
    public function upcomingTasks(int $sid, string $syear, int $me, int $limit = 6): array
    {
        return DB::table('task')
            ->where('task.sub_institute_id', $sid)
            ->where('task.SYEAR', $syear)
            ->whereNull('task.deleted_at')
            ->where('task.task_allocated_to', $me)
            ->whereRaw(self::STATUS_SQL . " <> 'COMPLETED'")
            ->whereNotNull('task.task_date')
            ->orderBy('task.task_date')
            ->limit($limit)
            ->get(['task.id', 'task.task_title', 'task.task_date', 'task.task_type', 'task.status'])
            ->map(fn ($r) => [
                'id'       => (int) $r->id,
                'title'    => $r->task_title,
                'due'      => $r->task_date,
                'priority' => $r->task_type ?: null,
                'status'   => trim((string) $r->status) ?: 'Not started',
                'overdue'  => $r->task_date !== null && $r->task_date < date('Y-m-d'),
            ])
            ->all();
    }

    /* ══════════════════════════════════════════════════════════════════
     * CAPABILITY
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Required proficiency against the caller's own KASBA ratings, per competency.
     *
     * The join is the one MyCapabilityController already proves:
     *   jobrole_competency_map -> competency_kasba_item -> competency_kasba_rating
     * with the rating leg bound to $me. It is repeated here rather than called
     * because that controller returns the full item tree — several hundred rows
     * — and this widget needs one number per competency.
     *
     * A competency with NO rated items returns current = null. It is not a zero:
     * "not yet assessed" and "assessed as incompetent" are opposite facts, and on
     * live the first is overwhelmingly the common one.
     */
    public function capabilityGap(int $sid, int $me, ?int $jobroleId): array
    {
        if ($jobroleId === null) {
            return [
                'axes'         => [],
                'empty_reason' => 'You do not have a job role yet, so no competencies are required of you. Ask your HR team to set one.',
                'action_key'   => null,
            ];
        }

        $rows = DB::table('jobrole_competency_map as m')
            ->leftJoin('competency as c', 'c.id', '=', 'm.competency_id')
            ->leftJoin('competency_kasba_item as k', function ($j) use ($sid) {
                $j->on('k.competency_id', '=', 'm.competency_id')
                  ->where('k.sub_institute_id', '=', $sid);
            })
            // THE CALLER'S OWN RATINGS ONLY, bound by $me on the JOIN rather than
            // in WHERE — in a WHERE it would turn the left join into an inner one
            // and silently drop every competency the caller has not been rated on,
            // which is most of them.
            ->leftJoin('competency_kasba_rating as r', function ($j) use ($me, $sid) {
                $j->on('r.kasba_item_id', '=', 'k.id')
                  ->where('r.user_id', '=', $me)
                  ->where('r.sub_institute_id', '=', $sid);
            })
            ->where('m.sub_institute_id', $sid)
            ->where('m.jobrole_id', $jobroleId)
            ->groupBy('m.competency_id', 'c.name', 'm.required_proficiency', 'm.is_mandatory')
            ->orderBy('c.name')
            ->selectRaw('m.competency_id, c.name as competency_name, m.required_proficiency, m.is_mandatory,
                         COUNT(DISTINCT k.id) as items_total,
                         COUNT(DISTINCT r.id) as items_rated')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'axes'         => [],
                'empty_reason' => 'Your job role does not have any competencies mapped to it yet, so there is nothing to measure against. Ask your HR team to map them.',
                'action_key'   => 'capability_explorer',
            ];
        }

        /*
         * THE LEVEL COMES FROM ProficiencyService, NOT FROM AVG(r.rating).
         *
         * This computed `AVG(r.rating)` in the query above — an UNWEIGHTED mean —
         * while ProficiencyService divides Σ(weight × rating) by the weight of the
         * MEASURED items only. Its own header says it is "never computed anywhere
         * else… a second implementation is a second answer", and this was the
         * second answer.
         *
         * It is not academic. 20 competencies hold KASBA items of differing
         * weights, 11 of them belong to tenant 6 and all 11 are mapped to a
         * tenant-6 job role — so for those, an employee's Me dashboard radar and
         * their own My Capability screen showed DIFFERENT levels for the same
         * competency on the same day. `JobroleTaskCompetencyMapController` already
         * documents this exact bug as fixed there; it was still live here.
         *
         * Calling the service rather than porting its formula is the point: one
         * implementation, one answer, and a change to the weighting rule reaches
         * every screen at once.
         */
        $rollUp = $this->proficiency->rollUp($sid, $me, $rows->pluck('competency_id')->map(fn ($v) => (int) $v)->all());

        $axes = $rows->map(function ($r) use ($rollUp) {
            $level = $rollUp[(int) $r->competency_id]['level'] ?? null;

            return [
                'competency_id' => (int) $r->competency_id,
                'competency'    => $r->competency_name ?: ('Competency #' . $r->competency_id),
                'required'      => $r->required_proficiency === null ? null : (float) $r->required_proficiency,
                // null when nothing has been rated. Never 0. rollUp() returns null
                // for a competency with no measured weight, which is the same
                // statement made in the same vocabulary.
                'current'       => $level === null ? null : round((float) $level, 2),
                'items_total'   => (int) $r->items_total,
                'items_rated'   => (int) $r->items_rated,
                'mandatory'     => (bool) $r->is_mandatory,
            ];
        })->values()->all();

        $measured = array_values(array_filter($axes, fn ($a) => $a['current'] !== null && $a['required'] !== null));
        $below    = array_values(array_filter($measured, fn ($a) => $a['current'] < $a['required']));

        return [
            'axes'              => $axes,
            'axes_total'        => count($axes),
            'axes_measured'     => count($measured),
            'axes_below_target' => count($below),
            // Below three measured axes a radar degenerates into a line, so the
            // client is told to draw bars instead of being left to discover it.
            'chart'             => count($measured) >= 3 ? 'radar' : 'bars',
            'empty_reason'      => count($measured) === 0
                ? 'None of your competencies have been rated yet, so there is a target but nothing to compare against. Ratings arrive from a self-assessment or from your manager.'
                : null,
            'action_key'        => 'capability_explorer',
        ];
    }

    /* ══════════════════════════════════════════════════════════════════
     * EXECUTION MODEL (ESO)
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * How the work of this job role is classified — by execution mode and risk.
     *
     * THE JOIN GOES THROUGH THE JOB ROLE, NOT THROUGH THE ASSIGNED TASK, and
     * that is a measurement, not a preference. On live only 6 of tenant 6's 611
     * assigned tasks carry a jobrole_task_id, so keying on task.jobrole_task_id
     * returns 1, 1 and 0 rows for the tenant's three busiest employees. Keying on
     * s_user_jobrole_task.jobrole_id returns 35, 13 and 30.
     *
     * The proposed/approved split is reported and NOT collapsed: 3,552 of the
     * 3,572 classifications on live are still AI-proposed, and presenting a
     * proposal as an agreed fact is the error this whole module was built to
     * avoid.
     */
    public function executionMix(int $sid, ?int $jobroleId): array
    {
        if ($jobroleId === null) {
            return [
                'modes'        => [],
                'total'        => 0,
                'empty_reason' => 'You do not have a job role yet, so the work of your role has not been classified. Ask your HR team to set one.',
            ];
        }

        $rows = DB::table('s_user_jobrole_task as ujt')
            ->join('jobrole_task_execution as e', 'e.user_jobrole_task_id', '=', 'ujt.id')
            ->where('ujt.jobrole_id', $jobroleId)
            ->where('e.sub_institute_id', $sid)
            ->whereNull('ujt.deleted_at')
            ->groupBy('e.execution_mode_current', 'e.risk_class', 'e.classification_status')
            ->selectRaw('e.execution_mode_current as mode, e.risk_class, e.classification_status,
                         COUNT(*) as n,
                         SUM(COALESCE(e.human_effort_current_min,0)) as effort_current,
                         SUM(COALESCE(e.human_effort_target_min,0)) as effort_target')
            ->get();

        if ($rows->isEmpty()) {
            return [
                'modes'        => [],
                'total'        => 0,
                'empty_reason' => 'The tasks of your job role have not been classified yet. Once they are, this shows which of them a person must do, which a person and an agent share, and which can be automated.',
            ];
        }

        $modes = [];
        $risks = [];
        $reviewed = 0;
        $total = 0;
        $effortCurrent = 0;
        $effortTarget  = 0;

        foreach ($rows as $r) {
            $mode = $r->mode ?: 'unclassified';
            $risk = $r->risk_class ?: 'Unclassified';
            $n    = (int) $r->n;

            $modes[$mode] = ($modes[$mode] ?? 0) + $n;
            $risks[$risk] = ($risks[$risk] ?? 0) + $n;
            $total += $n;
            $effortCurrent += (int) $r->effort_current;
            $effortTarget  += (int) $r->effort_target;

            // Anything a person has signed off. Approved and Human-reviewed are
            // the two human verdicts TaskExecutionController recognises.
            if (in_array((string) $r->classification_status, ['Approved', 'Human-reviewed'], true)) {
                $reviewed += $n;
            }
        }

        arsort($modes);
        arsort($risks);

        $label = [
            'human_only'      => 'Person only',
            'human_ai_assist' => 'Person with agent support',
            'ai_human_review' => 'Agent, person reviews',
            'automated'       => 'Fully automated',
            'unclassified'    => 'Not classified',
        ];

        return [
            'total'  => $total,
            'modes'  => array_map(
                fn ($k) => ['key' => $k, 'label' => $label[$k] ?? $k, 'value' => $modes[$k]],
                array_keys($modes),
            ),
            'risks'  => array_map(
                fn ($k) => ['label' => $k, 'value' => $risks[$k]],
                array_keys($risks),
            ),
            'reviewed'        => $reviewed,
            'proposed'        => $total - $reviewed,
            'effort_current_min' => $effortCurrent,
            'effort_target_min'  => $effortTarget,
            // Minutes a week the target model would hand back, if the proposals
            // are accepted. Stated as conditional because most are not yet.
            'effort_released_min' => max(0, $effortCurrent - $effortTarget),
            'review_note'     => $reviewed < $total
                ? ($total - $reviewed) . ' of these ' . $total . ' are still proposals awaiting human review.'
                : null,
            'empty_reason'    => null,
        ];
    }

    /**
     * Written procedures (ESO instances) covering this role's tasks.
     *
     * Same job-role path as executionMix, for the same measured reason.
     */
    public function procedures(int $sid, ?int $jobroleId, int $limit = 5): array
    {
        if ($jobroleId === null) {
            return ['items' => [], 'total' => 0];
        }

        $q = DB::table('eso')
            ->join('s_user_jobrole_task as ujt', 'ujt.id', '=', 'eso.user_jobrole_task_id')
            ->where('eso.sub_institute_id', $sid)
            ->where('eso.scope', 'Instance')
            ->whereNull('eso.deleted_at')
            ->where('ujt.jobrole_id', $jobroleId);

        $total = (int) (clone $q)->count();

        $items = $q->orderByDesc('eso.updated_at')
            ->limit($limit)
            ->get(['eso.id', 'eso.title', 'eso.status', 'eso.execution_mode', 'eso.version', 'ujt.task'])
            ->map(fn ($r) => [
                'id'      => (int) $r->id,
                'title'   => $r->title ?: $r->task,
                'task'    => $r->task,
                'status'  => $r->status,
                'mode'    => $r->execution_mode,
                'version' => $r->version,
            ])->all();

        return ['items' => $items, 'total' => $total];
    }

    /* ══════════════════════════════════════════════════════════════════
     * LEARNING
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Enrolled courses, and courses that would close a measured gap.
     *
     * THE RECOMMENDATION CHAIN IS BROKEN BY DATA IN THE SHOWCASE TENANT and this
     * method says so rather than returning an empty list. Tenant 6's nine
     * course_competency_map rows name competency ids 397-405; that tenant's
     * competencies are 423-445. Zero of the nine resolve. An empty array here
     * would read as "no courses match your gaps", which is a different and
     * untrue claim from "the mapping points at competencies that do not exist".
     *
     * @param list<int> $gapCompetencyIds competencies the caller is below target on
     */
    public function learning(int $sid, int $me, array $gapCompetencyIds): array
    {
        $enrolled = DB::table('lms_course_enroll as e')
            ->leftJoin('sub_std_map as c', function ($j) use ($sid) {
                $j->on('c.id', '=', 'e.course_id')->where('c.sub_institute_id', '=', $sid);
            })
            ->where('e.sub_institute_id', $sid)
            ->where('e.user_id', $me)
            ->whereNull('e.deleted_at')
            ->orderByDesc('e.id')
            ->limit(8)
            ->get(['e.id', 'e.status', 'e.start_date', 'e.end_date', 'c.display_name', 'c.subject_code', 'c.proficiency'])
            ->map(fn ($r) => [
                'id'          => (int) $r->id,
                'title'       => $r->display_name ?: ('Course #' . $r->id),
                'code'        => $r->subject_code,
                'status'      => $r->status,
                'proficiency' => $r->proficiency,
                'start'       => $r->start_date,
                'end'         => $r->end_date,
            ])->all();

        $recommended = [];
        $mappingNote = null;

        if (!empty($gapCompetencyIds)) {
            // Every mapping for the gap competencies, WITHOUT the course join, so
            // a broken foreign key is visible as a count rather than as absence.
            $mapped = DB::table('course_competency_map')
                ->where('sub_institute_id', $sid)
                ->whereIn('competency_id', $gapCompetencyIds)
                ->get(['course_id', 'competency_id', 'proficiency_level']);

            $rows = DB::table('course_competency_map as m')
                ->join('sub_std_map as c', function ($j) use ($sid) {
                    $j->on('c.id', '=', 'm.course_id')->where('c.sub_institute_id', '=', $sid);
                })
                ->leftJoin('competency as k', 'k.id', '=', 'm.competency_id')
                ->where('m.sub_institute_id', $sid)
                ->whereIn('m.competency_id', $gapCompetencyIds)
                ->whereNull('c.deleted_at')
                ->limit(8)
                ->get(['c.id', 'c.display_name', 'c.subject_code', 'm.proficiency_level', 'k.name as competency_name']);

            $recommended = $rows->map(fn ($r) => [
                'course_id'   => (int) $r->id,
                'title'       => $r->display_name ?: ('Course #' . $r->id),
                'code'        => $r->subject_code,
                'level'       => $r->proficiency_level,
                'competency'  => $r->competency_name,
            ])->all();

            if ($mapped->isNotEmpty() && empty($recommended)) {
                $mappingNote = $mapped->count() . ' course mappings exist for the competencies you are below target on, but none of them resolve to a course in this organisation. The course-to-competency import needs correcting before suggestions can appear.';
            }
        }

        // Tenant-wide catalogue size, so "no suggestions" can be told apart from
        // "no courses exist here at all".
        $catalogue = (int) DB::table('sub_std_map')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->count();

        return [
            'enrolled'         => $enrolled,
            'enrolled_total'   => count($enrolled),
            'recommended'      => $recommended,
            'catalogue_total'  => $catalogue,
            'mapping_note'     => $mappingNote,
            'empty_reason'     => empty($enrolled)
                ? ($catalogue === 0
                    ? 'No courses have been published for your organisation yet, so there is nothing to enrol on.'
                    : 'You are not enrolled on any course yet. ' . $catalogue . ' are available in the catalogue.')
                : null,
        ];
    }

    /* ══════════════════════════════════════════════════════════════════
     * HR
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Leave taken and — where it can be established — leave allocated.
     *
     * ALLOCATION IS READ TWICE ON PURPOSE. hrms_leave_allocation carries both
     * employee_id and department_id, and on live tenant 6 all twelve rows have
     * employee_id NULL and are allocated by department. Reading only the personal
     * shape would report "no allocation" to an employee who has one; reading only
     * the department shape would attribute a colleague's personal grant to them.
     * Both are read, the personal one wins, and the answer says which was used.
     */
    public function leave(int $sid, int $me, ?int $departmentId): array
    {
        // THE ALLOCATION YEAR IS THE LATEST ONE THIS TENANT HAS ROWS FOR, not
        // necessarily this one. Live tenant 6 allocated for 2025 and has not
        // rolled forward; asking only for date('Y') reports "no entitlement" to
        // every employee who has one, which is a worse answer than a correctly
        // dated older figure. The year used is returned so the widget can say so.
        $year = (int) (DB::table('hrms_leave_allocation')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->where('year', '<=', (int) date('Y'))
            ->max('year') ?: date('Y'));

        $requests = DB::table('hrms_emp_leaves as l')
            ->leftJoin('hrms_leave_types as t', function ($j) use ($sid) {
                $j->on('t.id', '=', 'l.leave_type_id')->where('t.sub_institute_id', '=', $sid);
            })
            ->where('l.sub_institute_id', $sid)
            ->where('l.user_id', $me)
            ->whereNull('l.deleted_at')
            ->orderByDesc('l.from_date')
            ->limit(6)
            ->get(['l.id', 'l.status', 'l.from_date', 'l.to_date', 'l.day_type', 't.leave_type'])
            ->map(fn ($r) => [
                'id'     => (int) $r->id,
                'type'   => $r->leave_type ?: 'Leave',
                'status' => $r->status ?: 'pending',
                'from'   => $r->from_date,
                'to'     => $r->to_date,
                'day_type' => $r->day_type,
            ])->all();

        $pending = (int) DB::table('hrms_emp_leaves')
            ->where('sub_institute_id', $sid)->where('user_id', $me)->whereNull('deleted_at')
            ->whereRaw("LOWER(COALESCE(status,'')) IN ('pending','applied','')")
            ->count();

        $personal = DB::table('hrms_leave_allocation')
            ->where('sub_institute_id', $sid)
            ->where('employee_id', $me)
            ->where('year', $year)
            ->whereNull('deleted_at')
            ->sum('value');

        $allocated   = (float) $personal;
        $allocatedBy = 'employee';

        if ($allocated <= 0 && $departmentId !== null) {
            $allocated   = (float) DB::table('hrms_leave_allocation')
                ->where('sub_institute_id', $sid)
                ->whereNull('employee_id')
                ->where('department_id', $departmentId)
                ->where('year', $year)
                ->whereNull('deleted_at')
                ->sum('value');
            $allocatedBy = 'department';
        }

        return [
            'requests'      => $requests,
            'pending'       => $pending,
            // null, not 0 — "no allocation is recorded" is not "you have no days".
            'allocated_days'=> $allocated > 0 ? $allocated : null,
            'allocated_by'  => $allocated > 0 ? $allocatedBy : null,
            'year'          => $year,
            'allocation_note' => $this->allocationNote($allocated, $allocatedBy, $year),
            'empty_reason'  => empty($requests)
                ? 'You have not applied for any leave. Applications and their decisions appear here.'
                : null,
        ];
    }

    /**
     * What to say about the entitlement figure.
     *
     * Three separate facts, none of which may be told as another: it is yours,
     * it is your department's, or nobody recorded one. A stale year is always
     * named — an employee reading "20 days" needs to know it is last year's.
     */
    private function allocationNote(float $allocated, string $by, int $year): ?string
    {
        if ($allocated <= 0) {
            return 'No leave entitlement is recorded against you or your department for ' . $year . '.';
        }

        $stale = $year < (int) date('Y')
            ? ' No allocation has been made for ' . date('Y') . ' yet, so this is the ' . $year . ' figure.'
            : '';

        if ($by === 'department') {
            return 'Your entitlement is allocated to your department rather than to you individually, so this is the departmental figure.' . $stale;
        }

        return $stale !== '' ? trim($stale) : null;
    }

    /** Certifications held, with the ones about to lapse called out. */
    public function certifications(int $sid, int $me): array
    {
        $rows = DB::table('s_competency_certifications')
            ->where('sub_institute_id', $sid)
            ->where('user_id', $me)
            ->whereNull('deleted_at')
            ->orderBy('expiry_date')
            ->get(['id', 'name', 'issuing_body', 'status', 'issued_date', 'expiry_date']);

        $today  = date('Y-m-d');
        $cutoff = date('Y-m-d', strtotime('+90 days'));

        $expiring = 0;
        $expired  = 0;

        $items = $rows->map(function ($r) use ($today, $cutoff, &$expiring, &$expired) {
            $state = 'valid';
            if ($r->expiry_date !== null && $r->expiry_date !== '') {
                if ($r->expiry_date < $today) {
                    $state = 'expired';
                    $expired++;
                } elseif ($r->expiry_date <= $cutoff) {
                    $state = 'expiring';
                    $expiring++;
                }
            } else {
                $state = 'no_expiry';
            }

            return [
                'id'      => (int) $r->id,
                'name'    => $r->name,
                'body'    => $r->issuing_body,
                'status'  => $r->status,
                'expires' => $r->expiry_date,
                'state'   => $state,
            ];
        })->all();

        return [
            'items'        => array_slice($items, 0, 6),
            'total'        => count($items),
            'expiring_90d' => $expiring,
            'expired'      => $expired,
            'empty_reason' => empty($items)
                ? 'No certifications are recorded against you. Once HR records one, its expiry is tracked here.'
                : null,
        ];
    }

    /**
     * Assessments assigned to the caller.
     *
     * Reads s_competency_assessments, the table that actually holds assignments.
     * The five competency_assessment_* tables are empty in every tenant on live,
     * so a widget built on them would be decorative by construction.
     */
    public function assessments(int $sid, int $me): array
    {
        $rows = DB::table('s_competency_assessments')
            ->where('sub_institute_id', $sid)
            ->where('user_id', $me)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get(['id', 'title', 'jobrole', 'status', 'due_date', 'completed_at', 'score']);

        $open = (int) DB::table('s_competency_assessments')
            ->where('sub_institute_id', $sid)->where('user_id', $me)->whereNull('deleted_at')
            ->whereRaw("LOWER(COALESCE(status,'')) NOT IN ('completed','cancelled')")
            ->count();

        return [
            'items' => $rows->map(fn ($r) => [
                'id'        => (int) $r->id,
                'title'     => $r->title ?: ($r->jobrole ?: 'Assessment #' . $r->id),
                'jobrole'   => $r->jobrole,
                'status'    => $r->status,
                'due'       => $r->due_date,
                'completed' => $r->completed_at,
                // A score of null is "not scored", never zero.
                'score'     => $r->score === null ? null : (float) $r->score,
            ])->all(),
            'open'         => $open,
            'empty_reason' => $rows->isEmpty()
                ? 'No assessment has been assigned to you. When one is, its deadline and result appear here.'
                : null,
        ];
    }

    /** The caller's own performance review, if a cycle has reached them. */
    public function performance(int $sid, int $me): array
    {
        $r = DB::table('s_performance_reviews')
            ->where('sub_institute_id', $sid)
            ->where('user_id', $me)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first(['id', 'stage', 'status', 'overall_rating', 'overall_rating_label',
                     'self_rating', 'manager_rating', 'due_date', 'finalized_at']);

        $goals = (int) DB::table('s_performance_goals')
            ->where('sub_institute_id', $sid)
            ->where('user_id', $me)
            ->whereNull('deleted_at')
            ->count();

        if (!$r) {
            return [
                'review'       => null,
                'goals'        => $goals,
                'empty_reason' => 'No performance review has been opened for you. When a cycle includes you, its stage and rating appear here.',
            ];
        }

        return [
            'review' => [
                'id'      => (int) $r->id,
                'stage'   => $r->stage,
                'status'  => $r->status,
                // An unrated review carries null, not 0. A review in progress with
                // no rating yet is the common case, not an outlier.
                'overall' => $r->overall_rating === null ? null : (float) $r->overall_rating,
                'label'   => $r->overall_rating_label,
                'self'    => $r->self_rating === null ? null : (float) $r->self_rating,
                'manager' => $r->manager_rating === null ? null : (float) $r->manager_rating,
                'due'     => $r->due_date,
                'final'   => $r->finalized_at,
            ],
            'goals'        => $goals,
            'empty_reason' => null,
        ];
    }
}
