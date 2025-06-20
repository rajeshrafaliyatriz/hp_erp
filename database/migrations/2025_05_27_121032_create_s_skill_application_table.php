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
        Schema::create('s_skill_application', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('sector')->index()->nullable();
            $table->string('category')->index()->nullable();
            $table->string('skill')->index()->nullable();
            $table->text('description')->nullable();
            $table->string('proficiency_level')->index()->nullable();
            $table->text('proficiency_description')->nullable();
            $table->text('range_application')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('s_skill_application');
    }
};
