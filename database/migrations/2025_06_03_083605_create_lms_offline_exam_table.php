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
        Schema::create('lms_offline_exam', function (Blueprint $table) {
               $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_id')->index()->nullable();
            $table->unsignedBigInteger('question_paper_id')->index()->nullable();
            $table->integer('assignment_id')->index()->nullable();
            $table->bigInteger('total_right')->nullable();
            $table->bigInteger('total_wrong')->nullable();
            $table->bigInteger('obtain_marks')->nullable();

            $table->string('syear',50)->index()->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            // $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->timestamps(); // Adds created_at and updated_at if needed
            $table->softDeletes(); // Adds deleted_at

            // Foreign Keys
            $table->foreign('employee_id')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('question_paper_id')->references('id')->on('question_paper')->onDelete('NO ACTION')->onUpdate('NO ACTION');
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
        Schema::dropIfExists('lms_offline_exam');
    }
};
