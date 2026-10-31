<?php

use App\Support\ColumnExists;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive run scope. Existing rows remain explicitly legacy/unscoped (NULL offer and scope key). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('g2g_research_runs')) {
            return;
        }
        Schema::table('g2g_research_runs', function (Blueprint $table) {
            if (! ColumnExists::has('g2g_research_runs', 'customer_model')) {
                $table->string('customer_model', 5)->nullable();
            }
            if (! ColumnExists::has('g2g_research_runs', 'offer_id')) {
                $table->string('offer_id', 80)->nullable();
            }
            if (! ColumnExists::has('g2g_research_runs', 'region_scope')) {
                $table->text('region_scope')->nullable();
            }
            if (! ColumnExists::has('g2g_research_runs', 'scope_key')) {
                $table->char('scope_key', 64)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        // Keep run provenance on rollback; dropping scope data would make saved research ambiguous.
    }
};
