<?php

namespace App\Services\Organization;

use App\Support\RoleKey;
use Illuminate\Support\Facades\DB;

/**
 * THE NINE ROLES THE PERMISSION MODEL IS WRITTEN AGAINST, for one organisation.
 *
 * ── WHY THIS IS A SERVICE AND NOT A CONTROLLER METHOD ───────────────────────
 *
 * Two callers need it and they arrive from opposite directions:
 *
 *   OrganizationSetupController::createRoles()  an existing organisation
 *                                               repairing itself from the setup
 *                                               checklist
 *   TenantProvisioner::provision()              a new organisation, inside the
 *                                               transaction that creates it
 *
 * Signup creates three of the nine. The other six - reporting_manager,
 * department_head, hr_executive, executive, auditor, recruiter - were only ever
 * created by a seeder that has never been run on live, which is why 10 of 12
 * live organisations are missing six roles that `profile:` route guards,
 * `hrms_leave_role_permissions` and the navigation rules all assume exist.
 *
 * A second copy of this list is how the two would drift, and a role model that
 * disagrees with itself between "created at signup" and "repaired later" is
 * worse than one that is merely incomplete.
 */
class StandardRoles
{
    /**
     * role_key => [display name, data_scope]
     *
     * Lifted from Phase3RoleSeeder. The keys are `RoleKey::ALL`; the names are
     * the tenant's to edit and the keys are not.
     */
    public const ROLES = [
        'employee'          => ['Employee',          'self'],
        'reporting_manager' => ['Reporting Manager', 'team'],
        'department_head'   => ['Department Head',   'department'],
        'hr_executive'      => ['HR Executive',      'department'],
        'hr_manager'        => ['HR',                'organization'],
        'administrator'     => ['Admin',             'organization'],
        'executive'         => ['Executive',         'organization'],
        'auditor'           => ['Auditor',           'organization'],
        'recruiter'         => ['Recruiter',         'organization'],
    ];

    /** The display names the three profiles created at signup ship with. */
    public const LEGACY_NAMES = [
        'Employee' => 'employee',
        'HR'       => 'hr_manager',
        'Admin'    => 'administrator',
    ];

    /**
     * Make sure this organisation has all nine, and that the three it may
     * already have are labelled.
     *
     * NOT transactional here - both callers already are, and a nested
     * transaction would silently become a savepoint whose rollback does not mean
     * what the caller thinks it means.
     *
     * @return array{created:int, stamped:int}
     */
    public function ensureFor(int $tenant): array
    {
        $stamped = 0;
        $created = 0;

        /*
         * Stamp the originals FIRST, so the existence check below sees them and
         * does not create a duplicate under the canonical name.
         *
         * The condition is "role_key OR data_scope is missing", not "role_key is
         * missing". Signup sets role_key on the three profiles it creates but
         * leaves data_scope NULL - it is not in the model's $fillable - so keying
         * only on role_key skipped them, and a fresh tenant showed three roles
         * reading "scope=-" beneath six that had one.
         */
        foreach (self::LEGACY_NAMES as $name => $key) {
            $stamped += DB::table('tbluserprofilemaster')
                ->where('sub_institute_id', $tenant)
                ->where('name', $name)
                ->where(function ($q) {
                    $q->whereNull('role_key')->orWhereNull('data_scope');
                })
                ->update([
                    'role_key' => $key,
                    'data_scope' => self::ROLES[$key][1],
                    'is_system' => 1,
                    'updated_at' => now(),
                ]);
        }

        foreach (self::ROLES as $key => [$name, $scope]) {
            $exists = DB::table('tbluserprofilemaster')
                ->where('sub_institute_id', $tenant)
                ->where('role_key', $key)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('tbluserprofilemaster')->insert([
                'name' => $name,
                'role_key' => $key,
                'data_scope' => $scope,
                'is_system' => 1,
                'status' => 1,
                'sub_institute_id' => $tenant,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created++;
        }

        return ['created' => $created, 'stamped' => $stamped];
    }

    /** How many of the nine this organisation actually has. */
    public function countFor(int $tenant): int
    {
        return DB::table('tbluserprofilemaster')
            ->where('sub_institute_id', $tenant)
            ->whereNotNull('role_key')
            ->where('role_key', '!=', '')
            ->whereIn('role_key', RoleKey::ALL)
            ->distinct()
            ->count('role_key');
    }
}
