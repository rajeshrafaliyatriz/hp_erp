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
        Schema::table('sub_std_map', function (Blueprint $table) {
            $table->integer('content_quantity')->nullable()->after('allow_content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sub_std_map', function (Blueprint $table) {
            $table->dropColumn('content_quantity');
        });
    }
};
