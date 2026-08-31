<?php

namespace App\Services\Competency;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates every metric the Competency Command Center dashboard renders:
 * the six summary tiles, four progress rings, five work queues, the recent
 * activity feed and the assessment-calendar bar.
 *
 * All queries are tenant scoped by sub_institute_id and honour the five
 * command-center filters (Department, Job Role, Location, Business Unit,
 * Job Family). The role-based filters are resolved against s_user_jobrole into
 * a set of matching job roles, which then constrains every competency-domain
 * table (they carry a `jobrole` column). Role coverage KPIs are computed
 * directly from s_user_jobrole / s_user_skill_jobrole, exactly like the
 * existing /api/competency/kpi endpoint.
 */
class CommandCenterService
{
    /** Build the full dashboard payload. */
    public function dashboard(int $subInstituteId, ?int $userId, array $filters): array
    {
        [$totalRoles, $rolesWithSkills, $scopeRoles] = $this->resolveRoleScope($subInstituteId, $filters);

        return [
            'summary'             => $this->summary($subInstituteId, $filters, $scopeRoles, $totalRoles, $rolesWithSkills),
            'progress'            => $this->progress($subInstituteId, $scopeRoles, $totalRoles, $rolesWithSkills),
            'work_queues'         => $this->workQueues($subInstituteId, $userId, $scopeRoles),
            'recent_activity'     => $this->recentActivity($subInstituteId),
            'assessment_calendar' => $this->assessmentCalendar($subInstituteId, $scopeRoles),
        ];
    }

    /* ----------------------------------------------------------------- *
     * Role scope + coverage KPIs (from the existing Skill/Role tables)
     * ----------------------------------------------------------------- */

    /**
     * @return array{0:int,1:int,2:?array<int,string>} [totalRoles, rolesWithSkills, scopeRoles|null]
     */
    private function resolveRoleScope(int $subInstituteId, array $filters): array
    {
        $roleBase = DB::table('s_user_jobrole as jr')
            ->where('jr.sub_institute_id', $subInstituteId)
            ->whereNull('jr.deleted_at');

        if ($filters['department_id'] !== null) {
            $roleBase->where('jr.department_id', (int) $filters['department_id']);
        }
        if ($filters['jobrole'] !== null) {
            $roleBase->where('jr.jobrole', $filters['jobrole']);
        }
        if ($filters['location'] !== null) {
            $roleBase->where('jr.location', $filters['location']);
        }
        if ($filters['business_unit'] !== null) {
            $roleBase->where('jr.industries', $filters['business_unit']);
        }
        if ($filters['job_family'] !== null) {
            $roleBase->where('jr.jobrole_category', $filters['job_family']);
        }

        $totalRoles = (clone $roleBase)->count();

        $rolesWithSkills = (clone $roleBase)
            ->whereExists(function ($q) use ($subInstituteId) {
                $q->select(DB::raw(1))
                    ->from('s_user_skill_jobrole as sj')
                    ->whereColumn('sj.jobrole', 'jr.jobrole')
                    ->where('sj.sub_institute_id', $subInstituteId)
                    ->whereNull('sj.deleted_at');
            })
            ->count();

        $scopeActive = collect($filters)->contains(fn ($v) => $v !== null);
        $scopeRoles = $scopeActive
            ? (clone $roleBase)->distinct()->pluck('jr.jobrole')->filter()->values()->all()
            : null;

        return [$totalRoles, $rolesWithSkills, $scopeRoles];
    }

    /** Constrain a competency-domain query to the active role scope. */
    private function applyScope($query, ?array $scopeRoles)
    {
        if ($scopeRoles !== null) {
            // Empty scope => the filter matched no roles => return nothing.
            $query->whereIn('jobrole', empty($scopeRoles) ? ['\0__none__'] : $scopeRoles);
        }

        return $query;
    }

    private function baseCount(string $table, int $subInstituteId, ?array $scopeRoles)
    {
        $q = DB::table($table)
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at');

        return $this->applyScope($q, $scopeRoles);
    }

    /**
     * Total competencies — FROM `competency`, the table the Library owns.
     *
     * ── WHAT THIS SAID BEFORE, AND WHY IT STOPPED BEING TRUE ────────────────
     *
     *   > "In this ERP a competency IS an approved skill, so this reads the
     *   >  existing s_users_skills catalog rather than a duplicate competency
     *   >  table."
     *
     * That was accurate when it was written and is not any more. The Competency
     * Library was moved onto the `competency` table, and nothing reachable edits
     * `s_users_skills` as a competency catalogue now — so this tile and the
     * screen a user opens from it counted two different populations.
     *
     * The gap is not a rounding difference: for tenant 6 this reported 124 (dev)
     * and 121 (live) while the Library listed 22. Two screens in one module using
     * one word for numbers 5x apart, and the tile was the one users saw first.
     *
     * ── THE FILTERS HAD TO CHANGE SHAPE WITH IT ─────────────────────────────
     *
     * `competency` has no `department_id` — competencies are not departmental,
     * job roles are — so Department now restricts to competencies mapped to a
     * job role in that department, via `jobrole_competency_map`. That is the same
     * question the old filter was reaching for, asked of a table that can answer
     * it. The role-based filters go through the same map, replacing the
     * skill-title match against `s_jobrole_skills`.
     */
    private function competenciesCount(int $sid, array $filters, ?array $scopeRoles): int
    {
        $q = DB::table('competency')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at');

        if ($filters['department_id'] !== null) {
            $q->whereIn('id', function ($sub) use ($sid, $filters) {
                $sub->select('m.competency_id')
                    ->from('jobrole_competency_map as m')
                    ->join('s_user_jobrole as r', 'r.id', '=', 'm.jobrole_id')
                    ->where('m.sub_institute_id', $sid)
                    ->where('r.department_id', (int) $filters['department_id']);
            });
        }

        $roleFilterActive = $filters['jobrole'] !== null || $filters['location'] !== null
            || $filters['business_unit'] !== null || $filters['job_family'] !== null;

        if ($roleFilterActive) {
            // A filter that resolves to no roles must return 0, not everything.
            $roles = ($scopeRoles && count($scopeRoles)) ? $scopeRoles : ['\0__none__'];
            $q->whereIn('id', function ($sub) use ($sid, $roles) {
                $sub->select('m.competency_id')
                    ->from('jobrole_competency_map as m')
                    ->join('s_user_jobrole as r', 'r.id', '=', 'm.jobrole_id')
                    ->where('m.sub_institute_id', $sid)
                    ->whereIn('r.jobrole', $roles);
            });
        }

        return $q->count();
    }

    /* ----------------------------------------------------------------- *
     * Summary tiles
     * ----------------------------------------------------------------- */

    private function summary(int $sid, array $filters, ?array $scopeRoles, int $totalRoles, int $rolesWithSkills): array
    {
        $competencies = $this->competenciesCount($sid, $filters, $scopeRoles);

        $frameworks = (clone $this->baseCount('s_competency_frameworks', $sid, $scopeRoles))
            ->where('status', 'active')->count();

        $assessments = (clone $this->baseCount('s_competency_assessments', $sid, $scopeRoles))
            ->whereIn('status', ['open', 'in_progress'])->count();

        $certifications = (clone $this->baseCount('s_competency_certifications', $sid, $scopeRoles))
            ->where('status', 'valid')->count();

        $plans = (clone $this->baseCount('s_competency_development_plans', $sid, $scopeRoles))
            ->where('status', 'active')->count();

        return [
            ['key' => 'competencies', 'label' => 'Total Competencies', 'value' => $competencies, 'desc' => 'Published'],
            ['key' => 'frameworks', 'label' => 'Active Frameworks', 'value' => $frameworks, 'desc' => 'Active'],
            ['key' => 'roles_mapped', 'label' => 'Job Roles Mapped', 'value' => $rolesWithSkills, 'total' => $totalRoles, 'desc' => 'Out of ' . $totalRoles],
            ['key' => 'assessments', 'label' => 'Active Assessments', 'value' => $assessments, 'desc' => 'In progress'],
            ['key' => 'certifications', 'label' => 'Certifications', 'value' => $certifications, 'desc' => 'Valid'],
            ['key' => 'development_plans', 'label' => 'Development Plans', 'value' => $plans, 'desc' => 'Active'],
        ];
    }

    /* ----------------------------------------------------------------- *
     * Progress rings
     * ----------------------------------------------------------------- */

    private function progress(int $sid, ?array $scopeRoles, int $totalRoles, int $rolesWithSkills): array
    {
        // Role mapping completion (from Skill/Role tables)
        $rolePercent = $totalRoles > 0 ? round(($rolesWithSkills / $totalRoles) * 100) : 0;

        // Assessment completion
        $totalAssessments = (clone $this->baseCount('s_competency_assessments', $sid, $scopeRoles))->count();
        $completedAssessments = (clone $this->baseCount('s_competency_assessments', $sid, $scopeRoles))
            ->where('status', 'completed')->count();
        $assessmentPercent = $totalAssessments > 0 ? round(($completedAssessments / $totalAssessments) * 100) : 0;

        // Certification compliance: valid certifications / all still-relevant
        // (non-revoked) certifications. Count-based so it stays meaningful
        // regardless of how many distinct employee records the tenant holds.
        $validCerts = (clone $this->baseCount('s_competency_certifications', $sid, $scopeRoles))
            ->where('status', 'valid')->count();
        $activeCerts = (clone $this->baseCount('s_competency_certifications', $sid, $scopeRoles))
            ->where('status', '!=', 'revoked')->count();
        $certPercent = $activeCerts > 0 ? round(($validCerts / $activeCerts) * 100) : 0;

        // Development plan completion
        $totalPlans = (clone $this->baseCount('s_competency_development_plans', $sid, $scopeRoles))->count();
        $completedPlans = (clone $this->baseCount('s_competency_development_plans', $sid, $scopeRoles))
            ->where('status', 'completed')->count();
        $planPercent = $totalPlans > 0 ? round(($completedPlans / $totalPlans) * 100) : 0;

        return [
            ['key' => 'role_mapping', 'title' => 'Role Mapping Completion', 'percent' => (int) $rolePercent, 'current' => $rolesWithSkills, 'total' => $totalRoles, 'sub' => 'Roles Mapped'],
            ['key' => 'assessment', 'title' => 'Assessment Completion', 'percent' => (int) $assessmentPercent, 'current' => $completedAssessments, 'total' => $totalAssessments, 'sub' => 'Completed Assessments'],
            ['key' => 'certification', 'title' => 'Certification Compliance', 'percent' => (int) $certPercent, 'current' => $validCerts, 'total' => $activeCerts, 'sub' => 'Valid Certifications'],
            ['key' => 'development', 'title' => 'Development Plan Completion', 'percent' => (int) $planPercent, 'current' => $completedPlans, 'total' => $totalPlans, 'sub' => 'Completed Plans'],
        ];
    }

    /* ----------------------------------------------------------------- *
     * Work queues
     * ----------------------------------------------------------------- */

    private function workQueues(int $sid, ?int $userId, ?array $scopeRoles): array
    {
        $today = Carbon::today()->toDateString();
        $in30Days = Carbon::today()->addDays(30)->toDateString();

        // Pending my approvals: dev plans awaiting the current user's approval.
        $myApprovals = 0;
        if ($userId !== null) {
            $myApprovals = (clone $this->baseCount('s_competency_development_plans', $sid, $scopeRoles))
                ->where('approval_status', 'pending_approval')
                ->where('approver_id', $userId)
                ->count();
        }

        $pendingReviews = (clone $this->baseCount('s_competency_assessments', $sid, $scopeRoles))
            ->where('review_status', 'pending_review')
            ->count();

        $openAssessments = (clone $this->baseCount('s_competency_assessments', $sid, $scopeRoles))
            ->whereIn('status', ['open', 'in_progress'])
            ->count();

        $expiringCerts = (clone $this->baseCount('s_competency_certifications', $sid, $scopeRoles))
            ->whereIn('status', ['valid', 'expiring'])
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [$today, $in30Days])
            ->count();

        $overduePlans = (clone $this->baseCount('s_competency_development_plans', $sid, $scopeRoles))
            ->where(function ($q) use ($today) {
                $q->where('status', 'overdue')
                    ->orWhere(function ($q2) use ($today) {
                        $q2->whereNotNull('due_date')
                            ->where('due_date', '<', $today)
                            ->whereNotIn('status', ['completed']);
                    });
            })
            ->count();

        return [
            ['key' => 'my_approvals', 'label' => 'Pending My Approvals', 'count' => $myApprovals],
            ['key' => 'pending_reviews', 'label' => 'Pending Reviews', 'count' => $pendingReviews],
            ['key' => 'open_assessments', 'label' => 'Open Assessments', 'count' => $openAssessments],
            ['key' => 'expiring_certifications', 'label' => 'Expiring Certifications (30 Days)', 'count' => $expiringCerts],
            ['key' => 'overdue_plans', 'label' => 'Overdue Development Plans', 'count' => $overduePlans],
        ];
    }

    /* ----------------------------------------------------------------- *
     * Recent activity
     * ----------------------------------------------------------------- */

    private function recentActivity(int $sid, int $limit = 6): array
    {
        $rows = DB::table('s_competency_activity_log')
            ->where('sub_institute_id', $sid)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $rows->map(function ($row) {
            $timestamp = $row->created_at;

            return [
                'id'        => (int) $row->id,
                'user'      => $row->actor_name ?: 'System',
                'action'    => $row->description ?: $row->action,
                'time'      => $timestamp ? Carbon::parse($timestamp)->diffForHumans() : '',
                'timestamp' => $timestamp,
            ];
        })->all();
    }

    /* ----------------------------------------------------------------- *
     * Assessment calendar
     * ----------------------------------------------------------------- */

    private function assessmentCalendar(int $sid, ?array $scopeRoles): array
    {
        $today = Carbon::today()->toDateString();
        $in60Days = Carbon::today()->addDays(60)->toDateString();

        // The active cycle, or the next one starting within 60 days.
        $cycle = DB::table('s_competency_assessment_cycles')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($today, $in60Days) {
                $q->where(function ($q2) use ($today) {
                    $q2->where('start_date', '<=', $today)->where('end_date', '>=', $today);
                })->orWhereBetween('start_date', [$today, $in60Days]);
            })
            ->orderBy('start_date')
            ->first();

        $upcomingAssessments = (clone $this->baseCount('s_competency_assessments', $sid, $scopeRoles))
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$today, $in60Days])
            ->count();

        $cyclePayload = null;
        if ($cycle) {
            $start = $cycle->start_date ? Carbon::parse($cycle->start_date) : null;
            $end = $cycle->end_date ? Carbon::parse($cycle->end_date) : null;
            $rangeLabel = $start && $end
                ? $start->format('M j') . ' - ' . $end->format('M j, Y')
                : null;

            $cyclePayload = [
                'id'          => (int) $cycle->id,
                'name'        => $cycle->name,
                'status'      => $cycle->status,
                'start_date'  => $cycle->start_date,
                'end_date'    => $cycle->end_date,
                'range_label' => $rangeLabel,
            ];
        }

        return [
            'cycle'          => $cyclePayload,
            'upcoming_count' => $upcomingAssessments,
        ];
    }

    /* ----------------------------------------------------------------- *
     * Filter options (drives the five dropdowns)
     * ----------------------------------------------------------------- */

    public function filterOptions(int $sid): array
    {
        $roleBase = DB::table('s_user_jobrole')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at');

        $departments = (clone $roleBase)
            ->whereNotNull('department_id')
            ->where('department_id', '!=', 0)
            ->select('department_id', 'department')
            ->distinct()
            ->orderBy('department')
            ->get()
            ->map(fn ($r) => ['value' => (string) $r->department_id, 'label' => $r->department ?: ('Department ' . $r->department_id)])
            ->unique('value')
            ->values()
            ->all();

        $simpleOptions = function ($column) use ($roleBase) {
            return (clone $roleBase)
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->select($column)
                ->distinct()
                ->orderBy($column)
                ->pluck($column)
                ->map(fn ($v) => ['value' => (string) $v, 'label' => (string) $v])
                ->all();
        };

        return [
            'departments'    => $departments,
            'jobroles'       => $simpleOptions('jobrole'),
            'locations'      => $simpleOptions('location'),
            'business_units' => $simpleOptions('industries'),
            'job_families'   => $simpleOptions('jobrole_category'),
        ];
    }
}
