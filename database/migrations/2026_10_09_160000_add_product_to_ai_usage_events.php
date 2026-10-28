<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D6 (shared cross-product AI gateway), minimal first step: a `product`
 * column so this table's rows can sit in a cross-product usage view
 * alongside EB's (same schema name, `hpbrain_ai_usage_events`) and a new
 * K-12 table of the same shape. Defaulted so every existing row is tagged
 * without a backfill, and AiUsageMeter needs no change to keep writing
 * correctly-tagged rows going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_events', function (Blueprint $table) {
            $table->string('product', 16)->default('g2g')->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_events', function (Blueprint $table) {
            $table->dropColumn('product');
        });
    }
};
