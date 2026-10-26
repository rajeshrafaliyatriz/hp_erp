<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * PREPARED, NOT RUN. Adds the stage-by-stage accounting of a research run (see App\Domain\Signals\Run\RunAccounting) as nullable text holding JSON.
 * Text, not the JSON type: the live MariaDB 10.1 has no JSON column. The code works without this column (the researcher skips it and the API
 * returns null), so running it is what turns the breakdown on. Additive and idempotent.
 */
return new class extends Migration
{
    // SHOW COLUMNS and plain ALTER on MySQL/MariaDB (Schema::hasColumn() fails on MariaDB 10.1: no information_schema.generation_expression);
    // the portable Schema calls on any other driver (the sqlite test database).
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (empty(DB::select("SHOW TABLES LIKE 'g2g_research_runs'"))) {
                return;   // the table's own migration has not run
            }
            if (empty(DB::select("SHOW COLUMNS FROM `g2g_research_runs` LIKE 'stage_stats'"))) {
                // AFTER stage_message only when that column exists (2026_10_01_130000); otherwise the column is simply appended.
                $after = empty(DB::select("SHOW COLUMNS FROM `g2g_research_runs` LIKE 'stage_message'")) ? '' : ' AFTER `stage_message`';
                DB::statement('ALTER TABLE `g2g_research_runs` ADD COLUMN `stage_stats` TEXT NULL' . $after);
            }

            return;
        }

        if (Schema::hasTable('g2g_research_runs') && ! Schema::hasColumn('g2g_research_runs', 'stage_stats')) {
            Schema::table('g2g_research_runs', function (Blueprint $table) {
                $table->text('stage_stats')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (! empty(DB::select("SHOW COLUMNS FROM `g2g_research_runs` LIKE 'stage_stats'"))) {
                DB::statement('ALTER TABLE `g2g_research_runs` DROP COLUMN `stage_stats`');
            }

            return;
        }

        if (Schema::hasColumn('g2g_research_runs', 'stage_stats')) {
            Schema::table('g2g_research_runs', fn (Blueprint $table) => $table->dropColumn('stage_stats'));
        }
    }
};
