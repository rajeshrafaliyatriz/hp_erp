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
        Schema::table('talent_interview_schedules', function (Blueprint $table) {
            $table->unsignedBigInteger('panel_id')->nullable()->after('id');
            $table->foreign('panel_id')->references('id')->on('talent_Interview_Panel')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('talent_interview_schedules', function (Blueprint $table) {
            $table->dropForeign(['panel_id']);
            $table->dropColumn('panel_id');
        });
    }
};
