<?php

namespace App\Domain\Signals;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads the organisation's own records and reduces them to AGGREGATES.
 *
 * PRIVACY: nothing personal leaves this class. No employee names, emails, ids or
 * contact details - only counts per department, and department names (which are
 * organisational structure, not personal data). The model is asked to reason over
 * these numbers and nothing else.
 *
 * TENANCY: every query is bounded by the `sub_institute_id` the caller passes in.
 * That value comes from a validated token (API) or from the scheduler's own list of
 * tenants - never from request input.
 */
class SignalDataCollector
{
    /**
     * @return array{organisation: array<string, int>, departments: array<int, array<string, mixed>>}|null
     *         null when there is nothing meaningful to analyse.
     */
    public function collect(int $tenantId): ?array
    {
        $limit = max(1, (int) config('signals.max_departments_in_prompt', 40));

        $departments = DB::table('hrms_departments')
            ->where('sub_institute_id', $tenantId)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'department', 'parent_id', 'head_user_id']);

        if ($departments->isEmpty()) {
            return null;
        }

        $employees = $this->countBy('tbluser', 'department_id', $tenantId, fn ($q) => $q->where('status', 1));
        $roles = Schema::hasTable('s_user_jobrole')
            ? $this->countBy('s_user_jobrole', 'department_id', $tenantId, fn ($q) => $q->where('status', 1)->whereNull('deleted_at'))
            : [];
        $children = $departments->whereNotNull('parent_id')->groupBy('parent_id')->map->count();

        $today = now()->toDateString();
        $content = [];
        foreach (['policies' => 'department_policies', 'sops' => 'department_sops', 'rules' => 'department_rules'] as $key => $table) {
            $content[$key] = [
                'total' => $this->contentCounts($table, $tenantId, null),
                'overdue' => $key === 'rules' ? [] : $this->contentCounts($table, $tenantId, $today),
            ];
        }

        $rows = [];
        foreach ($departments->take($limit) as $department) {
            $id = (int) $department->id;
            $rows[] = [
                'department_id' => $id,
                'name' => (string) $department->department,
                'has_department_head' => (int) ($department->head_user_id > 0),
                'employee_count' => (int) ($employees[$id] ?? 0),
                'job_role_count' => (int) ($roles[$id] ?? 0),
                'sub_department_count' => (int) ($children[$id] ?? 0),
                'policy_count' => (int) ($content['policies']['total'][$id] ?? 0),
                'sop_count' => (int) ($content['sops']['total'][$id] ?? 0),
                'rule_count' => (int) ($content['rules']['total'][$id] ?? 0),
                'policies_past_review_date' => (int) ($content['policies']['overdue'][$id] ?? 0),
                'sops_past_review_date' => (int) ($content['sops']['overdue'][$id] ?? 0),
            ];
        }

        $unassigned = (int) DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->where('status', 1)
            ->where(fn ($q) => $q->whereNull('department_id')->orWhere('department_id', 0))
            ->count();

        return [
            'organisation' => [
                'active_department_count' => $departments->count(),
                'departments_included' => count($rows),
                'active_employee_count' => (int) array_sum($employees),
                'employees_without_department' => $unassigned,
            ],
            'departments' => $rows,
        ];
    }

    /** @return array<int, int> department_id => count */
    private function countBy(string $table, string $column, int $tenantId, callable $scope): array
    {
        $query = DB::table($table)->where('sub_institute_id', $tenantId)->whereNotNull($column);
        $scope($query);

        return $query->selectRaw("{$column} as k, COUNT(*) as c")
            ->groupBy($column)
            ->pluck('c', 'k')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** @return array<int, int> */
    private function contentCounts(string $table, int $tenantId, ?string $reviewBefore): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return $this->countBy($table, 'department_id', $tenantId, function ($query) use ($reviewBefore) {
            $query->whereNull('deleted_at')->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'Archived'));
            if ($reviewBefore !== null) {
                $query->whereNotNull('review_date')->where('review_date', '<', $reviewBefore);
            }
        });
    }
}
