<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Revoke the Platform Services module rows' inherited non-administrator grants.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS — "CENTRALIZATION"
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `2026_09_29_100000_seed_decentralized_platform_services_menu.php` seeded
 * each of these 6 rows by INHERITING `can_view=1` from whichever profiles
 * already had `can_view=1` on that module's own parent row. For
 * hrms/lms/talent/competency/task, that is nearly every role — an ordinary
 * employee obviously has view rights on HRMS itself. Confirmed by a direct
 * read-only audit before writing this migration: 82 non-administrator
 * profiles / 232 active real users on the `default` connection, 24 profiles
 * / 126 active users on `live`, spread across every tenant.
 *
 * That was never a deliberate grant — it is inheritance debris from a
 * migration whose job was "create the row", not "decide who gets it". You
 * asked for the opposite default: nobody but an administrator sees these
 * consoles until an administrator deliberately grants them through Role &
 * Permissions ("centralization"). This migration is that correction.
 *
 * ── SAFETY ───────────────────────────────────────────────────────────────
 *
 * Pre-flight run manually before this was written (both connections): every
 * profile whose name contains "admin" resolves to `administrator` via
 * `RoleKey::fromProfile()` (role_key directly, or `LEGACY_NAMES` for the
 * `role_key IS NULL` tenants `live` still has) — nothing admin-looking would
 * be caught by this revoke. Zero tenants lose all admin coverage on these
 * rows (every tenant with a grant also has an administrator granted).
 * Tenant 9 (both connections) has zero ACTIVE administrator users at all —
 * pre-existing, unrelated to and unaffected by this migration either way.
 *
 * Every revoked row is copied into `g2g_platform_services_revoked_rights_backup`
 * (full column set, not just `can_view`) before deletion, so `down()`
 * restores exactly what existed rather than re-deriving it. Deletes are by
 * the row's own primary key — not by (menu_id, profile_id), which would be
 * unsafe given `sub_institute_id` on this table is TEXT and holds NULL, a
 * real tenant id, or a CSV string depending on the row (see MenuRight's own
 * F-152 note). Event Bus and Audit are untouched — already seeded narrowly,
 * excluded by construction via the WHERE filter below.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_30_120000_revoke_broad_platform_services_module_grants.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_30_120000_revoke_broad_platform_services_module_grants.php
 */
return new class extends Migration
{
    private const BACKUP_TABLE = 'g2g_platform_services_revoked_rights_backup';

    public function up(): void
    {
        if (! $this->tableExists('tblgroupwise_rights_g2g') || ! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $this->ensureBackupTable();

        $menuIds = DB::table('tblmenumaster_g2g')
            ->where('access_link', 'like', '/platform-services/workflow?module=%')
            ->pluck('id');

        if ($menuIds->isEmpty()) {
            return;
        }

        $rows = DB::table('tblgroupwise_rights_g2g')
            ->whereIn('menu_id', $menuIds)
            ->where('can_view', 1)
            ->get();

        $profileIds = $rows->pluck('profile_id')->unique();
        $profiles = DB::table('tbluserprofilemaster')
            ->whereIn('id', $profileIds)
            ->get(['id', 'role_key', 'name'])
            ->keyBy('id');

        foreach ($rows as $row) {
            $profile = $profiles[$row->profile_id] ?? null;
            $role = $profile ? RoleKey::fromProfile($profile) : null;

            if ($role === 'administrator') {
                continue;
            }

            DB::table(self::BACKUP_TABLE)->insert([
                'original_id' => $row->id,
                'sub_institute_id' => $row->sub_institute_id,
                'menu_id' => $row->menu_id,
                'profile_id' => $row->profile_id,
                'can_view' => $row->can_view,
                'can_add' => $row->can_add,
                'can_edit' => $row->can_edit,
                'can_delete' => $row->can_delete,
                'dashboard_right' => $row->dashboard_right,
                'is_mobile' => $row->is_mobile,
                'original_created_at' => $row->created_at,
                'backed_up_at' => now(),
            ]);

            DB::table('tblgroupwise_rights_g2g')->where('id', $row->id)->delete();
        }
    }

    public function down(): void
    {
        if (! $this->tableExists(self::BACKUP_TABLE) || ! $this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        $backedUp = DB::table(self::BACKUP_TABLE)->get();

        foreach ($backedUp as $row) {
            $exists = DB::table('tblgroupwise_rights_g2g')
                ->where('menu_id', $row->menu_id)
                ->where('profile_id', $row->profile_id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('tblgroupwise_rights_g2g')->insert([
                'sub_institute_id' => $row->sub_institute_id,
                'menu_id' => $row->menu_id,
                'profile_id' => $row->profile_id,
                'can_view' => $row->can_view,
                'can_add' => $row->can_add,
                'can_edit' => $row->can_edit,
                'can_delete' => $row->can_delete,
                'dashboard_right' => $row->dashboard_right,
                'is_mobile' => $row->is_mobile,
                'created_at' => $row->original_created_at,
            ]);
        }

        DB::statement('DROP TABLE IF EXISTS ' . self::BACKUP_TABLE);
    }

    private function ensureBackupTable(): void
    {
        if ($this->tableExists(self::BACKUP_TABLE)) {
            return;
        }

        DB::statement('
            CREATE TABLE ' . self::BACKUP_TABLE . ' (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                original_id BIGINT UNSIGNED NOT NULL,
                sub_institute_id TEXT NULL,
                menu_id BIGINT UNSIGNED NOT NULL,
                profile_id BIGINT UNSIGNED NOT NULL,
                can_view TINYINT(1) NOT NULL DEFAULT 0,
                can_add TINYINT(1) NOT NULL DEFAULT 0,
                can_edit TINYINT(1) NOT NULL DEFAULT 0,
                can_delete TINYINT(1) NOT NULL DEFAULT 0,
                dashboard_right TINYINT(1) NOT NULL DEFAULT 0,
                is_mobile TINYINT(1) NOT NULL DEFAULT 0,
                original_created_at DATETIME NULL,
                backed_up_at DATETIME NOT NULL
            )
        ');
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
