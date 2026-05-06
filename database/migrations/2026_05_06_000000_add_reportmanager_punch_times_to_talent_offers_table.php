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
        Schema::table('talent_offers', function (Blueprint $table) {
            $table->unsignedBigInteger('reportmanager')->nullable()->after('created_by');
            $table->time('punchintime')->nullable()->after('reportmanager');
            $table->time('punchouttime')->nullable()->after('punchintime');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('talent_offers', function (Blueprint $table) {
            $table->dropColumn(['reportmanager', 'punchintime', 'punchouttime']);
        });
    }
};