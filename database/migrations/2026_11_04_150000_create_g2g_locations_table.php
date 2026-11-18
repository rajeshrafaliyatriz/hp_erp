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
        if (! Schema::hasTable('g2g_locations')) {
            Schema::create('g2g_locations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sub_institute_id')->nullable();
                $table->string('country', 100);
                $table->string('state', 100)->nullable();
                $table->string('city', 100)->nullable();
                $table->string('normalized_key', 191)->unique();
                $table->boolean('is_system')->default(false);
                $table->timestamps();

                $table->index(['country', 'state'], 'g2g_loc_country_state');
                $table->index('sub_institute_id', 'g2g_loc_tenant');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('g2g_locations');
    }
};

