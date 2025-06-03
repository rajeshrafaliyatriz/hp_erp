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
        Schema::create('lms_content_master', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('grade_id')->nullable()->index();
            $table->unsignedBigInteger('standard_id')->nullable()->index();
            $table->unsignedBigInteger('subject_id')->nullable()->index();
            $table->unsignedBigInteger('chapter_id')->nullable()->index();
            $table->unsignedBigInteger('topic_id')->nullable()->index();
            $table->unsignedBigInteger('sub_topic_id')->nullable()->index();
            $table->string('lo_master_ids', 250)->nullable();
            $table->string('lo_indicator_ids', 250)->nullable();
            $table->unsignedBigInteger('lo_category_id')->nullable()->index();
            $table->string('title', 250)->index()->nullable();
            $table->longText('description')->nullable();
            $table->longText('file_folder')->nullable();
            $table->longText('filename')->nullable();
            $table->string('file_type', 250)->nullable();
            $table->string('file_size', 250)->nullable();
            $table->longText('url')->nullable();
            $table->integer('sort_order')->nullable();
            $table->integer('show_hide')->nullable();
            $table->string('meta_tags', 250)->nullable();
            $table->unsignedBigInteger('content_category')->nullable()->index();
            $table->string('syear', 50)->index()->nullable();
            $table->date('restrict_date')->nullable();
            $table->string('pre_grade_topic', 250)->nullable();
            $table->string('post_grade_topic', 250)->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();

            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('grade_id')
                ->references('id')->on('academic_section')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('standard_id')
                ->references('id')->on('standard')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('subject_id')
                ->references('id')->on('subject')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');
                
            $table->foreign('sub_institute_id')
                ->references('id')->on('school_setup')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');

            $table->foreign('chapter_id')
                ->references('id')->on('chapter_master')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');

            $table->foreign('created_by')
                ->references('id')->on('tbluser')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');

            $table->foreign('updated_by')
                ->references('id')->on('tbluser')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');

            $table->foreign('deleted_by')
                ->references('id')->on('tbluser')
                 ->nullOnDelete() 
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lms_content_master');
    }
};
