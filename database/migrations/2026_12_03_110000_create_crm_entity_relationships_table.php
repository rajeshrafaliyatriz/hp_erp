<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Knowledge Graph: canonical entity relationship store.
 *
 * Stores explicit, evidence-backed connections between any two CRM entities:
 * Account ↔ Contact, Account ↔ Signal, Account ↔ Opportunity, etc.
 * Each row carries provenance (source, source_url), confidence, validity
 * window and a verified flag so AI-inferred edges are never silently promoted
 * to verified facts.
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_12_03_110000_create_crm_entity_relationships_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_03_110000_create_crm_entity_relationships_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('crm_entity_relationships')) {
            return;
        }

        Schema::create('crm_entity_relationships', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('from_type', 30); // organization|contact|lead|opportunity|signal|campaign
            $table->unsignedBigInteger('from_id');
            $table->string('to_type', 30);
            $table->unsignedBigInteger('to_id');
            $table->string('relationship_type', 50); // contact_of|signal_for|opportunity_for|influenced_by|converted_from|referred_by|partner_of
            $table->decimal('confidence', 3, 2)->nullable(); // 0.00–1.00
            $table->string('source', 191)->nullable(); // manual|enrichment|ai_inference|system
            $table->text('source_url')->nullable();
            $table->text('evidence')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->tinyInteger('verified')->default(0);
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->index(['from_type', 'from_id'], 'cer_from_idx');
            $table->index(['to_type', 'to_id'], 'cer_to_idx');
            $table->index('relationship_type', 'cer_rel_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_entity_relationships');
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
