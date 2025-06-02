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
        Schema::create('subject', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('subject_name', 255)->index();
            $table->string('subject_code', 100)->index()->nullable();
            $table->string('subject_type', 100)->index()->nullable();
            $table->string('short_name', 100)->index()->nullable();

            $table->integer('status')->index()->default(1)->nullable();

            $table->unsignedBigInteger('marking_period_id')->index()->nullable();
            $table->unsignedBigInteger('sub_institute_id')->nullable();
            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

            $table->timestamps();       // created_at & updated_at
            $table->softDeletes();

            // Foreign keys without cascade
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
        Schema::dropIfExists('subject');
    }
};
