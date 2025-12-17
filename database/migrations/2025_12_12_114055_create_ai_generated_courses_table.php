<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAiGeneratedCoursesTable extends Migration
{
     /**
     * Run the migrations. php artisan migrate --path=/database/migrations/2025_09_25_060836_add_fields_to_employee_monthly_salary_data_table.php
     */
    public function up()
    {
        Schema::create('ai_generated_courses', function (Blueprint $table) {

            $table->bigIncrements('id');
            $table->unsignedBigInteger('outline_id');
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->text('export_url')->nullable();
            $table->string('presentation_platform', 50)->default('Gamma');
            $table->string('course_pdf')->nullable();
            $table->dropColumn('status');
            $table->enum('status', ['completed', 'Incompleted'])->after('presentation_platform');
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
            $table->unsignedBigInteger('created_by')->index()->nullable(); 
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();
            $table->timestamps();
            $table->softDeletes();


            $table->foreign('outline_id')
                ->references('id')->on('ai_course_outlines')
                ->onDelete('CASCADE')->onUpdate('CASCADE');

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

    public function down()
    {
        Schema::dropIfExists('ai_generated_courses');
    }
}
