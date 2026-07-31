<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('talent_offboarding_cases', function (Blueprint $table) {
            $table->json('clearance_tasks')->nullable();
            $table->json('documents')->nullable();
            $table->json('comments')->nullable();
            $table->json('activity_log')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('talent_offboarding_cases', function (Blueprint $table) {
            $table->dropColumn(['clearance_tasks', 'documents', 'comments', 'activity_log']);
        });
    }
};
