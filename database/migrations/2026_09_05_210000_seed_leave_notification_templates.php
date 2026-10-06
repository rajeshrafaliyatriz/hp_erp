<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What a leave notification actually says. F-128.
 *
 * NotificationComposer refuses to compose without a template - it returns null
 * and the send is skipped - so a registered event type with no template row is
 * a notification that silently never arrives. That failure mode is quiet, which
 * is why these ship in the same sprint as the events rather than after them.
 *
 * WRITING NOTES, because the copy is the product here:
 *
 *   - {payload.x} is substituted from the event; {term:x} from the tenant's own
 *     terminology map. These use payload only - "leave" is not a term any
 *     institute has renamed, and inventing a terminology key nobody set would
 *     render an empty word.
 *   - Every subject says WHAT HAPPENED, not what the system did. "Farida Khan
 *     approved your leave" beats "Leave request status updated".
 *   - Every body ends with what the reader should DO. A notification that only
 *     informs is a line in a list; one that asks is a task.
 *   - action_path deep-links to the screen that can act, so the notification is
 *     one click from being dealt with.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            [
                'event_type'  => 'leave.submitted',
                'channel'     => 'inapp',
                'locale'      => 'en',
                'subject'     => '{payload.employee_name} has applied for leave',
                'body'        => "{payload.employee_name} has applied for leave on {payload.dates}.\n\n"
                    . "You are step {payload.step} of {payload.of} in the approval chain, so it is waiting "
                    . "on you before anyone else can act on it.\n\nOpen the request to approve, reject or "
                    . 'send it back for amendment.',
                'action_path' => '/module/hrit-solutions/leave-management/leave-requests?id={payload.leave_id}',
                'is_active'   => 1,
            ],
            [
                'event_type'  => 'leave.decided',
                'channel'     => 'inapp',
                'locale'      => 'en',
                'subject'     => 'Your leave request was {payload.decision}',
                // Deliberately written to cover BOTH cases in one template: the
                // final decision, and the intermediate "it moved on" that the
                // product has never told anybody about. The next-approver line
                // renders empty when there is no next approver, because the
                // composer drops unresolved placeholders rather than printing
                // them - so a finished request does not end mid-sentence.
                'body'        => "Your leave request has been {payload.decision} at step {payload.step} "
                    . "of {payload.of}.\n\nOpen it to see the full approval chain and who decided what.",
                'action_path' => '/module/hrit-solutions/leave-management/leave-requests?id={payload.leave_id}',
                'is_active'   => 1,
            ],
            [
                'event_type'  => 'leave.escalated',
                'channel'     => 'inapp',
                'locale'      => 'en',
                'subject'     => 'Overdue leave approval escalated to you',
                'body'        => "A leave request has been waiting on {payload.from_label} since "
                    . "{payload.waiting_since} - longer than your organisation allows.\n\n"
                    . "It has been escalated to {payload.to_label}, which means you can now decide it as "
                    . "well as they can. They keep their right to decide it; escalation widens who may "
                    . "act, it does not take the work away from them.\n\nOpen the request to review it.",
                'action_path' => '/module/hrit-solutions/leave-management/leave-requests?id={payload.leave_id}',
                'is_active'   => 1,
            ],
        ];

        foreach ($rows as $row) {
            // updateOrInsert, not insert: re-running this migration on a database
            // that already has these must not create a second template for the
            // same (event_type, channel, locale) - the composer takes ->first()
            // and would then pick one arbitrarily.
            DB::table('g2g_notification_template')->updateOrInsert(
                [
                    'event_type' => $row['event_type'],
                    'channel'    => $row['channel'],
                    'locale'     => $row['locale'],
                ],
                array_merge($row, ['updated_at' => $now, 'created_at' => $now])
            );
        }
    }

    public function down(): void
    {
        DB::table('g2g_notification_template')
            ->whereIn('event_type', ['leave.submitted', 'leave.decided', 'leave.escalated'])
            ->delete();
    }
};
