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
        Schema::create('lms_question_master', function (Blueprint $table) {
             $table->bigIncrements('id');

            $table->unsignedBigInteger('question_type_id')->index()->nullable();
            $table->unsignedBigInteger('grade_id')->index()->nullable();
            $table->unsignedBigInteger('standard_id')->index()->nullable();
            $table->unsignedBigInteger('subject_id')->index()->nullable();
            $table->unsignedBigInteger('chapter_id')->index()->nullable();
            $table->unsignedBigInteger('topic_id')->index()->nullable();

            $table->text('question_title')->nullable();
            $table->text('description')->nullable();
            $table->integer('points')->default(0);
            $table->integer('multiple_answer')->index()->default(0);

            $table->string('concept', 191)->nullable();
            $table->string('subconcept', 191)->nullable();
            $table->string('pre_grade_topic', 191)->nullable();
            $table->string('post_grade_topic', 191)->nullable();
            $table->string('cross_curriculum_grade_topic', 191)->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
            $table->integer('status')->index()->default(1);

            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->dateTime('created_on')->nullable();

            $table->text('answer')->nullable();
            $table->text('hint_text')->nullable();
            $table->string('learning_outcome', 191)->nullable();

            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

            $table->timestamps(); // created_at & updated_at
            $table->softDeletes(); // deleted_at

            // Foreign Key Constraints (NO ACTION)
            $table->foreign('question_type_id')
                ->references('id')->on('question_type_master')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('grade_id')
                ->references('id')->on('academic_section')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('standard_id')
                ->references('id')->on('standard')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('subject_id')
                ->references('id')->on('subject')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('chapter_id')
                ->references('id')->on('chapter_master')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('topic_id')
                ->references('id')->on('topic_master')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

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
        Schema::dropIfExists('lms_question_master');
    }
};
