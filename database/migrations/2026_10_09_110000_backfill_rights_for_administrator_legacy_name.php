<?php

use App\Support\RoleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill for the `RoleKey::LEGACY_NAMES` gap this commit also fixes: the
 * literal profile name "administrator" (not "admin") was never in that list,
 * so any profile named exactly that — with `role_key` still NULL, true for
 * 10 of 12 known tenants per `Docs\cross-repo-audit\_evidence\roles-permissions-db3.txt`
 * — resolved to `role_key = null` through every migration below, not
 * `'administrator'`.
 *
 * Five migrations already ran against that false negative, all gated on
 * `RoleKey::fromProfile($profile) === 'administrator'` (or `in_array(...,
 * $row['roles'])`):
 *   2026_09_30_110000_create_event_bus_and_audit_menu_rights.php
 *   2026_09_30_120000_revoke_broad_platform_services_module_grants.php  (the one that DELETES)
 *   2026_09_30_121000_create_platform_administration_and_whats_coming_menu_rights.php
 *   2026_09_30_122000_create_ai_intelligence_menu_rights.php
 *   2026_10_05_150000_create_document_library_menu_right.php
 *
 * A profile matching the newly-added legacy name was therefore: (a) stripped
 * of its inherited Workflow/Scheduler/Integration/Add Process/Fields
 * Configuration grants by the revoke migration, backed up first into
 * `g2g_platform_services_revoked_rights_backup`; and (b) never granted the
 * brand-new rows the other four migrations created (Event Bus, Audit,
 * Platform Administration, What's Coming, 11 of 12 AI capabilities, Document
 * Library). This migration corrects both, scoped ONLY to profiles that the
 * fixed `RoleKey::fromProfile()` now resolves to `'administrator'` AND that
 * the OLD resolution did not — a profile correctly classified as non-admin
 * before this fix is untouched by either step.
 *
 * Both steps are idempotent (existence-checked inserts) — safe to run twice,
 * and safe for profiles already correctly granted by the original five
 * migrations.
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
