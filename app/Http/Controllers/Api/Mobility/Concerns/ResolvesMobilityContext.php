<?php

namespace App\Http\Controllers\Api\Mobility\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;

trait ResolvesMobilityContext
{
    use ResolvesApiIdentity;

    /**
     * @return array{sub_institute_id:int, user_id:int|null}|\Illuminate\Http\JsonResponse
     */
    protected function mobilityContext(Request $request)
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

    protected function mobilityPaging(Request $request, int $defaultPerPage = 10): array
    {
        $page = (int) ($request->input('page') ?: 1);
        $perPage = (int) ($request->input('per_page') ?: $defaultPerPage);

        return [
            'page'     => max(1, $page),
            'per_page' => min(200, max(5, $perPage)),
        ];
    }

    protected function mobilitySort(Request $request, array $allowed, string $default, string $defaultDir = 'desc'): array
    {
        $column = (string) $request->input('sort_by', $default);
        $direction = strtolower((string) $request->input('sort_dir', $defaultDir)) === 'asc' ? 'asc' : 'desc';

        return [
            in_array($column, $allowed, true) ? $column : $default,
            $direction,
        ];
    }

    protected function mobilityResponse($data, string $message = 'Success', int $code = 200, array $extra = [])
    {
        return response()->json(array_merge([
            'status'  => 1,
            'message' => $message,
            'data'    => $data,
        ], $extra), $code);
    }

    protected function mobilityError(string $message, int $code = 400, array $extra = [])
    {
        return response()->json(array_merge([
            'status'  => 0,
            'message' => $message,
        ], $extra), $code);
    }

    /**
     * Employee display bundle resolved from tbluser + hrms_departments + org_designation.
     */
    protected function mobilityDirectory(int $subInstituteId, array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));

        if (empty($userIds)) {
            return [];
        }

        $users = DB::table('tbluser')
            ->where('sub_institute_id', $subInstituteId)
            ->whereIn('id', $userIds)
            ->get([
                'id', 'first_name', 'last_name', 'user_name', 'employee_no', 'email', 'mobile',
                'department_id', 'image', 'city',
            ]);

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
                'email'         => $user->email,
                'mobile'        => $user->mobile,
                'initials'      => $this->mobInitialsOf($name),
                'department_id' => $user->department_id ? (int) $user->department_id : null,
                'department'    => $user->department_id ? ($departments[$user->department_id] ?? null) : null,
                'designation'   => $designations[$user->id] ?? null,
                'location'      => $user->city,
                'image'         => $user->image,
            ];
        }

        return $directory;
    }

    protected function mobInitialsOf(?string $name): string
    {
        if (!$name) {
            return '--';
        }

        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($first . $last) ?: '--';
    }
}
