<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private per-employee notes for the Employee Competency Profile "Notes" panel.
 * One row per (tenant, employee). No existing table fits: lms_content_notes is
 * course-content notes, not competency-profile notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('s_competency_employee_notes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->unsignedBigInteger('user_id')->index(); // employee (tbluser.id)
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('s_competency_employee_notes');
    }
};
