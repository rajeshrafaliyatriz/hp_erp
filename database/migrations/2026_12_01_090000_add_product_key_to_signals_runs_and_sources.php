<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which product a research run (and a document or link a person adds) was made for. Additive and nullable: existing rows keep
 * NULL, which means "made before products were recorded", and are never reassigned. Opportunities already carry their product in
 * `business_fit`, so they need no new column.
 */
return new class extends Migration
{
    private const TABLES = ['g2g_research_runs', 'g2g_ingestion_sources'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'product_key')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('product_key', 40)->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'product_key')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('product_key');
                });
            }
        }
    }
};
