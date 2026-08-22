<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Opens the g2g menu catalogue to organisations the hard-coded list forgot.
 *
 * ── THE OUTAGE ──────────────────────────────────────────────────────────────
 *
 * `tblmenumaster_g2g.sub_institute_id` looks like a tenant column. It is not.
 * It is a TEXT comma-list, and every one of the 188 rows on BOTH databases
 * carried the same literal:
 *
 *     '1,2,3,4,5,6,7,8,9,10,11'
 *
 * Four call sites gated reads on `FIND_IN_SET(<tenant>, sub_institute_id)`, so
 * the entire product menu was closed to any organisation with an id of 12 or
 * above. Measured on live before this ran:
 *
 *     tenant  3 -> 137 menus        tenant 13 -> 0
 *     tenant  6 -> 137 menus        tenant 14 -> 0
 *                                   tenant 15 (the next signup) -> 0
 *
 * Tenants 13 and 14 are real, paying signups. They could not open Department
 * Management, Employee Directory, Capability Library, Competency Library or
 * Competency Framework - and no permission row could have helped, because this
 * filter runs BEFORE rights are consulted. Nothing in the rights tables looked
 * wrong, which is why the cause went unfound for so long.
 *
 * ── WHY CLEAR IT RATHER THAN APPEND 12, 13, 14, 15 ─────────────────────────
 *
 * Because that list would then need one more entry per signup forever, in a
 * TEXT column re-read on every sidebar request, and would be one forgotten
 * INSERT away from repeating this exact outage. NOTHING IN THE CODEBASE WRITES
 * THIS COLUMN - verified across app/ and database/ - so the list was never
 * being maintained by anything. It is static configuration that quietly rotted.
 *
 * The COLUMN IS KEPT, not dropped. A genuinely tenant-specific menu is a
 * reasonable future need and `scopeVisibleToTenant` still honours a restriction
 * written here. What changes is the DEFAULT: absent now means "available to
 * everyone" instead of "denied to everyone".
 *
 * ── THIS MIGRATION IS HALF OF THE FIX, AND THE SECOND HALF ─────────────────
 *
 * `tblmenumaster_g2gModel::scopeVisibleToTenant()` must already be deployed.
 * Alone, this migration BLACKS OUT tenants 1-11, because with the literal gone
 * `FIND_IN_SET` matches nobody. Ship the code first, then run this. The reverse
 * order takes the sidebar away from every existing customer.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_22_110000_make_g2g_menu_catalogue_global.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_22_110000_make_g2g_menu_catalogue_global.php
 */
return new class extends Migration
{
    private const TABLE = 'tblmenumaster_g2g';

    /**
     * Matched EXACTLY, not with a LIKE or a blanket "set everything to NULL".
     *
     * If somebody has since written a real restriction on a row, it is not this
     * literal and it survives untouched. Clearing the column wholesale would
     * silently discard that intent.
     */
    private const LEGACY_LITERAL = '1,2,3,4,5,6,7,8,9,10,11';

    public function up(): void
    {
        if (!$this->tableExists(self::TABLE)) {
            return;
        }

        DB::table(self::TABLE)
            ->where('sub_institute_id', self::LEGACY_LITERAL)
            ->update(['sub_institute_id' => null]);
    }

    public function down(): void
    {
        /*
         * DELIBERATELY NOT REVERSED.
         *
         * Restoring the literal would re-close the catalogue against every
         * organisation with an id of 12 or above - it would reintroduce the
         * outage this migration exists to end, on live, for real customers. A
         * rollback that puts a production break back is not a safety net.
         *
         * It is also not cleanly reversible: any menu row added after this ran
         * would be stamped with a restriction it never had.
         *
         * If it must be undone by hand, this is the statement:
         *
         *   UPDATE tblmenumaster_g2g
         *      SET sub_institute_id = '1,2,3,4,5,6,7,8,9,10,11'
         *    WHERE sub_institute_id IS NULL;
         */
    }

    /**
     * Not Schema::hasTable().
     *
     * Laravel 11 introspects with a query selecting `generation_expression`,
     * a column live's MariaDB 10.1 does not have, so that helper throws there
     * while working fine on dev - the difference only shows up against
     * production, which is the database this migration matters most for.
     */
    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }
};
