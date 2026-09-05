<?php

namespace App\Http\Controllers\Api\Leave\Concerns;

use Illuminate\Support\Facades\DB;
use App\Models\HRMS\HrmsLeaveRolePermission;
use App\Support\RoleKey;

/**
 * Makes hrms_leave_role_permissions load-bearing.
 *
 * The table has existed, been seeded, been edited through a working screen and
 * been read by absolutely nothing else. Tenant 3's live row for the Employee
 * role says `approve_leave = 0, scope = 'Self'`, and an employee could still
 * approve their own leave (F-87), delete a colleague's (F-88), create leave
 * types (F-90) and rewrite this very table to grant themselves everything
 * (F-89). All four were executed during the audit, not inferred.
 *
 * So: one place that answers "may this caller do X, and to whom".
 *
 * SCOPE means whose records the caller may act on:
 *
 *   Self          only their own
 *   Team          the people who report to them (tbluser.reporting_manager_id)
 *   Department    everyone in their department (tbluser.department_id)
 *   Organization  everyone in the tenant
 *
 * A NOTE ON `Team`, because the codebase disagrees with itself about it.
 * ResolvesLeaveContext says reporting_manager_id is "NULL for every user", and
 * the talent audit measured 0 of 299. Both were reading a DIFFERENT HOST. On
 * the application's own database it is populated for 8 of 2345 active users,
 * all of them in tenant 3. So Team is evaluable, and today it resolves to a
 * very small set. That is honest behaviour - a manager with no recorded reports
 * sees only themselves - and it becomes correct the moment reporting lines are
 * filled in. It is not a reason to silently widen the scope to Department.
 *
 * department_id, by contrast, is populated for 2331 of 2345, so Department
 * scope is meaningful today.
 */
trait ResolvesLeaveAuthority
{
    /**
     * role_key -> the role_name used in hrms_leave_role_permissions.
     *
     * The table is keyed on a display-ish name because that is what its screen
     * shows; authorization still keys on role_key, and this is the only place
     * the two meet.
     */
    private const ROLE_NAME_BY_KEY = [
        'employee'          => 'Employee',
        'reporting_manager' => 'Reporting Manager',
        'department_head'   => 'Department Head',
        'hr_executive'      => 'HR Executive',
        'hr_manager'        => 'HR Manager',
        'administrator'     => 'Administrator',
        'executive'         => 'Executive',
        'auditor'           => 'Auditor',
        'recruiter'         => 'Recruiter',
    ];

    /**
     * What a caller gets when the tenant has no row for their role at all.
     *
     * Deny everything, see only yourself. An unconfigured role is not a licence.
     */
    private const NO_ROW = [
        'scope'              => 'Self',
        'approve_leave'      => false,
        'view_reports'       => false,
        'configure_settings' => false,
        'bulk_operations'    => false,
        'escalation_rights'  => false,
        'user_management'    => false,
    ];

    /** Per-request memo: this is asked several times per controller action. */
    private array $leaveAuthorityCache = [];

    /**
     * The caller's leave authority.
     *
     * @return array{role_key:?string, role_name:?string, scope:string, approve_leave:bool,
     *               view_reports:bool, configure_settings:bool, bulk_operations:bool,
     *               escalation_rights:bool, user_management:bool}
     */
    protected function leaveAuthority(array $context): array
    {
        $userId = (int) ($context['user_id'] ?? 0);
        $tenant = (int) ($context['sub_institute_id'] ?? 0);
        $key    = $tenant . ':' . $userId;

        if (isset($this->leaveAuthorityCache[$key])) {
            return $this->leaveAuthorityCache[$key];
        }

        $roleKey  = RoleKey::forUserId($userId);
        $roleName = self::ROLE_NAME_BY_KEY[$roleKey] ?? null;

        $row = $roleName
            ? HrmsLeaveRolePermission::where('sub_institute_id', $tenant)
                ->where('role_name', $roleName)
                ->whereNull('deleted_at')
                ->first()
            : null;

        $authority = array_merge(self::NO_ROW, ['role_key' => $roleKey, 'role_name' => $roleName]);

        if ($row) {
            $authority['scope'] = (string) ($row->scope ?: 'Self');
            foreach (HrmsLeaveRolePermission::PERMISSION_KEYS as $permission) {
                $authority[$permission] = (bool) $row->{$permission};
            }
        }

        /*
         * The escape hatch, and it is deliberate.
         *
         * saveRoles() already refuses a matrix in which NOBODY can configure,
         * but it does not stop an administrator removing it from themselves and
         * handing it to a role with no users. That is an unrecoverable lockout
         * of a tenant from its own settings, fixable only by a DBA. An
         * administrator is the tenant's owner; they keep the key to the room.
         *
         * Note the narrowness: configure_settings only. It does NOT grant
         * approve_leave, so an administrator who has been removed from the
         * approval chain stays removed - which is a real thing organisations do
         * on purpose, and the matrix is allowed to say it.
         */
        if ($roleKey === 'administrator') {
            $authority['configure_settings'] = true;
        }

        return $this->leaveAuthorityCache[$key] = $authority;
    }

    /** May the caller do this? */
    protected function leaveCan(array $context, string $permission): bool
    {
        return (bool) ($this->leaveAuthority($context)[$permission] ?? false);
    }

    /**
     * Guard for an action, returning the refusal or null to continue.
     *
     * 403, not 404: the caller is legitimately inside this tenant, so refusing
     * them does not confirm the existence of anything they could not otherwise
     * see. Cross-tenant is a different question and is answered with 404
     * elsewhere.
     */
    protected function denyUnlessLeaveCan(array $context, string $permission, string $message)
    {
        if ($this->leaveCan($context, $permission)) {
            return null;
        }

        return response()->json([
            'status'     => 0,
            'message'    => $message,
            'role'       => $this->leaveAuthority($context)['role_name'],
            'permission' => $permission,
        ], 403);
    }

    /**
     * The user ids the caller's scope covers, or null for "the whole tenant".
     *
     * null rather than an id list for Organization on purpose: the list would be
     * thousands of ids in a WHERE IN on every request, and the tenant filter the
     * query already carries says the same thing.
     */
    protected function leaveScopeUserIds(array $context): ?array
    {
        $authority = $this->leaveAuthority($context);
        $userId    = (int) ($context['user_id'] ?? 0);
        $tenant    = (int) ($context['sub_institute_id'] ?? 0);

        switch ($authority['scope']) {
            case 'Organization':
                return null;

            case 'Department':
                $departmentId = DB::table('tbluser')->where('id', $userId)->value('department_id');

                if (!$departmentId) {
                    // No department recorded: they can still see themselves.
                    return [$userId];
                }

                return DB::table('tbluser')
                    ->where('sub_institute_id', $tenant)
                    ->where('department_id', $departmentId)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->push($userId)
                    ->unique()
                    ->values()
                    ->all();

            case 'Team':
                return DB::table('tbluser')
                    ->where('sub_institute_id', $tenant)
                    ->where('reporting_manager_id', $userId)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->push($userId)
                    ->unique()
                    ->values()
                    ->all();

            case 'Self':
            default:
                return [$userId];
        }
    }

    /** Is this employee inside the caller's scope? */
    protected function leaveSubjectInScope(array $context, int $subjectId): bool
    {
        $ids = $this->leaveScopeUserIds($context);

        return $ids === null || in_array($subjectId, $ids, true);
    }

    /**
     * Narrow a leave query to the caller's scope.
     *
     * @param  string  $column  the user-id column on the query, e.g. 'hel.user_id'
     */
    protected function applyLeaveScope($query, array $context, string $column = 'hel.user_id')
    {
        $ids = $this->leaveScopeUserIds($context);

        if ($ids !== null) {
            $query->whereIn($column, $ids ?: [0]);
        }

        return $query;
    }
}
