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
        Schema::create('academic_section', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('title', 191)->index();
            $table->string('short_name', 100);
            $table->integer('sort_order')->index()->nullable();
            $table->string('shift', 100)->nullable();
            $table->string('medium', 100)->nullable();
            $table->string('payment_link', 191)->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

            $table->timestamps(); 
            $table->softDeletes();

            // Foreign key constraints WITHOUT cascade
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
                ->nullOnDelete()  // Optional, if you want to set to null on delete
                ->onUpdate('NO ACTION')
                ->index()->onDelete('NO ACTION');  
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_section');
    }
};
