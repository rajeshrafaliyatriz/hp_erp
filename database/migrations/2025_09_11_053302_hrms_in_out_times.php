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
            Schema::create('hrms_in_out_times', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('day')->nullable(); // day DATE
            $table->unsignedBigInteger('user_id')->index()->nullable();  // user_id BIGINT 20
            $table->time('in_time')->nullable(); // in_time TIME
            $table->time('out_time')->nullable(); // out_time TIME
            $table->unsignedBigInteger('client_id')->index()->nullable(); // client_id BIGINT 20
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();  // sub_institute_id BIGINT 20
            $table->unsignedBigInteger('created_by')->index()->nullable(); 
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();
            $table->timestamps();
            $table->softDeletes();


            $table->foreign('user_id')
                ->references('id')->on('tbluser')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->foreign('client_id')
                ->references('id')
                ->on('tbluser')
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
       Schema::dropIfExists('hrms_in_out_times');
        
    }
};