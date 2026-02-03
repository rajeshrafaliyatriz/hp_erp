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
        Schema::table('suggested_course', function (Blueprint $table) {
            $table->dropColumn('skill_id');
        });
    }

    public function down(): void
    {
        Schema::table('suggested_course', function (Blueprint $table) {
            $table->integer('skill_id')->nullable();
        });
    }
};
