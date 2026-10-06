<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Bind an assessment campaign to the framework it assesses against.
 *
 * Every row in s_competency_assessments already carries a framework_id (140 of
 * 140 on the reference tenant), but the campaign that groups them did not - so
 * the workspace could neither show nor set which framework a campaign runs on,
 * and a campaign's competency set was implicit in whatever its assessments
 * happened to reference.
 *
 * Nullable, because campaigns created before this had no framework and back-
 * filling a guess would be worse than an honest null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('s_competency_assessment_cycles')) {
            return;
        }

        if (!Schema::hasColumn('s_competency_assessment_cycles', 'framework_id')) {
            Schema::table('s_competency_assessment_cycles', function (Blueprint $table) {
                $table->unsignedBigInteger('framework_id')->nullable()->after('type');
                $table->index('framework_id', 's_competency_assessment_cycles_framework_id_index');
            });
        }

        // Where every assessment in a campaign already agrees on one framework,
        // that is the campaign's framework - no guessing involved.
        $resolved = DB::table('s_competency_assessments')
            ->whereNotNull('cycle_id')
            ->whereNotNull('framework_id')
            ->whereNull('deleted_at')
            ->select('cycle_id', DB::raw('COUNT(DISTINCT framework_id) as variants'), DB::raw('MIN(framework_id) as framework_id'))
            ->groupBy('cycle_id')
            ->having('variants', '=', 1)
            ->get();

        foreach ($resolved as $row) {
            DB::table('s_competency_assessment_cycles')
                ->where('id', $row->cycle_id)
                ->whereNull('framework_id')
                ->update(['framework_id' => $row->framework_id]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('s_competency_assessment_cycles')) {
            return;
        }

        if (Schema::hasColumn('s_competency_assessment_cycles', 'framework_id')) {
            Schema::table('s_competency_assessment_cycles', function (Blueprint $table) {
                $table->dropIndex('s_competency_assessment_cycles_framework_id_index');
                $table->dropColumn('framework_id');
            });
        }
    }
};
