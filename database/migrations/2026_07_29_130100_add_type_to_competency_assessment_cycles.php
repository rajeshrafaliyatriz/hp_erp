<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive `type` column on s_competency_assessment_cycles.
 *
 * The Assessment Workspace's campaigns table has always shown an "Assessment
 * Type" column, but AssessmentCycleController::index() hardcoded it to
 * 'Self + Manager' because the table had nowhere to store it. The campaign
 * detail panel's "Edit Campaign" action needs a real field to edit, so the
 * hardcoded display value becomes a real, per-campaign column.
 *
 * Nullable with no default: existing rows read NULL and the controller falls
 * back to 'Self + Manager', so the campaigns table renders exactly as before
 * until someone edits a campaign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('s_competency_assessment_cycles', function (Blueprint $table) {
            if (!Schema::hasColumn('s_competency_assessment_cycles', 'type')) {
                $table->string('type', 100)->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('s_competency_assessment_cycles', function (Blueprint $table) {
            if (Schema::hasColumn('s_competency_assessment_cycles', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
