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
        Schema::create('lms_offline_exam_answer', function (Blueprint $table) {
             $table->bigIncrements('id');

            $table->unsignedBigInteger('question_paper_id')->index()->nullable();
            $table->unsignedBigInteger('offline_exam_id')->index()->nullable();
            $table->unsignedBigInteger('employee_id')->index()->nullable();
            $table->unsignedBigInteger('question_id')->index()->nullable();
            $table->string('ans_status', 50)->index()->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->softDeletes(); // adds deleted_at

            // Foreign Keys
            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('question_paper_id')->references('id')->on('question_paper')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('offline_exam_id')->references('id')->on('lms_offline_exam')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('employee_id')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('question_id')->references('id')->on('lms_question_master')->onDelete('NO ACTION')->onUpdate('NO ACTION');
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
        Schema::dropIfExists('lms_offline_exam_answer');
    }
};
