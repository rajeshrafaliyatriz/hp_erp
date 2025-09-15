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
            Schema::create('hrms_emp_leaves', function (Blueprint $table) {
            $table->bigIncrements('id');// BIGINT auto-increment (Primary Key)
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable(); // sub_institute_id INT 11
            $table->unsignedBigInteger('department_id')->index()->nullable(); // department_id BIGINT 20
            $table->unsignedBigInteger('user_id')->index()->nullable(); // user_id BIGINT 20
            $table->unsignedBigInteger('leave_type_id')->index()->nullable(); // leave_type_id BIGINT 20
            $table->string('day_type', 191)->nullable(); // day_type VARCHAR 191
            $table->string('slot', 191)->nullable(); // slot VARCHAR 191
            $table->date('from_date')->nullable(); // from_date DATE
            $table->date('to_date')->nullable(); // to_date DATE
            $table->string('comment', 255)->nullable(); // comment VARCHAR 255
            $table->string('hod_comment', 50)->nullable(); // hod_comment VARCHAR 50
            $table->date('hod_comment_date')->nullable(); // hod_comment_date DATE
            $table->string('hr_remarks', 100)->nullable(); // hr_remarks VARCHAR 100
            $table->date('hr_remark_date')->nullable(); // hr_remark_date DATE
            $table->string('approved_by', 191)->nullable(); // approved_by VARCHAR 191
            $table->enum('status', ['approved', 'pending', 'rejected'])->nullable(); // status ENUM
            $table->unsignedBigInteger('created_by')->index()->nullable(); 
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();
            $table->timestamps(); // created_at and updated_at TIMESTAMP
            $table->softDeletes();
            
            $table->foreign('sub_institute_id')
                ->references('id')->on('school_setup')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('user_id')
                ->references('id')->on('tbluser')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('leave_type_id')
                ->references('id')->on('tbluser')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('department_id')
                ->references('id')->on('hrms_departments')
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
        Schema::dropIfExists('hrms_emp_leaves');
    }
};
