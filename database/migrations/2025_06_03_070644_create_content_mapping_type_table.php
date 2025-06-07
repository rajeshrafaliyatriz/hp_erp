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
        Schema::create('content_mapping_type', function (Blueprint $table) {
            $table->bigIncrements('id');
             $table->unsignedBigInteger('content_id')->nullable()->index();
            $table->unsignedBigInteger('mapping_type_id')->nullable();
            $table->unsignedBigInteger('mapping_value_id')->nullable();
            $table->timestamps();

           $table->foreign('content_id')
                ->references('id')->on('content_master')
                ->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_mapping_type');
    }
};
