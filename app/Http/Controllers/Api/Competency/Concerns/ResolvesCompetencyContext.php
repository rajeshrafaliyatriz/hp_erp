<?php

namespace App\Http\Controllers\Api\Competency\Concerns;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shared request context resolution for the Competency Management API.
 *
 * Every endpoint under /api/competency/* that this trait guards is token
 * authenticated (Sanctum personal access token) and tenant scoped by
 * sub_institute_id. Competency data is not fiscal-year scoped, so there is no
 * leave-year handling here.
 *
 * Identity comes from ResolvesApiIdentity, i.e. from the token's owner. It used
 * to come from the request body, which let any caller read another
 * organisation's competency data and forge the actor on every audit-log row.
 */
trait ResolvesCompetencyContext
{
    use ResolvesApiIdentity;

    /**
     * @return array{sub_institute_id:int, user_id:int|null}|\Illuminate\Http\JsonResponse
     */
    protected function competencyContext(Request $request)
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

    /**
     * Roles that may act on somebody else's competency profile.
     *
     * Keyed on role_key, the stable machine name added in D-010 - not on a
     * substring of the display name, which renames.
     *
     * department_head and reporting_manager are DELIBERATELY ABSENT. Their
     * legitimate scope is "my department" and "my team", and neither can be
     * evaluated while tbluser.reporting_manager_id is NULL for every user
     * (G-ORG-02). Granting them org-wide access in the meantime would be a
     * wider grant than the one being closed. They return here as team scope,
     * the day reporting-line coverage exists.
     */
    private const COMPETENCY_ELEVATED = [
        'administrator', 'hr_manager', 'hr_executive', 'executive', 'auditor',
    ];

    /**
     * Resolve the SUBJECT of a competency request - the employee whose profile
     * is being read or written - and refuse when the caller may not act on them.
     *
     * G-COMP-SEC-01: every method on EmployeeCompetencyProfileController took
     * $id straight from the route and never compared it to the caller. The
     * tenant boundary held; the ownership boundary did not exist. Any employee
     * could read a colleague's full profile, and addSkill/updateSkill let them
     * WRITE it - so anyone could raise their own ratings or lower someone
     * else's. Gap analysis, readiness and succession all resolve against that
     * table, and a tampered rating does not announce itself.
     *
     * Two checks, both required:
     *   1. the subject must belong to the CALLER'S OWN tenant, so an elevated
     *      role cannot reach across organisations;
     *   2. the caller must be the subject, or hold an elevated role.
     *
     * @return int|\Illuminate\Http\JsonResponse
     */
    protected function competencySubject(array $context, $requestedId)
    {
        $subjectId = (int) $requestedId;
        $callerId  = (int) ($context['user_id'] ?? 0);

        if ($subjectId <= 0 || $callerId <= 0) {
            return response()->json(['status' => 0, 'message' => 'Employee not found.'], 404);
        }

        // The subject must exist inside the caller's own organisation. Checked
        // before the ownership rule so a cross-tenant id cannot be probed for
        // existence by an elevated caller.
        $inTenant = DB::table('tbluser')
            ->where('id', $subjectId)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->exists();

        if (!$inTenant) {
            return response()->json(['status' => 0, 'message' => 'Employee not found.'], 404);
        }

        if ($subjectId === $callerId) {
            return $subjectId;
        }

        $roleKey = DB::table('tbluser as u')
            ->join('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')
            ->where('u.id', $callerId)
            ->value('p.role_key');

        if (in_array((string) $roleKey, self::COMPETENCY_ELEVATED, true)) {
            return $subjectId;
        }

        return response()->json([
            'status'  => 0,
            'message' => 'You may only access your own competency profile.',
        ], 403);
    }

    /**
     * The five command-center filter dimensions, normalised. Empty / 'all' / '0'
     * collapse to null so callers can skip them. Department and Job Role map to
     * columns that exist on the domain tables; Business Unit (industries),
     * Location and Job Family (jobrole_category) live on s_user_jobrole and are
     * resolved to a set of matching jobroles by the service.
     *
     * @return array{department_id:?string, jobrole:?string, location:?string, business_unit:?string, job_family:?string}
     */
    protected function competencyFilters(Request $request): array
    {
        return [
            'department_id' => $this->activeFilter($request->input('department_id')),
            'jobrole'       => $this->activeFilter($request->input('jobrole')),
            'location'      => $this->activeFilter($request->input('location')),
            'business_unit' => $this->activeFilter($request->input('business_unit')),
            'job_family'    => $this->activeFilter($request->input('job_family')),
        ];
    }

    /** Treat 'all', '0' and empty string as "no filter". */
    protected function activeFilter($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = array_values(array_filter($value, fn ($item) => $item !== null && $item !== '' && $item !== '0' && $item !== 'all'));
            return empty($value) ? null : implode(',', $value);
        }

        $value = trim((string) $value);

        return ($value === '' || $value === '0' || strtolower($value) === 'all') ? null : $value;
    }

    /**
     * Append a row to the competency activity feed. Resolves the actor's display
     * name from tbluser so the Recent Activity feed reads naturally.
     *
     * $subjectName and $changes are optional trailing parameters added for the
     * Audit & Activity Center: the first fills its "Record Name" column, the
     * second its "Change Summary" card / "Version History" tab. Both default to
     * null so every pre-existing call site keeps working unchanged.
     *
     * @param array<int, array{field:string, label:string, old:mixed, new:mixed}>|null $changes
     */
    protected function logCompetencyActivity(
        int $subInstituteId,
        ?int $userId,
        string $action,
        string $description,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $subjectName = null,
        ?array $changes = null
    ): void {
        $actorName = null;

        if ($userId) {
            $user = DB::table('tbluser')->where('id', $userId)->first();
            if ($user) {
                $actorName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                $actorName = $actorName !== '' ? $actorName : ($user->user_name ?? null);
            }
        }

        DB::table('s_competency_activity_log')->insert([
            'sub_institute_id' => $subInstituteId,
            'user_id'          => $userId,
            'actor_name'       => $actorName,
            'action'           => $action,
            'description'      => $description,
            'subject_type'     => $subjectType,
            'subject_id'       => $subjectId,
            'subject_name'     => $subjectName !== null ? mb_substr($subjectName, 0, 191) : null,
            'changes'          => ($changes !== null && $changes !== []) ? json_encode(array_values($changes)) : null,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    /**
     * Build the field-level diff the audit centre renders as "Change Summary".
     *
     * $before is the row as it stood (object or array), $after the validated
     * update payload, $labels the human column names to show. Only columns that
     * are present in $after AND actually differ are returned, so an edit that
     * touched one field does not report the whole record as changed.
     *
     * @param  object|array<string, mixed>   $before
     * @param  array<string, mixed>          $after
     * @param  array<string, string>         $labels  column => display label
     * @return array<int, array{field:string, label:string, old:mixed, new:mixed}>
     */
    protected function diffChanges($before, array $after, array $labels): array
    {
        $before = is_object($before) ? (array) $before : $before;
        $changes = [];

        foreach ($after as $column => $newValue) {
            if (!array_key_exists($column, $labels)) {
                continue;
            }

            $oldValue = $before[$column] ?? null;

            // Loose-but-safe comparison: everything reaches the API as a string,
            // so 3 and '3' must not read as a change.
            if ((string) ($oldValue ?? '') === (string) ($newValue ?? '')) {
                continue;
            }

            $changes[] = [
                'field' => $column,
                'label' => $labels[$column],
                'old'   => $oldValue,
                'new'   => $newValue,
            ];
        }

        return $changes;
    }
}
