<?php

namespace App\Services\Dashboard;

use App\Services\Competency\CommandCenterService;
use Illuminate\Support\Facades\DB;

/**
 * THE HR/ADMIN HOME DASHBOARD — BATCH 1 (the /summary and /filters sections).
 *
 * Orchestrator only. It owns no SQL that another service already owns:
 * headcount, attendance, departments and tasks come from
 * WorkforceMetricsService; the activity feed from DashboardActivityFeed;
 * location and business-unit vocabularies from CommandCenterService.
 *
 * EVERY TILE CAN SAY "NOT MEASURED" INDEPENDENTLY. A tile carries `value: null`
 * plus its own `empty_reason` rather than the section reporting one blanket
 * empty state — because "no certification requirements are defined" and "no
 * attendance was recorded today" are different facts with different fixes, and
 * collapsing them into one message tells the user to look in the wrong place.
 *
 * NULL IS NOT ZERO. A tile that cannot be measured shows nothing and explains
 * itself. It never shows 0, which is a measurement.
 */
class HrDashboardService
{
    public function __construct(
        private WorkforceMetricsService $workforce,
        private DashboardActivityFeed $activity,
        private CommandCenterService $commandCenter,
    ) {
    }

    /**
     * The filter vocabularies.
     *
     * Location and Business Unit are returned WITH an availability flag and a
     * reason. They exist on s_user_jobrole and describe job roles; tbluser has
     * no location or business_unit column, so choosing one cannot filter
     * headcount, attendance, tasks or departments. Returning them as working
     * filters would give the user a control that silently does nothing to most
     * of the page — worse than one that says it is unavailable.
     */
    public function filters(int $sid): array
    {
        $departments = DB::table('hrms_departments')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->orderBy('department')
            ->get(['id', 'department']);

        $withoutTenant = (int) DB::table('hrms_departments')
            ->whereNull('sub_institute_id')
            ->whereNull('deleted_at')
            ->count();

        $vocab = [];
        try {
            $vocab = $this->commandCenter->filterOptions($sid);
        } catch (\Throwable $e) {
            // The vocabularies are a convenience; losing them must not cost the
            // user the department filter, which is the one that actually works.
            $vocab = [];
        }

        return [
            'departments' => $departments->map(fn ($d) => [
                'value' => (string) $d->id,
                'label' => (string) $d->department,
            ])->values()->all(),

            'departments_empty_reason' => $departments->isEmpty()
                ? ('No departments are assigned to this organisation.'
                   . ($withoutTenant > 0 ? ' ' . $withoutTenant . ' departments exist with no organisation set.' : ''))
                : null,

            'locations'                => $this->vocabList($vocab, 'locations'),
            'locations_available'      => false,
            'locations_reason'         => 'Location is recorded on job roles, not on employees, so it cannot filter headcount, attendance or tasks.',

            'business_units'           => $this->vocabList($vocab, 'business_units'),
            'business_units_available' => false,
            'business_units_reason'    => 'No business unit is recorded against employees in this schema.',
        ];
    }

    /** BATCH 1 — the /summary section. */
    public function summary(int $sid, string $syear, array $filters, ?int $scopeUserId = null): array
    {
        // $filters WAS ACCEPTED AND IGNORED until now, so the Department
        // dropdown changed nothing on screen. It is applied to every
        // people-shaped figure below; the module-wide counts (approvals,
        // certifications) have no department dimension and stay organisation-wide.
        $departmentId = isset($filters['department_id']) && $filters['department_id'] !== null
            ? (int) $filters['department_id']
            : null;

        $headcount  = $this->workforce->headcount($sid, $scopeUserId, $departmentId);
        $attendance = $this->workforce->attendanceToday($sid, $scopeUserId, $departmentId);
        $overdue    = $this->workforce->overdueTasks($sid, $syear, $scopeUserId);
        $approvals  = $this->pendingApprovals($sid);
        $compliance = $this->complianceRate($sid);

        return [
            'kpis' => [
                $this->tile('total_employees', 'Total Employees', $headcount['total'], null, null, 'employee-directory'),
                $this->tile('attendance_today', 'Attendance Today', $attendance['value'], '%', $attendance['empty_reason'], 'attendance'),
                $this->tile('pending_approvals', 'Pending Approvals', $approvals['total'], null, null, 'approvals', ['breakdown' => $approvals['breakdown']]),
                $this->tile('overdue_tasks', 'Overdue Tasks', $overdue['value'], null, null, 'task-management'),
                $this->tile('compliance_rate', 'Compliance Rate', $compliance['value'], '%', $compliance['empty_reason'], 'certifications'),
                $this->tile('blocked_tasks', 'Blocked Tasks', $this->workforce->blockedTasks($sid, $syear, $scopeUserId), null, null, 'task-management'),
            ],

            'action_center' => $this->actionCenter($sid, $syear, $approvals),
            'departments'   => $this->workforce->departmentDistribution($sid, 5, $departmentId),
            'activity'      => $this->activity->feed($sid, $scopeUserId, 8),

            // Surfaced, not folded in: these rows are excluded from every count
            // above because they belong to no organisation, and a visible
            // diagnostic is the difference between an explicable discrepancy
            // and a mysterious one.
            'diagnostics' => [
                'employees_without_tenant' => $headcount['employees_without_tenant'],
                'tasks_without_tenant'     => $overdue['tasks_without_tenant'],
            ],
        ];
    }

    /**
     * MODULE SUMMARY - six cards, three figures each.
     *
     * Composed from what each module already counts, so Home cannot disagree
     * with the module's own screen. A figure with no source is null, never a
     * plausible-looking number.
     */
    public function moduleSummary(int $sid, ?string $syear): array
    {
        $employees = (int) $this->workforce->activeEmployeeQuery($sid)->count();

        $departments = (int) DB::table('hrms_departments')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')->count();

        $roles = (int) DB::table('s_user_jobrole')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')->count();

        $frameworks   = $this->safeCount('s_competency_frameworks', $sid);
        $assessments  = $this->safeCount('s_competency_assessments', $sid);
        $competencies = $this->safeCount('competency', $sid);

        $courses    = $this->safeCount('sub_std_map', $sid);
        $enrolments = DB::table('lms_course_enroll')->where('sub_institute_id', $sid)->whereNull('deleted_at');
        $inProgress = (int) (clone $enrolments)->whereRaw("LOWER(COALESCE(status,'')) = 'in-progress'")->count();
        $completed  = (int) (clone $enrolments)->whereRaw("LOWER(COALESCE(status,'')) = 'completed'")->count();

        $tasks = DB::table('task')->where('sub_institute_id', $sid)->whereNull('deleted_at');
        if ($syear !== null) {
            $tasks->where('SYEAR', $syear);
        }
        $totalTasks     = (int) (clone $tasks)->count();
        $completedTasks = (int) (clone $tasks)->whereRaw("UPPER(COALESCE(status,'')) = 'COMPLETED'")->count();
        $overdueTasks   = $syear === null ? null : $this->workforce->overdueTasks($sid, $syear)['value'];

        $attendance = $this->workforce->attendanceToday($sid);

        $pendingLeave = (int) DB::table('hrms_emp_leaves')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')
            ->whereRaw('LOWER(status) = ?', ['pending'])->count();

        $holidaysThisMonth = (int) DB::table('hrms_holidays')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')
            ->whereBetween('from_date', [date('Y-m-01'), date('Y-m-t')])->count();

        return [
            ['key' => 'organization', 'title' => 'Organization', 'figures' => [
                ['label' => 'Departments', 'value' => $departments, 'unit' => null],
                ['label' => 'Employees',   'value' => $employees,   'unit' => null],
                ['label' => 'Job Roles',   'value' => $roles,       'unit' => null],
            ]],
            ['key' => 'competency', 'title' => 'Competency', 'figures' => [
                ['label' => 'Frameworks',   'value' => $frameworks,   'unit' => null],
                ['label' => 'Assessments',  'value' => $assessments,  'unit' => null],
                ['label' => 'Competencies', 'value' => $competencies, 'unit' => null],
            ]],
            ['key' => 'talent', 'title' => 'Talent', 'figures' => [
                ['label' => 'Open Positions', 'value' => $this->talentCount($sid, 'open_positions'), 'unit' => null],
                ['label' => 'Candidates',     'value' => $this->talentCount($sid, 'candidates'),     'unit' => null],
                ['label' => 'Onboarding',     'value' => $this->talentCount($sid, 'onboarding'),     'unit' => null],
            ]],
            ['key' => 'lms', 'title' => 'LMS', 'figures' => [
                ['label' => 'Courses',     'value' => $courses,    'unit' => null],
                ['label' => 'In Progress', 'value' => $inProgress, 'unit' => null],
                ['label' => 'Completed',   'value' => $completed,  'unit' => null],
            ]],
            ['key' => 'hrit', 'title' => 'HRIT', 'figures' => [
                ['label' => 'Attendance',       'value' => $attendance['value'], 'unit' => '%'],
                ['label' => 'Leave Requests',   'value' => $pendingLeave,        'unit' => null],
                // PAYROLL RUNS REPLACED. This schema has no payroll run, cycle
                // or batch entity - only a salary-head master and per-employee
                // deductions - so "Payroll Runs: Complete" asserted a status
                // nothing computes. Holidays this month is measurable.
                ['label' => 'Holidays (month)', 'value' => $holidaysThisMonth,   'unit' => null],
            ]],
            ['key' => 'tasks', 'title' => 'Tasks', 'figures' => [
                ['label' => 'Total Tasks', 'value' => $totalTasks,     'unit' => null],
                ['label' => 'Completed',   'value' => $completedTasks, 'unit' => null],
                ['label' => 'Overdue',     'value' => $overdueTasks,   'unit' => null],
            ]],
        ];
    }

    /**
     * LEARNING PROGRESS - the three rings.
     *
     * The original had two rings both labelled "On Track" and a caption like
     * "256 / 304" with nothing saying what was being measured. Each ring now
     * carries a `title`.
     *
     * Deliberately NOT built on lms_content_progress or lms_certificates: both
     * are empty in every tenant, and a ring permanently reading 0% looks like a
     * broken feature rather than an unused one.
     */
    public function learningProgress(int $sid): array
    {
        $enrol = DB::table('lms_course_enroll')->where('sub_institute_id', $sid)->whereNull('deleted_at');

        $total     = (int) (clone $enrol)->count();
        $completed = (int) (clone $enrol)->whereRaw("LOWER(COALESCE(status,'')) = 'completed'")->count();
        $started   = (int) (clone $enrol)->whereRaw("LOWER(COALESCE(status,'')) IN ('completed','in-progress')")->count();
        $learners  = (int) (clone $enrol)->distinct()->count('user_id');

        $employees = (int) $this->workforce->activeEmployeeQuery($sid)->count();

        $courses       = (int) DB::table('sub_std_map')->where('sub_institute_id', $sid)->whereNull('deleted_at')->count();
        $activeCourses = (int) DB::table('sub_std_map')->where('sub_institute_id', $sid)->whereNull('deleted_at')->where('status', 1)->count();

        return [
            $this->ring('completion', 'Course Completion', $completed, $total, 'primary'),
            $this->ring('coverage', 'Learner Coverage', $learners, $employees, 'success'),
            $this->ring('catalogue', 'Catalogue Active', $activeCourses, $courses, 'indigo'),
        ];
    }

    /** A ring. A zero denominator is NOT 0% - it is not measurable. */
    private function ring(string $key, string $title, int $current, int $total, string $variant): array
    {
        return [
            'key'     => $key,
            'title'   => $title,
            'percent' => $total > 0 ? (int) round(($current / $total) * 100) : null,
            'current' => $current,
            'total'   => $total,
            'variant' => $variant,
            'empty_reason' => $total > 0 ? null : 'Nothing to measure against yet.',
        ];
    }

    /**
     * TALENT ACQUISITION FUNNEL.
     *
     * Stage names follow the recruitment module's own vocabulary so Home and
     * /talent/dashboard cannot print different numbers under the same label.
     */
    public function talentFunnel(int $sid): array
    {
        $apps = function (array $statuses) use ($sid) {
            try {
                $q = DB::table('talent_job_applications')->where('sub_institute_id', $sid)->whereNull('deleted_at');
                if ($statuses) {
                    $q->whereIn(DB::raw('LOWER(status)'), array_map('strtolower', $statuses));
                }
                return (int) $q->count();
            } catch (\Throwable $e) {
                return 0;
            }
        };

        $postings = $this->talentCount($sid, 'open_positions') ?? 0;

        return [
            ['key' => 'requisition', 'name' => 'Requisition', 'value' => $postings],
            ['key' => 'applications', 'name' => 'Applications', 'value' => $apps([])],
            ['key' => 'screening', 'name' => 'Screening', 'value' => $apps(['Pending Review', 'Under Review', 'Shortlisted'])],
            ['key' => 'interview', 'name' => 'Interview', 'value' => $apps(['Interview Scheduled'])],
            ['key' => 'hired', 'name' => 'Hired', 'value' => $apps(['Hired'])],
        ];
    }

    /** A count that costs one figure, not the whole card, when a table is absent. */
    private function safeCount(string $table, int $sid): ?int
    {
        try {
            return (int) DB::table($table)->where('sub_institute_id', $sid)->whereNull('deleted_at')->count();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Talent figures, guarded: those tables are empty or absent in some tenants. */
    private function talentCount(int $sid, string $which): ?int
    {
        try {
            if ($which === 'open_positions') {
                return (int) DB::table('talent_job_postings')
                    ->where('sub_institute_id', $sid)->whereNull('deleted_at')
                    ->whereRaw("LOWER(COALESCE(status,'')) = 'active'")->count();
            }

            if ($which === 'candidates') {
                return (int) DB::table('talent_job_applications')
                    ->where('sub_institute_id', $sid)->whereNull('deleted_at')->count();
            }

            return (int) DB::table('talent_onboarding_journeys')
                ->where('sub_institute_id', $sid)->whereNull('deleted_at')
                ->whereRaw("LOWER(COALESCE(status,'')) <> 'completed'")->count();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * UPCOMING EVENTS, merged from every dated source this tenant owns.
     *
     * The original design used a positional 4-tuple whose fourth slot was
     * sometimes a time ("All Day") and sometimes a room ("Conference Room A").
     * Naming the fields is what stops a room being rendered as a time.
     */
    public function upcomingEvents(int $sid, int $limit = 6): array
    {
        $today = date('Y-m-d');
        $out   = [];

        foreach (DB::table('hrms_holidays')
                    ->where('sub_institute_id', $sid)->whereNull('deleted_at')
                    ->where('to_date', '>=', $today)->orderBy('from_date')->limit($limit)
                    ->get(['id', 'holiday_name', 'description', 'from_date', 'day_type']) as $r) {
            $out[] = [
                'id'         => 'holiday-' . $r->id,
                'date'       => $r->from_date,
                'title'      => $r->holiday_name,
                'subtitle'   => $r->description ?: 'Company holiday',
                'time_label' => strtolower((string) $r->day_type) === 'half' ? 'Half Day' : 'All Day',
                'location'   => null,
                'source'     => 'holiday',
            ];
        }

        try {
            foreach (DB::table('talent_interview_schedules')
                        ->where('sub_institute_id', $sid)->whereNull('deleted_at')
                        ->where('interview_date', '>=', $today)->orderBy('interview_date')->limit($limit)
                        ->get(['id', 'interview_date', 'round_no', 'status']) as $r) {
                $out[] = [
                    'id'         => 'interview-' . $r->id,
                    'date'       => $r->interview_date,
                    'title'      => 'Candidate Interview',
                    'subtitle'   => 'Round ' . ($r->round_no ?: 1) . ' - ' . ucfirst((string) $r->status),
                    'time_label' => null,
                    'location'   => null,
                    'source'     => 'interview',
                ];
            }
        } catch (\Throwable $e) {
            // optional source
        }

        $extra = [
            ['calendar_events', 'school_date'],
            ['announcement',    'from_date'],
        ];

        foreach ($extra as [$table, $dateCol]) {
            try {
                foreach (DB::table($table)
                            ->where('sub_institute_id', $sid)->whereNull('deleted_at')
                            ->where($dateCol, '>=', $today)->orderBy($dateCol)->limit($limit)
                            ->get(['id', $dateCol . ' as event_date', 'title', 'description']) as $r) {
                    $out[] = [
                        'id'         => $table . '-' . $r->id,
                        'date'       => $r->event_date,
                        'title'      => $r->title,
                        'subtitle'   => $r->description,
                        'time_label' => null,
                        'location'   => null,
                        'source'     => $table,
                    ];
                }
            } catch (\Throwable $e) {
                // optional source
            }
        }

        usort($out, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        return array_slice($out, 0, $limit);
    }

    /**
     * One tile.
     *
     * `trend` is null throughout batch 1 — there is no metric history to
     * compare against yet. dashboard_metric_snapshot and its nightly command
     * fill this in; until that job has run twice, an arrow would be invented.
     */
    private function tile(string $key, string $label, $value, ?string $unit, ?string $emptyReason, ?string $menu, array $extra = []): array
    {
        return array_merge([
            'key'          => $key,
            'label'        => $label,
            'value'        => $value,
            'unit'         => $unit,
            'trend'        => null,
            'empty_reason' => $emptyReason,
            'menu'         => $menu,
        ], $extra);
    }

    /**
     * Pending approvals, returned as a total AND its breakdown.
     *
     * The breakdown is what makes the tile auditable — a single number nobody
     * can decompose is a number nobody can check. The Action Center reuses this
     * same array rather than re-querying, so the two cannot disagree.
     */
    private function pendingApprovals(int $sid): array
    {
        $breakdown = [];

        $breakdown[] = [
            'key'   => 'leave',
            'label' => 'Leave Requests',
            'count' => (int) DB::table('hrms_emp_leaves')
                ->where('sub_institute_id', $sid)
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(status) = ?', ['pending'])
                ->count(),
            'menu'  => 'leave-management',
        ];

        $breakdown[] = [
            'key'   => 'competency',
            'label' => 'Competency Approvals',
            'count' => (int) DB::table('s_competency_approvals')
                ->where('sub_institute_id', $sid)
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(status) = ?', ['pending'])
                ->count(),
            'menu'  => 'competency-approvals',
        ];

        $breakdown[] = [
            'key'   => 'performance',
            'label' => 'Performance Reviews',
            'count' => (int) DB::table('s_performance_reviews')
                ->where('sub_institute_id', $sid)
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(COALESCE(status,\'\')) = ?', ['pending'])
                ->count(),
            'menu'  => 'performance',
        ];

        return [
            'total'     => array_sum(array_column($breakdown, 'count')),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * The Action Center.
     *
     * FIVE ROWS, NOT SIX. The static design carried a "Compliance Violations"
     * row; no violations table, module or endpoint exists anywhere in this
     * schema. Inventing a counter for a workflow that does not exist — who
     * reports, who resolves, what severities — would be fabricating a feature
     * behind a number, so the row is gone rather than permanently zero.
     */
    private function actionCenter(int $sid, string $syear, array $approvals): array
    {
        // `state` is the short status phrase the design shows on the right of
        // each row ("Needs Approval", "Pending Review"). It is presentation
        // text, but it belongs with the row it describes rather than being
        // re-derived in the component from the key.
        $states = [
            'leave'       => 'Needs Approval',
            'competency'  => 'Pending Review',
            'performance' => 'Pending Review',
        ];

        $rows = [];

        foreach ($approvals['breakdown'] as $item) {
            $rows[] = [
                'key'   => $item['key'],
                'label' => $item['label'],
                'count' => $item['count'],
                'state' => $states[$item['key']] ?? 'Pending',
                'tone'  => $item['count'] > 0 ? 'warning' : 'neutral',
                'menu'  => $item['menu'],
            ];
        }

        // The column is expiry_date, and the status vocabulary is
        // valid/expiring/expired/revoked — both confirmed against the table, not
        // assumed. The date window is used rather than status='expiring'
        // because the status is a stored label that only moves when something
        // recomputes it, whereas the date is true the moment it passes.
        $expiring = (int) DB::table('s_competency_certifications')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [date('Y-m-d'), date('Y-m-d', strtotime('+30 days'))])
            ->count();

        $rows[] = [
            'key'   => 'expiring_certifications',
            'label' => 'Expiring Certifications',
            'count' => $expiring,
            'state' => 'Expiring Soon',
            'tone'  => $expiring > 0 ? 'danger' : 'neutral',
            'menu'  => 'certifications',
        ];

        $blocked = $this->workforce->blockedTasks($sid, $syear);

        $rows[] = [
            'key'   => 'blocked_tasks',
            'label' => 'Blocked Tasks',
            'count' => $blocked,
            'state' => 'Needs Unblock',
            'tone'  => $blocked > 0 ? 'danger' : 'neutral',
            'menu'  => 'task-management',
        ];

        // COMPLIANCE VIOLATIONS — the row keeps its place in the design, but
        // there is no violations table, controller or endpoint anywhere in this
        // schema. `count: null` renders as a dash; inventing a zero would claim
        // a measurement that nothing performs, and quietly dropping the row
        // would hide that the capability is missing rather than clean.
        $rows[] = [
            'key'   => 'compliance_violations',
            'label' => 'Compliance Violations',
            'count' => null,
            'state' => 'Not tracked yet',
            'tone'  => 'neutral',
            'menu'  => null,
        ];

        return $rows;
    }

    /**
     * Compliance rate.
     *
     * CONDITIONAL BY NATURE. Compliance is measured against active certification
     * requirements; with none defined the universe is empty and there is nothing
     * to be compliant with. That is not 0% — 0% asserts that every employee
     * failed. It returns null and names the fix.
     */
    private function complianceRate(int $sid): array
    {
        $requirements = (int) DB::table('s_competency_certification_requirements')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->count();

        if ($requirements === 0) {
            return [
                'value'        => null,
                'empty_reason' => 'No certification requirements are defined, so compliance cannot be measured. Define them under Capability Intelligence → Certifications.',
            ];
        }

        $employees = (int) $this->workforce->activeEmployeeQuery($sid)->count();

        if ($employees === 0) {
            return ['value' => null, 'empty_reason' => 'No active employees to measure compliance against.'];
        }

        // 'valid' is the compliant state. The full vocabulary in this table is
        // valid / expiring / expired / revoked — measured, not assumed. An
        // expiring certificate still counts as compliant today; that is what
        // the Expiring Certifications row in the Action Center is for.
        $compliant = (int) DB::table('s_competency_certifications')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereRaw("LOWER(COALESCE(status,'')) IN ('valid','expiring')")
            ->distinct()
            ->count('user_id');

        return [
            'value'        => round((min($compliant, $employees) / $employees) * 100, 1),
            'empty_reason' => null,
        ];
    }

    /** CommandCenterService's option lists arrive in a few shapes; normalise. */
    private function vocabList(array $vocab, string $key): array
    {
        $list = $vocab[$key] ?? [];

        if (!is_array($list)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($item) {
            if (is_array($item)) {
                $value = $item['value'] ?? $item['id'] ?? $item['label'] ?? null;
                $label = $item['label'] ?? $item['name'] ?? $value;
            } else {
                $value = $label = $item;
            }

            return $value === null || $value === '' ? null : ['value' => (string) $value, 'label' => (string) $label];
        }, $list)));
    }
}
