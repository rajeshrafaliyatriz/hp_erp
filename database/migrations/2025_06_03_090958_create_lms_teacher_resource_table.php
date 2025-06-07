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
        Schema::create('lms_teacher_resource', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('standard_id')->index()->nullable();
            $table->unsignedBigInteger('subject_id')->index()->nullable();
            $table->unsignedBigInteger('chapter_id')->index()->nullable();
            $table->unsignedBigInteger('topic_id')->nullable();

            $table->string('syear', 50)->index()->nullable();

            $table->string('title', 255)->index()->nullable();
            $table->mediumText('description')->nullable();
            $table->mediumText('activity')->nullable();
            $table->string('file_folder', 500)->nullable();
            $table->string('file_name', 250)->nullable();
            $table->string('file_type', 250)->nullable();
            $table->string('file_size', 250)->nullable();
            $table->text('mapping_value')->nullable();
            $table->string('status', 5)->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();

            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

             $table->timestamps();
            $table->softDeletes(); // deleted_at

            // Foreign keys with NO ACTION on delete/update
            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('standard_id')->references('id')->on('standard')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('subject_id')->references('id')->on('subject')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('chapter_id')->references('id')->on('chapter_master')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('topic_id')->references('id')->on('topic_master')->onDelete('NO ACTION')->onUpdate('NO ACTION');
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
        Schema::dropIfExists('lms_teacher_resource');
    }
};
