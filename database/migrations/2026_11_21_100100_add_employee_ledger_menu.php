<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Employee Ledger" under HRIT Management > Payroll Management.
 *
 * One account per employee: salary earned and paid, advances, and expense
 * claims, with a running balance and who owes whom.
 *
 * ── WHO SEES WHAT ───────────────────────────────────────────────────────────
 *
 * Every role gets the menu, because every employee can open THEIR OWN ledger.
 * The API decides the rest: administrators and HR see everybody's and can
 * record a salary payment; anybody else is shown only their own row and
 * statement and is refused anybody else's (salary is not for managers,
 * executives or auditors to browse). So the rights below only decide whether
 * the screen offers add/delete; they are not what protects the data.
 *
 *                       view   add   edit   delete
 *   administrator        v      a     e      d
 *   hr_manager           v      a     e      d
 *   hr_executive         v      a     e      d
 *   everyone else        v      .     .      .
 *
 * Rows are found by `access_link`; ids are auto-increment and differ per
 * database (the frontend matches by access_link alone). Rights are granted by
 * `role_key`, falling back to the profile name, for every existing profile.
 * Idempotent on `access_link` and on (menu, profile).
 *
 * Needs "Payroll Management" (access_link /module/hrit-solutions/payroll-management);
 * stops quietly if it is missing. `page_type` is enum('blade','page','link').
 *
 * RUN ON EACH DATABASE, AFTER 2026_11_21_100000_create_hrms_salary_payments_table.php:
 *   php artisan migrate --path=database/migrations/2026_11_21_100100_add_employee_ledger_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_21_100100_add_employee_ledger_menu.php
 */
return new class extends Migration
{
    private const PARENT_LINK = '/module/hrit-solutions/payroll-management';
    private const LINK = '/module/hrit-solutions/payroll-management/employee-ledger';

    public function up(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g') || !$this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        $parent = DB::table('tblmenumaster_g2g')->where('access_link', self::PARENT_LINK)->first();

        if (!$parent) {
            return;   // Payroll Management is missing here: do not guess a parent.
        }

        DB::transaction(function () use ($parent) {
            $existing = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->first();

            $menuId = $existing
                ? (int) $existing->id
                : (int) DB::table('tblmenumaster_g2g')->insertGetId([
                    'menu_name' => 'Employee Ledger',
                    'parent_id' => $parent->id,
                    'level' => (int) $parent->level + 1,
                    'page_type' => 'page',
                    'access_link' => self::LINK,
                    'icon' => 'mdi mdi-book-account-outline',
                    'status' => 1,
                    // One past the last sibling: nothing else is shifted.
                    'sort_order' => 1 + (int) DB::table('tblmenumaster_g2g')->where('parent_id', $parent->id)->max('sort_order'),
                    'sub_institute_id' => null,
                    'menu_type' => '',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->grantRights($menuId);
        });
    }

    public function down(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $row = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->first();

        if (!$row) {
            return;
        }

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->where('menu_id', $row->id)->delete();
        }

        DB::table('tblmenumaster_g2g')->where('id', $row->id)->delete();
    }

    private function grantRights(int $menuId): void
    {
        $already = DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->pluck('profile_id')->flip();
        $rows = [];

        foreach (DB::table('tbluserprofilemaster')->get() as $profile) {
            if ($already->has($profile->id)) {
                continue;
            }

            $hr = in_array(RoleKey::fromProfile($profile), ['administrator', 'hr_manager', 'hr_executive'], true);

            $rows[] = [
                'menu_id' => $menuId,
                'profile_id' => $profile->id,
                'can_view' => 1,
                'can_add' => $hr ? 1 : 0,
                'can_edit' => $hr ? 1 : 0,
                'can_delete' => $hr ? 1 : 0,
                'dashboard_right' => 0,
                'is_mobile' => 0,
                'sub_institute_id' => $profile->sub_institute_id,
                'created_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('tblgroupwise_rights_g2g')->insert($chunk);
        }
    }

    /** information_schema directly - live is MariaDB 10.1, where Schema::hasTable() throws. */
    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
