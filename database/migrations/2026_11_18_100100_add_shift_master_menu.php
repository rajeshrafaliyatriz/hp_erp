<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Shift Master" - where HR adds, edits and deletes the organisation's shifts.
 *
 * Sits straight after Department Management in Organization Setup, with the
 * sibling shift-then-insert done in one transaction, as
 * 2026_10_08_100100_add_my_department_documents_menu does. Idempotent on
 * `access_link`; down() removes only the row carrying this link.
 *
 * The page's write endpoints are gated `profile:admin,hr` in routes/api.php.
 * Grant the menu row to other profiles in Role & Permissions if they should
 * see it.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_11_18_100100_add_shift_master_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_18_100100_add_shift_master_menu.php
 */
return new class extends Migration
{
    private const LINK = '/module/organizational-management/organization-setup/shift-master';
    private const AFTER_LINK = '/module/organizational-management/organization-setup/department-management';

    public function up(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $existing = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->first();

        if ($existing) {
            // The row is there; make sure the rights are too (see grantRights()).
            $this->grantRights((int) $existing->id);

            return;
        }

        $sibling = DB::table('tblmenumaster_g2g')->where('access_link', self::AFTER_LINK)->first();

        if (!$sibling) {
            return;   // Department Management missing here: do not guess a parent.
        }

        $parentId = $sibling->parent_id;
        $level = $sibling->level;
        $position = (int) $sibling->sort_order + 1;

        DB::transaction(function () use ($parentId, $level, $position) {
            DB::table('tblmenumaster_g2g')
                ->where('parent_id', $parentId)
                ->where('sort_order', '>=', $position)
                ->increment('sort_order');

            $menuId = DB::table('tblmenumaster_g2g')->insertGetId([
                'menu_name' => 'Shift Master',
                'parent_id' => $parentId,
                'level' => $level,
                'page_type' => 'page',
                'access_link' => self::LINK,
                'icon' => 'mdi mdi-clock-time-four-outline',
                'status' => 1,
                'sort_order' => $position,
                'sub_institute_id' => null,
                'menu_type' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->grantRights((int) $menuId);
        });
    }

    /**
     * A menu row with no rights row is invisible to everybody, so the rights are
     * part of this migration: running it on a new database is the whole job.
     *
     * Full rights to administrator / hr_manager / hr_executive, by role_key
     * (falling back to the profile name, as RoleKey does) - the roles the
     * /api/shifts write routes allow. Anyone else gets no row, so cannot see it.
     * Profiles created later get nothing until granted in Role & Permissions.
     * Skips any (menu, profile) pair that already has a row.
     */
    private function grantRights(int $menuId): void
    {
        if (!$this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        $already = DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->pluck('profile_id')->flip();
        $rows = [];

        foreach (DB::table('tbluserprofilemaster')->get() as $profile) {
            $role = \App\Support\RoleKey::fromProfile($profile);

            if (!in_array($role, ['administrator', 'hr_manager', 'hr_executive'], true) || $already->has($profile->id)) {
                continue;
            }

            $rows[] = [
                'menu_id' => $menuId,
                'profile_id' => $profile->id,
                'can_view' => 1,
                'can_add' => 1,
                'can_edit' => 1,
                'can_delete' => 1,
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

    public function down(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $row = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->first();

        if (!$row) {
            return;
        }

        DB::table('tblmenumaster_g2g')->where('id', $row->id)->delete();

        DB::table('tblmenumaster_g2g')
            ->where('parent_id', $row->parent_id)
            ->where('sort_order', '>', $row->sort_order)
            ->decrement('sort_order');
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
