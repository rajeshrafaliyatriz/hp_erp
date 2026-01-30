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
        Schema::create('suggested_course', function (Blueprint $table) {
            $table->id(); // id (primary key)

            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('course_id');
            $table->string('course_name');
            $table->unsignedBigInteger('skill_id');

            $table->timestamps();

            // Optional: Foreign key constraints (uncomment if needed)
            /*
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
            $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
            $table->foreign('skill_id')->references('id')->on('skills')->onDelete('cascade');
            */
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suggested_course');
    }
};