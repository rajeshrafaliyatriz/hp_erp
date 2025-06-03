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
        Schema::create('lms_question_mapping', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('questionmaster_id')->index()->nullable();
            $table->unsignedBigInteger('mapping_type_id')->index()->nullable();
            $table->unsignedBigInteger('mapping_value_id')->index()->nullable();

            $table->text('reasons')->nullable();

            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();

            $table->timestamps(); // created_at, updated_at
            $table->softDeletes(); // deleted_at

            // Foreign keys (NO ACTION on delete/update)
            $table->foreign('questionmaster_id')
                ->references('id')->on('lms_question_master')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('mapping_type_id')
                ->references('id')->on('lms_mapping_type')
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

            $table->foreign('sub_institute_id')
                ->references('id')->on('school_setup')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lms_question_mapping');
    }
};
