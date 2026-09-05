<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HRIT Sprint 1. Gives the leave permission matrix the two roles it never had.
 *
 * hrms_leave_role_permissions seeds seven roles; the platform defines nine.
 * `auditor` and `recruiter` had no row, and until Sprint 1 that cost nothing
 * because the table governed nothing. Now that ResolvesLeaveAuthority reads it,
 * "no row" means "deny everything, scope Self" - so without this backfill an
 * auditor would lose the read access the role exists for, and a recruiter would
 * be unable to see their own leave.
 *
 * HrmsLeaveRolePermission::defaults() covers new tenants. This covers the
 * tenants that already seeded the original seven, because resolveRoles() only
 * seeds when the table is empty for that tenant and will never top it up.
 *
 * Idempotent: skips any tenant/role pair that already exists, including
 * soft-deleted ones (a tenant that deliberately removed Auditor keeps it
 * removed rather than having it silently return).
 */
return new class extends Migration
{
    private const ADDITIONS = [
        [
            'role_name'          => 'Auditor',
            'scope'              => 'Organization',
            'approve_leave'      => 0,
            'view_reports'       => 1,
            'configure_settings' => 0,
            'bulk_operations'    => 0,
            'escalation_rights'  => 0,
            'user_management'    => 0,
            'sort_order'         => 8,
        ],
        [
            'role_name'          => 'Recruiter',
            'scope'              => 'Self',
            'approve_leave'      => 0,
            'view_reports'       => 1,
            'configure_settings' => 0,
            'bulk_operations'    => 0,
            'escalation_rights'  => 0,
            'user_management'    => 0,
            'sort_order'         => 9,
        ],
    ];

    public function up(): void
    {
        // Only tenants that have already been seeded. A tenant with no rows is
        // left alone so resolveRoles() seeds the full nine from defaults().
        $tenants = DB::table('hrms_leave_role_permissions')
            ->select('sub_institute_id')
            ->distinct()
            ->pluck('sub_institute_id');

        foreach ($tenants as $tenant) {
            foreach (self::ADDITIONS as $role) {
                $exists = DB::table('hrms_leave_role_permissions')
                    ->where('sub_institute_id', $tenant)
                    ->where('role_name', $role['role_name'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('hrms_leave_role_permissions')->insert(array_merge($role, [
                    'sub_institute_id' => $tenant,
                    'status'           => 1,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]));
            }
        }
    }

    public function down(): void
    {
        // Only the rows this migration could have created: untouched since
        // insertion, so a tenant that has since edited Auditor or Recruiter
        // keeps their edit rather than losing it to a rollback.
        DB::table('hrms_leave_role_permissions')
            ->whereIn('role_name', ['Auditor', 'Recruiter'])
            ->whereColumn('created_at', 'updated_at')
            ->delete();
    }
};
