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
            Schema::create('hrms_leave_types', function (Blueprint $table) {
            $table->bigIncrements('id'); 
            $table->unsignedBigInteger('leave_type_id', 10)->index()->nullable(); // VARCHAR(10)
            $table->string('leave_type', 30)->nullable();    // VARCHAR(30)
            $table->integer('sort_order')->nullable();       // INT(11)
            $table->integer('status')->default(1);           // INT(11), default active
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable(); // INT(11)
            $table->unsignedBigInteger('created_by')->index()->nullable(); 
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();            
            $table->timestamps();  // created_at, updated_at TIMESTAMP
            $table->softDeletes(); // deleted_at TIMESTAMP NULL


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
        Schema::dropIfExists('hrms_leave_types');
    }
};
