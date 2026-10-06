<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 4b-prep (b) — THE NINE CANONICAL ROLES.
 *
 * Q-B1, Q-D1, Q-D3, A6. Creates the role model that 03-rbac-matrix.md §3.1–3.7 is
 * written against, so 4b can apply that matrix faithfully instead of collapsing
 * nine columns into three.
 *
 * ─── WHAT IT DOES ────────────────────────────────────────────────────────────
 * Per tenant:
 *   - stamps role_key + data_scope + is_system on the THREE existing profiles
 *   - creates the SIX missing roles, empty (no users assigned)
 *
 * ─── WHAT IT DOES NOT DO ─────────────────────────────────────────────────────
 *   - assign any user to a new role. Existing users keep their existing profile;
 *     only the profile's metadata is stamped.
 *   - touch tblgroupwise_rights_g2g. That is 4b, and it is a separate review gate.
 *
 * ─── THE MAPPING, for review ─────────────────────────────────────────────────
 *   Employee (238 users) -> employee            self
 *   HR       ( 72 users) -> hr_manager          organization
 *   Admin    ( 76 users) -> administrator       organization
 *
 *   created empty:
 *     reporting_manager   team          Q-B1
 *     department_head     department     Q-B1
 *     hr_executive        department     the HR Exec column of §3.1–3.7
 *     executive           organization   Q-D3 — dashboards + exception approval,
 *                                        NO audit-log access
 *     auditor             organization   Q-D3 — read + export INCLUDING audit logs
 *     recruiter           organization   Q-D1 — restricted to Recruitment
 *
 * `data_scope` lives on the role because SCOPE IS NEVER INDIVIDUALLY OVERRIDABLE
 * (A6). `role_key` is the stable machine name the resolver keys on — never `name`,
 * so renaming a role in a customer's UI cannot break access.
 *
 * Idempotent: re-running stamps the same values and creates nothing twice.
 */
class Phase3RoleSeeder extends Seeder
{
    /** name in the existing data => [role_key, data_scope] */
    private const EXISTING = [
        'Employee' => ['employee',      'self'],
        'HR'       => ['hr_manager',    'organization'],
        'Admin'    => ['administrator', 'organization'],
    ];

    /** role_key => [display name, data_scope] */
    private const MISSING = [
        'reporting_manager' => ['Reporting Manager', 'team'],
        'department_head'   => ['Department Head',   'department'],
        'hr_executive'      => ['HR Executive',      'department'],
        'executive'         => ['Executive',         'organization'],
        'auditor'           => ['Auditor',           'organization'],
        'recruiter'         => ['Recruiter',         'organization'],
    ];

    public function run(): void
    {
        $tenants = DB::table('tbluserprofilemaster')
            ->whereIn('name', array_keys(self::EXISTING))
            ->distinct()->pluck('sub_institute_id')->filter()->values();

        $stamped = 0;
        $created = 0;

        foreach ($tenants as $tenant) {
            foreach (self::EXISTING as $name => [$key, $scope]) {
                $stamped += DB::table('tbluserprofilemaster')
                    ->where('sub_institute_id', $tenant)->where('name', $name)
                    ->update(['role_key' => $key, 'data_scope' => $scope, 'is_system' => 1]);
            }

            foreach (self::MISSING as $key => [$name, $scope]) {
                $exists = DB::table('tbluserprofilemaster')
                    ->where('sub_institute_id', $tenant)->where('role_key', $key)->exists();
                if ($exists) {
                    continue;
                }
                DB::table('tbluserprofilemaster')->insert([
                    'name'             => $name,
                    'role_key'         => $key,
                    'data_scope'       => $scope,
                    'is_system'        => 1,
                    'sub_institute_id' => $tenant,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
                $created++;
            }
        }

        $this->command?->info("Phase3RoleSeeder: stamped {$stamped} existing profiles, created {$created} new roles across {$tenants->count()} tenants.");
    }
}
