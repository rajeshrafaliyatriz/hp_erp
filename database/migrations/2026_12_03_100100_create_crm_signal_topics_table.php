<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Signal Topics: keyword/topic configs that drive what gets monitored.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_12_03_100100_create_crm_signal_topics_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_03_100100_create_crm_signal_topics_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_signal_topics')) {
            return;
        }

        Schema::create('crm_signal_topics', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 191);
            $table->json('keywords');
            $table->string('topic_type', 30)->default('custom');
            $table->tinyInteger('status')->default(1)->index();
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_signal_topics');
    }
};
