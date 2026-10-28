<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 CRM Automation — Core Revenue Loop:
 * Per-channel consent preference log. One row per entity+channel combination;
 * updated in place (not append-only) so the current state is always a single
 * SELECT. The `consent_status` column on crm_leads/crm_contacts is the quick
 * "is this entity contactable at all?" flag; this table holds the detail —
 * which channel, how consent was obtained, when, and any notes.
 *
 * Channels: email | whatsapp | sms | phone
 * Status:   opted_in | opted_out | unknown
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_12_03_080200_create_crm_consent_preferences_table.php
 *   php artisan migrate --database=live --path=database/migrations/2026_12_03_080200_create_crm_consent_preferences_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('crm_consent_preferences')) {
            return;
        }

        Schema::create('crm_consent_preferences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('entity_type', 20);        // lead|contact
            $table->unsignedBigInteger('entity_id');
            $table->string('channel', 30);            // email|whatsapp|sms|phone
            $table->string('status', 20)->default('unknown'); // opted_in|opted_out|unknown
            $table->timestamp('captured_at')->nullable();
            $table->string('source', 191)->nullable(); // form|import|manual|inbound_reply|unsubscribe_link
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index(['entity_type', 'entity_id'], 'crm_consent_entity_idx');
            $table->unique(['entity_type', 'entity_id', 'channel'], 'crm_consent_entity_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_consent_preferences');
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
