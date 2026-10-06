<?php

namespace App\Http\Controllers\Api\Offboarding\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Support\SubjectAuthority;

/**
 * Shared request context, filters, paging and response envelope for the
 * Offboarding Center API.
 *
 * ── THIS TRAIT WAS A COPY THAT LEFT THE GUARD BEHIND ────────────────────────
 *
 * Written as a copy of ResolvesCompetencyContext. It took the context resolver,
 * the filter normaliser, the paging helpers, the sort whitelist and the response
 * envelopes - and not competencySubject(). So the whole offboarding route group
 * enforced the tenant boundary and no ownership boundary at all, and the group
 * itself carried no role gate either: any authenticated employee could open an
 * involuntary-exit case against a colleague, write their exit-interview record,
 * upload a document to their exit file, or delete a live case.
 *
 * The sibling Onboarding block was explicitly wrapped in a role gate for exactly
 * this reason. Offboarding was missed in that pass.
 */
trait ResolvesOffboardingContext
{
    use ResolvesApiIdentity;

    /**
     * Resolve the SUBJECT of an offboarding request - the employee whose exit
     * is being recorded - and refuse when the caller may not act on them.
     *
     * Same contract as competencySubject() and performanceSubject(): int on
     * success, JsonResponse on refusal, 404 before 403.
     *
     * The tier is HR_ELEVATED, NOT PEOPLE_MANAGERS, and the difference is
     * deliberate: an exit case carries a reason, a last working day and an exit
     * interview. Recording that is HR's act, not a line manager's, and the
     * route group is gated at the same tier so the gate and the guard agree. A
     * guard narrower than its gate is the shape where a caller passes the door
     * and is then refused by the room.
     *
     * @return int|\Illuminate\Http\JsonResponse
     */
    protected function offboardingSubject(array $context, $requestedId, ?array $tier = null)
    {
        $subjectId = (int) $requestedId;

        $verdict = SubjectAuthority::verdict(
            (int) ($context['user_id'] ?? 0),
            $subjectId,
            $context['sub_institute_id'],
            $tier ?? SubjectAuthority::RECORD_OWNERS
        );

        if ($verdict === SubjectAuthority::OK) {
            return $subjectId;
        }

        return $verdict === SubjectAuthority::NOT_FOUND
            ? $this->offboardingError('Employee not found', 404)
            : $this->offboardingError('You may only act on your own exit record.', 403);
    }

    /** Is the caller allowed to act on people other than themselves? */
    protected function offboardingElevated(array $context, ?array $tier = null): bool
    {
        return SubjectAuthority::userSatisfies(
            (int) ($context['user_id'] ?? 0),
            $tier ?? SubjectAuthority::HR_ELEVATED
        );
    }

    protected function offboardingContext(Request $request)
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

    protected function activeOffbFilter($value): ?string
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

    protected function offboardingPaging(Request $request, int $defaultPerPage = 25): array
    {
        $page = (int) ($request->input('page') ?: 1);
        $perPage = (int) ($request->input('per_page') ?: $defaultPerPage);

        return [
            'page'     => max(1, $page),
            'per_page' => min(200, max(5, $perPage)),
        ];
    }

    protected function offboardingSort(Request $request, array $allowed, string $default, string $defaultDir = 'desc'): array
    {
        $column = (string) $request->input('sort_by', $default);
        $direction = strtolower((string) $request->input('sort_dir', $defaultDir)) === 'asc' ? 'asc' : 'desc';

        return [
            in_array($column, $allowed, true) ? $column : $default,
            $direction,
        ];
    }

    protected function offboardingResponse($data, string $message = 'Success', int $code = 200, array $extra = [])
    {
        return response()->json(array_merge([
            'status'  => 1,
            'message' => $message,
            'data'    => $data,
        ], $extra), $code);
    }

    protected function offboardingError(string $message, int $code = 400, array $extra = [])
    {
        return response()->json(array_merge([
            'status'  => 0,
            'message' => $message,
        ], $extra), $code);
    }

    protected function offboardingDirectory(int $subInstituteId, array $userIds): array
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
                'department_id', 'joined_date', 'image', 'city'
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
                'initials'      => $this->offbInitialsOf($name),
                'department_id' => $user->department_id ? (int) $user->department_id : null,
                'department'    => $user->department_id ? ($departments[$user->department_id] ?? null) : null,
                'designation'   => $designations[$user->id] ?? null,
                'joined_date'   => $user->joined_date,
                'location'      => $user->city,
                'image'         => $user->image,
            ];
        }

        return $directory;
    }

    protected function offbInitialsOf(?string $name): string
    {
        if (!$name) return '??';
        $parts = array_filter(explode(' ', preg_replace('/\s+/', ' ', trim($name))));
        if (empty($parts)) return '??';
        if (count($parts) === 1) return strtoupper(substr($parts[0], 0, 2));
        return strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
    }
}
