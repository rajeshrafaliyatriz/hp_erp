<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two task-management menu rows on live point at the wrong screen.
 *
 * ── WHAT IS WRONG ───────────────────────────────────────────────────────────
 *
 * Measured on both databases. DEV IS ALREADY CORRECT; live is behind:
 *
 *   #219 "Permision"      dev  /module/task-management/task-permission
 *                         live /module/task-management/administration/task-priority  <- #218's link
 *   #222 "Administration" dev  ''  (a container, correctly linkless)
 *                         live /module/task-management/reports-and-analysis          <- #215's link
 *
 * So this is a SYNC, not a redesign. Dev has zero duplicated access_link values in
 * this module; live has two.
 *
 * ── WHY IT MATTERS BEYOND TIDINESS ──────────────────────────────────────────
 *
 * DashboardLinkResolver keys on the LAST SEGMENT of access_link and takes first
 * match wins. Two rows sharing a slug means whichever sorts first silently wins —
 * so clicking "Permision" opens Priority Management, and "Administration" is
 * indistinguishable from "Reports & Analysis". Nothing errors; the wrong screen
 * simply opens.
 *
 * ── WHY IT MATCHES ON THE WRONG VALUE, NOT JUST THE ID ──────────────────────
 *
 * Each update is conditioned on the row still holding the WRONG link. A row an
 * operator has already corrected by hand is left alone, and re-running cannot
 * clobber a later edit. That makes this idempotent in the way that matters —
 * not merely "runs twice without erroring", but "cannot undo somebody's fix".
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_31_180000_fix_task_admin_menu_slugs.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_31_180000_fix_task_admin_menu_slugs.php
 */
return new class extends Migration
{
    /** id => [the wrong value to correct, the value dev already holds] */
    private const FIXES = [
        219 => ['/module/task-management/administration/task-priority', '/module/task-management/task-permission'],
        222 => ['/module/task-management/reports-and-analysis', ''],
    ];

    public function up(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        foreach (self::FIXES as $id => [$wrong, $right]) {
            DB::table('tblmenumaster_g2g')
                ->where('id', $id)
                ->where('access_link', $wrong)
                ->update(['access_link' => $right, 'updated_at' => now()]);
        }
    }

    /**
     * Reversal restores the duplicate, which is what a rollback of this means.
     *
     * Guarded the same way: only a row still holding the corrected value is put
     * back, so a rollback cannot overwrite an unrelated later change.
     */
    public function down(): void
    {
        if (! $this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        foreach (self::FIXES as $id => [$wrong, $right]) {
            DB::table('tblmenumaster_g2g')
                ->where('id', $id)
                ->where('access_link', $right)
                ->update(['access_link' => $wrong, 'updated_at' => now()]);
        }
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
