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
        Schema::create('s_jobrole', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('track', 191)->index()->nullable();
            $table->string('jobrole', 191)->index()->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->enum('type', ['S', 'O', 'E'])->nullable();
            $table->string('code', 20)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('s_jobrole');
    }
};
