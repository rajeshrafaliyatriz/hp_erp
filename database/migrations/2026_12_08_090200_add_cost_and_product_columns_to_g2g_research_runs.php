<?php

use App\Support\ColumnExists;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Slice 2 of the agentic CRM (Docs/agentic-crm/slices/S02-signals-front-door.md): what a research run cost, and which product it was for.
 *
 *   product_master_id  the Product Master row the run was for. NULL for runs made before this existed (never guessed).
 *   ai_input_tokens    }  summed from the run's own ai_usage_events rows when the run finishes (RunCost). 0 = the run used no AI;
 *   ai_output_tokens   }  NULL = the run finished before this existed or its cost could not be read. The two are never confused.
 *   ai_cost_usd        }  decimal(10,6), the sum of ai_usage_events.estimated_cost_usd
 *   search_requests    search provider requests the run made
 *   search_credits     provider credits, when the provider reports them; NULL when it does not (unknown, not zero)
 *
 * ADDITIVE, all nullable, idempotent (each column checked first). Nothing existing is altered. `stage_stats` is NOT added here: it has its own
 * earlier migration (2026_12_03_090000), which already ran on the live database.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME, after the owner's go:
 *   php artisan migrate --path=database/migrations/2026_12_08_090200_add_cost_and_product_columns_to_g2g_research_runs.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_08_090200_add_cost_and_product_columns_to_g2g_research_runs.php
 */
return new class extends Migration
{
    private const TABLE = 'g2g_research_runs';

    /** @var array<string, callable(Blueprint): mixed> */
    private function columns(): array
    {
        return [
            'product_master_id' => fn (Blueprint $t) => $t->unsignedBigInteger('product_master_id')->nullable(),
            'ai_input_tokens' => fn (Blueprint $t) => $t->unsignedInteger('ai_input_tokens')->nullable(),
            'ai_output_tokens' => fn (Blueprint $t) => $t->unsignedInteger('ai_output_tokens')->nullable(),
            'ai_cost_usd' => fn (Blueprint $t) => $t->decimal('ai_cost_usd', 10, 6)->nullable(),
            'search_requests' => fn (Blueprint $t) => $t->unsignedSmallInteger('search_requests')->nullable(),
            'search_credits' => fn (Blueprint $t) => $t->decimal('search_credits', 10, 2)->nullable(),
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach ($this->columns() as $column => $define) {
            if (! ColumnExists::has(self::TABLE, $column)) {
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
            if (ColumnExists::has(self::TABLE, $column)) {
                Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
