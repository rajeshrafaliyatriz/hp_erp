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
        Schema::create('lms_portfolio', function (Blueprint $table) {
           $table->bigIncrements('id');

            $table->unsignedBigInteger('user_id')->index()->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
            $table->string('syear', 50)->index()->nullable(); // syear as string
            $table->unsignedBigInteger('user_profile_id')->index()->nullable();

            $table->string('title', 250)->nullable();
            $table->text('description')->nullable();
            $table->string('file_name', 250)->nullable();
            $table->string('type', 250)->nullable();
            $table->text('feedback')->nullable();

            $table->unsignedBigInteger('feedback_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->timestamps();
            $table->softDeletes(); // deleted_at

            // Foreign keys (NO ACTION on delete/update)
            $table->foreign('user_id')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('sub_institute_id')->references('id')->on('school_setup')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('user_profile_id')->references('id')->on('tbluserprofilemaster')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('feedback_by')->references('id')->on('tbluser')->onDelete('NO ACTION')->onUpdate('NO ACTION');
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
        Schema::dropIfExists('lms_portfolio');
    }
};
