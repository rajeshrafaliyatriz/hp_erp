<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What a capability-gap-driven learning notification actually says.
 *
 * Three event types gain a NotificationDispatcher consumer in this phase
 * (`learning.auto_assigned`, `capability.course_gap_flagged`,
 * `learning.assignment_due_soon`), and NotificationComposer::compose() returns
 * null - skipping the send silently - when no active template row exists for
 * (event_type, channel, locale). Shipped in the same change as the NOTIFIES
 * entries, same discipline as the leave templates (2026_09_05_210000).
 *
 * action_path deep-links to the screen that can act on each notification:
 * the learner's own course for the first, the new Capability Gap Inbox for
 * the second (so HR lands on the exact competency, not a generic list), and
 * the learner's course again for the due-soon reminder.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            [
                'event_type'  => 'learning.auto_assigned',
                'channel'     => 'inapp',
                'locale'      => 'en',
                'subject'     => 'A course has been assigned to you: {payload.course_name}',
                'body'        => "Your {payload.competency_name} rating is below what your role requires, "
                    . "so {payload.course_name} has been assigned to you automatically.\n\n"
                    . "Complete it by {payload.due_date} ({payload.assignment_type}).\n\nOpen it to get started.",
                'action_path' => '/module/lms/my-learning?course_id={payload.course_id}',
                'is_active'   => 1,
            ],
            [
                'event_type'  => 'capability.course_gap_flagged',
                'channel'     => 'inapp',
                'locale'      => 'en',
                'subject'     => 'No course exists for a capability gap: {payload.competency_name}',
                'body'        => "{payload.affected_count} employee(s) are below the level "
                    . "{payload.competency_name} requires, and no course is mapped to close it.\n\n"
                    . 'Create or map a course for this competency to assign it to everyone affected.',
                'action_path' => '/module/lms/training-and-records/capability-gap-inbox?competency_id={payload.competency_id}',
                'is_active'   => 1,
            ],
            [
                'event_type'  => 'learning.assignment_due_soon',
                'channel'     => 'inapp',
                'locale'      => 'en',
                'subject'     => '{payload.course_name} is due in {payload.days_remaining} day(s)',
                'body'        => "{payload.course_name} is due on {payload.due_date} "
                    . "({payload.days_remaining} day(s) remaining).\n\nOpen it to continue.",
                'action_path' => '/module/lms/my-learning?course_id={payload.course_id}',
                'is_active'   => 1,
            ],
        ];

        foreach ($rows as $row) {
            // updateOrInsert, not insert: re-running this migration must not
            // create a second template for the same (event_type, channel,
            // locale) - the composer takes ->first() and would pick one
            // arbitrarily between duplicates.
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
            ->whereIn('event_type', [
                'learning.auto_assigned',
                'capability.course_gap_flagged',
                'learning.assignment_due_soon',
            ])
            ->delete();
    }
};
