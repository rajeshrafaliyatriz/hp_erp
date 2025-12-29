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
        Schema::table('talent_evaluation_form', function (Blueprint $table) {
            $table->dropColumn('evaluation_criteria');
            $table->json('evaluation_criteria');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('talent_evaluation_form', function (Blueprint $table) {
            $table->dropColumn('evaluation_criteria');
            $table->string('evaluation_criteria', 50);
        });
    }
};
