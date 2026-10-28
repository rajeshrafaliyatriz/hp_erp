<?php

use App\Support\GtmMigrationSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * CRM lead automation, Step 3: detecting and recording customer replies.
 *
 *   php artisan migrate --path=database/migrations/2026_12_02_100000_crm_inbound_replies.php
 *
 * crm_emails gets three columns so a reply can be MATCHED and SHOWN:
 *   message_id    the Message-ID CrmOutreachMailer now puts on every outgoing email. A reply's In-Reply-To/References
 *                 header carries it back - the only reliable way to know which email (and so which lead and tenant) a
 *                 reply answers. Rows sent before this existed have none, and can only be matched by sender address.
 *   direction     outbound (every existing row) | inbound (a customer's reply, shown on the lead's Emails tab).
 *   from_address  who an inbound email came from.
 *   in_reply_to_inbound_id  on an AI-suggested DRAFT, the customer reply it answers (so one reply never gets two drafts).
 *
 * crm_inbound_events is the intake ledger, the CRM twin of gtm_inbound_events: UNIQUE (provider, provider_event_id) is what
 * makes a redelivered webhook harmless, and every event - matched or not - leaves a row saying what was done with it.
 * `classification` is filled later by the workflow's classify step.
 */
return new class extends Migration
{
    use GtmMigrationSupport;

    public function up(): void
    {
        foreach (['message_id' => fn (Blueprint $t) => $t->string('message_id', 191)->nullable()->index(), 'direction' => fn (Blueprint $t) => $t->string('direction', 10)->default('outbound'),
            'from_address' => fn (Blueprint $t) => $t->string('from_address', 191)->nullable(),
            'in_reply_to_inbound_id' => fn (Blueprint $t) => $t->unsignedBigInteger('in_reply_to_inbound_id')->nullable()->index()] as $column => $define) {
            if (! $this->hasColumn('crm_emails', $column)) {
                Schema::table('crm_emails', fn (Blueprint $t) => $define($t));
            }
        }

        if (! $this->has('crm_inbound_events')) {
            Schema::create('crm_inbound_events', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('sub_institute_id')->nullable();   // derived from the matched email/lead, NEVER from the payload
                $t->string('provider', 30);
                $t->string('provider_event_id', 191);
                $t->string('type', 12);                                   // reply | bounce | complaint
                $t->string('bounce_type', 4)->nullable();                 // hard | soft
                $t->string('status', 12)->default('unmatched');           // unmatched | processed | ignored | error
                $t->unsignedBigInteger('lead_id')->nullable();
                $t->unsignedBigInteger('email_id')->nullable();           // the outbound email this answers
                $t->unsignedBigInteger('inbound_email_id')->nullable();   // the crm_emails row created for the reply
                $t->string('classification', 30)->nullable();
                $t->timestamp('classified_at')->nullable();
                $t->mediumText('payload')->nullable();
                $t->string('detail', 500)->nullable();
                $t->string('error', 500)->nullable();
                $t->timestamp('received_at')->nullable();
                $t->timestamp('processed_at')->nullable();

                $t->unique(['provider', 'provider_event_id'], 'crm_inbound_events_unique');
                $t->index(['sub_institute_id', 'lead_id'], 'crm_inbound_events_lead_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_inbound_events');
        foreach (['message_id', 'direction', 'from_address', 'in_reply_to_inbound_id'] as $column) {
            if ($this->hasColumn('crm_emails', $column)) {
                Schema::table('crm_emails', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
