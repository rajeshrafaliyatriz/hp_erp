<?php

use App\Support\GtmMigrationSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * GTM outreach automation: scheduled sending, every attempt, suppression, inbound replies/bounces.
 *
 *   php artisan migrate --path=database/migrations/2026_10_11_140000_gtm_outreach_automation.php
 *
 * SCHEDULED SENDING. An approval may carry a time (`scheduled_for`): approving means "this exact text
 * may go at this time". The scheduler only ever sends APPROVED rows whose time has come, through the same
 * OutreachMailer gates as a manual send, claiming each atomically. `attempts`/`next_attempt_at` drive
 * bounded retries of TRANSIENT failures; a permanent failure is not retried.
 *
 * gtm_outreach_attempts: one row per attempt, including refusals, so "what happened to this email" is
 * answerable from data.
 *
 * gtm_suppressions: addresses/domains that must never be emailed, whatever a contact record says. A
 * hard bounce, a complaint and an unsubscribe each add one, so re-importing the same contact cannot
 * quietly re-enable them.
 *
 * gtm_inbound_events: every reply/bounce notification received, matched or not, with its outcome.
 * UNIQUE (provider, provider_event_id) makes redelivery harmless.
 *
 * gtm_system_state: a tiny key/value table for infrastructure facts that are not tenant data - the
 * scheduler heartbeat above all.
 */
return new class extends Migration
{
    use GtmMigrationSupport;

    public function up(): void
    {
        if ($this->has('gtm_outreach_messages')) {
            foreach ([
                'scheduled_for' => fn (Blueprint $t) => $t->timestamp('scheduled_for')->nullable(),
                'attempts' => fn (Blueprint $t) => $t->unsignedTinyInteger('attempts')->default(0),
                'last_attempt_at' => fn (Blueprint $t) => $t->timestamp('last_attempt_at')->nullable(),
                'next_attempt_at' => fn (Blueprint $t) => $t->timestamp('next_attempt_at')->nullable(),
                'outcome' => fn (Blueprint $t) => $t->string('outcome', 12)->nullable(),       // replied | bounced | complained
                'outcome_at' => fn (Blueprint $t) => $t->timestamp('outcome_at')->nullable(),
                'outcome_detail' => fn (Blueprint $t) => $t->string('outcome_detail', 255)->nullable(),
            ] as $col => $add) {
                if (! $this->hasColumn('gtm_outreach_messages', $col)) {
                    Schema::table('gtm_outreach_messages', fn (Blueprint $t) => $add($t));
                }
            }
        }

        if (! $this->has('gtm_outreach_attempts')) {
            Schema::create('gtm_outreach_attempts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->unsignedBigInteger('message_id');
                $t->unsignedTinyInteger('attempt');
                $t->string('outcome', 12);                               // sent | retry | failed | refused
                $t->string('code', 40)->nullable();
                $t->string('detail', 400)->nullable();
                $t->boolean('scheduled')->default(false);
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->timestamp('created_at')->nullable();

                $t->index(['sub_institute_id', 'message_id'], 'gtm_outreach_attempts_message_idx');
            });
        }

        if (! $this->has('gtm_suppressions')) {
            Schema::create('gtm_suppressions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id');
                $t->string('kind', 8);                                   // email | domain
                $t->string('value', 191);
                $t->string('reason', 20);                                // unsubscribed | hard_bounce | soft_bounce_limit | complaint | manual
                $t->string('note', 255)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamp('created_at')->nullable();

                $t->unique(['sub_institute_id', 'kind', 'value'], 'gtm_suppressions_unique');
            });
        }

        if (! $this->has('gtm_inbound_events')) {
            Schema::create('gtm_inbound_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('sub_institute_id')->nullable();   // null until matched
                $t->string('provider', 30);
                $t->string('provider_event_id', 191);
                $t->string('type', 12);                                  // reply | bounce | complaint
                $t->string('bounce_type', 8)->nullable();                // hard | soft
                $t->string('status', 12);                                // processed | unmatched | error
                $t->unsignedBigInteger('message_id')->nullable();
                $t->unsignedBigInteger('contact_id')->nullable();
                $t->string('detail', 500)->nullable();
                $t->text('error')->nullable();
                $t->longText('payload')->nullable();                     // truncated, never contains the signing secret
                $t->timestamp('received_at')->nullable();
                $t->timestamp('processed_at')->nullable();

                $t->unique(['provider', 'provider_event_id'], 'gtm_inbound_events_unique');
                $t->index(['sub_institute_id', 'status'], 'gtm_inbound_events_tenant_status_idx');
            });
        }

        if (! $this->has('gtm_system_state')) {
            Schema::create('gtm_system_state', function (Blueprint $t) {
                $t->string('state_key', 64)->primary();
                $t->text('value')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }

        $this->addMenus([['Reports', '/gtm/reports'], ['Monitoring', '/gtm/monitoring']]);
    }

    public function down(): void
    {
        $this->removeMenus(['/gtm/reports', '/gtm/monitoring']);
        Schema::dropIfExists('gtm_system_state');
        Schema::dropIfExists('gtm_inbound_events');
        Schema::dropIfExists('gtm_suppressions');
        Schema::dropIfExists('gtm_outreach_attempts');
        if ($this->has('gtm_outreach_messages')) {
            foreach (['scheduled_for', 'attempts', 'last_attempt_at', 'next_attempt_at', 'outcome', 'outcome_at', 'outcome_detail'] as $col) {
                if ($this->hasColumn('gtm_outreach_messages', $col)) {
                    Schema::table('gtm_outreach_messages', fn (Blueprint $t) => $t->dropColumn($col));
                }
            }
        }
    }
};
