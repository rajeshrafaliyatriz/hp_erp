<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Signals, ranked feed: the card fields the researcher must now emit.
 *
 * ADDITIVE ONLY and idempotent. Nothing is dropped, renamed or backfilled:
 *   g2g_company_opportunities += what_to_sell, buying_trigger, outreach_angle, likely_buyer_role
 *
 * `entry_point` (string 30) is NOT added here: 2026_10_08_100000_extend_g2g_signals_for_market_import
 * already creates it. The allowed values (discovery, pilot, poc, paid_assessment, workshop,
 * si_partnership, rfp_response) are validated in code, not by a database ENUM, so the list can
 * change without an ALTER.
 *
 * Same guards as the migration above it: SHOW COLUMNS (Schema::hasColumn() breaks on old
 * MariaDB), and the session's zero-date sql_mode modes are relaxed only while this runs, because
 * g2g_company_opportunities carries legacy `0000-00-00` timestamp defaults that strict mode
 * refuses to ALTER.
 */
return new class extends Migration
{
    private const TABLE = 'g2g_company_opportunities';

    private function isMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function hasColumn(string $column): bool
    {
        if ($this->isMysql()) {
            return ! empty(DB::select('SHOW COLUMNS FROM `' . self::TABLE . '` LIKE ?', [$column]));
        }

        return Schema::hasColumn(self::TABLE, $column);
    }

    private function setSqlMode(string $modes): void
    {
        if (preg_match('/^[A-Z_,]*$/', $modes) !== 1) {
            throw new \RuntimeException('Unexpected sql_mode value; refusing to change it.');
        }

        DB::unprepared("SET SESSION sql_mode = '{$modes}'");
    }

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $columns = [
            'what_to_sell' => fn (Blueprint $t) => $t->text('what_to_sell')->nullable(),
            'buying_trigger' => fn (Blueprint $t) => $t->text('buying_trigger')->nullable(),
            'outreach_angle' => fn (Blueprint $t) => $t->text('outreach_angle')->nullable(),
            'likely_buyer_role' => fn (Blueprint $t) => $t->string('likely_buyer_role', 191)->nullable(),
        ];
        $missing = array_filter($columns, fn ($define, $name) => ! $this->hasColumn($name), ARRAY_FILTER_USE_BOTH);

        if ($missing === []) {
            return;
        }

        $original = null;
        if ($this->isMysql()) {
            $original = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m;
            $this->setSqlMode(implode(',', array_filter(explode(',', $original), fn ($m) => ! in_array($m, ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE'], true))));
        }

        try {
            Schema::table(self::TABLE, function (Blueprint $t) use ($missing) {
                foreach ($missing as $define) {
                    $define($t);
                }
            });
        } finally {
            if ($this->isMysql()) {
                $this->setSqlMode((string) $original);
            }
        }
    }

    /** Left in place on purpose: dropping would destroy generated content (see the 10_08 migration). */
    public function down(): void
    {
    }
};
