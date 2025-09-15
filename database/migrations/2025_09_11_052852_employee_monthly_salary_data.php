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
        Schema::create('employee_monthly_salary_data', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
        $table->unsignedBigInteger('employee_id')->index()->nullable();
        $table->string('month', 20)->nullable();   
        $table->year('year')->nullable();          
        $table->decimal('salary_amount', 10, 2)->nullable(); 
        $table->unsignedBigInteger('created_by')->index()->nullable(); 
        $table->unsignedBigInteger('updated_by')->index()->nullable();
        $table->unsignedBigInteger('deleted_by')->index()->nullable();
        $table->timestamps();
        $table->softDeletes();
  
        $table->foreign('sub_institute_id')
                ->references('id')->on('school_setup')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

        $table->foreign('employee_id')
              ->references('id')
              ->on('tbluser')
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
        Schema::dropIfExists('employee_monthly_salary_data');
    }
};
