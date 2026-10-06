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
        Schema::create('talent_exit_interviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('case_id')->index();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('interviewer_id')->nullable();
            $table->date('interview_date')->nullable();
            $table->string('status', 30)->default('scheduled');
            $table->integer('feedback_rating')->nullable();
            $table->text('reason_for_leaving')->nullable();
            $table->text('positive_aspects')->nullable();
            $table->text('areas_for_improvement')->nullable();
            $table->boolean('would_recommend')->nullable();
            $table->boolean('rehire_eligibility')->nullable();
            $table->text('comments')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('talent_exit_interviews');
    }
};
