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
        Schema::create('chapter_master', function (Blueprint $table) {
            $table->bigIncrements('id');
            
            $table->unsignedBigInteger('grade_id')->index()->nullable();
            $table->unsignedBigInteger('standard_id')->index()->nullable();
            $table->unsignedBigInteger('subject_id')->index()->nullable();

            $table->string('chapter_name', 250)->index();
            $table->text('chapter_desc')->nullable();
            $table->integer('availability')->index()->nullable();
            $table->integer('show_hide')->index()->nullable();
            $table->integer('sort_order')->index()->nullable();
            $table->unsignedInteger('syear')->index();
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();

            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

            $table->timestamps();      // created_at & updated_at
            $table->softDeletes();

            // Foreign Keys (no cascade)
            $table->foreign('sub_institute_id')
                ->references('id')->on('school_setup')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');

            $table->foreign('grade_id')
                ->references('id')->on('academic_section')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');

            $table->foreign('standard_id')
                ->references('id')->on('standard')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');

            $table->foreign('subject_id')
                ->references('id')->on('subject')
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
        Schema::dropIfExists('chapter_master');
    }
};
