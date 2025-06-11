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
        Schema::create('master_skills', function (Blueprint $table) {
            $table->bigIncrements('id');
             $table->string('category', 191)->index()->nullable();
            $table->string('sub_category', 191)->index()->nullable();
            $table->string('title', 191)->index()->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->text('related_skills')->nullable(); // Can store JSON or comma-separated IDs
            $table->text('bussiness_links')->nullable();
            $table->text('custom_tags')->nullable();
            $table->text('proficiency_level')->nullable(); // Can store levels in JSON or text
            $table->text('job_titles')->nullable();
            $table->text('learning_resources')->nullable();
            $table->text('assesment_method')->nullable();
            $table->text('certification_qualifications')->nullable();
            $table->text('experience_project')->nullable();
            $table->text('skill_maps')->nullable(); // Can store mappings in JSON or text
            $table->timestamps(); // created_at & updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_skills');
    }
};
