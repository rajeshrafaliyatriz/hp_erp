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
        Schema::create('hrms_leave_allocation', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->unsignedBigInteger('employee_id')->index()->nullable();
        $table->unsignedBigInteger('department_id')->index()->nullable();
        $table->unsignedBigInteger('leave_type_id')->index()->nullable();
        $table->year('year')->nullable();
        $table->integer('value')->default(0); // value INT with default 0
        $table->unsignedBigInteger('sub_institute_id')->length(11)->index()->nullable();
        $table->unsignedBigInteger('created_by')->index()->nullable(); 
        $table->unsignedBigInteger('updated_by')->index()->nullable();
        $table->unsignedBigInteger('deleted_by')->index()->nullable();
        $table->timestamps();
        $table->softDeletes();

        $table->foreign('employee_id')
                ->references('id')->on('tbluser')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

        $table->foreign('department_id')
                ->references('id')->on('hrms_departments')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

        $table->foreign('leave_type_id')
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
         Schema::dropIfExists('hrms_leave_allocation');
    }
    
};
