<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * S2 add-on "AI prices in rupees" (Docs/agentic-crm/slices/S02-pricing-contract.md): what a call cost, in the currency it was priced in.
 *
 *   estimated_cost  DECIMAL(14,6) NULL  tokens x the rate card, in cost_currency. NULL = no price was known when the call was made (never 0)
 *   cost_currency   CHAR(3) NULL        the currency of estimated_cost; NULL exactly when estimated_cost is
 *
 * estimated_cost_usd stays and is still written, but only for calls priced in USD: nothing else may read a rupee price as dollars. Rows written
 * before this file ran keep both new columns NULL (a call made before a price existed stays unpriced; it is never repriced afterwards).
 *
 * ADDITIVE, two nullable columns, idempotent (each checked first). Nothing existing is altered. Uses Schema::hasColumn rather than
 * ColumnExists::has on purpose (DB-RULES.md: the bound SHOW COLUMNS form fails on the live MariaDB 10.1). On MariaDB 10.1 an ADD COLUMN copies
 * the table; ai_usage_events is small (the Signals rows alone are 114 on live and 116 on dev, 3 Nov), so that is quick.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME, after the owner's go (live: after the db3 scratch-table rehearsal in DB-RULES.md):
 *   php artisan migrate --path=database/migrations/2026_12_08_100100_add_cost_columns_to_ai_usage_events.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_08_100100_add_cost_columns_to_ai_usage_events.php
 */
return new class extends Migration
{
    private const TABLE = 'ai_usage_events';

    /** @var array<string, callable(Blueprint): mixed> */
    private function columns(): array
    {
        return [
            'estimated_cost' => fn (Blueprint $t) => $t->decimal('estimated_cost', 14, 6)->nullable()->after('estimated_cost_usd'),
            'cost_currency' => fn (Blueprint $t) => $t->char('cost_currency', 3)->nullable()->after('estimated_cost'),
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
