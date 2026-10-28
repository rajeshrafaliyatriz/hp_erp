<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CRM lead automation, Step 6: the notification for `crm.invoice.overdue` - an Invoice still has a balance after its due date.
 * Both channels are seeded (the email channel stays globally off, G2G_NOTIFY_EMAIL, like every other event); NotificationComposer
 * refuses to compose without a template, so this ships with the event. updateOrInsert keeps a re-run from duplicating it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (['inapp', 'email'] as $channel) {
            DB::table('g2g_notification_template')->updateOrInsert(
                ['event_type' => 'crm.invoice.overdue', 'channel' => $channel, 'locale' => 'en'],
                [
                    'subject' => 'Invoice {payload.invoice_no} is overdue',
                    'body' => "\"{payload.subject}\" was due on {payload.due_date} ({payload.days_overdue} days ago) and {payload.balance_due} {payload.currency} is still unpaid.\n\nOpen the invoice to follow up with the customer or record a payment you have received.",
                    'action_path' => '/module/crm/sales/invoices/{payload.entity_id}', 'is_active' => 1, 'updated_at' => $now, 'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('g2g_notification_template')->where('event_type', 'crm.invoice.overdue')->delete();
    }
};
