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
        Schema::create('signup_otp', function (Blueprint $table) {
            $table->id();
             $table->string('email');
             $table->string('otp');
             $table->timestamp('expires_at')->nullable();
             $table->boolean('is_verified')->default(false);
             $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signup_otp');
    }
};
