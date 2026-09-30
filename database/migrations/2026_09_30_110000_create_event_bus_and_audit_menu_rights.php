<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Real menu rows for Event Bus and Audit — the two Platform Services with no
 * `tblmenumaster_g2g` row at all today, and so no way for an admin to grant
 * anyone but a hardcoded 'administrator' access to them.
 *
 * Same house pattern as `2026_09_29_170000_consolidate_decentralized_platform_services_menu.php`
 * (idempotent insert-by-access_link, information_schema existence checks —
 * `Schema::hasTable()` throws on the `live` connection's older MariaDB, real
 * reversible down()), with one deliberate departure from that migration's own
 * seeding approach: rights here are seeded from `role_key = administrator`
 * (Event Bus) / `administrator,auditor` (Audit) directly, NOT inherited from
 * a module parent's existing can_view=1 set. Event Bus's event stream carries
 * actor ids across every module and routes/platform.php's own comment already
 * treats it as more sensitive than the rest — inheriting from a business
 * module's rights would silently widen it past today's admin-only status quo.
 * Audit's role set must match OrganizationSettingsController::audit()'s
 * existing inline array exactly, or auditors regress.
 *
 * Both rows are created `status = 0` — hidden from the sidebar immediately,
 * like every other Platform Services row. The navbar strip and avatar menu
 * are the real navigation paths; this table exists here purely so
 * tblgroupwise_rights_g2g has something to attach a right to.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_30_110000_create_event_bus_and_audit_menu_rights.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_30_110000_create_event_bus_and_audit_menu_rights.php
 */
return new class extends Migration
{
    private const PARENT_ID = 1; // Organisational Management — see docblock above.

    private const ROWS = [
        ['label' => 'Event Bus', 'link' => '/platform-services/event-bus', 'roles' => ['administrator']],
        ['label' => 'Audit', 'link' => '/settings?s=audit', 'roles' => ['administrator', 'auditor']],
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
                $resolved = RoleKey::fromProfile($profile);

                if (! in_array($resolved, $row['roles'], true)) {
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
