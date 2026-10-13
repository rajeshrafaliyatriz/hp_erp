<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emails - legacy CRM's `Emails` module (confirmed built on
 * `vtiger_activity`, one shared table across every module it's used from,
 * cross-linked via crmid-based relation tables - not a per-module table).
 * This table carries no `related_*` columns of its own on purpose - see
 * crm_email_links, which plays the same polymorphic-pivot role
 * `vtiger_cntactivityrel`/`vtiger_seactivityrel` played in legacy, and the
 * same role crm_campaign_targets already plays elsewhere in this CRM
 * subsystem. One email should be able to show on more than one record's
 * timeline (sent to a Contact, also visible on its parent Organization).
 *
 * `status` starts at 'Draft' and moves through 'Queued'/'Sent'/'Failed'.
 * Real dispatch is gated by CRM_EMAIL_SENDING_ENABLED (default false,
 * matching NotificationSender's own documented convention for the
 * identical real-external-recipient risk) - a 'Queued' row with sending
 * disabled is a fully valid, intentional end state, not a bug.
 *
 * No soft-deletes - an email that was actually sent (or queued to send)
 * is a real event that happened; append-only, same reasoning as
 * crm_sms_log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_emails', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_id')->nullable()->index();

            $table->string('subject', 255);
            $table->text('body');
            $table->string('to_address', 191);
            $table->unsignedBigInteger('template_id')->nullable()->index();
            $table->string('status', 20)->default('Draft');
            $table->timestamp('sent_at')->nullable();
            $table->text('failure_reason')->nullable();

            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('sub_institute_id')->index();
            $table->timestamps();

            $table->foreign('template_id')->references('id')->on('crm_email_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_emails');
    }
};
