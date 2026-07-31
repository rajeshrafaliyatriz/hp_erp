<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('talent_offboarding_cases', function (Blueprint $table) {
            if (!Schema::hasColumn('talent_offboarding_cases', 'clearance_tasks')) {
                $table->json('clearance_tasks')->nullable();
            }
            if (!Schema::hasColumn('talent_offboarding_cases', 'documents')) {
                $table->json('documents')->nullable();
            }
            if (!Schema::hasColumn('talent_offboarding_cases', 'comments')) {
                $table->json('comments')->nullable();
            }
            if (!Schema::hasColumn('talent_offboarding_cases', 'activity_log')) {
                $table->json('activity_log')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('talent_offboarding_cases', function (Blueprint $table) {
            $table->dropColumn(['clearance_tasks', 'documents', 'comments', 'activity_log']);
        });
    }
};
