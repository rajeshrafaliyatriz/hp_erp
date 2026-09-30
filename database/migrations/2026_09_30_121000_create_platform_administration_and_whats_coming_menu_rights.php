<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Real menu rows for Platform Administration and What's Coming — the last
 * two Platform Services entries still hardcoded visible-to-everyone in
 * `gtg-user-menu.tsx` regardless of rights. Same reasoning and pattern as
 * `2026_09_30_110000_create_event_bus_and_audit_menu_rights.php`: no
 * per-module concept, so one fixed row each, seeded administrator-only
 * (not inherited from a module's existing rights, for the same reason that
 * migration gives).
 *
 * Neither has a backend API of its own (both are pure frontend pages
 * reading other registries — Platform Administration is this console
 * itself, What's Coming is derived client-side from every service's own
 * status) — this migration only creates the row an admin can grant through
 * Role & Permissions; there is no route to gate.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_30_121000_create_platform_administration_and_whats_coming_menu_rights.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_30_121000_create_platform_administration_and_whats_coming_menu_rights.php
 */
return new class extends Migration
{
    private const PARENT_ID = 1;

    private const ROWS = [
        ['label' => 'Platform Administration', 'link' => '/platform-services', 'roles' => ['administrator']],
        ['label' => "What's Coming", 'link' => '/platform-services/whats-coming', 'roles' => ['administrator']],
    ];

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $hasRights = $this->tableExists('tblgroupwise_rights_g2g') && $this->tableExists('tbluserprofilemaster');
        $profiles = $hasRights
            ? DB::table('tbluserprofilemaster')->get(['id', 'sub_institute_id', 'role_key', 'name'])
            : collect();

        foreach (self::ROWS as $row) {
            $menuId = $this->createMenuRow($row['label'], $row['link']);

            if ($menuId === null || ! $hasRights) {
                continue;
            }

            foreach ($profiles as $profile) {
                if (! in_array(RoleKey::fromProfile($profile), $row['roles'], true)) {
                    continue;
                }

                $exists = DB::table('tblgroupwise_rights_g2g')
                    ->where('menu_id', $menuId)
                    ->where('profile_id', $profile->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('tblgroupwise_rights_g2g')->insert([
                    'sub_institute_id' => $profile->sub_institute_id,
                    'menu_id' => $menuId,
                    'profile_id' => $profile->id,
                    'can_view' => 1,
                    'can_add' => 0,
                    'can_edit' => 0,
                    'can_delete' => 0,
                    'dashboard_right' => 0,
                    'is_mobile' => 0,
                    'created_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $links = array_column(self::ROWS, 'link');

        $ids = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT_ID)
            ->whereIn('access_link', $links)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        if ($this->tableExists('tblgroupwise_rights_g2g')) {
            DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', $ids)->delete();
        }

        DB::table('tblmenumaster_g2g')->whereIn('id', $ids)->delete();
    }

    private function createMenuRow(string $label, string $accessLink): ?int
    {
        $existing = DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT_ID)
            ->where('access_link', $accessLink)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $nextSort = (int) DB::table('tblmenumaster_g2g')
            ->where('parent_id', self::PARENT_ID)
            ->max('sort_order');

        return DB::table('tblmenumaster_g2g')->insertGetId([
            'menu_name' => $label,
            'parent_id' => self::PARENT_ID,
            'level' => 2,
            'page_type' => null,
            'access_link' => $accessLink,
            'icon' => '',
            'status' => 0,
            'sort_order' => $nextSort + 1,
            'sub_institute_id' => null,
            'menu_type' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
