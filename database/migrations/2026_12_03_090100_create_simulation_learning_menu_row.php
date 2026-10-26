<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Simulation Learning sub-module: a real `tblmenumaster_g2g` row under LMS.
 *
 * A level-2 row directly under the LMS root, the same shape as Assessments (`/module/lms/assessments`): the sub-module is itself the screen
 * (g2gv0: components/domain/lms/simulations, registered in hooks/content-map-m4.ts at this access_link). It lists the simulations of the
 * caller's own organisation as cards and plays the one that is opened. Without this row the screen exists but has no sidebar entry and no
 * rights of its own.
 *
 * The ids of menu rows differ between the `default` and `live` connections (Capability Gap Inbox is 488 on one and 475 on the other), so
 * nothing here names an id: the LMS root, the Course Builder row and the My Learning row are all found by access_link on THIS connection.
 *
 * RIGHTS (the `tblgroupwise_rights_g2g` matrix, managed by admins in Role & Permissions like every other screen):
 *   - this migration grants the row to the people who AUTHOR in the LMS: every profile that holds `Course Builder` WITH an authoring bit
 *     (add, edit or delete), carrying the same view/add/edit/delete bits that row has. Those bits are what `/api/lms/simulations` checks for
 *     adding, editing and removing simulations. A profile that can only VIEW Course Builder is not granted: measured on `live` on
 *     2026-10-26, view-only holders there include the Employee profiles of eight organisations, and the first version of this migration
 *     (every holder with view) would have put a new menu entry in front of all of them; only the authoring profile (organisation 6's
 *     administrator) has those bits, so that is the one row it writes there.
 *   - learners are NOT granted here. Which organisations offer simulations to their employees is a rollout decision made per organisation
 *     (`php artisan simulations:grant --tenant=<id>`), so no organisation id is written into this file. Until then an organisation's
 *     administrators can also grant it to any role themselves.
 *   - the grant is the LEAF ONLY. Its only ancestor is the LMS root, which every profile that holds Course Builder already holds; walking
 *     further up would be the "granting an ancestor reveals stale sibling grants" trap (see the leaf-only note in the repo memory).
 *
 * `page_type` is `enum('blade','page','link')`, nullable; 'page' is what Assessments holds.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_12_03_090100_create_simulation_learning_menu_row.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_03_090100_create_simulation_learning_menu_row.php
 */
return new class extends Migration
{
    private const ROOT_LINK = '/module/lms';
    private const ACCESS_LINK = '/module/lms/simulation-learning';
    private const AUTHOR_LINK = '/module/lms/administration/course-builder';
    private const LABEL = 'Simulation Learning';
    private const ICON = 'FlaskConical';

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $rootId = DB::table('tblmenumaster_g2g')->where('access_link', self::ROOT_LINK)->where('parent_id', 0)->value('id');
        if ($rootId === null) {
            return;
        }

        $menuId = $this->createMenuRow((int) $rootId);

        if ($menuId === null || ! $this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        $authorId = DB::table('tblmenumaster_g2g')->where('access_link', self::AUTHOR_LINK)->value('id');
        if ($authorId === null) {
            return;
        }

        $grants = DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', $authorId)
            ->where('can_view', 1)
            ->where(function ($authoring) {
                $authoring->where('can_add', 1)->orWhere('can_edit', 1)->orWhere('can_delete', 1);
            })
            ->get();

        foreach ($grants as $grant) {
            $exists = DB::table('tblgroupwise_rights_g2g')
                ->where('menu_id', $menuId)
                ->where('profile_id', $grant->profile_id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('tblgroupwise_rights_g2g')->insert([
                'sub_institute_id' => $grant->sub_institute_id,
                'menu_id' => $menuId,
                'profile_id' => $grant->profile_id,
                'can_view' => 1,
                'can_add' => (int) $grant->can_add,
                'can_edit' => (int) $grant->can_edit,
                'can_delete' => (int) $grant->can_delete,
                'dashboard_right' => 0,
                'is_mobile' => $grant->is_mobile,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $menuId = DB::table('tblmenumaster_g2g')->where('access_link', self::ACCESS_LINK)->value('id');

        if ($menuId === null) {
            return;
        }

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->delete();
        }

        DB::table('tblmenumaster_g2g')->where('id', $menuId)->delete();
    }

    private function createMenuRow(int $rootId): ?int
    {
        $existing = DB::table('tblmenumaster_g2g')->where('access_link', self::ACCESS_LINK)->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $nextSort = (int) DB::table('tblmenumaster_g2g')->where('parent_id', $rootId)->max('sort_order');

        return DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => self::LABEL,
            'parent_id' => $rootId,
            'level' => 2,
            'page_type' => 'page',
            'access_link' => self::ACCESS_LINK,
            'icon' => self::ICON,
            'status' => 1,
            'sort_order' => $nextSort + 1,
            'sub_institute_id' => null,
            'menu_type' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Schema::hasTable() can throw on live's MariaDB 10.1, so fall back to information_schema directly. */
    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return DB::select(
                'SELECT 1 FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
                [$table]
            ) !== [];
        }
    }
};
