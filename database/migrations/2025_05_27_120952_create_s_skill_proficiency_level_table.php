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
        Schema::create('s_proficiency_levels', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('skill_id')->nullable()->index();
            $table->foreign('skill_id')->references('id')->on('s_users_skills')
                    ->onUpdate('NO ACTION')
                    ->onDelete('NO ACTION');
            $table->string('proficiency_level')->index()->nullable();
            $table->text('description')->nullable();
            $table->string('proficiency_type')->index()->nullable();
            $table->text('type_description')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->foreign('sub_institute_id')
                    ->references('id')
                    ->on('school_setup')
                      // Optional, if you want to set to null on delete
                    ->onUpdate('NO ACTION')
                    ->onDelete('NO ACTION');  
            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->foreign('created_by')->references('id')->on('tbluser')  // Optional, if you want to set to null on delete
                    ->onUpdate('NO ACTION')
                    ->onDelete('NO ACTION');
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->foreign('updated_by')->references('id')->on('tbluser')  // Optional, if you want to set to null on delete
                    ->onUpdate('NO ACTION')
                    ->onDelete('NO ACTION');
            $table->unsignedBigInteger('deleted_by')->index()->nullable();
            $table->foreign('deleted_by')->references('id')->on('tbluser')  // Optional, if you want to set to null on delete
                    ->onUpdate('NO ACTION')
                    ->onDelete('NO ACTION');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('s_skill_proficiency_level');
    }
};
