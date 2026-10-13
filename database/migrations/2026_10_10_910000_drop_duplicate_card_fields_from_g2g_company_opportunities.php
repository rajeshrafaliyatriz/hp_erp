<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Removes the four columns added by 2026_10_10_900000_add_card_fields_to_g2g_company_opportunities,
 * which duplicated columns that 2026_10_08_100000_extend_g2g_signals_for_market_import already
 * created and that QualificationGate / MarketImporter already write:
 *
 *   what_to_sell      -> what_we_could_sell
 *   buying_trigger    -> trigger_summary
 *   outreach_angle    -> soft_marketing_angle
 *   likely_buyer_role -> likely_stakeholder
 *
 * Two writers per concept leaves no authoritative value, so the researcher will write the
 * existing columns instead.
 *
 * GUARDED: a column is dropped only if it exists AND holds no data (every row NULL or empty).
 * If any row has a value the migration stops with an exception and drops nothing, so a column
 * that something has started writing to is never destroyed silently. Re-running is a no-op.
 */
return new class extends Migration
{
    private const TABLE = 'g2g_company_opportunities';

    private const COLUMNS = ['what_to_sell', 'buying_trigger', 'outreach_angle', 'likely_buyer_role'];

    private function hasColumn(string $column): bool
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return ! empty(DB::select('SHOW COLUMNS FROM `' . self::TABLE . '` LIKE ?', [$column]));
        }

        return Schema::hasColumn(self::TABLE, $column);
    }

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $present = array_values(array_filter(self::COLUMNS, fn ($c) => $this->hasColumn($c)));
        if ($present === []) {
            return;
        }

        // Check every column before dropping any: all or nothing.
        foreach ($present as $column) {
            $used = DB::table(self::TABLE)->whereNotNull($column)->where($column, '<>', '')->count();
            if ($used > 0) {
                throw new \RuntimeException("Refusing to drop `{$column}`: {$used} row(s) already hold data. Move it to the existing column first.");
            }
        }

        Schema::table(self::TABLE, function (Blueprint $t) use ($present) {
            $t->dropColumn($present);
        });
    }

    /** Re-adds the empty columns so a rollback restores the earlier schema. */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $define = [
            'what_to_sell' => fn (Blueprint $t) => $t->text('what_to_sell')->nullable(),
            'buying_trigger' => fn (Blueprint $t) => $t->text('buying_trigger')->nullable(),
            'outreach_angle' => fn (Blueprint $t) => $t->text('outreach_angle')->nullable(),
            'likely_buyer_role' => fn (Blueprint $t) => $t->string('likely_buyer_role', 191)->nullable(),
        ];

        Schema::table(self::TABLE, function (Blueprint $t) use ($define) {
            foreach ($define as $name => $fn) {
                if (! $this->hasColumn($name)) {
                    $fn($t);
                }
            }
        });
    }
};
