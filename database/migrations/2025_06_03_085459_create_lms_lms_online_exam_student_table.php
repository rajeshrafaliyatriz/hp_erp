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
        Schema::create('lms_online_exam_student', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_id')->index()->nullable();
            $table->unsignedBigInteger('question_paper_id')->index()->nullable();
            $table->integer('total_right')->index()->nullable();
            $table->bigInteger('total_wrong')->index()->nullable();
            $table->bigInteger('obtain_marks')->index()->nullable();
            $table->bigInteger('start_time')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();

            // Optional syear if needed
            // $table->string('syear', 50)->nullable();

            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();
            $table->softDeletes(); // deleted_at

            // Foreign Key Constraints (NO ACTION on delete/update)
            $table->foreign('question_paper_id')->references('id')->on('question_paper')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('employee_id')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
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
        Schema::dropIfExists('lms_online_exam_student');
    }
};
