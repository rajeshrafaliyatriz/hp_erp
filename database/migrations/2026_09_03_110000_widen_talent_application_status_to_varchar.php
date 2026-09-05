<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * talent_job_applications.status: ENUM -> VARCHAR(30).
 *
 *   php artisan migrate --database=mysql --path=database/migrations/2026_09_03_110000_widen_talent_application_status_to_varchar.php
 *   php artisan migrate --database=live  --path=database/migrations/2026_09_03_110000_widen_talent_application_status_to_varchar.php
 *
 * ── WHY ─────────────────────────────────────────────────────────────────────
 *
 * The recruitment kanban has six columns. Two of them could never work:
 * dragging a candidate to Assessment writes status 'Assessment' and dragging to
 * Offer writes 'Offered', and neither value was in the ENUM or in the
 * controller's `in:` rule. Two of six columns were decoration.
 *
 * The house rule is VARCHAR + a PHP const, never ENUM, precisely so adding a
 * value later is a code change rather than an ALTER TABLE rebuild on live. This
 * migration is that rebuild, done once, so it is the last one.
 *
 * ── THE PART THAT MAKES THIS URGENT ─────────────────────────────────────────
 *
 * The two databases do not agree about what an invalid write means:
 *
 *   application DB (202.47.117.220)  sql_mode = STRICT_TRANS_TABLES,...  -> error
 *   live          (128.199.17.97)    sql_mode = NO_ENGINE_SUBSTITUTION   -> silently ''
 *
 * So the same drag that raises a 500 on one host writes an empty string on the
 * other. Both hosts currently hold 58 applications with status = '' - rows that
 * match no filter and appear in no kanban column. Those rows are NOT repaired
 * here: rewriting production data is a decision for the product owner, not a
 * side effect of a schema migration. The column is widened so the repair is
 * possible; the repair itself is deliberately left out.
 *
 * Values kept exactly as they were - this widens the type, it does not rename
 * anything. talent_jobapplicationcontroller::STATUSES is the vocabulary now.
 */
return new class extends Migration
{
    private const TABLE = 'talent_job_applications';

    public function up(): void
    {
        if (!$this->isEnum()) {
            return;
        }

        DB::statement(
            'ALTER TABLE `' . self::TABLE . '`
             MODIFY `status` VARCHAR(30) NULL DEFAULT "Pending Review"'
        );
    }

    public function down(): void
    {
        if ($this->isEnum()) {
            return;
        }

        // Reinstating the ENUM would truncate any row now holding Assessment or
        // Offered, so those are folded back to their nearest legal predecessor
        // first. Down is lossy by nature here; it is written so it does not fail.
        DB::table(self::TABLE)->where('status', 'Assessment')->update(['status' => 'Shortlisted']);
        DB::table(self::TABLE)->where('status', 'Offered')->update(['status' => 'Interview Scheduled']);

        DB::statement(
            'ALTER TABLE `' . self::TABLE . '`
             MODIFY `status` ENUM("Pending Review","Under Review","Shortlisted","Interview Scheduled","Rejected","Hired","Completed")
             NULL DEFAULT "Pending Review"'
        );
    }

    /** Schema::hasTable()/getColumnType() throw on live; read the catalogue. */
    private function isEnum(): bool
    {
        $type = DB::selectOne(
            'SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [self::TABLE, 'status']
        );

        return $type && str_starts_with(strtolower((string) $type->t), 'enum');
    }
};
