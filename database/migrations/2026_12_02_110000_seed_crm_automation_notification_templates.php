<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CRM lead automation: notification templates for the events that tell an owner "a person has to look at this now".
 *
 *   crm.lead.replied          a customer answered a lead email
 *   crm.opportunity.inactive  an open Opportunity has had no activity for the configured number of days
 *   crm.opportunity.overdue   an open Opportunity is past its closing date
 *
 * NotificationComposer refuses to compose without a template (a registered event with no template silently never arrives),
 * so these ship in the same change as the events. Both channels are seeded; the email channel stays globally off
 * (G2G_NOTIFY_EMAIL) exactly as for every other event. updateOrInsert keeps a re-run from creating a second template.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $defs = [
            'crm.lead.replied' => [
                'subject' => 'Customer replied: {payload.company} ({payload.lead_no})',
                'body' => "A customer answered your email to {payload.company}.\n\nOpen the lead to read the reply and respond. Automatic follow-up has been stopped for this lead.",
                'path' => '/module/crm/marketing/leads/{payload.entity_id}',
            ],
            'crm.opportunity.inactive' => [
                'subject' => 'Opportunity {payload.opportunity_no} has gone quiet',
                'body' => "\"{payload.name}\" has had no recorded activity for {payload.days_inactive} days.\n\nOpen the Opportunity and log the next step, or move it forward or close it.",
                'path' => '/module/crm/sales/opportunities/{payload.entity_id}',
            ],
            'crm.opportunity.overdue' => [
                'subject' => 'Opportunity {payload.opportunity_no} is past its closing date',
                'body' => "\"{payload.name}\" was due to close on {payload.closing_date} ({payload.days_overdue} days ago) and is still open.\n\nOpen the Opportunity to update the closing date, move it forward or close it.",
                'path' => '/module/crm/sales/opportunities/{payload.entity_id}',
            ],
        ];
        foreach ($defs as $event => $d) {
            foreach (['inapp', 'email'] as $channel) {
                DB::table('g2g_notification_template')->updateOrInsert(
                    ['event_type' => $event, 'channel' => $channel, 'locale' => 'en'],
                    ['subject' => $d['subject'], 'body' => $d['body'], 'action_path' => $d['path'], 'is_active' => 1, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('g2g_notification_template')->whereIn('event_type', ['crm.lead.replied', 'crm.opportunity.inactive', 'crm.opportunity.overdue'])->delete();
    }
};
