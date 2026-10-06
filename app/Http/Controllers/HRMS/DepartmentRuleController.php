<?php

namespace App\Http\Controllers\HRMS;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Operational rules for a department.
 *
 * Replaces rules-tab.tsx's MOCK_RULES - four hardcoded records (Leave Approval
 * Threshold, Overtime Cap, Expense Auto-Approve, Remote Check-in Window) held
 * in useState and never persisted.
 *
 * The frontend also hardcoded RULE_CATEGORIES as a five-item array. Categories
 * are stored per row instead, and categories() below returns the ones a tenant
 * is actually using, so the dropdown reflects real data rather than a list
 * frozen in a bundle.
 */
class DepartmentRuleController extends DepartmentContentController
{
    protected function table(): string
    {
        return 'department_rules';
    }

    protected function label(): string
    {
        return 'Rule';
    }

    protected function writableColumns(): array
    {
        return [
            'title',
            'code',
            'description',
            'category',
            'rule_definition',
            'status',
            'effective_date',
        ];
    }

    protected function rules(bool $creating): array
    {
        return [
            'title'           => ($creating ? 'required' : 'sometimes|required') . '|string|max:191',
            'code'            => 'nullable|string|max:50',
            'description'     => 'nullable|string',
            'category'        => 'nullable|string|max:100',
            'rule_definition' => 'nullable|string',
            'status'          => 'nullable|string|in:Active,Draft,Archived',
            'effective_date'  => 'nullable|date',
        ];
    }

    /**
     * Distinct rule categories in use across the caller's organisation.
     *
     * Seeded with the five the UI used to hardcode, so an organisation with no
     * rules yet still gets a usable dropdown instead of an empty one.
     */
    public function categories(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $used = DB::table($this->table())
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category')
            ->all();

        $categories = array_values(array_unique(array_merge(
            ['Leave', 'Attendance', 'Approval', 'Finance', 'Security'],
            $used
        )));

        sort($categories);

        return response()->json(['status' => 1, 'data' => $categories]);
    }
}
