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
        Schema::create('gamma_api', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('account')->index()->nullable();
            $table->mediumText('key')->nullable();
            $table->integer('status')->index()->nullable();
            $table->integer('limit')->index()->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();
            $table->timestamps();

            $table->foreign('sub_institute_id')
                ->references('id')->on('school_setup')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gamma_api');
    }
};
