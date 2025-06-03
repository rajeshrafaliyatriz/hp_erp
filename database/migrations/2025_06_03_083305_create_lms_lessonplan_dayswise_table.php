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
        Schema::create('lms_lessonplan_dayswise', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
            $table->bigInteger('lpid')->index()->nullable();
            $table->integer('days')->nullable();
            $table->date('date')->nullable();
            $table->unsignedBigInteger('period_id')->index()->nullable();
            $table->unsignedBigInteger('teacher_id')->index()->nullable();

            $table->string('topicname', 150)->nullable();
            $table->string('classtime', 50)->nullable();

            $table->text('duringcontent')->nullable();
            $table->text('assessmentqualifying')->nullable();
            $table->text('learningobjective')->nullable();
            $table->text('learningoutcome')->nullable();
            $table->text('pedagogicalprocess')->nullable();
            $table->text('resource')->nullable();
            $table->text('closure')->nullable();
            $table->text('selfstudyhomework')->nullable();

            $table->string('selfstudyactivity', 150)->nullable();
            $table->text('assessment')->nullable();
            $table->string('assessmentactivity', 150)->nullable();
            $table->string('lesson_plan_number', 150)->nullable();

            $table->unsignedBigInteger('createdby')->index()->nullable();
            $table->dateTime('timecreated')->nullable();
            $table->dateTime('created_on')->nullable();
            $table->string('ipaddress')->nullable();

            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

            $table->timestamps();
            $table->softDeletes(); // deleted_at

            // Foreign Keys (NO ACTION on delete/update)
            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->onDelete('NO ACTION')->onUpdate('NO ACTION');
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
        Schema::dropIfExists('lms_lessonplan_dayswise');
    }
};
