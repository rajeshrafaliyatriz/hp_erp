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
        Schema::create('hrms_departments_mapping', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('department', 191)->nullable();
        $table->unsignedBigInteger('parent_id')->index()->nullable();
        $table->mediumText('tasks')->nullable();
        $table->mediumText('roles_responsibility')->nullable();
        $table->string('much_skill', 191)->nullable();
        $table->enum('status', ['1', '0'])->nullable(); // assuming 1=active, 0=inactive
        $table->integer('is_calculated')->nullable();
        $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
        $table->unsignedBigInteger('created_by')->index()->nullable(); 
        $table->unsignedBigInteger('updated_by')->index()->nullable();
        $table->unsignedBigInteger('deleted_by')->index()->nullable();
        $table->timestamps();
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
        Schema::dropIfExists('hrms_departments_mapping');
    }
};
