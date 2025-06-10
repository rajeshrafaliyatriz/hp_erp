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
        Schema::create('question_type_master', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('question_type', 191)->index();
            $table->integer('status')->index()->default(1);

            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
            $table->string('syear', 50)->index()->nullable();

            $table->unsignedBigInteger('created_by')->index()->nullable();
            $table->dateTime('created_on')->nullable();

            $table->unsignedBigInteger('updated_by')->index()->nullable();
            $table->unsignedBigInteger('deleted_by')->index()->nullable();

            $table->timestamps(); // adds created_at and updated_at
            $table->softDeletes(); // adds deleted_at

            // Foreign Key Constraints (No Cascade on Delete/Update)
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
        Schema::dropIfExists('question_type_master');
    }
};
