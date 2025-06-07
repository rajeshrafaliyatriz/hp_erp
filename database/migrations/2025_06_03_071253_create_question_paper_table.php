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
        Schema::create('question_paper', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('grade_id')->index()->nullable();
            $table->unsignedBigInteger('standard_id')->index()->nullable();
            $table->unsignedBigInteger('subject_id')->index()->nullable();
            $table->string('paper_name', 250)->index()->nullable();
            $table->string('paper_desc', 250)->nullable();
            $table->dateTime('open_date')->index()->nullable();
            $table->dateTime('close_date')->index()->nullable();
            $table->integer('timelimit_enable')->default(0);
            $table->integer('time_allowed')->nullable();
            $table->integer('total_marks')->index()->nullable();
            $table->integer('total_ques')->index()->nullable();
            $table->text('question_ids')->index()->nullable();
            $table->integer('shuffle_question')->default(0);
            $table->integer('attempt_allowed')->default(1);
            $table->integer('show_feedback')->default(0);
            $table->integer('show_hide')->default(1);
            $table->integer('result_show_ans')->default(0);
            $table->dateTime('created_on')->nullable();

            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
            $table->string('syear', 50)->index()->nullable();
            $table->string('exam_type', 250)->nullable();

            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

            $table->timestamps(); // includes created_at and updated_at
            $table->softDeletes(); // adds deleted_at

            // Foreign keys - NO ACTION on delete/update
            $table->foreign('sub_institute_id')
                ->references('id')->on('school_setup')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('created_by')
                ->references('id')->on('tbluser')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('updated_by')
                ->references('id')->on('tbluser')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('deleted_by')
                ->references('id')->on('tbluser')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_paper');
    }
};
