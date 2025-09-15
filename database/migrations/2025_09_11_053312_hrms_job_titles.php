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
        Schema::create('hrms_job_titles', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('title')->nullable(); // title VARCHAR (default 255)
        $table->text('description')->nullable(); // description TEXT
        $table->unsignedBigInteger('client_id')->index()->nullable(); // client_id BIGINT 20
        $table->unsignedBigInteger('sub_institute_id')->index()->nullable();  // sub_institute_id BIGINT 20
        $table->boolean('is_active')->default(true)->nullable(); // is_active TINYINT (as boolean)  
        $table->unsignedBigInteger('created_by')->index()->nullable(); 
        $table->unsignedBigInteger('updated_by')->index()->nullable();
        $table->unsignedBigInteger('deleted_by')->index()->nullable();
        $table->timestamps();
        $table->softDeletes();

         $table->foreign('client_id')
                ->references('id')->on('tblclient')
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
        Schema::dropIfExists('hrms_job_titles');
    }
};
