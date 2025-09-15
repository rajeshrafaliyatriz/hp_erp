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
            Schema::create('hrms_holidays', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable(); // sub_institute_id INT 11 UNSIGNED
            $table->string('holiday_name', 191)->nullable(); // holiday_name VARCHAR 191
            $table->enum('day_type', ['full', 'half'])->nullable(); // day_type ENUM 'full','half'
            $table->string('department', 191)->nullable(); // department VARCHAR 191
            $table->date('from_date')->nullable(); // from_date DATE
            $table->date('to_date')->nullable(); // to_date DATE
            $table->unsignedBigInteger('created_by')->index()->nullable(); 
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();
            $table->timestamps(); // created_at and updated_at TIMESTAMP
            $table->softDeletes();

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
        Schema::dropIfExists('hrms_holidays');
    }
    
};
