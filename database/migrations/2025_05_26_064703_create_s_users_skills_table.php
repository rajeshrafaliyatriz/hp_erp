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
        Schema::create('s_users_skills', function (Blueprint $table) {
           $table->bigIncrements('id');
            $table->string('department')->index()->nullable();
            $table->string('sub_department')->index()->nullable();
            $table->string('category')->index()->nullable();
            $table->string('sub_category')->index()->nullable();
            $table->string('title')->index();
            $table->text('description')->nullable();
            $table->tinyText('related_skills')->nullable();
            $table->string('bussiness_links',191)->nullable();
            $table->tinyText('custom_tags')->nullable();
            $table->tinyText('proficiency_level')->nullable();
            $table->tinyText('job_titles')->nullable();
            $table->tinyText('learning_resources')->nullable();
            $table->tinyText('assesment_method')->nullable();
            $table->tinyText('certification_qualifications')->nullable();
            $table->tinyText('experience_project')->nullable();
            $table->tinyText('skill_maps')->nullable();
            $table->enum('status', ['Active', 'Inactive'])->index()->nullable();
            $table->enum('approve_status', ['Approved', 'Pending', 'Cancelled'])->index()->nullable();
           $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->foreign('sub_institute_id')
                    ->references('id')
                    ->on('school_setup')
                    ->nullOnDelete()  // Optional, if you want to set to null on delete
                    ->onUpdate('NO ACTION')
                    ->onDelete('NO ACTION');  
           $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('tbluser')->nullOnDelete()  // Optional, if you want to set to null on delete
                    ->onUpdate('NO ACTION')
                    ->onDelete('NO ACTION');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('updated_by')->references('id')->on('tbluser')->nullOnDelete()  // Optional, if you want to set to null on delete
                    ->onUpdate('NO ACTION')
                    ->onDelete('NO ACTION');
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->foreign('deleted_by')->references('id')->on('tbluser')->nullOnDelete()  // Optional, if you want to set to null on delete
                    ->onUpdate('NO ACTION')
                    ->onDelete('NO ACTION');
            $table->softDeletes();
            $table->timestamps();
            $table->index('created_by');
            $table->index('updated_by');
            $table->index('deleted_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('s_users_skills');
    }
};
