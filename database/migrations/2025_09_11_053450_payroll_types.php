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
        Schema::create('payroll_types', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->integer('payroll_type')->nullable();
        $table->string('payroll_name', 191)->nullable();
        $table->integer('amount_type')->nullable();
        $table->tinyInteger('status')->nullable();
        $table->integer('sort_order')->nullable();
        $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
        $table->string('payroll_percentage', 191)->nullable();
        $table->char('day_count', 1)->nullable();
        $table->unsignedBigInteger('created_by')->index()->nullable(); 
        $table->unsignedBigInteger('updated_by')->index()->nullable();
        $table->unsignedBigInteger('deleted_by')->index()->nullable();
        $table->timestamps();
        $table->softDeletes();
        
        $table->foreign('sub_institute_id')
                ->references('id')->on('school_setup')
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
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_types');
    }
};
