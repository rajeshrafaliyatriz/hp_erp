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
             $table->string('sector', 50);
            $table->string('track', 50);
            $table->string('jobrole', 191);
            $table->string('skill', 191);
            $table->string('type', 10);
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
