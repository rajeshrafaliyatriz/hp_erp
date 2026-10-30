<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Social Listening MVP: Signal Inbox.
 *
 * Stores ingested signals from any external source (Google News, LinkedIn, RSS,
 * manual entry). `signal_key` (SHA-256 of source_url+title) is the deduplication
 * handle so the same story ingested twice produces one row, not two.
 *
 * `crm_organizations` FK is nullable+nullOnDelete: an account link is
 * evidence-based and may be absent or wrong at ingest time; a human reviewer
 * confirms or rejects it in the review queue.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_12_03_100000_create_crm_signal_inbox_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_03_100000_create_crm_signal_inbox_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_signal_inbox')) {
            return;
        }

        Schema::create('crm_signal_inbox', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('signal_key', 64)->unique();

            $table->string('source_name', 191)->nullable();
            $table->text('source_url')->nullable();
            $table->string('title', 500);
            $table->text('body')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('ingested_at')->useCurrent();

            // Classification: set by AI on ingest, may be corrected by reviewer
            $table->string('classification', 30)->default('unclassified')->index();
            $table->decimal('classification_confidence', 3, 2)->nullable();
            $table->text('classification_reason')->nullable();
            $table->decimal('relevance_score', 3, 2)->nullable();

            // Account linking — evidence-based, not enforced
            $table->unsignedBigInteger('linked_account_id')->nullable()->index();
            $table->decimal('linked_account_confidence', 3, 2)->nullable();

            // Review workflow
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            // Promotion (lead/opportunity created from this signal)
            $table->string('promoted_to', 30)->nullable();
            $table->unsignedBigInteger('promoted_id')->nullable();

            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->json('tags')->nullable();

            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->index('published_at');

            $table->foreign('linked_account_id')
                ->references('id')->on('crm_organizations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_signal_inbox');
    }
};
