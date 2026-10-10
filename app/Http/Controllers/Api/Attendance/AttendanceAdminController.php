<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Attendance\AttendanceCorrector;
use App\Services\Events\EventRecorder;
use App\Support\RoleKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * HR corrects an employee's attendance.
 *
 * ── THE GAP THIS FILLS ──────────────────────────────────────────────────────
 *
 * Until now there was no clean way for HR to change somebody's attendance.
 * Three things came close and none was usable:
 *
 *   - `HrmsController::updateUserAttendance` (POST hrms/update_user_att) is
 *     role-gated and does update + insert, but has ZERO callers in any surface
 *     - no Blade view, no React service, no mobile path. It also omits
 *     sub_institute_id on the update, so it writes by (day, user_id) alone and
 *     reaches across tenants, takes its actor id from the request body, and its
 *     delete branch calls a destroy() that is an empty stub which still replies
 *     "Deleted Successfully".
 *   - `hrms-attendance-in-time/store` and its out-time sibling wrote an
 *     arbitrary employee id while gated only on "is logged in". Those are now
 *     forced to the caller; they are self-service, not an admin tool.
 *   - The regularisation approval path writes correctly - tenant-scoped,
 *     transactional, recomputing timestamp_diff, with a full before-image - but
 *     only ever in response to a request the EMPLOYEE raised. `store()` there
 *     has no parameter for anybody else, deliberately.
 *
 * So the writer was right and the way in was missing. This is the way in. It
 * shares AttendanceCorrector with the approval path rather than copying it, so
 * the two cannot drift.
 *
 * ── GATED TWICE, AND THE SECOND ONE IS THE ONE THAT MATTERS ─────────────────
 *
 * The route carries `profile:admin,hr`, which says WHO may ask. The tenant
 * check below says WHOM they may ask about. A role gate alone is not enough:
 * an HR manager is HR for one organisation, not for all twelve, and the route
 * cannot know which employee id belongs to whose.
 *
 * A probe of mine passed with exactly this check deleted, because the route
 * gate stopped the plain employee it was testing with. The assertion that
 * catches it uses an administrator token from a DIFFERENT tenant.
 */
class AttendanceAdminController extends Controller
{
    use ResolvesApiIdentity;

    /** Who may correct somebody else's attendance. */
    private const ALLOWED = ['admin', 'hr'];

    /**
     * POST /api/attendance/admin/corrections
     *
     * One employee, one day. A null time means "leave that side alone", so a
     * missing punch-out can be filled without restating the punch-in.
     */
    public function correct(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $actorId  = (int) $identity['user_id'];
        $tenantId = (int) $identity['sub_institute_id'];

        /*
         * The role is resolved from the caller's own profile, never from the
         * request, and through RoleKey rather than the profile NAME - ten live
         * tenants have a legacy profile called something the name test would
         * have got wrong (D-010).
         */
        if (!RoleKey::satisfies(RoleKey::forUserId($actorId), self::ALLOWED)) {
            return response()->json([
                'status'  => 0,
                'message' => 'You may not change another employee\'s attendance.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'user_id'  => 'required|integer',
            'day'      => 'required|date_format:Y-m-d',
            'in_time'  => 'nullable|date_format:H:i',
            'out_time' => 'nullable|date_format:H:i',
            // Required, as it is on the employee-raised path. A correction with
            // no reason is a record that satisfies an auditor and helps nobody.
            'reason'   => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $data = $validator->validated();

        if (empty($data['in_time'] ?? null) && empty($data['out_time'] ?? null)) {
            return response()->json([
                'status'  => 0,
                'message' => 'Give a punch in time, a punch out time, or both.',
            ], 422);
        }

        /*
         * A day in the future cannot have been worked. The self-service path
         * refuses this too; an admin route that did not would be the easy way
         * to put a figure into payroll for a day that has not happened.
         */
        if (Carbon::parse($data['day'])->isAfter(Carbon::today())) {
            return response()->json([
                'status'  => 0,
                'message' => 'That day has not happened yet.',
            ], 422);
        }

        $subjectId = (int) $data['user_id'];

        // THE SECOND GATE. See the class docblock.
        $inTenant = DB::table('tbluser')
            ->where('id', $subjectId)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->exists();

        if (!$inTenant) {
            // 404, not 403: a refusal should not confirm that an employee id
            // exists in somebody else's organisation.
            return response()->json([
                'status'  => 0,
                'message' => 'That employee is not in your organisation.',
            ], 404);
        }

        $correction = DB::transaction(function () use ($data, $subjectId, $tenantId, $actorId) {
            $applied = app(AttendanceCorrector::class)->apply((object) [
                'user_id'            => $subjectId,
                'sub_institute_id'   => $tenantId,
                'day'                => $data['day'],
                /*
                 * ?? and not just ?:  -  Validator::validated() returns only
                 * the keys that were PRESENT in the request, so a `nullable`
                 * field the caller omitted is missing from the array rather
                 * than null in it. `$data['out_time'] ?: null` therefore threw
                 * on exactly the common case: filling in a missing punch-out
                 * without restating the punch-in.
                 */
                'requested_in_time'  => ($data['in_time'] ?? null) ?: null,
                'requested_out_time' => ($data['out_time'] ?? null) ?: null,
            ], $actorId);

            /*
             * The HR-readable trail, written in the same transaction as the
             * change it describes. A correction that committed without its
             * record would be exactly the unexplained pay change this exists to
             * prevent.
             *
             * The column mapping lives on AttendanceCorrector so the
             * regularisation-approval path writes an identically shaped row;
             * the decision to write it, and the values the two paths disagree
             * about (`reason`, `source`), stay here where they are decided.
             */
            $applied['edit_id'] = DB::table('hrms_attendance_edits')->insertGetId(
                app(AttendanceCorrector::class)->editRowFrom(
                    $applied,
                    $tenantId,
                    $subjectId,
                    $data['day'],
                    $data['reason'],
                    'admin',
                    $actorId,
                )
            );

            return $applied;
        });

        /*
         * The platform event, AFTER the transaction commits - the same event the
         * approval path emits, so one query finds every correction however it
         * was initiated. Recording it must never fail the correction itself.
         */
        try {
            app(EventRecorder::class)->record(
                'attendance.corrected',
                $tenantId,
                'hrms_attendances',
                $correction['attendance_id'],
                $actorId,
                [
                    'employee_id'  => $subjectId,
                    'day'          => $data['day'],
                    'initiated_by' => 'admin',
                    'reason'       => $data['reason'],
                    'before'       => $correction['before'],
                    'after'        => [
                        'punchin_time'   => $correction['after']['punchin_time'] ?? null,
                        'punchout_time'  => $correction['after']['punchout_time'] ?? null,
                        'timestamp_diff' => $correction['after']['timestamp_diff'] ?? null,
                    ],
                    'created_row'  => $correction['before'] === null,
                ],
                null,
                /*
                 * KEYED ON THE EDIT, NOT ON THE EMPLOYEE-DAY.
                 *
                 * My first version keyed this 'admin:{employee}:{day}', which
                 * reads sensibly and is wrong: correcting the same day twice is
                 * ordinary - HR fixes the punch-in, then the punch-out - and the
                 * second event would have been dropped. The row would change and
                 * the history would say it had not.
                 *
                 * Where the dropping happens is worth being precise about:
                 * g2g_event.idempotency_key carries a UNIQUE index
                 * (uq_event_idem) on both hosts, so the DATABASE rejects the
                 * duplicate and the try/catch around this call swallows the
                 * throw. EventRecorder performs no check of its own - which
                 * means the key's precision is the only thing standing between
                 * a second correction and a lost before-image.
                 *
                 * The edit id is unique per correction, so a retry of the SAME
                 * request is still absorbed while a genuine second correction is
                 * recorded.
                 */
                'attendance.corrected:admin:edit:' . $correction['edit_id']
            );
        } catch (\Throwable $caught) {
            report($caught);
        }

        return response()->json([
            'status'  => 1,
            'message' => $correction['before'] === null
                ? 'Attendance recorded for a day that had none.'
                : 'Attendance corrected.',
            'data'    => [
                'attendance_id' => $correction['attendance_id'],
                'created_row'   => $correction['before'] === null,
                'before'        => $correction['before'],
                'after'         => [
                    'punchin_time'   => $correction['after']['punchin_time'] ?? null,
                    'punchout_time'  => $correction['after']['punchout_time'] ?? null,
                    'timestamp_diff' => $correction['after']['timestamp_diff'] ?? null,
                ],
            ],
        ]);
    }

    /**
     * GET /api/attendance/admin/grid
     *
     * Every employee down, every day of one month across - the screen's whole
     * dataset in one request.
     *
     * ── WHY THIS HAD TO BE A NEW ENDPOINT ───────────────────────────────────
     *
     * Nothing returned more than one employee's month. `employee-attendance-
     * monthly-report` is the richest attendance endpoint in the module and it
     * takes a single user_id; the two dashboard endpoints return aggregates,
     * not days. A grid built on the monthly report would be one request per
     * employee - 31 days x 50 people as 50 round trips, and 2,283 for a whole
     * organisation. This is three queries regardless of the headcount.
     *
     * ── PAGED ON EMPLOYEES, NOT ON DAYS ─────────────────────────────────────
     *
     * A month is a month; it is the employee axis that grows without limit. So
     * the page is a slice of PEOPLE and always carries their complete month -
     * a half-filled row would be worse than a missing one, because an empty
     * cell already means something here.
     *
     * ── WHAT AN EMPTY CELL MEANS, AND THE 88% ───────────────────────────────
     *
     * 2,008 of 2,283 active employees have no roster at all: all seven weekday
     * flags are 0. Two existing screens then disagree about the same person,
     * because their fallbacks differ - Monthly Attendance Report reads a 0 flag
     * as a weekend, so every day of the month comes back "weekend", while
     * Attendance Tracking falls back to "everything but Sunday is a working
     * day". Same employee, same month, two answers.
     *
     * This endpoint refuses to guess. A day with no attendance row resolves to:
     *
     *   weekend   - the roster exists and says this weekday is not worked
     *   absent    - the roster exists and says it IS worked
     *   unset     - the employee has NO roster, so neither word is true
     *
     * `unset` is the honest third answer and the reason it exists: it puts the
     * 88% on the screen that can fix it, instead of dressing a data gap up as
     * nine absences or a month of weekends. Days in the future resolve to
     * `upcoming` rather than `absent`, which is the other way a month-to-date
     * view manufactures absences that have not happened.
     */
    public function grid(Request $request)
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
                'message' => 'You may not read other employees\' attendance.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'month'         => 'required|date_format:Y-m',
            'department_id' => 'nullable',
            'search'        => 'nullable|string|max:100',
            'page'          => 'nullable|integer|min:1',
            'per_page'      => 'nullable|integer|min:1|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $month    = Carbon::parse($request->input('month') . '-01')->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
        $perPage  = (int) ($request->input('per_page') ?: 25);
        $page     = (int) ($request->input('page') ?: 1);

        /* ---------------- the people ---------------- */

        $employees = DB::table('tbluser as u')
            // hrms_departments, via App\Models\HRMS\hrmsDepartmentModel, which
            // declares no $table and so resolves by convention. The reports
            // controller reaches it through the model; this is a grid assembled
            // in one query, so it joins the table the model resolves to.
            ->leftJoin('hrms_departments as d', 'd.id', '=', 'u.department_id')
            ->where('u.sub_institute_id', $tenantId)
            // 1 and 0, NOT the string 'active'. `where('status','active')`
            // coerces to 0 in MySQL and silently returns the DISABLED users -
            // the exact inversion that has bitten this table before.
            ->where('u.status', 1)
            ->whereNull('u.deleted_at');

        $departmentId = $request->input('department_id');

        if ($departmentId !== null && $departmentId !== '' && $departmentId !== 'all') {
            $employees->where('u.department_id', $departmentId);
        }

        if ($search = trim((string) $request->input('search'))) {
            $employees->where(function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where('u.first_name', 'like', $like)
                  ->orWhere('u.last_name', 'like', $like)
                  // BOTH code columns. tbluser carries employee_no AND
                  // employee_id and neither is complete - of 2,283 active
                  // employees, 1,132 have a employee_no and 1,048 an
                  // employee_id. Searching one column fails for half the
                  // workforce, and which half depends on which column.
                  ->orWhere('u.employee_no', 'like', $like)
                  ->orWhere('u.employee_id', 'like', $like);
            });
        }

        $total = (clone $employees)->count('u.id');

        $rows = $employees
            ->orderBy('u.first_name')
            ->orderBy('u.last_name')
            ->orderBy('u.id')          // a stable tiebreak, so paging cannot
                                       // drop or repeat a namesake
            ->forPage($page, $perPage)
            ->get([
                'u.id', 'u.first_name', 'u.middle_name', 'u.last_name',
                'u.employee_no', 'u.employee_id',
                'u.department_id', 'd.department as department_name',
                'u.monday', 'u.tuesday', 'u.wednesday', 'u.thursday',
                'u.friday', 'u.saturday', 'u.sunday',
                'u.monday_in_date', 'u.monday_out_date',
                'u.tuesday_in_date', 'u.tuesday_out_date',
                'u.wednesday_in_date', 'u.wednesday_out_date',
                'u.thursday_in_date', 'u.thursday_out_date',
                'u.friday_in_date', 'u.friday_out_date',
                'u.saturday_in_date', 'u.saturday_out_date',
                'u.sunday_in_date', 'u.sunday_out_date',
            ]);

        $userIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();

        /* ---------------- their month ---------------- */

        $attendance = [];
        $edited     = [];

        if ($userIds !== []) {
            $punches = DB::table('hrms_attendances')
                ->where('sub_institute_id', $tenantId)
                ->whereIn('user_id', $userIds)
                ->whereNull('deleted_at')
                ->whereBetween('day', [$month->toDateString(), $monthEnd->toDateString()])
                ->get(['id', 'user_id', 'day', 'punchin_time', 'punchout_time',
                       'timestamp_diff', 'work_mode']);

            foreach ($punches as $punch) {
                $key = (int) $punch->user_id . '|' . Carbon::parse($punch->day)->toDateString();
                $attendance[$key] = $punch;
            }

            // Which days carry a correction, so the grid can mark them. One
            // query rather than a per-cell lookup; the screen shows WHO and WHY
            // from /admin/edits when a marked cell is opened.
            $marks = DB::table('hrms_attendance_edits')
                ->where('sub_institute_id', $tenantId)
                ->whereIn('user_id', $userIds)
                ->whereBetween('day', [$month->toDateString(), $monthEnd->toDateString()])
                ->get(['user_id', 'day']);

            foreach ($marks as $mark) {
                $edited[(int) $mark->user_id . '|' . Carbon::parse($mark->day)->toDateString()] = true;
            }
        }

        /* ---------------- the days ---------------- */

        $weekdays = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $today    = Carbon::today();

        $days = [];
        for ($d = $month->copy(); $d->lte($monthEnd); $d->addDay()) {
            $days[] = [
                'date'       => $d->toDateString(),
                'day_of'     => (int) $d->day,
                'weekday'    => $d->format('D'),
                'is_future'  => $d->isAfter($today),
            ];
        }

        $employeesOut = [];

        foreach ($rows as $row) {
            // "Has a roster at all" is the question the 88% turns on, and it is
            // asked once per employee rather than per cell.
            $hasRoster = false;
            foreach ($weekdays as $w) {
                if ((int) ($row->{$w} ?? 0) === 1) {
                    $hasRoster = true;
                    break;
                }
            }

            $cells = [];

            foreach ($days as $day) {
                $weekday = $weekdays[Carbon::parse($day['date'])->dayOfWeek];
                $works   = (int) ($row->{$weekday} ?? 0) === 1;
                $key     = (int) $row->id . '|' . $day['date'];
                $punch   = $attendance[$key] ?? null;

                if ($punch && $punch->punchin_time) {
                    $status = $punch->punchout_time ? 'present' : 'incomplete';
                } elseif ($punch) {
                    // A row with neither time. Rare, and it is not an absence -
                    // something created the row.
                    $status = 'recorded';
                } elseif ($day['is_future']) {
                    $status = 'upcoming';
                } elseif (!$hasRoster) {
                    $status = 'unset';
                } elseif (!$works) {
                    $status = 'weekend';
                } else {
                    $status = 'absent';
                }

                $cells[$day['date']] = [
                    'status'    => $status,
                    'in'        => $punch?->punchin_time ? Carbon::parse($punch->punchin_time)->format('H:i') : null,
                    'out'       => $punch?->punchout_time ? Carbon::parse($punch->punchout_time)->format('H:i') : null,
                    'duration'  => $punch?->timestamp_diff
                        ? substr((string) $punch->timestamp_diff, 0, 5)
                        : null,
                    'work_mode' => $punch?->work_mode,
                    'edited'    => isset($edited[$key]),
                    // The rostered hours for this weekday, so a cell can say
                    // "09:00-18:00 expected" without a second request. Saturday
                    // is the day these genuinely differ, which is why it is
                    // read per weekday rather than from Monday - two existing
                    // endpoints read monday_in_date for every day of the week.
                    'shift_in'  => $row->{$weekday . '_in_date'} ? substr((string) $row->{$weekday . '_in_date'}, 0, 5) : null,
                    'shift_out' => $row->{$weekday . '_out_date'} ? substr((string) $row->{$weekday . '_out_date'}, 0, 5) : null,
                ];
            }

            $employeesOut[] = [
                'user_id'         => (int) $row->id,
                // CONCAT_WS-style, so an absent middle name does not leave a
                // double space in the one column the whole grid is read by.
                'name'            => trim(preg_replace('/\s+/', ' ',
                    ($row->first_name ?? '') . ' ' . ($row->middle_name ?? '') . ' ' . ($row->last_name ?? ''))),
                // employee_no first, employee_id as the fallback - see the
                // search clause above for why one column is not enough.
                'employee_code'   => $row->employee_no ?: ($row->employee_id ?: null),
                'department_id'   => $row->department_id ? (int) $row->department_id : null,
                'department_name' => $row->department_name,
                'has_roster'      => $hasRoster,
                'days'            => $cells,
            ];
        }

        return response()->json([
            'status' => 1,
            'data'   => [
                'month'     => $month->format('Y-m'),
                'days'      => $days,
                'employees' => $employeesOut,
                'meta'      => [
                    'page'        => $page,
                    'per_page'    => $perPage,
                    'total'       => $total,
                    'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
                    // How many of this page have no roster. The screen shows it
                    // as a banner: it is the difference between "nine absences"
                    // and "nine days we never knew they were meant to work".
                    'without_roster' => collect($employeesOut)->where('has_roster', false)->count(),
                ],
            ],
        ]);
    }

    /**
     * GET /api/attendance/admin/edits
     *
     * What has been changed, and by whom. Scoped to the caller's tenant, newest
     * first, optionally narrowed to one employee or one month - which is how the
     * screen asks it, and the reason this exists alongside the event log.
     */
    public function edits(Request $request)
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
                'message' => 'You may not read other employees\' attendance history.',
            ], 403);
        }

        $query = DB::table('hrms_attendance_edits as e')
            ->leftJoin('tbluser as subject', 'subject.id', '=', 'e.user_id')
            ->leftJoin('tbluser as actor', 'actor.id', '=', 'e.created_by')
            ->where('e.sub_institute_id', $tenantId);

        if ($request->filled('user_id')) {
            $query->where('e.user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('month')) {
            /*
             * Filters on e.day - the day that was CORRECTED - not on
             * e.created_at, the day somebody corrected it. The two are
             * unrelated: a correction made today to a day last month does not
             * appear under this month, and should not.
             *
             * It is worth being explicit because the list is SORTED by
             * created_at while being FILTERED by day, which reads as a
             * contradiction until you know that is deliberate: "changes to days
             * in October, most recently made first".
             */
            $month = Carbon::parse($request->input('month') . '-01');
            $query->whereBetween('e.day', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ]);
        }

        if ($request->filled('day')) {
            // One day, for the drill-down from a cell the grid has marked as
            // changed. Narrower than `month`, and compatible with it.
            $query->whereDate('e.day', Carbon::parse($request->input('day'))->toDateString());
        }

        /*
         * The cap is still here, but it is no longer silent.
         *
         * 200 newest-first degrades safely, but a busy tenant was being shown a
         * truncated list with nothing to say so - and "the history only goes
         * back so far" is a very different statement from "that is all the
         * history there is".
         */
        $limit = 200;
        $total = (clone $query)->count('e.id');

        $rows = $query
            ->orderByDesc('e.created_at')
            ->limit($limit)
            ->get([
                'e.id', 'e.user_id', 'e.day', 'e.attendance_id', 'e.regularisation_id',
                'e.before_in_time', 'e.before_out_time', 'e.before_duration',
                'e.after_in_time', 'e.after_out_time', 'e.after_duration',
                'e.created_row', 'e.reason', 'e.source', 'e.created_at',
                DB::raw("CONCAT_WS(' ', subject.first_name, subject.last_name) as employee_name"),
                DB::raw("CONCAT_WS(' ', subject.employee_no, subject.employee_id) as employee_code"),
                DB::raw("CONCAT_WS(' ', actor.first_name, actor.last_name) as changed_by_name"),
            ]);

        return response()->json([
            'status' => 1,
            'data'   => $rows->map(fn ($row) => $this->transformEdit($row))->all(),
            /*
             * An additive sibling, not a reshaping of `data`.
             *
             * `data` stays a plain array because its consumers are outside this
             * repository and the blast radius of changing its shape could not be
             * measured from here.
             */
            'meta'   => [
                'total'     => $total,
                'returned'  => $rows->count(),
                'limit'     => $limit,
                'truncated' => $total > $rows->count(),
            ],
        ]);
    }

    /**
     * One edit row, with its types fixed at the boundary that broke them.
     *
     * ── THE BUG THIS EXISTS FOR ─────────────────────────────────────────────
     *
     * `config/database.php` sets `PDO::ATTR_EMULATE_PREPARES => true` on the
     * `mysql` connection, so the driver returns EVERY column as a string
     * regardless of its declared SQL type. `created_row` is a boolean column and
     * arrived as the string `"0"`.
     *
     * `"0"` is falsy in PHP and **truthy in JavaScript**. The Change History
     * screen tests it directly, so every single row rendered "day did not
     * exist" and the before-image - the one thing that makes this an audit
     * rather than a log - was never shown on any row. The table looked like it
     * worked; the data in it was uniformly wrong.
     *
     * ── WHY HERE AND NOT IN THE FRONTEND ────────────────────────────────────
     *
     * The bug exists precisely BECAUSE a PHP string crossed into JavaScript, so
     * the fix belongs at the crossing. Fixing it in the React screen fixes one
     * consumer and leaves the mobile client and the next caller to rediscover
     * it. A SQL `CAST(... AS UNSIGNED)` does not work either - with emulated
     * prepares the driver stringifies the result of the cast too.
     *
     * The precedent is in this module:
     * AttendanceRegularisationApiController::transform() casts every field on
     * the way out, and `edits()` was the only read in the attendance API
     * returning raw DB::table rows.
     *
     * It is not only `created_row`: `id`, `user_id`, `attendance_id` and
     * `regularisation_id` are all strings too, and the last two are nullable, so
     * a client saw either `null` or `"41"`.
     */
    private function transformEdit(object $row): array
    {
        return [
            'id'                => (int) $row->id,
            // Carried so the screen stops falling back to "Employee #{id}" with
            // the EDIT row's id - an audit-row number printed as an employee
            // number, which is worse than printing nothing.
            'user_id'           => (int) $row->user_id,
            'employee_name'     => trim((string) ($row->employee_name ?? '')) ?: null,
            'employee_code'     => trim((string) ($row->employee_code ?? '')) ?: null,
            'day'               => $row->day ? Carbon::parse($row->day)->toDateString() : null,
            'attendance_id'     => $row->attendance_id !== null ? (int) $row->attendance_id : null,
            'regularisation_id' => $row->regularisation_id !== null ? (int) $row->regularisation_id : null,
            /*
             * Times are normalised to HH:MM here rather than sliced in the
             * client. `after_*` is written as a full Y-m-d H:i:s by
             * AttendanceCorrector::stamp(), but `before_*` is whatever the
             * driver handed back from hrms_attendances - and the audit columns
             * are varchar(20), so there is no schema guarantee that a historical
             * row holds the wide form. A client slicing at a fixed offset
             * returns an empty string for the narrow one, which is not null and
             * so does not trigger its own fallback.
             */
            'before_in_time'    => $this->clock($row->before_in_time),
            'before_out_time'   => $this->clock($row->before_out_time),
            'before_duration'   => $this->clock($row->before_duration),
            'after_in_time'     => $this->clock($row->after_in_time),
            'after_out_time'    => $this->clock($row->after_out_time),
            'after_duration'    => $this->clock($row->after_duration),
            // THE ONE THAT WAS BREAKING EVERY ROW.
            'created_row'       => (bool) (int) $row->created_row,
            'reason'            => (string) $row->reason,
            'source'            => (string) $row->source,
            'created_at'        => $row->created_at ? Carbon::parse($row->created_at)->toDateTimeString() : null,
            'changed_by_name'   => trim((string) ($row->changed_by_name ?? '')) ?: null,
        ];
    }

    /**
     * Any stored time shape to "HH:MM", or null.
     *
     * Handles "2026-10-03 09:15:00", "09:15:00" and "09:15" alike, because all
     * three are in the live data - the two writers of `timestamp_diff` disagree
     * about the seconds, and the audit columns are plain strings that accept
     * whatever they were given.
     */
    private function clock(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return preg_match('/(\d{1,2}):(\d{2})/', $value, $m)
            ? str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2]
            : null;
    }
}
