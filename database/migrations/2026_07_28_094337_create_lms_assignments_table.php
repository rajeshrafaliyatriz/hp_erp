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
        Schema::create('lms_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->string('assignment_type')->default('Mandatory');
            $table->date('due_date')->nullable();
            $table->string('status')->default('Not Started');
            $table->integer('progress')->default(0);
            $table->string('assigned_by')->nullable();
            $table->timestamp('assigned_on')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lms_assignments');
    }
};
