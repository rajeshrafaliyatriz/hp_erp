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
        Schema::create('s_jobrole_skills', function (Blueprint $table) {
            $table->bigIncrements('id');
             $table->string('sector', 50)->index()->nullable();
            $table->string('track', 50)->index()->nullable();
            $table->string('jobrole', 191)->index()->nullable();
            $table->string('skill', 191)->index()->nullable();
            $table->string('type', 10)->index()->nullable();
            $table->string('proficiency_level', 20)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('s_jobrole_skills');
    }
};
