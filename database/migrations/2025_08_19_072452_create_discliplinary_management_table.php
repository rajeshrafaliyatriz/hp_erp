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
        Schema::create('discliplinary_management', function (Blueprint $table) {
            $table->bigIncrements('id'); // Primary key
             // Foreign keys
             $table->unsignedBigInteger('department_id')->index()->nullable(); 
             $table->unsignedBigInteger('employee_id')->index()->nullable(); 
             $table->dateTime('incident_datetime')->index()->nullable();      
             $table->string('location')->nullable();                  
 
             // Misconduct details
             $table->enum('misconduct_type', [
                 'Late Arrival',
                 'Absenteeism',
                 'Misbehavior',
                 'Violation of Policy',
                 'Others'
             ])->index()->nullable();                                          
 
             $table->text('description')->nullable();                
             $table->unsignedBigInteger('witness_id')->nullable();   
 
             // Action taken
             $table->enum('action_taken', [
                 'Warning',
                 'Suspension',
                 'Termination',
                 'Counseling',
                 'Others'
             ])->index()->nullable();                                        
 
             $table->text('remarks')->nullable();                     
 
             // Reporting details
             $table->unsignedBigInteger('reported_by')->index()->nullable();               
             $table->date('date_of_report')->index()->nullable();              
 
             // Organization
             $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
             $table->unsignedBigInteger('created_by')->index()->nullable(); 
             $table->unsignedBigInteger('updated_by')->index()->nullable();
             $table->unsignedBigInteger('deleted_by')->index()->nullable();
 
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys - NO ACTION on delete/update
            $table->foreign('employee_id')
                ->references('id')->on('tbluser')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

                $table->foreign('department_id')
                ->references('id')->on('hrms_departments')
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
        Schema::dropIfExists('discliplinary_management');
    }
};
