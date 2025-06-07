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
        Schema::create('lms_lesson_plan', function (Blueprint $table) {
              $table->bigIncrements('id');

            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
            $table->string('syear', 50)->index()->nullable();
            $table->unsignedBigInteger('standard_id')->index()->nullable();
            $table->unsignedBigInteger('subject_id')->index()->nullable();
            $table->unsignedBigInteger('chapter_id')->index()->nullable();
            $table->unsignedBigInteger('topic_id')->nullable();

            $table->bigInteger('numberofperiod')->nullable();
            $table->string('teachingtime', 50)->nullable();
            $table->string('assessmenttime', 50)->nullable();
            $table->string('learningtime', 50)->nullable();

            $table->text('assessmentqualifying')->nullable();
            $table->text('focauspoint')->nullable();
            $table->text('innovativepadagogy')->nullable();
            $table->text('pedagogicalprocess')->nullable();
            $table->text('resource')->nullable();
            $table->text('classroompresentation')->nullable();
            $table->string('classroomactivity', 150)->nullable();
            $table->text('classroomdiversity')->nullable();
            $table->text('prerequisite')->nullable();
            $table->text('learningobjective')->nullable();
            $table->text('learningknowledge')->nullable();
            $table->text('learningskill')->nullable();
            $table->text('selfstudyhomework')->nullable();
            $table->string('selfstudyactivity', 150)->nullable();
            $table->text('assessment')->nullable();
            $table->string('assessmentactivity', 150)->nullable();
            $table->text('marks')->nullable();
            $table->text('assessmentquetions')->nullable();

            $table->string('hardword', 50)->nullable();
            $table->string('tagmetatag', 50)->nullable();
            $table->string('valueintegration', 50)->nullable();
            $table->string('globalconnection', 50)->nullable();
            $table->string('crosscurriculum', 50)->nullable();
            $table->string('sel', 50)->nullable();
            $table->string('stem', 50)->nullable();
            $table->string('vocationaltraining', 50)->nullable();
            $table->string('simulation', 50)->nullable();
            $table->string('games', 50)->nullable();
            $table->string('activities', 50)->nullable();
            $table->string('reallifeapplication', 50)->nullable();
            $table->string('lesson_plan_number', 50)->nullable();
            $table->text('mapping_value')->nullable();

            $table->unsignedBigInteger('createdby')->nullable();
            $table->dateTime('timecreated')->nullable();
            $table->dateTime('updated_on')->nullable();
            $table->string('ipaddress', 50)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->softDeletes(); // includes deleted_at

            // Foreign keys with NO ACTION
            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('standard_id')->references('id')->on('standard')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('subject_id')->references('id')->on('subject')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('chapter_id')->references('id')->on('chapter_master')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('topic_id')->references('id')->on('topic_master')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('createdby')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('updated_by')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('deleted_by')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lms_lesson_plan');
    }
};
