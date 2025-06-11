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
        Schema::create('staff_document', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('document_type_id');
            $table->string('document_title',191)->nullable()->index();
            $table->string('file_name',191)->nullable()->index();
            $table->string('syear', 50)->nullable()->index(); // As per requirement: string(50)

            // Organizational context
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();

            // Audit fields for tracking who created/updated/deleted the record
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->unsignedBigInteger('deleted_by')->nullable()->index();
            // Timestamps (created_at and updated_at)
            $table->timestamps();

            // Soft Deletes (deleted_at)
            $table->softDeletes();
            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('user_id')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
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
        Schema::dropIfExists('staff_document');
    }
};
