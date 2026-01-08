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
            $table->text('recommendation')->nullable()->change();
            $table->text('key_strengths')->nullable()->change();
            $table->text('areas_of_concern')->nullable()->change();
            $table->text('additional_comments')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('talent_evaluation_form', function (Blueprint $table) {
            $table->string('recommendation')->change();
            $table->string('key_strengths')->change();
            $table->string('areas_of_concern')->change();
            $table->string('additional_comments')->change();
        });
    }
};
