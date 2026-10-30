<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Knowledge Graph: evidence and knowledge source store.
 *
 * Tracks provenance for every claim or enrichment datum attached to a CRM
 * entity. Each row names the entity it supports, the collection mechanism,
 * a confidence score and a verified flag. AI-generated content always starts
 * unverified; a human reviewer sets verified=1 after inspection.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_12_03_110100_create_crm_knowledge_sources_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_03_110100_create_crm_knowledge_sources_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('crm_knowledge_sources')) {
            return;
        }

        Schema::create('crm_knowledge_sources', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('entity_type', 30); // organization|contact|lead|opportunity
            $table->unsignedBigInteger('entity_id');
            $table->string('source_type', 30); // web_scrape|linkedin|news|document|manual|api
            $table->string('title', 500)->nullable();
            $table->text('url')->nullable();
            $table->text('content')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->tinyInteger('verified')->default(0);
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index(['entity_type', 'entity_id'], 'cks_entity_idx');
            $table->index('source_type', 'cks_source_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_knowledge_sources');
    }

    private function tableExists(string $table): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return Schema::hasTable($table);
        }

        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->c > 0;
    }
};
