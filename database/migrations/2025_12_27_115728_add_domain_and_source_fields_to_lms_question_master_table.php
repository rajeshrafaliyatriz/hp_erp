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
        Schema::table('lms_question_master', function (Blueprint $table) {
            $table->string('domain_category')->nullable();
            $table->string('source_dataset')->nullable();
            $table->string('source_title')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lms_question_master', function (Blueprint $table) {
            $table->dropColumn(['domain_category', 'source_dataset', 'source_title']);
        });
    }
};
