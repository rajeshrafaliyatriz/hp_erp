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
        Schema::create('user_rating_details', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();

            $table->foreign('user_id')
                ->references('id')->on('tbluser')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->unsignedBigInteger('jobrole_id')->index();

                $table->foreign('jobrole_id')
                ->references('id')->on('s_user_jobrole')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

                $table->mediumText('skill_ids')->nullable();
                $table->mediumText('knowledge_ids')->nullable();
                $table->mediumText('ability_ids')->nullable();
                $table->mediumText('attitude_ids')->nullable();
                $table->mediumText('behavior_ids')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->index()->nullable();

             $table->foreign('sub_institute_id')
                ->references('id')->on('school_setup')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->unsignedBigInteger('created_by')->nullable();

                $table->foreign('created_by')
                ->references('id')->on('tbluser')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');

            $table->unsignedBigInteger('updated_by')->nullable();

                $table->foreign('updated_by')
                ->references('id')->on('tbluser')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_rating_details');
    }
};
