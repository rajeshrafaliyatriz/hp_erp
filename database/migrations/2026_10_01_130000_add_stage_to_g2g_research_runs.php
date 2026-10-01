<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('g2g_research_runs')) {
            Schema::table('g2g_research_runs', function (Blueprint $table) {
                if (! Schema::hasColumn('g2g_research_runs', 'stage')) {
                    $table->string('stage', 50)->nullable()->after('status');
                }
                if (! Schema::hasColumn('g2g_research_runs', 'stage_message')) {
                    $table->string('stage_message', 255)->nullable()->after('stage');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('g2g_research_runs')) {
            Schema::table('g2g_research_runs', function (Blueprint $table) {
                if (Schema::hasColumn('g2g_research_runs', 'stage_message')) {
                    $table->dropColumn('stage_message');
                }
                if (Schema::hasColumn('g2g_research_runs', 'stage')) {
                    $table->dropColumn('stage');
                }
            });
        }
    }
};

