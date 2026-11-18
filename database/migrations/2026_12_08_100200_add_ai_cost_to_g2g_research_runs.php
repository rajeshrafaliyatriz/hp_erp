<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * S2 add-on "AI prices in rupees" (Docs/agentic-crm/slices/S02-pricing-contract.md): what a research run's AI calls cost, with the currency.
 *
 *   ai_cost           DECIMAL(14,4) NULL  the sum of the run's ai_usage_events.estimated_cost. 0 = the run used no AI. NULL = unknown (a call that
 *                                         used tokens has no price, or the calls were priced in two currencies)
 *   ai_cost_currency  CHAR(3) NULL        the currency of ai_cost; NULL exactly when ai_cost is
 *
 * ai_cost_usd (migration 2026_12_08_090200) stays: dropping a column is not additive. RunCost simply stops writing it, so it keeps whatever it
 * holds today (NULL on every run), and nothing reads it any more.
 *
 * ADDITIVE, two nullable columns, idempotent (each checked first). Nothing existing is altered. Uses Schema::hasColumn rather than
 * ColumnExists::has on purpose (DB-RULES.md: the bound SHOW COLUMNS form fails on the live MariaDB 10.1).
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME, after the owner's go (live: after the db3 scratch-table rehearsal in DB-RULES.md):
 *   php artisan migrate --path=database/migrations/2026_12_08_100200_add_ai_cost_to_g2g_research_runs.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_08_100200_add_ai_cost_to_g2g_research_runs.php
 */
return new class extends Migration
{
    private const TABLE = 'g2g_research_runs';

    /** @var array<string, callable(Blueprint): mixed> */
    private function columns(): array
    {
        return [
            'ai_cost' => fn (Blueprint $t) => $t->decimal('ai_cost', 14, 4)->nullable(),
            'ai_cost_currency' => fn (Blueprint $t) => $t->char('ai_cost_currency', 3)->nullable(),
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach ($this->columns() as $column => $define) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, fn (Blueprint $t) => $define($t));
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (array_reverse(array_keys($this->columns())) as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
