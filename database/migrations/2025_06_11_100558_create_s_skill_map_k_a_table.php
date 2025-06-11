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
        Schema::create('s_skill_map_k_a', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tsc_ccs_type', 10)->index()->nullable();
            $table->string('tsc_ccs_code', 25)->index()->nullable();
            $table->string('sector', 25)->index()->nullable();
            $table->string('tsc_ccs_category', 50)->index()->nullable();
            $table->string('tsc_ccs_title', 191)->index()->nullable();
            $table->text('tsc_ccs_description')->nullable();
            $table->string('proficiency_level', 20)->index()->nullable();
            $table->text('proficiency_description')->nullable();
            $table->string('knowledge_ability_classification', 10)->index()->nullable();
            $table->text('knowledge_ability_items')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('s_skill_map_k_a');
    }
};
