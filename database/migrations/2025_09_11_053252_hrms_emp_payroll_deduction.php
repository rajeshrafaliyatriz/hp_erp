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
            Schema::create('hrms_emp_payroll_deduction', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('month', 20)->nullable(); // month VARCHAR 20
            $table->year('year')->nullable(); // year INT 11 (using year type for better validation)
            $table->unsignedBigInteger('employee_id')->index()->nullable(); // employee_id BIGINT 20
            $table->unsignedBigInteger('deduction_type_id')->index()->nullable(); // deduction_type BIGINT 20
            $table->bigInteger('deduction_amount')->nullable(); // deduction_amount INT 11
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable(); // sub_institute_id BIGINT 20
            $table->unsignedBigInteger('created_by')->index()->nullable(); 
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();
            $table->timestamps(); // created_at and updated_at TIMESTAMP
            $table->softDeletes();

            $table->foreign('employee_id')
                ->references('id')->on('tbluser')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('deduction_type_id')
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
        Schema::dropIfExists('hrms_emp_payroll_deduction');
    }
};
