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
        Schema::create('answer_master', function (Blueprint $table) {
             $table->bigIncrements('id');
            
            $table->unsignedBigInteger('question_id')->index()->nullable();
            $table->string('answer')->index()->nullable();
            $table->string('feedback')->nullable();
            $table->integer('correct_answer')->index()->default(0);
            $table->unsignedBigInteger('sub_institute_id')->nullable();

             $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

            $table->timestamps();
            $table->softDeletes(); // deleted_at

            // Foreign key constraints - NO ACTION on delete/update
            $table->foreign('question_id')->references('id')->on('lms_question_master')->onDelete('NO ACTION')->onUpdate('NO ACTION');
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
        Schema::dropIfExists('answer_master');
    }
};
