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
         Schema::create('s_industries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('industries', 191)->index()->nullable();
            $table->string('department', 191)->index()->nullable();
            $table->string('sub_department', 191)->index()->nullable();
            $table->enum('type', ['S', 'O', 'E']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('s_industries');
    }
};
