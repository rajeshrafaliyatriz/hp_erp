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
        Schema::create('talent_screening_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('talent_job_applications')->onDelete('cascade');
            $table->integer('competency_match')->unsigned();
            $table->enum('cultural_fit', ['High', 'Medium', 'Low']);
            $table->enum('predicted_success', ['Highly Likely', 'Likely', 'Possible', 'Unlikely']);
            $table->integer('overall_fit_score')->unsigned();
            $table->integer('ranking_score')->unsigned();
            $table->longText('skill_gaps');
            $table->longText('strengths');
            $table->text('recommendation');
            $table->longText('deepseek_analysis');
            $table->foreignId('sub_institute_id')->constrained('school_setup');
            $table->bigInteger('created_by')->nullable();
            $table->bigInteger('updated_by')->nullable();
            $table->bigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('talent_screening_results');
    }
};
