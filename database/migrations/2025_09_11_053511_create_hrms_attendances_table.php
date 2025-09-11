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
        Schema::create('hrms_attendances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();
            $table->string('employee_no')->index()->nullable();
            $table->date('day')->index();
            $table->dateTime('punchin_time')->index()->nullable();
            $table->dateTime('punchout_time')->index()->nullable();
            $table->boolean('in_note')->index()->default(0);
            $table->boolean('out_note')->index()->default(0);
            $table->time('timestamp_diff')->index()->nullable();
            $table->boolean('status')->index()->default(1);
            $table->string('ipaddress_in')->index()->nullable();
            $table->string('ipaddress_out')->index()->nullable();
            $table->unsignedBigInteger('attendance_log_id')->index()->nullable();
            $table->unsignedBigInteger('client_id')->index()->nullable();
            $table->string('photo_in')->index()->nullable();
            $table->string('photo_out')->index()->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('sub_institute_id')
                ->references('id')->on('school_setup')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('user_id')
                ->references('id')->on('tbluser')
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
        Schema::dropIfExists('hrms_attendances');
    }
};
