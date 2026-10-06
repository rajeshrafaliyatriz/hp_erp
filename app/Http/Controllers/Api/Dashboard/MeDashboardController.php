<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Dashboard\Concerns\ResolvesDashboardContext;
use App\Http\Controllers\Concerns\ResolvesEmployeeJobRole;
use App\Services\Dashboard\DashboardActivityFeed;
use App\Services\Dashboard\DashboardLinkResolver;
use App\Services\Dashboard\MeDashboardService;
use App\Services\Dashboard\WorkforceTrendService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * THE EMPLOYEE'S OWN HOME DASHBOARD.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * THE SUBJECT IS THE TOKEN OWNER, AND THERE IS NO OTHER WAY TO NAME ONE
 * ═══════════════════════════════════════════════════════════════════════
 *
 * These routes take NO user_id, no employee_id and no subject of any kind. That
 * is the whole security design, and it is structural rather than a check:
 *
 *     AN ENDPOINT THAT ACCEPTS NO SUBJECT CANNOT BE MADE TO RETURN SOMEBODY
 *     ELSE'S DATA. There is no parameter to tamper with, no id to guess, and no
 *     authorisation rule that can be got wrong later, because no decision is
 *     being made.
 *
 * The same argument MyCapabilityController makes. It is repeated here because
 * this controller reads eight more domains than that one does, and every added
 * query is a chance to reintroduce a subject parameter "just for HR".
 * DO NOT. HR reading somebody else's figures is the HR dashboard's job, behind
 * `profile:admin,hr`.
 *
 * The guard is therefore only `api.token` — every authenticated employee may
 * see their own dashboard, and that is the entire rule.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * WHY THREE SECTIONS
 * ═══════════════════════════════════════════════════════════════════════
 *
 *   /summary   task counts and status — cheap, paints first
 *   /growth    capability, execution model, learning — the join-heavy section
 *   /signals   attendance scan, leave, activity, upcoming — the slow section
 *
 * HrDashboardController argues this out and the reasoning is identical: behind
 * one endpoint and one try/catch, a single slow or broken source blanks the
 * whole page, which is how the LMS dashboard fails today with its one
 * Promise.all. Twelve per-widget endpoints would be the opposite mistake —
 * resolveApiIdentity() runs a token lookup on EVERY call with no memoisation,
 * and there is no cache layer in front of a remote database.
 */
class MeDashboardController extends Controller
{
    use ResolvesDashboardContext;
    use ResolvesEmployeeJobRole;

    public function __construct(
        private MeDashboardService $me,
        private WorkforceTrendService $trend,
        private DashboardActivityFeed $activity,
        private DashboardLinkResolver $links,
    ) {
    }

    /**
     * GET /api/dashboard/me/summary
     *
     * The tiles and both task charts, plus who the caller is and what job role
     * they hold — everything the header needs to render before the heavier
     * sections arrive.
     */
    public function summary(Request $request)
    {
        $context = $this->dashboardContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];
        $who = $this->identify($sid, $context['user_id']);
        if (!is_array($who)) {
            return $who;
        }

        // SYEAR is mandatory for anything touching `task`: those tables are
        // scoped by academic year as well as tenant, and omitting it silently
        // mixes years. Refusing matches ResolvesTaskContext and HrDashboard.
        $syear = $this->dashboardFilters($request)['syear'];
        if ($syear === null) {
            return $this->dashboardFail('syear is required to read your task figures.', 422);
        }

        try {
            $tasks = $this->me->taskSummary($sid, $syear, $who['id']);
        } catch (\Throwable $e) {
            return $this->failed($e, 'summary', $sid, $who['id']);
        }

        return $this->ok([
            'me'    => $who['profile'],
            'tasks' => $tasks,
            'links' => $this->links->resolve($sid),
        ], 'Your dashboard summary was fetched successfully', [
            'empty_is_expected' => $tasks['total'] === 0,
            'empty_reason'      => $tasks['total'] === 0
                ? 'No tasks have been assigned to you for this academic year. They appear here as soon as one is.'
                : null,
            'meta' => ['as_of' => now()->toIso8601String(), 'syear' => $syear],
        ]);
    }

    /**
     * GET /api/dashboard/me/growth
     *
     * Capability, the execution model of the caller's job role, its written
     * procedures, learning, assessments and performance. The join-heavy section,
     * kept away from /summary so it cannot delay the tiles.
     */
    public function growth(Request $request)
    {
        $context = $this->dashboardContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];
        $who = $this->identify($sid, $context['user_id']);
        if (!is_array($who)) {
            return $who;
        }

        try {
            $capability = $this->me->capabilityGap($sid, $who['id'], $who['jobrole_id']);

            // Courses are suggested against the competencies the caller is
            // measurably BELOW target on — not against every competency their
            // role requires. Suggesting a course for something already met is
            // noise, and noise is what makes people stop reading a widget.
            $gapIds = array_values(array_map(
                fn ($a) => $a['competency_id'],
                array_filter(
                    $capability['axes'] ?? [],
                    fn ($a) => $a['current'] !== null && $a['required'] !== null && $a['current'] < $a['required'],
                ),
            ));

            $data = [
                'capability'   => $capability,
                'execution'    => $this->me->executionMix($sid, $who['jobrole_id']),
                'procedures'   => $this->me->procedures($sid, $who['jobrole_id']),
                'learning'     => $this->me->learning($sid, $who['id'], $gapIds),
                'assessments'  => $this->me->assessments($sid, $who['id']),
                'performance'  => $this->me->performance($sid, $who['id']),
                'certifications' => $this->me->certifications($sid, $who['id']),
            ];
        } catch (\Throwable $e) {
            return $this->failed($e, 'growth', $sid, $who['id']);
        }

        return $this->ok($data, 'Your growth section was fetched successfully', [
            'empty_is_expected' => $who['jobrole_id'] === null,
            'empty_reason'      => $who['jobrole_id'] === null
                ? 'You do not have a job role yet. Capability targets, the execution model and course suggestions all follow from it, so they stay empty until your HR team sets one.'
                : null,
        ]);
    }

    /**
     * GET /api/dashboard/me/signals
     *
     * The six-month attendance scan, leave, recent activity and what is due.
     * The slowest section and therefore its own request.
     */
    public function signals(Request $request)
    {
        $context = $this->dashboardContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];
        $who = $this->identify($sid, $context['user_id']);
        if (!is_array($who)) {
            return $who;
        }

        $syear = $this->dashboardFilters($request)['syear'];

        try {
            // series() honours $scopeUserId in every query including, since this
            // dashboard, the headcount that forms the denominator.
            $attendance = $this->trend->series($sid, 6, $who['id']);

            $data = [
                'attendance' => $attendance,
                'leave'      => $this->me->leave($sid, $who['id'], $who['department_id']),
                'activity'   => $this->activity->feed($sid, $who['id'], 8),
                // Without a syear the task table cannot be read honestly, so the
                // list is omitted rather than guessed at. The rest of the section
                // does not depend on it and still answers.
                'upcoming'   => $syear === null ? [] : $this->me->upcomingTasks($sid, $syear, $who['id']),
                'upcoming_note' => $syear === null
                    ? 'Your academic year is not set in this session, so upcoming tasks cannot be listed.'
                    : null,
            ];
        } catch (\Throwable $e) {
            return $this->failed($e, 'signals', $sid, $who['id']);
        }

        // ═══════════════════════════════════════════════════════════════
        // ATTENDANCE IS A RECORD OF WHAT WAS LOGGED, NOT A RATE OF TURNING UP
        // ═══════════════════════════════════════════════════════════════
        //
        // This is the most dangerous number on the page. WorkforceTrendService
        // computes present / (present + absent), which is correct where punches
        // are recorded every day. They are not here: the showcase tenant holds
        // 1,182 rows across 17 people and eight months, so the busiest employee
        // has three logged days in a 26-working-day month. Rendered as a
        // percentage that reads "attended 12% of the time" — an accusation
        // manufactured out of a gap in an import.
        //
        // So the section reports COVERAGE alongside the series, and the client
        // charts days recorded rather than a rate whenever coverage is thin.
        $months   = $data['attendance']['months'] ?? [];
        $recorded = array_values(array_filter($months, fn ($m) => $m['present'] !== null));

        $loggedDays   = array_sum(array_map(fn ($m) => (int) $m['present'], $recorded));
        $expectedDays = array_sum(array_map(fn ($m) => (float) ($m['expected_working_days'] ?? 0), $recorded));
        $coverage     = $expectedDays > 0 ? round(($loggedDays / $expectedDays) * 100, 1) : null;

        // Below two thirds, a percentage describes the import rather than the
        // person. The threshold is a judgement; the reason for having one is not.
        $partial = $coverage !== null && $coverage < 66;

        $data['attendance']['recording'] = [
            'days_logged'      => $loggedDays,
            'working_days'     => $expectedDays > 0 ? $expectedDays : null,
            'coverage_percent' => $coverage,
            'partial'          => $partial,
            'months_recorded'  => count($recorded),
            'note'             => $partial
                ? 'Attendance has been logged for ' . $loggedDays . ' of about ' . (int) $expectedDays
                  . ' working days in this period, so this shows days recorded rather than an attendance rate.'
                : null,
        ];

        return $this->ok($data, 'Your signals section was fetched successfully', [
            'empty_is_expected' => empty($recorded),
            'empty_reason'      => empty($recorded)
                ? 'No attendance has been recorded against you in the last six months. This fills once you punch in, or once an attendance import runs.'
                : $data['attendance']['note'],
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════ */

    /**
     * Who is asking, and what job role do they hold.
     *
     * Every section needs the same three facts, and each one costs a query, so
     * they are resolved once per request rather than once per widget.
     *
     * THE JOB ROLE IS RESOLVED THROUGH THE TRAIT, never by reading jobtitle_id.
     * That column is 0 for most employees because the employee form writes the
     * role to allocated_standards instead; reading it directly resolves 23 of 98
     * people and silently shows the other 75 an empty capability widget.
     *
     * @return array{id:int, jobrole_id:?int, department_id:?int, profile:array}|\Illuminate\Http\JsonResponse
     */
    private function identify(int $sid, ?int $userId)
    {
        if ($userId === null) {
            return $this->dashboardFail('Unable to identify your record from this session.', 401);
        }

        $user = DB::table('tbluser')
            ->where('id', $userId)
            ->where('sub_institute_id', $sid)
            ->first(['id', 'first_name', 'last_name', 'email', 'jobtitle_id',
                     'allocated_standards', 'department_id', 'joined_date']);

        if (!$user) {
            // The token resolved to somebody who is not in the token's own
            // tenant. That should be impossible; refusing is cheaper than
            // reasoning about how it happened.
            return $this->dashboardFail('Unable to identify your record.', 401);
        }

        $jobroleId = $this->resolveJobRoleId($user);
        $jobrole   = null;

        if ($jobroleId !== null) {
            $jobrole = DB::table('s_user_jobrole')
                ->where('id', $jobroleId)
                ->where('sub_institute_id', $sid)
                ->whereNull('deleted_at')
                ->first(['id', 'jobrole', 'department']);

            // An id that resolves to no row is not a job role. Held as null so
            // every downstream widget reports "no role" rather than querying an
            // id that matches nothing and reporting "no data".
            if (!$jobrole) {
                $jobroleId = null;
            }
        }

        $department = null;
        $departmentId = (int) ($user->department_id ?? 0) ?: null;

        if ($departmentId !== null) {
            $department = DB::table('hrms_departments')
                ->where('id', $departmentId)
                ->where('sub_institute_id', $sid)
                ->value('department');
        }

        return [
            'id'            => (int) $user->id,
            'jobrole_id'    => $jobroleId,
            'department_id' => $departmentId,
            'profile'       => [
                'user_id'    => (int) $user->id,
                'name'       => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: null,
                'email'      => $user->email,
                'jobrole_id' => $jobroleId,
                'jobrole'    => $jobrole->jobrole ?? null,
                // A zero-date joined_date is MariaDB's, not a real date. It is
                // held as null rather than rendered as "0000-00-00".
                'joined'     => $this->realDate($user->joined_date ?? null),
                'department' => $department,
                'jobrole_note' => $jobroleId === null
                    ? 'No job role is set against your record, so capability targets and the execution model cannot be shown.'
                    : null,
            ],
        ];
    }

    /** MariaDB zero-dates are absence, not a date in the year zero. */
    private function realDate($value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        return $value;
    }

    /**
     * The success envelope, with scope pinned to 'self'.
     *
     * dashboardOk() defaults scope to 'organisation' because the HR dashboard is
     * what it was written for. Every answer from this controller is one person's
     * data and must say so — a client that caches by scope would otherwise store
     * an employee's figures under the organisation's key.
     */
    private function ok(array $data, string $message, array $extra = [])
    {
        return $this->dashboardOk($data, $message, array_merge(['scope' => 'self'], $extra));
    }

    /**
     * One place to fail.
     *
     * The exception is logged with its tenant, section and subject; the CLIENT is
     * told only what failed. NEVER $e->getMessage() — TalentDashboardController
     * returns it and hands out SQL fragments, table names and file paths on any
     * unexpected error. That is the pattern being avoided, not followed.
     */
    private function failed(\Throwable $e, string $section, int $sid, int $me)
    {
        Log::error('Employee dashboard section failed', [
            'section' => $section,
            'tenant'  => $sid,
            'user'    => $me,
            'error'   => $e->getMessage(),
            'file'    => $e->getFile() . ':' . $e->getLine(),
        ]);

        return $this->dashboardFail('The ' . $section . ' section could not be loaded.', 500);
    }
}
