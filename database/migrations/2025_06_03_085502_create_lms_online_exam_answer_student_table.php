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
        Schema::create('lms_online_exam_answer_student', function (Blueprint $table) {
           $table->bigIncrements('id');

            $table->unsignedBigInteger('question_paper_id')->index()->nullable();
            $table->unsignedBigInteger('online_exam_id')->index()->nullable();
            $table->unsignedBigInteger('employee_id')->index()->nullable();
            $table->unsignedBigInteger('question_id')->index()->nullable();
            $table->unsignedBigInteger('answer_id')->index()->nullable();
            $table->text('narrative_answer')->nullable();
            $table->string('ans_status', 50)->index()->nullable();

            // $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();
            $table->timestamps();
            $table->softDeletes(); // deleted_at

            // Optional syear
            // $table->string('syear', 50)->nullable();

            // Foreign key constraints (NO ACTION)
            $table->foreign('question_paper_id')->references('id')->on('question_paper')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('online_exam_id')->references('id')->on('lms_online_exam_student')->onDelete('NO ACTION')->onUpdate('NO ACTION');
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
        Schema::dropIfExists('lms_online_exam_answer_student');
    }
};
