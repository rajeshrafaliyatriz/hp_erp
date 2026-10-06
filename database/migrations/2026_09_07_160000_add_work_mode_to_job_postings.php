<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHERE the work happens - On-site, Hybrid or Remote.
 *
 * ── WHY IT IS NOT A NEW employment_type ─────────────────────────────────────
 *
 * `employment_type` already holds Full-Time (38), Part-Time (32), Contract (29)
 * and Internship (27) on the live host. Adding 'Remote' to that list would have
 * made a remote internship unrepresentable - a role has a contract AND a place,
 * and folding two questions into one column loses whichever is asked second.
 *
 * ── VARCHAR AND A PHP CONST, NEVER ENUM ─────────────────────────────────────
 *
 * Live is MariaDB 10.1. Adding a fourth mode to an ENUM is an ALTER on a live
 * table; adding it to a const is a deploy.
 *
 * ── NULLABLE ────────────────────────────────────────────────────────────────
 *
 * All 128 existing postings get NULL, which reads as "not stated" rather than
 * as On-site. Guessing on their behalf would put a claim on a job advert that
 * nobody made.
 */
return new class extends Migration
{
    private const TABLE = 'talent_job_postings';
    private const COLUMN = 'work_mode';

    private function columnExists(string $table, string $column): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        )->c > 0;
    }

    public function up(): void
    {
        if ($this->columnExists(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            // 20 is comfortably over the longest value ('On-site' is 7) and
            // leaves room for a mode nobody has thought of yet.
            $table->string(self::COLUMN, 20)->nullable()->after('employment_type');
        });
    }

    /** Refuses to drop the column while any posting states a work mode. */
    public function down(): void
    {
        if (!$this->columnExists(self::TABLE, self::COLUMN)) {
            return;
        }

        $inUse = DB::table(self::TABLE)->whereNotNull(self::COLUMN)->count();

        if ($inUse > 0) {
            throw new RuntimeException(
                $inUse . ' posting(s) state a work mode. Dropping ' . self::COLUMN
                . ' would silently remove "Remote" from adverts that promise it. '
                . 'Clear the column deliberately first if this is really intended.'
            );
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn(self::COLUMN);
        });
    }
};
