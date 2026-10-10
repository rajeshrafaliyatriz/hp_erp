<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SMS Notifier's real, genuinely-live-today part: a sent-message log,
 * matching legacy's `vtiger_smsnotifier`'s real CRUD-list nature. The actual
 * transport is the existing generic integration-credential vault
 * (`g2g_integration_credentials`, provider_key 'sms'), falling back to the
 * legacy `sms_api_details` row if no vault row exists yet for a tenant - see
 * the SMS adapter, not this migration.
 *
 * Deliberately NOT built: legacy's 10-provider gateway roster (this app's
 * real usage today is one generic template-based gateway), per-recipient
 * delivery-status webhook tracking (`vtiger_smsnotifier_status`'s
 * equivalent - needs inbound webhook infrastructure this app doesn't have).
 * `related_type`/`related_id` is the same type+id pair convention
 * `crm_campaign_targets` already uses for a polymorphic pointer.
 *
 * Append-only - no soft-deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_sms_log', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('to_number', 30);
            $table->text('message');
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->text('error_reason')->nullable();

            $table->string('related_type', 30)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();

            $table->unsignedBigInteger('sent_by')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamp('created_at')->nullable();

            $table->index(['related_type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_sms_log');
    }
};
