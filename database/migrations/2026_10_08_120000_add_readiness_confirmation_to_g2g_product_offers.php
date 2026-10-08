<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Who confirmed an offer's readiness, and when, plus an append-only history of every change.
 *
 * ADDITIVE and guarded (hasTable / SHOW COLUMNS on MySQL and MariaDB, no JSON): two nullable
 * columns on g2g_product_offers and one new table. No offer is confirmed by this migration;
 * existing readiness_confirmed values are left exactly as they are.
 */
return new class extends Migration
{
    /** Schema::hasColumn() breaks on old MariaDB (no generation_expression), so use SHOW COLUMNS there. */
    private function hasColumn(string $table, string $column): bool
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return ! empty(DB::select("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]));
        }

        return Schema::hasColumn($table, $column);
    }

    public function up(): void
    {
        if (Schema::hasTable('g2g_product_offers')) {
            $isMysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
            $original = $isMysql ? (string) DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m : null;

            if ($isMysql) {
                // Same reason as the other Signals migrations: legacy zero-date defaults.
                $relaxed = implode(',', array_filter(explode(',', $original), fn ($m) => ! in_array($m, ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE'], true)));
                DB::statement('SET SESSION sql_mode = ?', [$relaxed]);
            }

            try {
                $missing = [
                    'readiness_confirmed_by' => fn (Blueprint $t) => $t->unsignedBigInteger('readiness_confirmed_by')->nullable(),
                    'readiness_confirmed_at' => fn (Blueprint $t) => $t->dateTime('readiness_confirmed_at')->nullable(),
                ];
                $missing = array_filter($missing, fn ($_, $col) => ! $this->hasColumn('g2g_product_offers', $col), ARRAY_FILTER_USE_BOTH);

                if ($missing !== []) {
                    Schema::table('g2g_product_offers', function (Blueprint $t) use ($missing) {
                        foreach ($missing as $define) {
                            $define($t);
                        }
                    });
                }
            } finally {
                if ($isMysql) {
                    DB::statement('SET SESSION sql_mode = ?', [$original]);
                }
            }
        }

        if (! Schema::hasTable('g2g_offer_readiness_log')) {
            Schema::create('g2g_offer_readiness_log', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('offer_row_id');           // g2g_product_offers.id
                $t->string('offer_id', 50);
                $t->string('action', 20);                         // confirmed | unconfirmed
                $t->string('readiness_status', 100)->nullable();  // the status label at that moment
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->string('note', 1000)->nullable();
                $t->timestamps();

                $t->index(['sub_institute_id', 'offer_row_id'], 'g2g_readiness_log_offer');
            });
        }
    }

    /** Leaves the columns in place: dropping them would erase who confirmed what. */
    public function down(): void
    {
        Schema::dropIfExists('g2g_offer_readiness_log');
    }
};
