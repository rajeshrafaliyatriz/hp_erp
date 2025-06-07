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
        Schema::create('lms_virtual_classroom', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('grade_id')->index()->nullable();
            $table->unsignedBigInteger('standard_id')->index()->nullable();
            $table->unsignedBigInteger('subject_id')->index()->nullable();
            $table->unsignedBigInteger('chapter_id')->index()->nullable();
            $table->unsignedBigInteger('topic_id')->index()->nullable();

            $table->string('room_name', 255)->index()->nullable();
            $table->mediumText('description')->nullable();

            $table->date('event_date')->nullable();
            $table->time('from_time')->nullable();
            $table->time('to_time')->nullable();

            $table->string('recurring', 10)->nullable();
            $table->text('url')->nullable();
            $table->string('password', 100)->nullable();
            $table->string('status', 10)->nullable();
            $table->string('notification', 10)->nullable();
            $table->string('sort_order', 10)->nullable();

            $table->string('syear', 50)->index()->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();

            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

            $table->string('created_ip')->nullable();

            $table->timestamps();
            $table->softDeletes(); // deleted_at

            // Foreign keys with NO ACTION on delete/update
            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('standard_id')->references('id')->on('standard')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('subject_id')->references('id')->on('subject')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('chapter_id')->references('id')->on('chapter_master')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('topic_id')->references('id')->on('topic_master')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('created_by')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('updated_by')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('deleted_by')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lms_virtual_classroom');
    }
};
