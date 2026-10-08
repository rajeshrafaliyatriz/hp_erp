<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * g2g_company_opportunities.first_discovered_at must never change after a row is created.
 *
 * The original migration declared it with useCurrent(). On MySQL/MariaDB the FIRST
 * `NOT NULL DEFAULT CURRENT_TIMESTAMP` column of a table also receives `ON UPDATE
 * current_timestamp()` (explicit_defaults_for_timestamp = OFF), so any UPDATE that changes
 * a value in the row, such as a review/dismiss click, a research re-verification, or a market
 * import update, silently rewrote the discovery date and reordered the feed.
 * (Reproduced on a copy of the real database: review_status -> 'Reviewed' moved it to "now".)
 *
 * This removes only the ON UPDATE clause. Values are preserved (MODIFY keeps the data), the
 * default for new rows stays CURRENT_TIMESTAMP, and the migration is a no-op when the clause is
 * already gone, on sqlite, and when run twice.
 *
 * The session drops NO_ZERO_DATE while it runs: this table's last_verified_at has a legacy
 * `DEFAULT '0000-00-00 00:00:00'` that strict mode refuses to ALTER. The mode is restored.
 */
return new class extends Migration
{
    private function hasOnUpdate(): bool
    {
        $row = DB::select('SHOW COLUMNS FROM `g2g_company_opportunities` LIKE ?', ['first_discovered_at'])[0] ?? null;

        return $row !== null && stripos((string) $row->Extra, 'on update') !== false;
    }

    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            || ! Schema::hasTable('g2g_company_opportunities')
            || ! $this->hasOnUpdate()) {
            return;
        }

        $original = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m;
        $relaxed = implode(',', array_filter(explode(',', $original), fn ($m) => ! in_array($m, ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE'], true)));
        $this->setSqlMode($relaxed);

        try {
            DB::statement('ALTER TABLE `g2g_company_opportunities` MODIFY `first_discovered_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        } finally {
            $this->setSqlMode($original);
        }
    }

    /** A literal, not a bound parameter (a prepared SET is unverified on MariaDB 10.1); see the sibling migration. */
    private function setSqlMode(string $modes): void
    {
        if (preg_match('/^[A-Z_,]*$/', $modes) !== 1) {
            throw new \RuntimeException('Unexpected sql_mode value; refusing to change it.');
        }

        DB::unprepared("SET SESSION sql_mode = '{$modes}'");
    }

    /** Restoring an accidental auto-update would reintroduce the bug, so there is nothing to undo. */
    public function down(): void
    {
    }
};
