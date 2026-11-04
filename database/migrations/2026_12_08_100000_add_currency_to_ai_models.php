<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * S2 add-on "AI prices in rupees" (Docs/agentic-crm/slices/S02-pricing-contract.md): the rate card gets a currency.
 *
 *   currency  CHAR(3) NOT NULL DEFAULT 'INR'  the currency of input_cost_per_1k and output_cost_per_1k on this row
 *
 * Every rate in ai_models is NULL on both hosts today, so defaulting the existing rows to INR relabels no real price. Until this column exists
 * AiUsageMeter reads a rate as US dollars, exactly as before, so the code is safe on a host that has not run this file yet.
 *
 * ADDITIVE, one column, idempotent (checked first). Nothing existing is altered. Uses Schema::hasColumn rather than ColumnExists::has on purpose
 * (DB-RULES.md: the bound SHOW COLUMNS form fails on the live MariaDB 10.1; AppServiceProvider's legacy grammar makes hasColumn safe there).
 * Run the three 2026_12_08_1000xx files together: a rate that is in rupees can only be recorded once ai_usage_events has its cost columns too.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME, after the owner's go (live: after the db3 scratch-table rehearsal in DB-RULES.md):
 *   php artisan migrate --path=database/migrations/2026_12_08_100000_add_currency_to_ai_models.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_08_100000_add_currency_to_ai_models.php
 */
return new class extends Migration
{
    private const TABLE = 'ai_models';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, 'currency')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->char('currency', 3)->default('INR')->after('output_cost_per_1k');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'currency')) {
            return;
        }

        Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn('currency'));
    }
};
