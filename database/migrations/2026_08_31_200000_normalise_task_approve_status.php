<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One spelling for `task.approve_status`.
 *
 * ── WHAT WAS THERE ──────────────────────────────────────────────────────────
 *
 * Three spellings of two ideas, measured across both databases:
 *
 *   'approved'  'rejected'   — lowercase, the bulk of the data
 *   'PENDING'                — uppercase, 4 rows on live
 *   'Approved'  'Rejected'   — title case, what WorkspaceController::approve wrote
 *
 * Every reader then needed its own case handling, and the ones that forgot
 * silently dropped rows. `userReportController` compared against lowercase
 * 'pending' and so never counted the uppercase rows at all — on top of having its
 * approved and rejected labels transposed.
 *
 * ── LOWERCASE, BECAUSE THAT IS WHAT THE DATA ALREADY IS ─────────────────────
 *
 * Not a preference: the overwhelming majority of existing rows are already
 * lowercase, so this touches the fewest rows and leaves the common case alone.
 * The writer was corrected in the same change.
 *
 * ── WHY down() IS DELIBERATELY A NO-OP ──────────────────────────────────────
 *
 * The original state was three inconsistent spellings applied unevenly across
 * years of rows. There is no faithful reversal — restoring "the previous casing"
 * would mean recording which of three variants each row held, to recreate a
 * defect. Rolling back leaves the data correct, which is the honest outcome.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_31_200000_normalise_task_approve_status.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_31_200000_normalise_task_approve_status.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('task')) {
            return;
        }

        // Only rows that are not already lowercase, so a re-run is a no-op and
        // `updated_at` is not churned across the whole table.
        DB::statement("
            UPDATE `task`
               SET `approve_status` = LOWER(TRIM(`approve_status`))
             WHERE `approve_status` IS NOT NULL
               AND TRIM(`approve_status`) <> ''
               AND BINARY `approve_status` <> BINARY LOWER(TRIM(`approve_status`))
        ");

        // An empty string is not a decision. Collapsing it to NULL means
        // 'no decision yet' has exactly one representation instead of two.
        DB::statement("
            UPDATE `task`
               SET `approve_status` = NULL
             WHERE `approve_status` IS NOT NULL
               AND TRIM(`approve_status`) = ''
        ");
    }

    public function down(): void
    {
        // Intentionally empty — see the class docblock.
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
