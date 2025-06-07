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
        Schema::create('lms_content_category', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('category_name', 250)->index()->nullable();
            $table->unsignedBigInteger('chapter_id')->nullable()->index();
            $table->integer('status')->index()->nullable();
            $table->integer('sort_order')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();

            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('sub_institute_id')
                ->references('id')->on('school_setup')
                ->onUpdate('NO ACTION')
                ->onDelete('NO ACTION');

            $table->foreign('chapter_id')
                ->references('id')->on('chapter_master')
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
        Schema::dropIfExists('lms_content_category');
    }
};
