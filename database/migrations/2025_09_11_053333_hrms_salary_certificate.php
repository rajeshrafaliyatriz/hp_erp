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
            Schema::create('hrms_salary_certificate', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('department_id')->index()->nullable(); // department_id BIGINT
            $table->unsignedBigInteger('employee_id')->index()->nullable(); // employee_id BIGINT
            $table->year('year')->nullable(); // year INT (using year type)
            $table->string('month', 20)->nullable(); // month VARCHAR
            $table->string('payroll_type_id', 50)->nullable(); // payroll_type_id VARCHAR
            $table->string('reason', 255)->nullable(); // reason VARCHAR
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable(); // sub_institute_id BIGINT
            $table->string('pdf_file_name', 255)->nullable(); // pdf_file_name VARCHAR
            $table->text('pdf_html')->nullable(); // pdf_html TEXT
            $table->unsignedBigInteger('created_by')->index()->nullable(); 
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Foreign key constraints
         
            $table->foreign('employee_id')
                    ->references('id')->on('tbluser')
                   ->onDelete('NO ACTION')->onUpdate('NO ACTION');


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
        Schema::dropIfExists('hrms_salary_certificate');
    
    }
};
