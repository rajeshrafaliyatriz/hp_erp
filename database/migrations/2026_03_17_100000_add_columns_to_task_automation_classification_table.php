<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('task_automation_classification', function (Blueprint $table) {
            $table->text('recommended_automation_flow')->nullable()->after('final_classification');
            $table->text('override_reason')->nullable()->after('recommended_automation_flow');
            $table->text('reasoning')->nullable()->after('override_reason');
            $table->text('human_intervention_points')->nullable()->after('reasoning');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_automation_classification', function (Blueprint $table) {
            $table->dropColumn([
                'recommended_automation_flow',
                'override_reason',
                'reasoning',
                'human_intervention_points',
            ]);
        });
    }
};
