<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Events\EventRecorder;
use App\Support\RoleKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Office hours, set per department and applied to its employees.
 *
 * ── A TEMPLATE, NOT A SECOND SOURCE OF TRUTH ────────────────────────────────
 *
 * `hrms_department_schedules` holds what a department's week SHOULD look like.
 * Applying it copies those values onto the `tbluser` columns - the seven
 * weekday flags and the fourteen time columns - because those are what the
 * attendance and payroll calculations actually read. Nothing downstream has to
 * learn about the new table, and an employee whose hours genuinely differ keeps
 * them until somebody applies over them.
 *
 * ── THE APPLY IS PREVIEWED, AND THAT IS NOT POLITENESS ──────────────────────
 *
 * The previous version of this feature was REMOVED because a bulk shift write
 * silently flattened Saturday across a department - 100 employees in one tenant
 * have a Saturday that ends at 14:00, and a blanket 09:00-18:00 wiped it with
 * no record and no warning. So:
 *
 *   - preview() says how many employees it would change, how many already
 *     match, and how many currently hold something DIFFERENT, per weekday,
 *     before anything is written.
 *   - apply() takes an explicit list of weekdays. There is no "all" shortcut on
 *     the server; Saturday and Sunday have to be named.
 *   - apply() records a `department.schedule.applied` event carrying the
 *     before-image of every employee it touched, so the one thing the deleted
 *     version could not do - answer "what did this used to be" - is possible.
 *
 * ── WHY THIS EXISTS AT ALL ──────────────────────────────────────────────────
 *
 * 2,008 of 2,283 active employees have no roster: all seven flags 0. The two
 * screens that read them disagree about those people, each having invented its
 * own fallback - Monthly Attendance Report calls every day a weekend,
 * Attendance Tracking calls Mon-Sat worked. Fixing that one employee at a time
 * is 2,008 edits.
 */
class DepartmentScheduleController extends Controller
{
    use ResolvesApiIdentity;

    private const ALLOWED = ['admin', 'hr'];

    /**
     * The weekday names, in the order a week is read.
     *
     * These are also the tbluser column prefixes - `monday` for the flag,
     * `monday_in_date` / `monday_out_date` for the hours - which is the whole
     * reason the schedule stores a name rather than an index. Two live
     * endpoints select only `monday_in_date` and compare every day of the week
     * against it, and the Early Going Report reads Saturday's column for
     * Thursday; both are index arithmetic gone wrong. A name has no arithmetic
     * to get wrong.
     */
    private const WEEKDAYS = [
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
    ];

    /** The two days whose hours genuinely vary, and which a bulk write must never assume. */
    private const WEEKEND = ['saturday', 'sunday'];

    /* ====================================================================== */
    /* Read                                                                    */
    /* ====================================================================== */

    /**
     * GET /api/attendance/admin/schedules
     *
     * Every department of the caller's organisation with its seven weekday
     * rows, and how many employees each has. Departments with no schedule yet
     * come back with all seven days present and empty, so the screen renders one
     * shape rather than branching on "has a schedule".
     */
    public function index(Request $request)
    {
        $gate = $this->gate($request);

        if (!is_array($gate)) {
            return $gate;
        }

        [$actorId, $tenantId] = $gate;

        $departments = DB::table('hrms_departments')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderBy('department')
            ->get(['id', 'department']);

        $schedules = DB::table('hrms_department_schedules')
            ->where('sub_institute_id', $tenantId)
            ->get()
            ->groupBy('department_id');

        // How many employees each department would be applied to, counted once
        // rather than per department. status is 1/0 - `= 'active'` coerces to 0
        // in MySQL and silently returns the DISABLED users.
        $headcount = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->whereNotNull('department_id')
            ->groupBy('department_id')
            ->pluck(DB::raw('count(*)'), 'department_id');

        $out = [];

        foreach ($departments as $department) {
            $rows = collect($schedules[$department->id] ?? [])->keyBy('weekday');

            $week = [];

            foreach (self::WEEKDAYS as $weekday) {
                $row = $rows[$weekday] ?? null;

                $week[] = [
                    'weekday'    => $weekday,
                    'is_working' => $row ? (bool) $row->is_working : null,   // null = never set
                    'in_time'    => $row && $row->in_time ? substr((string) $row->in_time, 0, 5) : null,
                    'out_time'   => $row && $row->out_time ? substr((string) $row->out_time, 0, 5) : null,
                ];
            }

            $out[] = [
                'department_id'   => (int) $department->id,
                'department_name' => $department->department,
                'employee_count'  => (int) ($headcount[$department->id] ?? 0),
                'has_schedule'    => $rows->isNotEmpty(),
                'week'            => $week,
            ];
        }

        return response()->json(['status' => 1, 'data' => $out]);
    }

    /* ====================================================================== */
    /* Write the template                                                      */
    /* ====================================================================== */

    /**
     * POST /api/attendance/admin/schedules
     *
     * Save one department's week. This writes the TEMPLATE only - no employee
     * row changes until apply() is called. That separation is the point: saving
     * what the hours should be is reversible and cheap; writing them onto 100
     * people is not.
     */
    public function store(Request $request)
    {
        $gate = $this->gate($request);

        if (!is_array($gate)) {
            return $gate;
        }

        [$actorId, $tenantId] = $gate;

        $validator = Validator::make($request->all(), [
            'department_id'     => 'required|integer',
            'week'              => 'required|array|min:1|max:7',
            'week.*.weekday'    => 'required|string|in:' . implode(',', self::WEEKDAYS),
            'week.*.is_working' => 'required|boolean',
            'week.*.in_time'    => 'nullable|date_format:H:i',
            'week.*.out_time'   => 'nullable|date_format:H:i',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data         = $validator->validated();
        $departmentId = (int) $data['department_id'];

        if (!$this->departmentInTenant($departmentId, $tenantId)) {
            // 404 rather than 403: a refusal should not confirm that a
            // department id exists in somebody else's organisation.
            return response()->json([
                'status'  => 0,
                'message' => 'That department is not in your organisation.',
            ], 404);
        }

        foreach ($data['week'] as $entry) {
            if (!empty($entry['in_time']) && !empty($entry['out_time'])
                && $entry['out_time'] <= $entry['in_time']) {
                return response()->json([
                    'status'  => 0,
                    'message' => ucfirst($entry['weekday']) . ': the closing time has to be after the opening time.',
                ], 422);
            }
        }

        DB::transaction(function () use ($data, $departmentId, $tenantId, $actorId) {
            foreach ($data['week'] as $entry) {
                // updateOrInsert against the unique index, so pressing Save
                // twice does not leave fourteen rows for seven days.
                DB::table('hrms_department_schedules')->updateOrInsert(
                    [
                        'sub_institute_id' => $tenantId,
                        'department_id'    => $departmentId,
                        'weekday'          => $entry['weekday'],
                    ],
                    [
                        'is_working' => $entry['is_working'] ? 1 : 0,
                        'in_time'    => $entry['in_time'] ?: null,
                        'out_time'   => $entry['out_time'] ?: null,
                        'updated_by' => $actorId,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        });

        return response()->json([
            'status'  => 1,
            'message' => 'Office hours saved. Nothing has changed for employees yet'
                . ' - use Apply to write these hours onto the department.',
        ]);
    }

    /* ====================================================================== */
    /* Preview, then apply                                                     */
    /* ====================================================================== */

    /**
     * POST /api/attendance/admin/schedules/preview
     *
     * What an apply WOULD do. Per weekday: how many employees already match,
     * how many hold something different, and how many have nothing set.
     *
     * The middle number is the one that matters. "40 already match, 0 differ"
     * is a safe apply; "40 match, 12 differ" means twelve people have hours
     * somebody chose for a reason, and flattening them is what got the previous
     * version of this feature deleted.
     */
    public function preview(Request $request)
    {
        return $this->previewOrApply($request, false);
    }

    /**
     * POST /api/attendance/admin/schedules/apply
     *
     * Write the template onto the department's employees.
     *
     * `weekdays` is required and explicit. There is no "all" on the server:
     * Saturday and Sunday have to be named, because Saturday is the day whose
     * hours genuinely vary and a blanket write is how 100 people lost a 14:00
     * finish last time.
     */
    public function apply(Request $request)
    {
        return $this->previewOrApply($request, true);
    }

    /**
     * One body for both, because a preview that does not compute exactly what
     * the apply computes is worse than no preview - it is a promise the write
     * does not keep.
     */
    private function previewOrApply(Request $request, bool $write)
    {
        $gate = $this->gate($request);

        if (!is_array($gate)) {
            return $gate;
        }

        [$actorId, $tenantId] = $gate;

        $validator = Validator::make($request->all(), [
            'department_id' => 'required|integer',
            'weekdays'      => 'required|array|min:1|max:7',
            'weekdays.*'    => 'required|string|in:' . implode(',', self::WEEKDAYS),
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $departmentId = (int) $request->input('department_id');
        $weekdays     = array_values(array_unique($request->input('weekdays')));

        if (!$this->departmentInTenant($departmentId, $tenantId)) {
            return response()->json([
                'status'  => 0,
                'message' => 'That department is not in your organisation.',
            ], 404);
        }

        $schedule = DB::table('hrms_department_schedules')
            ->where('sub_institute_id', $tenantId)
            ->where('department_id', $departmentId)
            ->whereIn('weekday', $weekdays)
            ->get()
            ->keyBy('weekday');

        $missing = array_values(array_diff($weekdays, $schedule->keys()->all()));

        if ($missing !== []) {
            return response()->json([
                'status'  => 0,
                'message' => 'No office hours are set for ' . implode(', ', $missing)
                    . '. Save the week first, then apply.',
            ], 422);
        }

        // The columns needed to compare, built from the weekday names rather
        // than an index - see the WEEKDAYS docblock.
        $columns = ['id', 'first_name', 'last_name'];

        foreach ($weekdays as $weekday) {
            $columns[] = $weekday;
            $columns[] = $weekday . '_in_date';
            $columns[] = $weekday . '_out_date';
        }

        $employees = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->where('department_id', $departmentId)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->get($columns);

        if ($employees->isEmpty()) {
            return response()->json([
                'status'  => 0,
                'message' => 'This department has no active employees to apply hours to.',
            ], 422);
        }

        /* ---------------- what would change ---------------- */

        $perWeekday = [];
        $updates    = [];      // user_id => [column => value]
        $before     = [];      // user_id => [column => old value], for the event

        foreach ($weekdays as $weekday) {
            $target  = $schedule[$weekday];
            $working = (int) $target->is_working;
            $in      = $target->in_time ? substr((string) $target->in_time, 0, 5) : null;
            $out     = $target->out_time ? substr((string) $target->out_time, 0, 5) : null;

            $same = 0;
            $differ = 0;
            $unset = 0;

            foreach ($employees as $employee) {
                $currentWorking = $employee->{$weekday} === null ? null : (int) $employee->{$weekday};
                $currentIn      = $employee->{$weekday . '_in_date'}
                    ? substr((string) $employee->{$weekday . '_in_date'}, 0, 5) : null;
                $currentOut     = $employee->{$weekday . '_out_date'}
                    ? substr((string) $employee->{$weekday . '_out_date'}, 0, 5) : null;

                $hasNothing = ($currentWorking === null || $currentWorking === 0)
                    && $currentIn === null && $currentOut === null;

                $matches = $currentWorking === $working && $currentIn === $in && $currentOut === $out;

                if ($matches) {
                    $same++;
                    continue;
                }

                if ($hasNothing) {
                    $unset++;
                } else {
                    $differ++;
                }

                $userId = (int) $employee->id;

                $before[$userId][$weekday] = [
                    'is_working' => $currentWorking,
                    'in_time'    => $currentIn,
                    'out_time'   => $currentOut,
                ];

                $updates[$userId][$weekday] = $working;

                /*
                 * A working day with no hours set writes the FLAG only.
                 * Writing null into the time columns there would clear whatever
                 * hours the employee already had and read downstream as
                 * midnight - which is how a roster change becomes a pay change
                 * nobody asked for.
                 */
                if ($in !== null || $out !== null) {
                    $updates[$userId][$weekday . '_in_date']  = $in;
                    $updates[$userId][$weekday . '_out_date'] = $out;
                }
            }

            $perWeekday[] = [
                'weekday'      => $weekday,
                'is_working'   => (bool) $working,
                'in_time'      => $in,
                'out_time'     => $out,
                'is_weekend'   => in_array($weekday, self::WEEKEND, true),
                'already_match' => $same,
                // The number to read before pressing Apply: employees who hold
                // hours somebody chose, which this would overwrite.
                'would_change' => $differ,
                'would_set'    => $unset,
            ];
        }

        $summary = [
            'department_id'    => $departmentId,
            'employees'        => $employees->count(),
            'weekdays'         => $weekdays,
            'includes_weekend' => array_values(array_intersect($weekdays, self::WEEKEND)),
            'per_weekday'      => $perWeekday,
            'employees_touched' => count($updates),
            'total_would_change' => array_sum(array_column($perWeekday, 'would_change')),
            'total_would_set'    => array_sum(array_column($perWeekday, 'would_set')),
        ];

        if (!$write) {
            return response()->json([
                'status'  => 1,
                'applied' => false,
                'data'    => $summary,
            ]);
        }

        /* ---------------- the write ---------------- */

        if ($updates === []) {
            return response()->json([
                'status'   => 1,
                'applied'  => false,
                'message'  => 'Every employee in this department already has these hours.'
                    . ' Nothing was changed.',
                'data'     => $summary,
            ]);
        }

        DB::transaction(function () use ($updates, $tenantId, $actorId) {
            foreach ($updates as $userId => $values) {
                DB::table('tbluser')
                    ->where('id', $userId)
                    // Scoped on the tenant as well as the id. The id alone is
                    // enough today, but this is the write that touches other
                    // people's rows and it should not depend on that.
                    ->where('sub_institute_id', $tenantId)
                    ->update($values + ['updated_at' => now()]);
            }
        });

        /*
         * The before-image, after commit. This is the thing the deleted version
         * of this feature could not do: when somebody asks in March why their
         * Saturday ends at 18:00, this says who applied what, when, and what it
         * used to be.
         */
        try {
            app(EventRecorder::class)->record(
                'department.schedule.applied',
                $tenantId,
                'hrms_departments',
                $departmentId,
                $actorId,
                [
                    'weekdays'  => $weekdays,
                    'employees' => count($updates),
                    'applied'   => array_map(static fn ($row) => [
                        'weekday'    => $row['weekday'],
                        'is_working' => $row['is_working'],
                        'in_time'    => $row['in_time'],
                        'out_time'   => $row['out_time'],
                    ], $perWeekday),
                    'before'    => $before,
                ],
                null,
                /*
                 * THE WEEKDAYS ARE IN THE KEY, AND THEY HAVE TO BE.
                 *
                 * g2g_event.idempotency_key carries a UNIQUE index
                 * (uq_event_idem) on both hosts, so the deduplication is done
                 * by the database: a repeated key makes the insert throw, and
                 * the try/catch around this swallows it. EventRecorder itself
                 * does no checking.
                 *
                 * That makes the key's precision load-bearing. Keyed on
                 * department + second + actor, applying Monday and then
                 * Saturday within the same second would record only the first,
                 * and the second apply's before-image - the whole reason this
                 * event exists - would be silently gone. Including the weekday
                 * selection separates two real applies while still absorbing a
                 * double-submit of the same one.
                 */
                'department.schedule.applied:' . $departmentId
                    . ':' . implode('-', $weekdays)
                    . ':' . now()->format('YmdHis')
                    . ':' . $actorId,
            );
        } catch (\Throwable $caught) {
            report($caught);
        }

        return response()->json([
            'status'  => 1,
            'applied' => true,
            'message' => sprintf(
                'Applied to %d %s. %d had different hours and were changed; %d had none set.',
                count($updates),
                count($updates) === 1 ? 'employee' : 'employees',
                $summary['total_would_change'],
                $summary['total_would_set'],
            ),
            'data'    => $summary,
        ]);
    }

    /* ====================================================================== */
    /* Shared                                                                  */
    /* ====================================================================== */

    /**
     * Identify the caller and check their role.
     *
     * @return array{0:int,1:int}|\Illuminate\Http\JsonResponse  [actorId, tenantId] or a refusal
     */
    private function gate(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $actorId  = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];

        if (!RoleKey::satisfies(RoleKey::forUserId($actorId), self::ALLOWED)) {
            return response()->json([
                'status'  => 0,
                'message' => 'Setting office hours is limited to HR and Administrator roles.',
            ], 403);
        }

        return [$actorId, $tenantId];
    }

    /**
     * The gate the role check cannot provide.
     *
     * A role gate says WHO may ask; this says whom they may ask ABOUT. An HR
     * manager is HR for one organisation, not for all twelve, and the route
     * cannot know which department id belongs to whose.
     */
    private function departmentInTenant(int $departmentId, int $tenantId): bool
    {
        return DB::table('hrms_departments')
            ->where('id', $departmentId)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->exists();
    }
}
