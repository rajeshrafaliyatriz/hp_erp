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
        Schema::create('master_compliance', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 191)->index()->nullable();
            $table->text('description')->nullable();
            $table->string('standard_name', 191)->index()->nullable();
            $table->unsignedBigInteger('assigned_to')->index()->nullable();
            $table->date('duedate')->index()->nullable();
            $table->string('attachment', 191)->index()->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Foreign Keys
            $table->foreign('sub_institute_id')
                ->references('id')
                ->on('school_setup')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');

            $table->foreign('created_by')
                ->references('id')
                ->on('tbluser')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');

            $table->foreign('updated_by')
                ->references('id')
                ->on('tbluser')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');

            $table->foreign('deleted_by')
                ->references('id')
                ->on('tbluser')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_compliance');
    }
};
