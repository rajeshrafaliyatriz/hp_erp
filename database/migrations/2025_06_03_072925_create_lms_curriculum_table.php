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
        Schema::create('lms_curriculum', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
            $table->unsignedBigInteger('grade_id')->index()->nullable();
            $table->unsignedBigInteger('standard_id')->index()->nullable();
            $table->unsignedBigInteger('subject_id')->index()->nullable();
            $table->unsignedBigInteger('board_id')->index()->nullable();

            $table->string('curriculum_name', 255)->index()->nullable();
            $table->mediumText('curriculum_alignment')->nullable();
            $table->mediumText('holistic_curriculum')->nullable();
            $table->mediumText('model_integration')->nullable();
            $table->mediumText('objective')->nullable();
            $table->mediumText('chapter')->nullable();
            $table->mediumText('outcome')->nullable();
            $table->mediumText('assessment_tool')->nullable();

            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

            $table->timestamps(); // created_at and updated_at
            $table->softDeletes(); // deleted_at

            // Foreign key constraints with NO ACTION
            $table->foreign('sub_institute_id')
                ->references('id')->on('school_setup')
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
        Schema::dropIfExists('lms_curriculum');
    }
};
