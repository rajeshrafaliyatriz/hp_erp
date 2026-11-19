<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Travel Visit" under HRIT Management: the list of trips (own, team, or all
 * depending on role) with the route map for each.
 *
 * Rows are found by `access_link`; ids are whatever the host's auto-increment
 * gives, and the frontend matches this screen by access_link alone.
 *
 * Rights are granted by role_key for every existing profile. Everyone may see
 * the screen (an employee sees only their own trips - the API enforces that);
 * nobody adds/edits/deletes from the web, trips are started in the mobile app.
 *
 * Idempotent on `access_link` and on (menu, profile).
 *
 * RUN ON EACH DATABASE, AFTER 2026_11_22_100000_create_hrms_travel_visits_tables.php:
 *   php artisan migrate --path=database/migrations/2026_11_22_100100_add_travel_visit_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_11_22_100100_add_travel_visit_menu.php
 */
return new class extends Migration
{
    private const MODULE_LINK = '/module/hrit-solutions';
    private const LINK = '/module/hrit-solutions/travel-visit';

    public function up(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g') || !$this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        $module = DB::table('tblmenumaster_g2g')->where('access_link', self::MODULE_LINK)->first();

        if (!$module) {
            return;   // HRIT Management missing here: do not guess a parent.
        }

        DB::transaction(function () use ($module) {
            $existing = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->first();

            $menuId = $existing
                ? (int) $existing->id
                : (int) DB::table('tblmenumaster_g2g')->insertGetId([
                    'menu_name' => 'Travel Visit',
                    'parent_id' => $module->id,
                    'level' => (int) $module->level + 1,
                    'page_type' => 'page',
                    'access_link' => self::LINK,
                    'icon' => 'mdi mdi-map-marker-path',
                    'status' => 1,
                    'sort_order' => 1 + (int) DB::table('tblmenumaster_g2g')->where('parent_id', $module->id)->max('sort_order'),
                    'sub_institute_id' => null,
                    'menu_type' => '',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            $already = DB::table('tblgroupwise_rights_g2g')->where('menu_id', $menuId)->pluck('profile_id')->flip();
            $rows = [];

            foreach (DB::table('tbluserprofilemaster')->get() as $profile) {
                if ($already->has($profile->id)) {
                    continue;
                }

                $rows[] = [
                    'menu_id' => $menuId,
                    'profile_id' => $profile->id,
                    'can_view' => 1,
                    'can_add' => 0,
                    'can_edit' => 0,
                    'can_delete' => 0,
                    'dashboard_right' => 0,
                    'is_mobile' => 1,
                    'sub_institute_id' => $profile->sub_institute_id,
                    'created_at' => now(),
                ];
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('tblgroupwise_rights_g2g')->insert($chunk);
            }
        });
    }

    public function down(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $ids = DB::table('tblmenumaster_g2g')->where('access_link', self::LINK)->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->delete();
        }

        DB::table('tblmenumaster_g2g')->whereIn('id', $ids)->delete();
    }

    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
