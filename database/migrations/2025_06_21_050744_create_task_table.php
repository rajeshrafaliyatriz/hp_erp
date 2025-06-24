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
        Schema::create('task', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('task_title', 191);
            $table->text('task_description')->nullable();
            $table->text('task_attachment')->nullable();
            $table->string('file_size', 191)->index()->nullable();
            $table->string('file_type', 191)->index()->nullable();
            $table->date('task_date')->index()->nullable();
            $table->string('kra', 50)->index()->nullable();
            $table->string('kpa', 50)->index()->nullable();
            $table->string('task_type', 50)->index()->nullable();
            $table->string('status', 50)->index()->nullable();
            $table->unsignedBigInteger('task_allocated')->index()->nullable();
            $table->unsignedBigInteger('task_allocated_to')->index()->nullable();
            $table->string('required_skills', 191)->index()->nullable();
            $table->text('observation_point')->nullable();
            $table->string('CREATED_IP_ADDRESS', 191)->index()->nullable();
            $table->string('SYEAR', 50)->index()->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
            $table->unsignedBigInteger('approved_by')->index()->nullable();
            $table->dateTime('approved_on')->index()->nullable();
            $table->text('reply')->nullable();

            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Foreign Keys
            $table->foreign('sub_institute_id')
                ->references('id')
                ->on('school_setup')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');

            $table->foreign('created_by')
                ->references('id')
                ->on('tbluser')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');

            $table->foreign('updated_by')
                ->references('id')
                ->on('tbluser')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');

            $table->foreign('deleted_by')
                ->references('id')
                ->on('tbluser')
                ->onDelete('NO ACTION')
                ->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task');
    }
};
