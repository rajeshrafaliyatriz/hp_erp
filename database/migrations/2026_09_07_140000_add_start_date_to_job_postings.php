<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The date applications OPEN, beside the date they close.
 *
 * ── WHY IT WAS MISSING ──────────────────────────────────────────────────────
 *
 * `talent_job_postings` had `deadline` and nothing else. The job-opening detail
 * sheet has rendered `DetailField label="Opening Date" value={job.start_date}`
 * for as long as it has existed, against a column that was never there - so the
 * field was permanently blank, and the create and edit forms had no way to offer
 * it. A closing date with no opening date is half an application window.
 *
 * ── NULLABLE ON PURPOSE ─────────────────────────────────────────────────────
 *
 * All 127 existing postings get NULL, which the careers page treats as "already
 * open". Nothing that is public today stops being public because this column
 * arrived.
 *
 * ── LIVE IS MariaDB 10.1 ────────────────────────────────────────────────────
 *
 * A plain nullable DATE: no default, no index, no json, no ENUM. The table holds
 * 127 rows across the whole installation, so the ALTER is instant on both hosts.
 */
return new class extends Migration
{
    private const TABLE = 'talent_job_postings';
    private const COLUMN = 'start_date';

    /** Schema::hasColumn() is unreliable on the 10.1 host; information_schema is not. */
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
            $table->date(self::COLUMN)->nullable()->after('positions');
        });
    }

    /**
     * Refuses to drop the column while any posting is using it.
     *
     * Dropping it would silently republish every role that was deliberately
     * scheduled for a future opening date.
     */
    public function down(): void
    {
        if (!$this->columnExists(self::TABLE, self::COLUMN)) {
            return;
        }

        $inUse = DB::table(self::TABLE)->whereNotNull(self::COLUMN)->count();

        if ($inUse > 0) {
            throw new RuntimeException(
                $inUse . ' posting(s) have an opening date set. Dropping ' . self::COLUMN
                . ' would republish any that are scheduled for the future. '
                . 'Clear the column deliberately first if this is really intended.'
            );
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn(self::COLUMN);
        });
    }
};
