<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove grants on menu 208 that predate the menu itself.
 *
 * ── WHAT WAS FOUND ──────────────────────────────────────────────────────────
 *
 * Creating menu 208 revealed that LIVE already held 29 rights rows pointing at
 * it, all stamped 2026-08-03 — grants for a menu that has never existed. An
 * earlier attempt evidently wrote the rights and not the menu.
 *
 * While the menu was missing they were inert. The moment it exists they decide
 * who sees it:
 *
 *   27 rows  profile_id with no row in tbluserprofilemaster (orphans, part of
 *            the wider ~750 orphan-rights problem on live)
 *    1 row   profile 3, role_key `employee`
 *
 * That single employee row is the one that matters. The seven /competency/audit/*
 * endpoints are now gated `profile:admin,hr`, so an employee granted this menu
 * would see "Audit & Activity Center" in their sidebar and receive a 403 on
 * opening it — a menu item that exists only to fail. A visible control that
 * cannot work is worse than an absent one, because the user cannot tell whether
 * they lack permission or the product is broken.
 *
 * Dev is already clean (30 rows, all correct), so this is effectively a live-only
 * repair that runs harmlessly on both.
 *
 * ── SCOPE: MENU 208 ONLY ────────────────────────────────────────────────────
 *
 * The orphan-rights problem on live is much larger than this and is NOT swept up
 * here. Deleting ~750 rows across every module on the strength of a competency
 * audit would be a far wider change than the one being reviewed, and some of
 * those profiles may be restorable. This removes only what makes THIS menu behave
 * incorrectly; the rest is reported and left for its own decision.
 *
 * ── WHY DELETE AND NOT REVOKE ───────────────────────────────────────────────
 *
 * Setting can_view = 0 would leave a row asserting that a non-existent profile
 * has a considered "no" on this menu. Absence is the honest record for a grant
 * that was never valid. down() cannot restore them, and says so.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_31_235500_prune_stale_audit_menu_rights.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_31_235500_prune_stale_audit_menu_rights.php
 */
return new class extends Migration
{
    private const MENU_ID = 208;

    /** RequireProfile::ALIASES for 'admin' and 'hr' — the gate on these routes. */
    private const ROLE_KEYS = ['administrator', 'hr_manager', 'hr_executive'];

    public function up(): void
    {
        if (! $this->tableExists('tblgroupwise_rights_g2g') || ! $this->tableExists('tbluserprofilemaster')) {
            return;
        }

        /*
         * Anything whose profile does not resolve to one of the gated role_keys.
         * Expressed as "keep the good" rather than "delete the known bad" so a
         * database carrying a variant nobody measured is still corrected.
         */
        $keep = DB::table('tbluserprofilemaster')
            ->whereIn('role_key', self::ROLE_KEYS)
            ->pluck('id');

        DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', self::MENU_ID)
            ->when($keep->isNotEmpty(), fn ($q) => $q->whereNotIn('profile_id', $keep))
            ->delete();
    }

    public function down(): void
    {
        /*
         * Intentionally empty. The rows removed were grants to profiles that do
         * not exist, plus one to a role the API refuses. Recreating them would be
         * restoring a defect, and the orphans cannot be meaningfully recreated at
         * all — the profiles they named are gone.
         */
    }

    /** Schema::hasTable() throws on live (MariaDB 10.1.48); read the catalogue directly. */
    private function tableExists(string $table): bool
    {
        $rows = DB::select(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        return (int) ($rows[0]->c ?? 0) > 0;
    }
};
