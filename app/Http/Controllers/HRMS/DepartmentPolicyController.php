<?php

namespace App\Http\Controllers\HRMS;

/**
 * Policies for a department.
 *
 * Replaces policies-tab.tsx's MOCK_POLICIES - four hardcoded records (Code of
 * Conduct, Data Privacy & Retention, Remote Work & Connectivity, Leave &
 * Attendance) that every department in every tenant displayed identically, and
 * which reset the moment the tab lost focus.
 *
 * All behaviour is in DepartmentContentController; this declares only what is
 * specific to policies.
 */
class DepartmentPolicyController extends DepartmentContentController
{
    protected function table(): string
    {
        return 'department_policies';
    }

    protected function label(): string
    {
        return 'Policy';
    }

    protected function writableColumns(): array
    {
        return [
            'title',
            'code',
            'description',
            'category',
            'version',
            'status',
            'effective_date',
            'review_date',
        ];
    }

    protected function rules(bool $creating): array
    {
        return [
            'title'          => ($creating ? 'required' : 'sometimes|required') . '|string|max:191',
            'code'           => 'nullable|string|max:50',
            'description'    => 'nullable|string',
            'category'       => 'nullable|string|max:100',
            'version'        => 'nullable|string|max:20',
            'status'         => 'nullable|string|in:Active,Draft,Archived',
            'effective_date' => 'nullable|date',
            'review_date'    => 'nullable|date',
        ];
    }
}
