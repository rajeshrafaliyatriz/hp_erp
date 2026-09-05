<?php

namespace App\Http\Controllers\talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The hiring team: who in this institute recruits, screens and interviews.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * `talent_team_members` held 0 rows on both hosts and had no controller, no
 * route, no model and no migration - it existed only on the databases. Audit
 * F-59 flagged it as one of five tables with no tenant column, and the proposal
 * was to drop it. The decision was to keep it and make it real, so this is the
 * feature that makes it mean something.
 *
 * ── IT WAS NEVER QUITE DEAD, WHICH MATTERS HERE ─────────────────────────────
 *
 * The table is a registered entry in the department merge/delete engine
 * (Services/Org/DepartmentMergeService.php:89). `impact()` counts rows by
 * department, `merge()` repoints department_id, and `release()` NULLs it when a
 * department is deleted. So `department_id` on these rows is already load-
 * bearing for a screen elsewhere in the product: the delete/merge confirmation
 * dialog lists "N team members" as soon as any exist.
 *
 * That is why department_id is validated against the caller's own tenant rather
 * than accepted as given - a row pointing at another institute's department
 * would surface in that institute's delete dialog.
 *
 * ── TENANCY ─────────────────────────────────────────────────────────────────
 *
 * The token decides. The request is never trusted for identity, the tenant
 * predicate lives INSIDE the id lookup rather than as a fetch-then-compare, and
 * a row belonging to another institute answers 404, not 403 - a 403 would
 * confirm that the row exists.
 */
class HiringTeamController extends Controller
{
    use ResolvesApiIdentity;

    /**
     * The roles a hiring team member can hold.
     *
     * This was an ENUM on the column until the 2026_09_03_170000 migration. The
     * house rule is VARCHAR plus a const precisely so that adding a role is a
     * change here rather than an ALTER TABLE rebuild on a live database.
     */
    public const ROLES = ['HR Manager', 'Recruiter', 'Interviewer'];

    /** GET /api/talent/hiring-team */
    public function index(Request $request)
    {
        $tenant = $this->apiTenantId($request);

        if (!$tenant) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated'], 401);
        }

        $role = trim((string) $request->input('role'));
        $search = trim((string) $request->input('search'));
        $active = $request->input('active');

        $rows = DB::table('talent_team_members as m')
            ->leftJoin('tbluser as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('hrms_departments as d', 'd.id', '=', 'm.department_id')
            ->where('m.sub_institute_id', $tenant)
            ->whereNull('m.deleted_at')
            ->when($role !== '' && $role !== 'all', fn ($q) => $q->where('m.role', $role))
            ->when($active !== null && $active !== '' && $active !== 'all',
                fn ($q) => $q->where('m.active', filter_var($active, FILTER_VALIDATE_BOOLEAN) ? 1 : 0))
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('u.first_name', 'like', "%{$search}%")
                  ->orWhere('u.last_name', 'like', "%{$search}%")
                  ->orWhere('u.employee_id', 'like', "%{$search}%")
                  ->orWhere('d.department', 'like', "%{$search}%");
            }))
            ->orderBy('m.role')
            ->orderBy('u.first_name')
            ->get([
                'm.id', 'm.user_id', 'm.department_id', 'm.role', 'm.active', 'm.created_at',
                'u.first_name', 'u.last_name', 'u.employee_id', 'u.email',
                'd.department as department_name',
            ]);

        // Counts per role, for the roster header. Every role appears even at
        // zero, so the header does not change shape as people are added.
        $counts = array_fill_keys(self::ROLES, 0);
        foreach ($rows as $r) {
            if (isset($counts[$r->role])) {
                $counts[$r->role]++;
            }
        }

        return response()->json([
            'status' => 1,
            'data' => [
                'members' => $rows->map(fn ($r) => $this->present($r))->all(),
                'summary' => [
                    'total' => $rows->count(),
                    'active' => $rows->where('active', 1)->count(),
                    'by_role' => $counts,
                ],
                'roles' => self::ROLES,
                'assignable' => $this->assignable($tenant),
                'departments' => $this->departments($tenant),
            ],
        ]);
    }

    /**
     * Employees in this tenant who are not already on the team.
     *
     * Served with the roster rather than from a separate directory call so the
     * picker can only ever offer a valid choice - the alternative is a form that
     * lets you select somebody the endpoint will then refuse.
     *
     * Capped: a large institute has thousands of employees and this is a
     * dropdown, not a report. The cap is stated rather than silent.
     */
    private function assignable(int $tenant): array
    {
        $taken = DB::table('talent_team_members')
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->pluck('user_id')
            ->all();

        return DB::table('tbluser as u')
            ->leftJoin('hrms_departments as d', 'd.id', '=', 'u.department_id')
            ->where('u.sub_institute_id', $tenant)
            ->whereNull('u.deleted_at')
            ->when($taken, fn ($q) => $q->whereNotIn('u.id', $taken))
            ->orderBy('u.first_name')
            ->limit(500)
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.employee_id', 'u.department_id', 'd.department'])
            ->map(fn ($u) => [
                'id' => (int) $u->id,
                'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: 'Unknown employee',
                'employee_no' => $u->employee_id ?: null,
                'department_id' => $u->department_id ? (int) $u->department_id : null,
                'department' => $u->department ?: null,
            ])
            ->all();
    }

    private function departments(int $tenant): array
    {
        return DB::table('hrms_departments')
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->orderBy('department')
            ->get(['id', 'department'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->department])
            ->all();
    }

    /** POST /api/talent/hiring-team */
    public function store(Request $request)
    {
        $tenant = $this->apiTenantId($request);

        if (!$tenant) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated'], 401);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'department_id' => 'nullable|integer',
            'role' => ['required', 'string', 'in:' . implode(',', self::ROLES)],
            'active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();

        /*
         * The subject must belong to the caller's tenant.
         *
         * Sprint 1b found this exact hole in the mobility controllers: the
         * tenant guard lived only in the WHERE of the write, so a foreign
         * user_id passed validation and inserted a row attributed to the
         * caller's institute. Check the subject, not just the writer.
         */
        $subject = DB::table('tbluser')
            ->where('sub_institute_id', $tenant)
            ->where('id', $data['user_id'])
            ->whereNull('deleted_at')
            ->first(['id', 'department_id']);

        if (!$subject) {
            return response()->json(['status' => 0, 'message' => 'Selected employee not found'], 404);
        }

        $departmentId = $this->resolveDepartment($tenant, $data['department_id'] ?? null, $subject->department_id);

        if ($departmentId === false) {
            return response()->json(['status' => 0, 'message' => 'Selected department not found'], 404);
        }

        // One roster entry per person. Re-adding somebody already on the team is
        // a mistake worth naming rather than a silent second row.
        $existing = DB::table('talent_team_members')
            ->where('sub_institute_id', $tenant)
            ->where('user_id', $subject->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($existing) {
            return response()->json([
                'status' => 0,
                'message' => 'That person is already on the hiring team.',
            ], 422);
        }

        $id = DB::transaction(fn () => DB::table('talent_team_members')->insertGetId([
            'sub_institute_id' => $tenant,
            'user_id' => $subject->id,
            'department_id' => $departmentId,
            'role' => $data['role'],
            'active' => array_key_exists('active', $data) ? (int) $data['active'] : 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json([
            'status' => 1,
            'message' => 'Added to the hiring team',
            'data' => $this->find($tenant, $id),
        ], 201);
    }

    /** PUT /api/talent/hiring-team/{id} */
    public function update(Request $request, $id)
    {
        $tenant = $this->apiTenantId($request);

        if (!$tenant) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated'], 401);
        }

        // Tenant predicate inside the lookup, so another institute's row is
        // indistinguishable from one that does not exist.
        $member = DB::table('talent_team_members')
            ->where('sub_institute_id', $tenant)
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$member) {
            return response()->json(['status' => 0, 'message' => 'Team member not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'department_id' => 'nullable|integer',
            'role' => ['sometimes', 'required', 'string', 'in:' . implode(',', self::ROLES)],
            'active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();
        $changes = ['updated_at' => now()];

        if (array_key_exists('role', $data)) {
            $changes['role'] = $data['role'];
        }

        if (array_key_exists('active', $data)) {
            $changes['active'] = (int) $data['active'];
        }

        if (array_key_exists('department_id', $data)) {
            $departmentId = $this->resolveDepartment($tenant, $data['department_id'], null);

            if ($departmentId === false) {
                return response()->json(['status' => 0, 'message' => 'Selected department not found'], 404);
            }

            $changes['department_id'] = $departmentId;
        }

        DB::transaction(fn () => DB::table('talent_team_members')->where('id', $member->id)->update($changes));

        return response()->json([
            'status' => 1,
            'message' => 'Hiring team updated',
            'data' => $this->find($tenant, (int) $member->id),
        ]);
    }

    /** DELETE /api/talent/hiring-team/{id} */
    public function destroy(Request $request, $id)
    {
        $tenant = $this->apiTenantId($request);

        if (!$tenant) {
            return response()->json(['status' => 0, 'message' => 'Unauthenticated'], 401);
        }

        $member = DB::table('talent_team_members')
            ->where('sub_institute_id', $tenant)
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first(['id']);

        if (!$member) {
            return response()->json(['status' => 0, 'message' => 'Team member not found'], 404);
        }

        /*
         * Soft delete. The `deleted_at` column arrived with this feature's
         * migration for exactly this reason - the audit's complaint about
         * s_mobility_talent_pool_members was that removeMember() hard deletes
         * with no audit trail. Removing somebody from a hiring team is a record
         * worth keeping.
         */
        DB::table('talent_team_members')->where('id', $member->id)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 1, 'message' => 'Removed from the hiring team']);
    }

    /* ------------------------------------------------------------------ */

    /**
     * A department id that belongs to this tenant, or the fallback, or false.
     *
     * False, not null, because "not found" and "deliberately none" are different
     * answers and the caller has to be able to tell them apart.
     *
     * @return int|null|false
     */
    private function resolveDepartment(int $tenant, $requested, $fallback)
    {
        $candidate = $requested !== null && $requested !== '' ? (int) $requested : null;

        if ($candidate === null) {
            // The person's own department, but only if it is really this
            // tenant's - tbluser.department_id has dangling values in this data.
            if (!$fallback) {
                return null;
            }

            $ownDepartmentIsOurs = DB::table('hrms_departments')
                ->where('sub_institute_id', $tenant)
                ->where('id', $fallback)
                ->whereNull('deleted_at')
                ->exists();

            return $ownDepartmentIsOurs ? (int) $fallback : null;
        }

        $ok = DB::table('hrms_departments')
            ->where('sub_institute_id', $tenant)
            ->where('id', $candidate)
            ->whereNull('deleted_at')
            ->exists();

        return $ok ? $candidate : false;
    }

    private function find(int $tenant, int $id): ?array
    {
        $row = DB::table('talent_team_members as m')
            ->leftJoin('tbluser as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('hrms_departments as d', 'd.id', '=', 'm.department_id')
            ->where('m.sub_institute_id', $tenant)
            ->where('m.id', $id)
            ->first([
                'm.id', 'm.user_id', 'm.department_id', 'm.role', 'm.active', 'm.created_at',
                'u.first_name', 'u.last_name', 'u.employee_id', 'u.email',
                'd.department as department_name',
            ]);

        return $row ? $this->present($row) : null;
    }

    private function present($row): array
    {
        $name = trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''));

        return [
            'id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'name' => $name !== '' ? $name : 'Unknown employee',
            'initials' => $this->initials($name),
            'employee_no' => $row->employee_id ?: null,
            'email' => $row->email ?: null,
            'department_id' => $row->department_id ? (int) $row->department_id : null,
            'department' => $row->department_name ?: null,
            'role' => $row->role,
            'active' => (bool) $row->active,
            'added_on' => $row->created_at ? substr((string) $row->created_at, 0, 10) : null,
        ];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts));

        if (!$parts) {
            return 'EM';
        }

        $first = mb_substr($parts[0], 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($first . $last);
    }
}
