<?php

namespace App\Http\Controllers\Api\Talent\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;

/**
 * Shared request context, filters, paging and response envelope for the Talent
 * Management API (onboarding, mobility & succession, offboarding, dashboard).
 *
 * Mirrors ResolvesPerformanceContext exactly: every endpoint under
 * /api/talent/* is token authenticated (Sanctum personal access token passed as
 * `token`) and tenant scoped by sub_institute_id, the same contract as
 * /api/performance/*, /api/competency/* and /api/leave/*.
 *
 * IMPORTANT - the `user_id` trap, inherited from the Performance module. On
 * every call `user_id` is the CONTEXT ACTOR (whoever pressed the button), never
 * the subject. A controller writing an owner column must take the subject from
 * an explicit field (employee_id, owner_id, ...) - writing the raw request
 * `user_id` into an owner column silently reassigns the record to the actor.
 */
trait ResolvesTalentContext
{
    use ResolvesApiIdentity;

    /**
     * @return array{sub_institute_id:int, user_id:int|null}|\Illuminate\Http\JsonResponse
     */
    protected function talentContext(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        return [
            'sub_institute_id' => $identity['sub_institute_id'],
            'user_id'          => $identity['user_id'],
        ];
    }

    /** Treat 'all', '0' and empty string as "no filter". */
    protected function activeTalentFilter($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = array_values(array_filter(
                $value,
                fn ($item) => $item !== null && $item !== '' && $item !== '0' && $item !== 'all'
            ));

            return empty($value) ? null : implode(',', $value);
        }

        $value = trim((string) $value);

        return ($value === '' || $value === '0' || strtolower($value) === 'all') ? null : $value;
    }

    /** page / per_page, clamped so a hostile per_page cannot dump the table. */
    protected function talentPaging(Request $request, int $defaultPerPage = 25): array
    {
        $page = (int) ($request->input('page') ?: 1);
        $perPage = (int) ($request->input('per_page') ?: $defaultPerPage);

        return [
            'page'     => max(1, $page),
            'per_page' => min(200, max(5, $perPage)),
        ];
    }

    /** Whitelisted sorting - never interpolate a client string into ORDER BY. */
    protected function talentSort(Request $request, array $allowed, string $default, string $defaultDir = 'desc'): array
    {
        $column = (string) $request->input('sort_by', $default);
        $direction = strtolower((string) $request->input('sort_dir', $defaultDir)) === 'asc' ? 'asc' : 'desc';

        return [
            in_array($column, $allowed, true) ? $column : $default,
            $direction,
        ];
    }

    /** The {status,message,data} envelope every other module in this API returns. */
    protected function talentResponse($data, string $message = 'Success', int $code = 200, array $extra = [])
    {
        return response()->json(array_merge([
            'status'  => 1,
            'message' => $message,
            'data'    => $data,
        ], $extra), $code);
    }

    protected function talentError(string $message, int $code = 400, array $extra = [])
    {
        return response()->json(array_merge([
            'status'  => 0,
            'message' => $message,
        ], $extra), $code);
    }

    /**
     * Employee display bundle, resolved from the single user master (tbluser)
     * plus the department master. Identical shape to the Performance module's
     * employeeDirectory so both modules render an employee cell the same way.
     *
     * @param  array<int, int> $userIds
     * @return array<int, array{id:int, name:string, employee_no:?string, initials:string, department_id:?int, department:?string, designation:?string, joined_date:?string, image:?string}>
     */
    protected function talentEmployeeDirectory(int $subInstituteId, array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));

        if (empty($userIds)) {
            return [];
        }

        $users = DB::table('tbluser')
            ->where('sub_institute_id', $subInstituteId)
            ->whereIn('id', $userIds)
            ->get(['id', 'first_name', 'last_name', 'user_name', 'employee_no', 'department_id', 'joined_date', 'image']);

        $departmentIds = $users->pluck('department_id')->filter()->unique()->values()->all();

        $departments = empty($departmentIds)
            ? collect()
            : DB::table('hrms_departments')->whereIn('id', $departmentIds)->pluck('department', 'id');

        $designations = DB::table('org_designation')
            ->where('sub_institute_id', $subInstituteId)
            ->whereIn('user_id', $userIds)
            ->pluck('designation', 'user_id');

        $directory = [];

        foreach ($users as $user) {
            $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
            $name = $name !== '' ? $name : ($user->user_name ?? 'Unknown');

            $directory[(int) $user->id] = [
                'id'            => (int) $user->id,
                'name'          => $name,
                'employee_no'   => $user->employee_no,
                'initials'      => $this->talentInitialsOf($name),
                'department_id' => $user->department_id ? (int) $user->department_id : null,
                'department'    => $user->department_id ? ($departments[$user->department_id] ?? null) : null,
                'designation'   => $designations[$user->id] ?? null,
                'joined_date'   => $user->joined_date,
                'image'         => $user->image,
            ];
        }

        return $directory;
    }

    protected function talentInitialsOf(?string $name): string
    {
        if (!$name) {
            return '--';
        }

        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($first . $last) ?: '--';
    }

    /**
     * Next sequential reference for a case / job code, per tenant and year.
     *
     * Derived from the highest existing suffix rather than a row count, so a
     * deleted row cannot cause a duplicate code.
     */
    protected function nextTalentCode(string $table, string $column, string $prefix, int $subInstituteId): string
    {
        $year = now()->format('Y');
        $stem = $prefix . '-' . $year . '-';

        $last = DB::table($table)
            ->where('sub_institute_id', $subInstituteId)
            ->where($column, 'like', $stem . '%')
            ->orderByDesc($column)
            ->value($column);

        $next = $last ? ((int) substr((string) $last, strlen($stem))) + 1 : 1;

        return $stem . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    /** "15 May 2025" - the date format the talent screens render. */
    protected function talentDateLabel($date): ?string
    {
        if (!$date) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($date)->format('d M Y');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Human label for a hyphenated enum: 'notice-period' -> 'Notice Period'. */
    protected function talentLabel(?string $value): ?string
    {
        return $value ? ucwords(str_replace(['-', '_'], ' ', $value)) : null;
    }
}
