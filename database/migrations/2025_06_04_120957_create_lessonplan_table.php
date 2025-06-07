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
        Schema::create('lessonplan', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('syear', 50);
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('user_group_id')->nullable();
            $table->date('school_date')->nullable();
            $table->unsignedBigInteger('grade_id')->nullable()->index();
            $table->unsignedBigInteger('standard_id')->nullable()->index();
            $table->unsignedBigInteger('division_id')->nullable()->index();
            $table->unsignedBigInteger('subject_id')->nullable()->index();
            $table->unsignedBigInteger('teacher_id')->nullable()->index();

            $table->string('title')->nullable();
            $table->string('description')->nullable();

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->unsignedBigInteger('deleted_by')->nullable()->index();

            $table->timestamps();
            $table->softDeletes(); // deleted_at

            $table->string('completion_status')->nullable();
            $table->date('completion_date')->nullable();
            $table->mediumText('reasons')->nullable();

            // Foreign keys with NO ACTION on delete/update
            $table->foreign('grade_id')->references('id')->on('academic_section')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('standard_id')->references('id')->on('standard')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('subject_id')->references('id')->on('subject')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->onDelete('NO ACTION')->onUpdate('NO ACTION');

            // Optional: FK for created_by/updated_by/deleted_by/teacher_id if using tbluser
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
        Schema::dropIfExists('lessonplan');
    }
};
