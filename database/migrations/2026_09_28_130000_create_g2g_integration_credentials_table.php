<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 2. The encrypted credential vault this platform has never had.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS DID NOT EXIST BEFORE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Every working integration up to this point avoided needing one: Gemini/n8n/FCM read
 * from server `.env`, never per tenant; `lms_integrations` deliberately stores no
 * secrets, "because a governance UI reading them would put credentials back on the
 * wire" (see that migration). This table is the first place in this codebase a
 * *tenant-supplied* secret — an SMTP password, a webhook signing secret — is stored at
 * all, so it is the one place that decision has to be made correctly.
 *
 * ── `config` IS APPLICATION-LEVEL ENCRYPTED, NOT A DB FEATURE ───────────────
 *
 * `longText`, holding `Crypt::encryptString(json_encode($fields))` — never plain JSON.
 * Laravel's `Crypt` uses `APP_KEY`, so a database dump or a read-replica with different
 * access controls than the application still cannot recover a secret from this column.
 * Decryption happens in exactly one place — `IntegrationController` — never in a
 * migration, a seeder, or a query run by hand.
 *
 * ── ONE ROW PER (TENANT, PROVIDER), AND `status` IS DERIVED, NOT AUTHORITATIVE ──
 *
 * `status` is a cached summary ('not_configured' | 'configured' | 'error') for fast
 * listing; the true state is always `config` (is there one) plus `last_test_message`
 * (did the last real test succeed). A row can be `configured` with a stale, since-
 * broken endpoint — that is exactly what `last_tested_at`/`last_test_message` exist to
 * surface, not to be hidden by a status column that only ever said "configured".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('g2g_integration_credentials')) {
            return;
        }

        Schema::create('g2g_integration_credentials', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('sub_institute_id');

            // Matches a key in config('platform_services.integrations'). Validated
            // against that registry on every write — see IntegrationController.
            $table->string('provider_key', 64);

            $table->string('status', 20)->default('not_configured');

            // Crypt::encryptString(json_encode($fields)). Never plain text. Nullable:
            // a row can exist with status 'error' and no config if a save is refused
            // after a partial write is prevented — in practice this is always set
            // together with the row, but the column does not assume it.
            $table->longText('config')->nullable();

            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_message', 500)->nullable();

            $table->string('created_by', 191)->nullable();
            $table->string('updated_by', 191)->nullable();

            $table->timestamps();

            $table->unique(['sub_institute_id', 'provider_key'], 'g2g_ic_tenant_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g2g_integration_credentials');
    }
};
