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
       Schema::create('sub_std_map', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('subject_id')->index()->nullable();
            $table->unsignedBigInteger('standard_id')->index()->nullable();
            $table->string('allow_grades',20)->index()->nullable();
            $table->string('elective_subject',20)->index()->nullable();
            $table->string('display_name')->index()->nullable();
            $table->string('load',20)->nullable();
            $table->string('optional_type',20)->nullable();
            $table->string('add_content',255)->default(1);
            $table->string('allow_content',20)->default(1);
            $table->string('subject_category')->nullable();
            $table->string('display_image')->nullable();
            $table->integer('sort_order')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->nullable();
            $table->integer('status')->index()->default(1);

            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

            $table->timestamps();       // created_at & updated_at
            $table->softDeletes();  

            // Foreign Keys without cascade
            $table->foreign('subject_id')
                ->references('id')->on('subject')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');

            $table->foreign('standard_id')
                ->references('id')->on('standard')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');

            $table->foreign('sub_institute_id')
                ->references('id')->on('school_setup')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');

            $table->foreign('created_by')
                ->references('id')->on('tbluser')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');

            $table->foreign('updated_by')
                ->references('id')->on('tbluser')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');

            $table->foreign('deleted_by')
                ->references('id')->on('tbluser')
                 ->nullOnDelete() 
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_std_map');
    }
};
