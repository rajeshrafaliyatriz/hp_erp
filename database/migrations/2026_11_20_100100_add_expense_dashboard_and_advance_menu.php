<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Four more screens under HRIT Management > Expense Management:
 *
 *   Expense Dashboard       everyone: the picture, shortcuts, monthly chart
 *   Employee Advances       everyone: ask for an advance, follow it; managers/HR decide
 *   Advance Summary         HR / admin / executive / auditor: who holds how much
 *   Unpaid Expense Claims   HR / admin / executive / auditor: approved, not yet paid
 *
 * Added by a separate migration from 2026_11_19_100100_add_expense_management_menu
 * because that one has already run on databases. It needs that one to have
 * created the "Expense Management" group, and stops quietly if it is missing.
 *
 * ── SAME RULES AS THE FIRST EXPENSE MENU MIGRATION ──────────────────────────
 *
 * Rows are found by `access_link`, ids are auto-increment and differ per
 * database (the frontend matches by access_link alone). Rights are granted by
 * `role_key`, falling back to the profile name, for every existing profile;
 * a profile with no recognisable role counts as an employee. These are what the
 * screen offers - the API enforces independently. Idempotent on `access_link`
 * and on (menu, profile).
 *
 *                       Dashboard   Advances    Summary   Unpaid claims
 *   administrator       view        v a e d     view      view
 *   hr_manager          view        v a e d     view      view
 *   hr_executive        view        v a e d     view      view
 *   reporting_manager   view        v a e d     -         -
 *   department_head     view        v a e d     -         -
 *   executive           view        view        view      view
 *   auditor             view        view        view      view
 *   everyone else       view        v a e d     -         -
 *
 * The Dashboard goes FIRST in the group (sort_order 0).
 *
 * `page_type` is enum('blade','page','link') - pages are 'page'.
 *
 * RUN ON EACH DATABASE, AFTER the two expense migrations before it:
 *   php artisan migrate --path=database/migrations/2026_11_20_100100_add_expense_dashboard_and_advance_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_20_100100_add_expense_dashboard_and_advance_menu.php
 */
return new class extends Migration
{
    private const GROUP_LINK = '/module/hrit-solutions/expense-management';

    /** [access_link => [menu_name, icon, sort_order]] */
    private const PAGES = [
        '/module/hrit-solutions/expense-management/expense-dashboard' => ['Expense Dashboard', 'mdi mdi-view-dashboard-outline', 0],
        '/module/hrit-solutions/expense-management/employee-advances' => ['Employee Advances', 'mdi mdi-cash-fast', 4],
        '/module/hrit-solutions/expense-management/advance-summary' => ['Advance Summary', 'mdi mdi-chart-box-outline', 5],
        '/module/hrit-solutions/expense-management/unpaid-expense-claims' => ['Unpaid Expense Claims', 'mdi mdi-cash-clock', 6],
    ];

    /** [view, add, edit, delete] by role_key, per page key. A role missing from a map gets no row. */
    private const RIGHTS = [
        'expense-dashboard' => [
            '*' => [1, 0, 0, 0],
        ],
        'employee-advances' => [
            'executive' => [1, 0, 0, 0],
            'auditor' => [1, 0, 0, 0],
            '*' => [1, 1, 1, 1],
        ],
        'advance-summary' => [
            'administrator' => [1, 0, 0, 0],
            'hr_manager' => [1, 0, 0, 0],
            'hr_executive' => [1, 0, 0, 0],
            'executive' => [1, 0, 0, 0],
            'auditor' => [1, 0, 0, 0],
        ],
        'unpaid-expense-claims' => [
            'administrator' => [1, 0, 0, 0],
            'hr_manager' => [1, 0, 0, 0],
            'hr_executive' => [1, 0, 0, 0],
            'executive' => [1, 0, 0, 0],
            'auditor' => [1, 0, 0, 0],
        ],
    ];

    public function up(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g') || !$this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        $group = DB::table('tblmenumaster_g2g')->where('access_link', self::GROUP_LINK)->first();

        if (!$group) {
            // Run 2026_11_19_100100_add_expense_management_menu first.
            return;
        }

        DB::transaction(function () use ($group) {
            $menuIds = [];

            foreach (self::PAGES as $link => [$name, $icon, $sort]) {
                $menuIds[basename($link)] = $this->ensureMenu($name, $link, (int) $group->id, (int) $group->level + 1, $icon, $sort);
            }

            $this->grantRights($menuIds);
        });
    }

    public function down(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $ids = DB::table('tblmenumaster_g2g')->whereIn('access_link', array_keys(self::PAGES))->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->delete();
        }

        DB::table('tblmenumaster_g2g')->whereIn('id', $ids)->delete();
    }

    private function ensureMenu(string $name, string $link, int $parentId, int $level, string $icon, int $sort): int
    {
        $existing = DB::table('tblmenumaster_g2g')->where('access_link', $link)->first();

        if ($existing) {
            return (int) $existing->id;
        }

        return (int) DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => $name,
            'parent_id' => $parentId,
            'level' => $level,
            'page_type' => 'page',
            'access_link' => $link,
            'icon' => $icon,
            'status' => 1,
            'sort_order' => $sort,
            'sub_institute_id' => null,
            'menu_type' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string,int> $menuIds  RIGHTS key => menu id */
    private function grantRights(array $menuIds): void
    {
        $already = DB::table('tblgroupwise_rights_g2g')
            ->whereIn('menu_id', array_values($menuIds))
            ->get(['menu_id', 'profile_id'])
            ->mapWithKeys(fn ($r) => [$r->menu_id . ':' . $r->profile_id => true]);

        $now = now();
        $rows = [];

        foreach (DB::table('tbluserprofilemaster')->get() as $profile) {
            $role = RoleKey::fromProfile($profile) ?? 'employee';

            foreach ($menuIds as $key => $menuId) {
                if ($already->has($menuId . ':' . $profile->id)) {
                    continue;
                }

                $rights = self::RIGHTS[$key][$role] ?? self::RIGHTS[$key]['*'] ?? null;

                if ($rights === null) {
                    continue;
                }

                $rows[] = [
                    'menu_id' => $menuId,
                    'profile_id' => $profile->id,
                    'can_view' => $rights[0],
                    'can_add' => $rights[1],
                    'can_edit' => $rights[2],
                    'can_delete' => $rights[3],
                    'dashboard_right' => 0,
                    'is_mobile' => 0,
                    'sub_institute_id' => $profile->sub_institute_id,
                    'created_at' => $now,
                ];
            }
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
