<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('employee_salary_structures', function (Blueprint $table) {
             $table->bigIncrements('id');
            $table->unsignedBigInteger('employee_id');
            $table->text('employee_salary_data')->nullable();
            $table->year('year');
            $table->unsignedBigInteger('sub_institute_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Foreign key constraints
            // $table->foreign('employee_id')->references('id')->on('tbluser')->onDelete('cascade');
            // $table->foreign('sub_institute_id')->references('id')->on('school_setup')->onDelete('cascade');
            
            // Unique constraint to prevent duplicate entries
            // $table->unique(['employee_id', 'year', 'sub_institute_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('employee_salary_structures');
    }
};