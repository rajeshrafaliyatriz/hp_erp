<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menu rows and rights for Expense Management, under HRIT Management (id 5).
 *
 *   HRIT Management
 *   └─ Expense Management                    (group, level 2)
 *        ├─ My Expenses                      everyone: claim and track your own
 *        ├─ Expense Approvals                managers + HR approve; HR pays
 *        └─ Expense Categories               HR / admin: what can be claimed
 *
 * ── NOTHING IS PINNED TO AN ID ──────────────────────────────────────────────
 *
 * Rows are found by `access_link`, and ids are whatever the host's
 * auto-increment gives. That is safe here because the frontend matches these
 * screens by access_link alone (no `submenuId` fallback), so the same screen
 * resolves on every database even though the ids differ.
 *
 * ── RIGHTS ARE GRANTED BY ROLE, FOR EVERY EXISTING PROFILE ──────────────────
 *
 * A menu row with no rights row is invisible to everybody, so the rights are
 * part of the migration: running it on a new database is the whole job.
 * Rights come from `tbluserprofilemaster.role_key` (falling back to the
 * profile's name for the older profiles, exactly as RoleKey does), never from
 * a hardcoded profile id. A profile with no recognisable role is treated as an
 * ordinary employee.
 *
 *                       group   My Expenses   Approvals   Categories
 *   administrator       view    v a e d       v . e .     v a e d
 *   hr_manager          view    v a e d       v . e .     v a e d
 *   hr_executive        view    v a e d       v . e .     v a e d
 *   reporting_manager   view    v a e d       v . e .     -
 *   department_head     view    v a e d       v . e .     -
 *   executive           view    v a e d       v . . .     -
 *   auditor             view    v . . .       v . . .     -
 *   everyone else       view    v a e d       -           -
 *
 * These are only what the SCREEN offers. The API enforces independently (see
 * ExpenseClaimController): an employee can only ever read and change their own
 * claims, a manager only their team's, and only HR can mark a claim paid.
 *
 * Profiles created AFTER this runs get no rows until somebody grants them in
 * Role & Permissions, as with every other menu.
 *
 * Idempotent on `access_link` and on (menu, profile). down() removes only the
 * rows carrying these links, with their rights.
 *
 * RUN ON EACH DATABASE, AFTER 2026_11_19_100000_create_hrms_expense_tables.php:
 *   php artisan migrate --path=database/migrations/2026_11_19_100100_add_expense_management_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_19_100100_add_expense_management_menu.php
 */
return new class extends Migration
{
    /** HRIT Management. */
    private const MODULE_LINK = '/module/hrit-solutions';
    private const GROUP_LINK = '/module/hrit-solutions/expense-management';

    /** [access_link => [menu_name, icon]] - the three screens under the group. */
    private const PAGES = [
        '/module/hrit-solutions/expense-management/my-expenses' => ['My Expenses', 'mdi mdi-receipt-text-outline'],
        '/module/hrit-solutions/expense-management/expense-approvals' => ['Expense Approvals', 'mdi mdi-check-decagram-outline'],
        '/module/hrit-solutions/expense-management/expense-categories' => ['Expense Categories', 'mdi mdi-shape-outline'],
    ];

    /**
     * [view, add, edit, delete] by role_key, per menu key. A role missing from a
     * map gets no row, which is "cannot see it".
     */
    private const RIGHTS = [
        'group' => [
            '*' => [1, 0, 0, 0],
        ],
        'my-expenses' => [
            'auditor' => [1, 0, 0, 0],
            '*' => [1, 1, 1, 1],
        ],
        'expense-approvals' => [
            'administrator' => [1, 0, 1, 0],
            'hr_manager' => [1, 0, 1, 0],
            'hr_executive' => [1, 0, 1, 0],
            'reporting_manager' => [1, 0, 1, 0],
            'department_head' => [1, 0, 1, 0],
            'executive' => [1, 0, 0, 0],
            'auditor' => [1, 0, 0, 0],
        ],
        'expense-categories' => [
            'administrator' => [1, 1, 1, 1],
            'hr_manager' => [1, 1, 1, 1],
            'hr_executive' => [1, 1, 1, 1],
        ],
    ];

    public function up(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g') || !$this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        $module = DB::table('tblmenumaster_g2g')->where('access_link', self::MODULE_LINK)->first();

        if (!$module) {
            // HRIT Management itself is missing on this database - do not guess a parent.
            return;
        }

        DB::transaction(function () use ($module) {
            $groupId = $this->ensureMenu(
                'Expense Management',
                self::GROUP_LINK,
                (int) $module->id,
                (int) $module->level + 1,
                'mdi mdi-cash-multiple',
                // `page_type` is enum('blade','page','link'): a group has no page type,
                // and '' is refused in strict mode, so it is NULL like the other groups.
                null,
                1 + (int) DB::table('tblmenumaster_g2g')->where('parent_id', $module->id)->max('sort_order'),
            );

            $menuIds = ['group' => $groupId];
            $order = 1;

            foreach (self::PAGES as $link => [$name, $icon]) {
                $menuIds[basename($link)] = $this->ensureMenu(
                    $name,
                    $link,
                    $groupId,
                    (int) $module->level + 2,
                    $icon,
                    'page',
                    $order++,
                );
            }

            $this->grantRights($menuIds);
        });
    }

    public function down(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $links = array_merge([self::GROUP_LINK], array_keys(self::PAGES));
        $ids = DB::table('tblmenumaster_g2g')->whereIn('access_link', $links)->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->delete();
        }

        DB::table('tblmenumaster_g2g')->whereIn('id', $ids)->delete();
    }

    /** The row for an access_link, creating it only if it is not there already. */
    private function ensureMenu(string $name, string $link, int $parentId, int $level, string $icon, ?string $pageType, int $sort): int
    {
        $existing = DB::table('tblmenumaster_g2g')->where('access_link', $link)->first();

        if ($existing) {
            return (int) $existing->id;
        }

        return (int) DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => $name,
            'parent_id' => $parentId,
            'level' => $level,
            'page_type' => $pageType,
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
