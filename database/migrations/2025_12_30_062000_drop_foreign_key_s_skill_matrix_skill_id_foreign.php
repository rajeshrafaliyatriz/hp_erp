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
        Schema::table('s_skill_matrix', function (Blueprint $table) {
            $table->dropForeign('s_skill_matrix_skill_id_foreign');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('s_skill_matrix', function (Blueprint $table) {
            $table->foreign('skill_id')->references('id')->on('s_users_skills');
        });
    }
};
