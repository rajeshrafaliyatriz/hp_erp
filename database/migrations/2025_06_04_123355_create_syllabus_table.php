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
        Schema::create('syllabus', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('syear', 50)->nullable();

            $table->unsignedBigInteger('standard_id')->nullable()->index();
            $table->unsignedBigInteger('grade_id')->nullable()->index();
            $table->unsignedBigInteger('subject_id')->nullable()->index();
            $table->unsignedBigInteger('division_id')->nullable()->index(); // from your FK requirement
            $table->unsignedBigInteger('curriculum_id')->nullable()->index();
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();

            $table->integer('no_of_days')->nullable();
            $table->integer('no_of_periods')->nullable();
            $table->string('types')->nullable();
            $table->string('months')->nullable();

            $table->mediumText('title')->nullable();
            $table->mediumText('message')->nullable();
            $table->mediumText('assesment_tool')->nullable();
            $table->mediumText('objectives')->nullable();
            $table->mediumText('learning_outcomes')->nullable();
            $table->mediumText('assessment_plan')->nullable();
            $table->mediumText('suggested_materials')->nullable();

            $table->string('file_name')->nullable();
            $table->date('date_')->nullable();
            $table->decimal('progress_tracking', 5, 2)->nullable();

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->unsignedBigInteger('deleted_by')->nullable()->index();

            $table->timestamps();
            $table->softDeletes(); // includes deleted_at

            // Foreign keys with NO ACTION on delete and update
            $table->foreign('standard_id')->references('id')->on('standard')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('grade_id')->references('id')->on('academic_section')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('subject_id')->references('id')->on('subject')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->onDelete('NO ACTION')->onUpdate('NO ACTION');

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
        Schema::dropIfExists('syllabus');
    }
};
