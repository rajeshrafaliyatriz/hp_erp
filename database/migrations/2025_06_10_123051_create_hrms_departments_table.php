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
        Schema::create('hrms_departments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('department',191)->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->text('tasks')->nullable();
            $table->text('roles_responsibility')->nullable();
            $table->integer('status')->index();
            $table->integer('is_calculated')->index();

            // Organizational context
            $table->unsignedBigInteger('sub_institute_id')->nullable();

            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

            // Timestamps (created_at and updated_at)
            $table->timestamps();

            // Soft Deletes (deleted_at)
            $table->softDeletes();
            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->onDelete('NO ACTION')->onUpdate('NO ACTION');
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
        Schema::dropIfExists('hrms_departments');
    }
};
