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
        Schema::create('academic_year', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('syear', 10)->nullable()->index();
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->foreign('sub_institute_id')
                    ->references('id')
                    ->on('school_setup')
                    ->nullOnDelete()  // Optional, if you want to set to null on delete
                    ->onUpdate('NO ACTION')
                    ->onDelete('NO ACTION');  // Explicitly avoid cascade

            $table->string('title')->nullable()->index();
            $table->string('short_name')->nullable()->index();
            $table->integer('sort_order')->nullable()->index();
            $table->date('start_date')->nullable()->index();
            $table->date('end_date')->nullable()->index();
            $table->date('post_start_date')->nullable()->index();
            $table->date('post_end_date')->nullable()->index();
             $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_year');
    }
};
