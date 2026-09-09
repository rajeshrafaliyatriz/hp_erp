<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ONE ONBOARDING DOOR, NOT TWO.
 *
 *   php artisan migrate --path=database/migrations/2026_09_08_120000_close_duplicate_onboarding_menu.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_08_120000_close_duplicate_onboarding_menu.php
 *
 * ── THE SECOND DOOR THAT OPENS ONTO NOTHING ─────────────────────────────────
 *
 * Employee onboarding has two menu rows:
 *
 *   48   Onboarding           parent 3 (Talent Management), status 1
 *        /module/talent-management/onboarding
 *        Mapped in hooks/content-map-m3.ts to OnboardingCenter - the real,
 *        4,800-line screen. 81 rights rows on dev, 35 on live.
 *
 *   169  Employee Onboarding  parent 180, status 1
 *        /module/talent-management/talent-onboarding/employee-onboarding
 *        MAPPED TO NOTHING. Its access_link appears in no content map, so it
 *        would render a blank screen even if somebody reached it.
 *
 * And menu 180, its parent, is already `status = 0`. The sidebar drops a branch
 * whose parent is disabled, so 169 has been invisible all along - a live row
 * hanging off a dead one.
 *
 * ── WHY THIS IS WORTH A MIGRATION AND NOT A SHRUG ───────────────────────────
 *
 * Because of what it is doing to the permission data. Measured:
 *
 *     dev    menu 169: 1 rights row
 *     live   menu 169: 29 RIGHTS ROWS
 *
 * Twenty-nine profiles on production carry a grant that reads, in the Role &
 * Permissions matrix, as "this role can open Employee Onboarding". None of them
 * can. Somebody administering that screen is making decisions against a row
 * that has never meant anything - and if menu 180 were ever re-enabled, those 29
 * grants would switch on at once, for a screen that renders nothing.
 *
 * Setting 169 to `status = 0` makes the catalogue agree with the truth: this
 * branch is closed, both levels of it.
 *
 * ── WHAT THIS DELIBERATELY DOES NOT DO ──────────────────────────────────────
 *
 * IT DOES NOT DELETE THE 29 RIGHTS ROWS. They are inert either way once the menu
 * is off, and they are somebody's production permission data - removing rows
 * that cannot be restored, to tidy a table, is not a schema fix. They are
 * reported instead, so the decision stays with a person.
 *
 * ── LIVE ────────────────────────────────────────────────────────────────────
 *
 * MariaDB 10.1.48. One UPDATE to one column on one row; no index, no type, no
 * identifier length in play. Guarded on the row still looking the way it did
 * when this was written, so a re-purposed id is left alone.
 */
return new class extends Migration
{
    private const MENU_ID = 169;
    private const PARENT_ID = 180;
    private const ACCESS_LINK = '/module/talent-management/talent-onboarding/employee-onboarding';

    public function up(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        $menu = DB::table('tblmenumaster_g2g')->where('id', self::MENU_ID)->first();

        /*
         * Every one of these has to still be true. The id is a claim about
         * another table's contents, and a catalogue can be renumbered - if this
         * row is no longer the orphan described above, it is not this
         * migration's business.
         */
        if (!$menu
            || (int) $menu->parent_id !== self::PARENT_ID
            || trim((string) $menu->access_link) !== self::ACCESS_LINK
            || (int) $menu->status === 0) {
            return;
        }

        // And only while the parent really is closed. Re-enabling 180 is a
        // decision somebody could legitimately take, and if they have, this
        // should not quietly undo half of it.
        $parentStatus = DB::table('tblmenumaster_g2g')->where('id', self::PARENT_ID)->value('status');

        if ($parentStatus === null || (int) $parentStatus !== 0) {
            return;
        }

        DB::table('tblmenumaster_g2g')
            ->where('id', self::MENU_ID)
            ->update(['status' => 0, 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (!$this->tableExists('tblmenumaster_g2g')) {
            return;
        }

        DB::table('tblmenumaster_g2g')
            ->where('id', self::MENU_ID)
            ->where('access_link', self::ACCESS_LINK)
            ->update(['status' => 1, 'updated_at' => now()]);
    }

    /** information_schema, not Schema::hasTable() - that throws on live's 10.1. */
    private function tableExists(string $table): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ));
    }
};
