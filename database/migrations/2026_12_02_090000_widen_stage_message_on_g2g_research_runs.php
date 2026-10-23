<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('g2g_research_runs') && Schema::hasColumn('g2g_research_runs', 'stage_message')) {
            Schema::table('g2g_research_runs', function (Blueprint $table) {
                $table->text('stage_message')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Widening is not reversed: narrowing could truncate or reject existing rows.
    }
};
