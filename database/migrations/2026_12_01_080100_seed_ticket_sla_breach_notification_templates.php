<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sales & Marketing gap-closure, Wave 6. `NotificationComposer` refuses to
 * compose without a template - a registered event type with no template row
 * is a notification that silently never arrives - so this ships in the same
 * change as the event itself, same discipline `seed_leave_notification_
 * templates.php` already set.
 *
 * Both channels are seeded (inapp AND email) even though the email channel
 * stays globally OFF (`G2G_NOTIFY_EMAIL`, see NotificationSender's own
 * header) - the smoke suite requires an active email template for every
 * NOTIFIES entry regardless, and "built, not stubbed" is the same standard
 * every other event here is held to.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            [
                'event_type'  => 'ticket.sla_breached',
                'channel'     => 'inapp',
                'locale'      => 'en',
                'subject'     => 'Ticket {payload.ticket_no} has breached its SLA',
                'body'        => "\"{payload.title}\" ({payload.priority} priority) was due a first response by "
                    . "{payload.due_at} and has not had one.\n\nOpen the ticket to respond, or reassign it if you "
                    . 'are not the right person to.',
                'action_path' => '/module/crm/support/tickets/{payload.ticket_id}',
                'is_active'   => 1,
            ],
            [
                'event_type'  => 'ticket.sla_breached',
                'channel'     => 'email',
                'locale'      => 'en',
                'subject'     => 'Ticket {payload.ticket_no} has breached its SLA',
                'body'        => "\"{payload.title}\" ({payload.priority} priority) was due a first response by "
                    . "{payload.due_at} and has not had one.\n\nOpen the ticket to respond, or reassign it if you "
                    . 'are not the right person to.',
                'action_path' => '/module/crm/support/tickets/{payload.ticket_id}',
                'is_active'   => 1,
            ],
        ];

        foreach ($rows as $row) {
            // updateOrInsert, not insert: re-running this on a database that
            // already has these rows must not create a second template for
            // the same (event_type, channel, locale) - the composer takes
            // ->first() and would then pick one arbitrarily.
            DB::table('g2g_notification_template')->updateOrInsert(
                ['event_type' => $row['event_type'], 'channel' => $row['channel'], 'locale' => $row['locale']],
                array_merge($row, ['updated_at' => $now, 'created_at' => $now])
            );
        }
    }

    public function down(): void
    {
        DB::table('g2g_notification_template')->where('event_type', 'ticket.sla_breached')->delete();
    }
};
