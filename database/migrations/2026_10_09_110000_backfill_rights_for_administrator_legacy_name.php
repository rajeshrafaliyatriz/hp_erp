<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * General repair: make sure every profile `RoleKey::fromProfile()` resolves
 * to `'administrator'` TODAY actually holds the rights rows it should, from
 * the five migrations below (all gated on that same check, or `in_array(...,
 * $row['roles'])`):
 *   2026_09_30_110000_create_event_bus_and_audit_menu_rights.php
 *   2026_09_30_120000_revoke_broad_platform_services_module_grants.php  (the one that DELETES)
 *   2026_09_30_121000_create_platform_administration_and_whats_coming_menu_rights.php
 *   2026_09_30_122000_create_ai_intelligence_menu_rights.php
 *   2026_10_05_150000_create_document_library_menu_right.php
 *
 * ── WHY THIS EXISTS, AND WHY IT IS NOT NARROWER ─────────────────────────────
 *
 * Originally written to backfill one specific cause: `RoleKey::LEGACY_NAMES`
 * was missing the literal profile name "administrator" (only "admin" was
 * recognised), so a profile named exactly that with `role_key` still NULL
 * resolved to `null`, not `'administrator'`, through every migration above —
 * see this commit's fix to `RoleKey.php` itself. That fix is real and worth
 * keeping (10 of 12 known tenants still have `role_key IS NULL`, per
 * `Docs\cross-repo-audit\_evidence\roles-permissions-db3.txt`, so an
 * "Administrator"-named profile among them would hit exactly this gap).
 *
 * But it does NOT explain the specific account that prompted this migration.
 * Direct inspection of both database connections (read-only, 2026-10-09)
 * found that account's profile named "Admin" — already a recognised legacy
 * name before this fix — with `role_key` already `'administrator'` on the
 * `live` connection specifically, yet still missing its Event Bus right
 * there, while ten OTHER administrator profiles on `live` have it and the
 * SAME profile has it on the `default` connection. The migrations above are
 * logged as having run on both connections. The exact mechanism was not
 * pinned down (a timing interaction with the same-day role_key backfill is
 * the leading guess, since Event Bus's own grant runs before it), and is not
 * pursued further here — whatever the cause, the fix is the same: make sure
 * every current administrator profile holds every row it should, which is
 * what this migration already did before this note was corrected. It is
 * deliberately NOT scoped to "only profiles the name fix newly affects",
 * because that framing turned out not to cover the actual reported case.
 *
 * Separately, worth the user's attention: the `default` connection's own
 * `migrations` table is missing log entries for
 * `2026_09_30_120000_backfill_role_key_where_unambiguous` and
 * `2026_09_30_120000_revoke_broad_platform_services_module_grants` entirely
 * (present on `live`) — the two connections' migration histories have
 * diverged beyond just this one profile's rights, which may be worth its own
 * investigation independent of this fix.
 *
 * Both steps below are idempotent (existence-checked inserts) — safe to run
 * twice, and safe for profiles already correctly granted.
 *
 * Everything this migration itself inserts is tracked in its own backup
 * table (`g2g_administrator_backfill_rights_backup`), the same way the
 * revoke migration tracks what IT deleted — so down() reverses exactly what
 * up() did, not a guess.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME — same as every migration above:
 *   php artisan migrate --path=database/migrations/2026_10_09_110000_backfill_rights_for_administrator_legacy_name.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_09_110000_backfill_rights_for_administrator_legacy_name.php
 */
return new class extends Migration
{
    private const TRACK_TABLE = 'g2g_administrator_backfill_rights_backup';
    private const REVOKE_BACKUP_TABLE = 'g2g_platform_services_revoked_rights_backup';

    /**
     * The access_link patterns every row created/touched by the five
     * migrations above falls under — queried live against tblmenumaster_g2g
     * rather than re-listing each row, so this stays correct even if those
     * migrations' own row sets are later edited. Verified against all five:
     * Event Bus (`/platform-services/event-bus`), Audit (`/settings?s=audit`),
     * Platform Administration (`/platform-services`), What's Coming
     * (`/platform-services/whats-coming`), the 11 AI capability rows
     * (`/ai/<slug>`), Document Library (`/documents`).
     */
    private function targetMenuIds()
    {
        return DB::table('tblmenumaster_g2g')
            ->where(function ($query) {
                $query->where('access_link', 'like', '/platform-services/%')
                    ->orWhere('access_link', '/platform-services')
                    ->orWhere('access_link', '/settings?s=audit')
                    ->orWhere('access_link', 'like', '/ai/%')
                    ->orWhere('access_link', '/documents');
            })
            ->pluck('id');
    }

    /** Profiles the FIXED RoleKey resolves to administrator. */
    private function nowAdministratorProfiles()
    {
        return DB::table('tbluserprofilemaster')
            ->get(['id', 'sub_institute_id', 'role_key', 'name'])
            ->filter(fn ($profile) => RoleKey::fromProfile($profile) === 'administrator');
    }

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')
            || ! $this->tableExists('tblgroupwise_rights_g2g')
            || ! $this->tableExists('tbluserprofilemaster')
        ) {
            return;
        }

        $this->ensureTrackTable();

        $profiles = $this->nowAdministratorProfiles();

        if ($profiles->isEmpty()) {
            return;
        }

        $profileIds = $profiles->pluck('id');

        // Step 1 — restore rows the revoke migration wrongly deleted for
        // profiles that are actually administrators under the fix.
        if ($this->tableExists(self::REVOKE_BACKUP_TABLE)) {
            $backedUp = DB::table(self::REVOKE_BACKUP_TABLE)
                ->whereIn('profile_id', $profileIds)
                ->get();

            foreach ($backedUp as $row) {
                $exists = DB::table('tblgroupwise_rights_g2g')
                    ->where('menu_id', $row->menu_id)
                    ->where('profile_id', $row->profile_id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $newId = DB::table('tblgroupwise_rights_g2g')->insertGetId([
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

                $this->track($newId);
            }
        }

        // Step 2 — grant the rows the four "create_*_menu_rights" migrations
        // never seeded for these profiles.
        $menuIds = $this->targetMenuIds();

        if ($menuIds->isEmpty()) {
            return;
        }

        foreach ($profiles as $profile) {
            foreach ($menuIds as $menuId) {
                $exists = DB::table('tblgroupwise_rights_g2g')
                    ->where('menu_id', $menuId)
                    ->where('profile_id', $profile->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $newId = DB::table('tblgroupwise_rights_g2g')->insertGetId([
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

                $this->track($newId);
            }
        }
    }

    public function down(): void
    {
        if (! $this->tableExists(self::TRACK_TABLE) || ! $this->tableExists('tblgroupwise_rights_g2g')) {
            return;
        }

        $ids = DB::table(self::TRACK_TABLE)->pluck('rights_row_id');

        if ($ids->isNotEmpty()) {
            DB::table('tblgroupwise_rights_g2g')->whereIn('id', $ids)->delete();
        }

        DB::statement('DROP TABLE IF EXISTS ' . self::TRACK_TABLE);
    }

    private function track(int $rightsRowId): void
    {
        DB::table(self::TRACK_TABLE)->insert([
            'rights_row_id' => $rightsRowId,
            'created_at' => now(),
        ]);
    }

    private function ensureTrackTable(): void
    {
        if ($this->tableExists(self::TRACK_TABLE)) {
            return;
        }

        DB::statement('
            CREATE TABLE ' . self::TRACK_TABLE . ' (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                rights_row_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL
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
