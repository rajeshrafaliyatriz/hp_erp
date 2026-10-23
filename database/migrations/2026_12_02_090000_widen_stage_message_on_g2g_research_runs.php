<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Schema::hasColumn() and ->change() select information_schema.columns.generation_expression, which MariaDB 10.1
    // (the hp_erp server) does not have, so MySQL/MariaDB use SHOW COLUMNS and a plain ALTER. Other drivers (the sqlite
    // test database) use the portable Schema calls.
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            if (! empty(DB::select("SHOW COLUMNS FROM `g2g_research_runs` LIKE 'stage_message'"))) {
                DB::statement('ALTER TABLE `g2g_research_runs` MODIFY `stage_message` TEXT NULL');
            }

            return;
        }

        if (Schema::hasTable('g2g_research_runs') && Schema::hasColumn('g2g_research_runs', 'stage_message')) {
            Schema::table('g2g_research_runs', function ($table) {
                $table->text('stage_message')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Widening is not reversed: narrowing could truncate or reject existing rows.
    }
};
