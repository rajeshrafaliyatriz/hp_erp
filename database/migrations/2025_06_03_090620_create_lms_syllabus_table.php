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
        Schema::create('lms_syllabus', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
            $table->unsignedBigInteger('grade_id')->index()->nullable();
            $table->unsignedBigInteger('standard_id')->index()->nullable();
            $table->unsignedBigInteger('subject_id')->index()->nullable();
            $table->unsignedBigInteger('curriculum_id')->index()->nullable();

            $table->string('title', 191)->index()->nullable();
            $table->mediumText('objectives')->nullable();
            $table->mediumText('learning_outcomes')->nullable();
            $table->mediumText('suggested_materials')->nullable();
            $table->mediumText('assessment_plan')->nullable();
            $table->decimal('progress_tracking', 5, 2)->nullable();

             $table->timestamps();
            $table->softDeletes(); // deleted_at

            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

            // Foreign keys with NO ACTION on delete/update
            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('grade_id')->references('id')->on('academic_section')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('standard_id')->references('id')->on('standard')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('subject_id')->references('id')->on('subject')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('curriculum_id')->references('id')->on('lms_curriculum')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('created_by')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('updated_by')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('deleted_by')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lms_syllabus');
    }
};
