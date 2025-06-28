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
        Schema::create('s_level_responsibility', function (Blueprint $table) {
            $table->id();
            $table->string('level', 10);
            $table->string('guiding_phrase', 50);
            $table->text('essence_level')->nullable();
            $table->text('guidance_notes')->nullable();
            $table->string('attribute_code', 10)->nullable();
            $table->string('attribute_name', 50)->nullable();
            $table->string('attribute_type', 50)->nullable();
            $table->text('attribute_overall_description')->nullable();
            $table->text('attribute_guidance_notes')->nullable();
            $table->text('attribute_description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('s_level_responsibility');
    }
};
