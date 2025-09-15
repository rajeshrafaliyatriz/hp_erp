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
        Schema::create('hrms_weekdays', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('day', 50)->nullable(); // day VARCHAR(50)
        $table->string('day_type', 50)->nullable(); // day_type VARCHAR(50)
        $table->unsignedBigInteger('created_by')->index()->nullable(); 
        $table->unsignedBigInteger('updated_by')->index()->nullable();
        $table->unsignedBigInteger('deleted_by')->index()->nullable();
        $table->timestamps();
        $table->softDeletes();


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
        Schema::dropIfExists('hrms_weekdays');
    }
};
