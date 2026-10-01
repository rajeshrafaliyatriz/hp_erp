<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair what the first run of 110100 and 110200 left behind.
 *
 * Those two migrations shipped with a tenant-scoped query in a table whose
 * tenant column the reader ignores. Both have since been corrected, but they
 * are already recorded as run on both databases, so the corrections only help a
 * fresh environment. This migration fixes the rows that exist today.
 *
 * ── DEFECT 1: DUPLICATE RIGHTS ROWS ─────────────────────────────────────────
 *
 * 110100 looped over `DISTINCT sub_institute_id` from the rights table, whose
 * column is `text` and holds a CSV value '1,2,3,4,5,6,7,8,9,10,11' alongside
 * plain ids. `tbluserprofilemaster.sub_institute_id` is `bigint`, so MySQL
 * coerced that CSV string to 1 on comparison and tenant 1's profiles matched
 * both the '1' iteration and the CSV one - two rights rows each.
 *
 * Measured after that run: 9 duplicated profiles on app, 3 on live, for each of
 * menu 3 and menu 401.
 *
 * Harmless today - every duplicate carries can_view = 1, and there are zero
 * can_view = 0 rows for menu 3, so no deny row can be shadowed. It is cleaned
 * up because displaySidebarMenu does keyBy('menu_id') over an UNORDERED query:
 * which of two rows wins is whatever order MySQL returns, and the day one of
 * them differs from the other is the day that becomes a real bug.
 *
 * ── DEFECT 2: A REVOKE THAT DID NOT REVOKE ──────────────────────────────────
 *
 * 110200 deleted menu 158 from employee profiles with a `sub_institute_id`
 * predicate. On live, profile 3 "Employee" (tenant 1) holds that right on a row
 * whose sub_institute_id is NULL - one of 4,379 such rows - so the delete could
 * not see it, while displaySidebarMenu, which reads unscoped, still honoured
 * it. The admin Certification Center stayed reachable for that profile.
 *
 * ── HOW DUPLICATES ARE RESOLVED ─────────────────────────────────────────────
 *
 * Keep the LOWEST id per (profile_id, menu_id), delete the rest. Lowest id is
 * the oldest row, which is the one an administrator may have configured
 * deliberately; the later row is the one the faulty loop added. Deterministic
 * and idempotent - running it twice removes nothing the second time.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_09_30_110300_repair_my_certifications_rights.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_30_110300_repair_my_certifications_rights.php
 */
return new class extends Migration
{
    /** My Certifications, and the Talent container 110100 also granted. */
    private const MENUS = [3, 401];

    /** The admin Certification & Compliance Center. */
    private const ADMIN_CERTS = 158;

    public function up(): void
    {
        if (!$this->tableExists('tblgroupwise_rights_g2g') || !$this->tableExists('tbluserprofilemaster')) {
            return;
        }

        $this->dedupe();
        $this->finishRevoke();
    }

    /**
     * There is nothing to undo.
     *
     * Restoring a duplicate row would be restoring a defect, and restoring the
     * revoked right is what 110200's own down() is for. A no-op down is honest
     * here; a down that recreated junk would not be.
     */
    public function down(): void
    {
    }

    /** Keep the oldest row per profile+menu, drop the rest. */
    private function dedupe(): void
    {
        foreach (self::MENUS as $menuId) {
            $keep = DB::table('tblgroupwise_rights_g2g')
                ->where('menu_id', $menuId)
                ->selectRaw('profile_id, MIN(id) AS keep_id')
                ->groupBy('profile_id')
                ->pluck('keep_id');

            if ($keep->isEmpty()) {
                continue;
            }

            DB::table('tblgroupwise_rights_g2g')
                ->where('menu_id', $menuId)
                ->whereNotIn('id', $keep->all())
                ->delete();
        }
    }

    /**
     * The unscoped delete 110200 should have used.
     *
     * Matches the key displaySidebarMenu actually reads on - profile_id +
     * menu_id, no tenant predicate - so a NULL or CSV tenant value cannot hide
     * a row from it.
     */
    private function finishRevoke(): void
    {
        $employeeProfiles = DB::table('tbluserprofilemaster')
            ->where('role_key', 'employee')
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($employeeProfiles->isEmpty()) {
            return;
        }

        DB::table('tblgroupwise_rights_g2g')
            ->where('menu_id', self::ADMIN_CERTS)
            ->whereIn('profile_id', $employeeProfiles->all())
            ->delete();
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
