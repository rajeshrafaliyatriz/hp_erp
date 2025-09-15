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
        Schema::create('employee_salary_structures', function (Blueprint $table) {
            $table->bigIncrements('id');// BIGINT auto-increment (Primary Key)
            $table->unsignedBigInteger('employee_id')->index()->nullable(); // BIGINT
            $table->longText('employee_salary_data')->index()->nullable(); // LONGTEXT
            $table->integer('year')->nullable(); // INT
            $table->integer('sub_institute_id')->nullable(); // INT
            $table->unsignedBigInteger('created_by')->index()->nullable(); 
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();
            $table->timestamps();// created_at & updated_at (TIMESTAMP)
            $table->softDeletes();

            $table->foreign('employee_id')
                ->references('id')->on('employee')
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
        Schema::dropIfExists('employee_salary_structures');
    }
};
