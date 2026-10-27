<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CRM lead automation, Step 7: the notification for `crm.ticket.created` - the support onboarding ticket the automation opens
 * after a sale lands on its assignee. Both channels are seeded (email stays globally off, G2G_NOTIFY_EMAIL); NotificationComposer
 * refuses to compose without a template. updateOrInsert keeps a re-run from duplicating it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (['inapp', 'email'] as $channel) {
            DB::table('g2g_notification_template')->updateOrInsert(
                ['event_type' => 'crm.ticket.created', 'channel' => $channel, 'locale' => 'en'],
                [
                    'subject' => 'New onboarding ticket {payload.ticket_no}: {payload.title}',
                    'body' => "A sale was completed and a support onboarding ticket was opened for you (priority: {payload.priority}).\n\nOpen the ticket to see the customer, the related Opportunity, Quote, Sales Order and Invoice, and to start onboarding.",
                    'action_path' => '/module/crm/support/tickets/{payload.entity_id}', 'is_active' => 1, 'updated_at' => $now, 'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('g2g_notification_template')->where('event_type', 'crm.ticket.created')->delete();
    }
};
