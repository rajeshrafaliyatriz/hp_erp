<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * THE SEND. This is the line where the system stops being reversible.
 *
 * TWO CHANNELS, AND THEY ARE NOT THE SAME KIND OF THING:
 *
 *   inapp — writes a row to g2g_notification. Real: a person opens the bell and
 *           reads it. Recoverable: a wrong one can be marked read or corrected.
 *           ON by default.
 *
 *   email — leaves the building. NOT recoverable by any means available to us.
 *           OFF by default, behind G2G_NOTIFY_EMAIL.
 *
 * WHY EMAIL IS OFF BY DEFAULT AND STAYS OFF:
 *   386 of 387 users carry a real email address, and MAIL_MAILER is a live Gmail
 *   SMTP account. Enabling this channel on a shared database means real mail to
 *   real people at real companies the moment any event fires - including a
 *   backfill, a test, or a replay bug.
 *
 *   ┌───────────────────────────────────────────────────────────────────────┐
 *   │ THREE CONDITIONS BEFORE G2G_NOTIFY_EMAIL IS EVER SET TRUE.            │
 *   │ ALL THREE. NOT ANY.  (Triz, 2026-08-11)                               │
 *   │                                                                        │
 *   │   1. The C23 WRITE HALF exists and passes. 772 write routes are        │
 *   │      currently NOT TESTED AT ALL.                                      │
 *   │   2. A TEST TENANT with fake addresses to send to.                     │
 *   │   3. TRIZ'S EXPLICIT DECISION, IN WRITING, IN THE TURN IT HAPPENS.     │
 *   │                                                                        │
 *   │ "386 real addresses at real companies is not something to switch on    │
 *   │  to see what happens."                                                 │
 *   └───────────────────────────────────────────────────────────────────────┘
 *
 *   The regression suite FAILS if this flag flips, so it cannot drift on
 *   quietly. That check is not a correctness test - it is a TRIPWIRE ON A
 *   DELIBERATE DEFAULT.
 *
 *   The channel is BUILT, not stubbed: the send path below is complete and the
 *   templates are seeded. What is withheld is the trigger, not the capability.
 */
class NotificationSender
{
    public function __construct(
        private NotificationComposer $composer,
        private \App\Services\Account\UserPreferences $preferences,
    ) {
    }

    public function emailEnabled(): bool
    {
        return filter_var(env('G2G_NOTIFY_EMAIL', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array{user_id:int, reason:string}  $recipient
     * @return array<int,string> the channels actually delivered on
     */
    public function send(object $event, array $recipient, int $tenant): array
    {
        $delivered = [];

        if ($this->sendInApp($event, $recipient, $tenant)) {
            $delivered[] = 'inapp';
        }

        /*
         * ═══════════════════════════════════════════════════════════════════
         * THE FIRST TIME THIS METHOD HAS ASKED ANYBODY WHAT THEY WANT
         * ═══════════════════════════════════════════════════════════════════
         *
         * `send()` consulted no preference of any kind: no mute, no channel
         * choice, no opt-out. Once `G2G_NOTIFY_EMAIL` is switched on, every one
         * of the ten notifiable events would email every resolved recipient,
         * for ever, with no way for any of them to stop it.
         *
         * `wantsEmail()` defaults to TRUE - a preference nobody has expressed is
         * not consent to silence, and somebody who has never opened Settings
         * should still be told their leave was approved. So this changes nothing
         * for anybody who has not asked, which is why it is safe to add to a
         * live send path.
         *
         * IN-APP IS DELIBERATELY NOT GATED. It is the product telling you
         * something happened in your own workspace, and a silent inbox is how an
         * approval sits unseen for a week.
         */
        if ($this->emailEnabled()
            && $this->preferences->wantsEmail((int) $recipient['user_id'], $event->type)
            && $this->sendEmail($event, $recipient, $tenant)) {
            $delivered[] = 'email';
        }

        return $delivered;
    }

    private function sendInApp(object $event, array $recipient, int $tenant): bool
    {
        $msg = $this->composer->compose($event, 'inapp', $tenant);
        if ($msg === null) {
            return false;
        }

        // insertOrIgnore, not insert: the unique key (event_id, user_id, channel)
        // is what makes a retried dispatch harmless. Checking first and then
        // inserting would leave a race between the check and the write.
        $written = DB::table('g2g_notification')->insertOrIgnore([
            'sub_institute_id' => $tenant,
            'user_id'          => $recipient['user_id'],
            'event_id'         => (int) $event->id,
            'event_type'       => $event->type,
            'channel'          => 'inapp',
            'subject'          => $msg['subject'],
            'body'             => $msg['body'],
            'action_url'       => $msg['action_url'],
            'recipient_reason' => $recipient['reason'],
            'created_at'       => now(),
        ]);

        return $written > 0;
    }

    private function sendEmail(object $event, array $recipient, int $tenant): bool
    {
        $address = DB::table('tbluser')
            ->where('id', $recipient['user_id'])
            ->where('sub_institute_id', $tenant)
            ->value('email');

        if (!$address) {
            return false;
        }

        $msg = $this->composer->compose($event, 'email', $tenant);
        if ($msg === null) {
            return false;
        }

        // Idempotency for an IRREVERSIBLE channel has to be claimed BEFORE the
        // send, not recorded after it. If the row inserts we own the send; if it
        // does not, someone already sent this and we must not send it again.
        $claimed = DB::table('g2g_notification')->insertOrIgnore([
            'sub_institute_id' => $tenant,
            'user_id'          => $recipient['user_id'],
            'event_id'         => (int) $event->id,
            'event_type'       => $event->type,
            'channel'          => 'email',
            'subject'          => $msg['subject'],
            'body'             => $msg['body'],
            'action_url'       => $msg['action_url'],
            'recipient_reason' => $recipient['reason'],
            'created_at'       => now(),
        ]);

        if ($claimed === 0) {
            return false;
        }

        try {
            Mail::raw($msg['body'], function ($m) use ($address, $msg) {
                $m->to($address)->subject($msg['subject']);
            });
            return true;
        } catch (\Throwable $e) {
            // The claim row STAYS. A failed send that leaves no trace is a send
            // that will be retried blindly; a claim row with a logged failure is
            // one a human can look at and decide about.
            Log::channel('single')->error('notification.email_failed', [
                'event_id' => $event->id,
                'user_id'  => $recipient['user_id'],
                'error'    => $e->getMessage(),
            ]);
            return false;
        }
    }
}
