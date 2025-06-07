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
        Schema::create('tbluser', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('user_name')->index();
            $table->string('password');
            $table->string('name_suffix')->nullable();
            $table->string('first_name')->index()->nullable();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->index()->nullable();
            $table->string('email')->index()->unique();
            $table->string('mobile')->nullable()->index();
            $table->string('gender', 1)->nullable();
            $table->date('birthdate')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable()->index();
            $table->string('state')->nullable()->index();
            $table->string('pincode')->nullable()->index();
            $table->string('otp')->nullable();
            $table->unsignedBigInteger('user_profile_id')->index();
            $table->foreign('user_profile_id')->references('id')->on('tbluserprofilemaster')->nullOnDelete()  // Optional, if you want to set to null on delete
                    ->onUpdate('NO ACTION')
                    ->onDelete('NO ACTION');
            $table->string('join_year')->nullable();
            $table->string('image')->nullable();
            $table->string('plain_password')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->foreign('sub_institute_id')
                    ->references('id')
                    ->on('school_setup')
                    ->nullOnDelete()  // Optional, if you want to set to null on delete
                    ->onUpdate('NO ACTION')
                    ->onDelete('NO ACTION');  
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->boolean('is_admin')->default(false)->nullable()->index();
            $table->boolean('portal_user')->default(false)->nullable()->index();
            $table->boolean('status')->default(true)->nullable()->index();
            $table->timestamp('last_login')->nullable();
            $table->string('login_ip')->nullable();
            $table->string('landmark')->nullable();
            $table->string('address_2')->nullable();
            $table->date('expire_date')->nullable();
            $table->integer('total_lecture')->nullable();
            $table->text('subject_ids')->nullable();
            $table->text('allocated_standards')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('branch_name')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('transfer_type')->nullable();
            $table->string('account_no')->nullable();
            $table->string('ifsc_code')->nullable();
            $table->unsignedBigInteger('jobtitle_id')->nullable()->index();
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->string('employee_no')->nullable()->index();
            $table->string('qualification')->nullable();
            $table->string('occupation')->nullable();
            $table->string('pan_no')->nullable()->index();
            $table->string('aadhar_no')->nullable()->index();
            $table->string('pf_no')->nullable();
            $table->string('esic_no')->nullable();
            $table->string('uan_no')->nullable();
            $table->boolean('tds_deduction')->default(false)->nullable();
            $table->boolean('pf_deduction')->default(false)->nullable();
            $table->boolean('pt_deduction')->default(false)->nullable();
            $table->date('joined_date')->nullable()->index();
            $table->date('probation_period_from')->nullable();
            $table->date('probation_period_to')->nullable();
            $table->date('terminated_date')->nullable();
            $table->text('termination_reason')->nullable();
            $table->date('notice_fromdate')->nullable();
            $table->date('notice_todate')->nullable();
            $table->text('noticereason')->nullable();
            $table->integer('openingleave')->nullable();
            $table->date('relieving_date')->nullable();
            $table->text('relieving_reason')->nullable();
            $table->integer('CL_opening_leave')->nullable();
            $table->string('supervisor_opt')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->integer('load')->nullable();
            $table->string('reporting_method')->nullable();
            $table->boolean('monday')->default(false)->nullable();
            $table->boolean('tuesday')->default(false)->nullable();
            $table->boolean('wednesday')->default(false)->nullable();
            $table->boolean('thursday')->default(false)->nullable();
            $table->boolean('friday')->default(false)->nullable();
            $table->boolean('saturday')->default(false)->nullable();
            $table->boolean('sunday')->default(false)->nullable();
            $table->timestamp('monday_in_date')->nullable();
            $table->timestamp('monday_out_date')->nullable();
            $table->timestamp('tuesday_in_date')->nullable();
            $table->timestamp('tuesday_out_date')->nullable();
            $table->timestamp('wednesday_in_date')->nullable();
            $table->timestamp('wednesday_out_date')->nullable();
            $table->timestamp('thursday_in_date')->nullable();
            $table->timestamp('thursday_out_date')->nullable();
            $table->timestamp('friday_in_date')->nullable();
            $table->timestamp('friday_out_date')->nullable();
            $table->timestamp('saturday_in_date')->nullable();
            $table->timestamp('saturday_out_date')->nullable();
            $table->timestamp('sunday_in_date')->nullable();
            $table->timestamp('sunday_out_date')->nullable();
            $table->integer('total_experience')->nullable();
            $table->decimal('employee_deposite', 10, 2)->nullable();
            $table->bigInteger('created_by')->nullable()->index();
            $table->bigInteger('updated_by')->nullable()->index();
            $table->bigInteger('deleted_by')->nullable()->index();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbluser');
    }
};
