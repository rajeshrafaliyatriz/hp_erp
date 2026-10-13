<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Schema::hasColumn() selects information_schema.columns.generation_expression,
    // which MariaDB 10.1 (the hp_erp server) does not have, so MySQL/MariaDB keep using
    // SHOW COLUMNS. SHOW COLUMNS is MySQL-only SQL, though: on any other driver (the sqlite
    // test database) the portable Schema::hasColumn() is used instead.
    private function hasColumn(string $table, string $column): bool
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return Schema::hasColumn($table, $column);
        }

        return ! empty(DB::select("SHOW COLUMNS FROM `{$table}` LIKE ?", [$column]));
    }

    public function up(): void
    {
        if (Schema::hasTable('g2g_research_runs')) {
            $needsStage = ! $this->hasColumn('g2g_research_runs', 'stage');
            $needsMessage = ! $this->hasColumn('g2g_research_runs', 'stage_message');

            Schema::table('g2g_research_runs', function (Blueprint $table) use ($needsStage, $needsMessage) {
                if ($needsStage) {
                    $table->string('stage', 50)->nullable()->after('status');
                }
                if ($needsMessage) {
                    $table->string('stage_message', 255)->nullable()->after('stage');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('g2g_research_runs')) {
            $dropMessage = $this->hasColumn('g2g_research_runs', 'stage_message');
            $dropStage = $this->hasColumn('g2g_research_runs', 'stage');

            Schema::table('g2g_research_runs', function (Blueprint $table) use ($dropMessage, $dropStage) {
                if ($dropMessage) {
                    $table->dropColumn('stage_message');
                }
                if ($dropStage) {
                    $table->dropColumn('stage');
                }
            });
        }
    }
};
