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
        Schema::create('standard', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('grade_id')->index();
            $table->string('name', 191)->index();
            $table->string('short_name', 191)->index()->nullable();
            $table->integer('sort_order')->nullable();
            $table->string('medium', 191)->nullable();
            $table->string('course_duration', 191)->nullable();
            $table->unsignedBigInteger('next_grade_id')->index()->nullable();
            $table->unsignedBigInteger('next_standard_id')->index()->nullable();
            $table->string('school_stream', 191)->nullable();
            $table->unsignedBigInteger('marking_period_id')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->nullable();

            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

            $table->timestamps();        // created_at & updated_at
            $table->softDeletes();      
            // Foreign keys without cascade
            $table->foreign('grade_id')
                ->references('id')->on('academic_section')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');  

            $table->foreign('sub_institute_id')
                ->references('id')->on('school_setup')
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
        Schema::dropIfExists('standard');
    }
};
