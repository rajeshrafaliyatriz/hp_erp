<?php
/**
 * F-165 EVIDENCE — the notification opt-out actually stops an email.
 *
 * ── WHY THIS SCRIPT EXISTS ──────────────────────────────────────────────────
 *
 * A Notifications screen that saves switches nothing reads is precisely the
 * defect this product has shipped before: a "Go Live" button that wrote an
 * unread localStorage key, an invite that reported success without sending.
 * Storing a preference is not the feature. HONOURING it is.
 *
 * So this proves the loop closes at the send path itself, not at the API:
 *
 *   1. By default everybody is emailed - a preference nobody set is not consent
 *      to silence.
 *   2. Turning ONE event off stops that event and no other.
 *   3. Turning the master switch off stops everything, whatever the per-event
 *      switches say.
 *   4. IN-APP IS NEVER SUPPRESSED, at any setting.
 *   5. The switches the screen offers are exactly the events the dispatcher can
 *      send - no more, no fewer.
 *
 * `emailEnabled()` is FALSE on every environment (G2G_NOTIFY_EMAIL is absent
 * from .env), so the real send path cannot be exercised directly. It is forced
 * on for this test only, in-process, and Mail is faked - tenant 6 is on the mail
 * allowlist and MAIL_MAILER is smtp, so an unfaked run WOULD SEND REAL EMAIL.
 *
 *   php artisan tinker --execute="require getcwd().'/Docs/organization-audit/_evidence/prove-notification-optout.php';"
 */
DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');

\Illuminate\Support\Facades\Mail::fake();

$tenant = 6;

$person = $db->table('tbluser')->where('sub_institute_id', $tenant)->where('status', 1)
    ->whereNotNull('email')->first(['id', 'email']);

printf("recipient is #%d (%s), tenant %d\n\n", $person->id, $person->email, $tenant);

$prefs = app(\App\Services\Account\UserPreferences::class);

DB::beginTransaction();

try {
    // ── 5. THE SWITCHES MATCH THE DISPATCHER ────────────────────────────────
    echo "══ 1. the screen offers exactly the events that can be sent ══\n";

    $offered = \App\Services\Account\UserPreferences::notifiableEvents();
    $dispatches = \App\Services\Events\NotificationDispatcher::NOTIFIES;

    printf("  events the dispatcher sends : %d\n", count($dispatches));
    printf("  switches the screen offers  : %d\n", count($offered));
    printf("  identical sets              : %s  %s\n",
        empty(array_diff($offered, $dispatches)) && empty(array_diff($dispatches, $offered)) ? 'yes' : 'NO',
        empty(array_diff($offered, $dispatches)) && empty(array_diff($dispatches, $offered))
            ? 'CORRECT - derived, not hand-copied' : 'WRONG');
    printf("  and they are names, not indexes: %s  %s\n",
        $offered[0],
        is_string($offered[0]) && str_contains($offered[0], '.')
            ? 'CORRECT' : 'WRONG - array_keys() on a list gives 0..9');

    // ── 1. THE DEFAULT ──────────────────────────────────────────────────────
    echo "\n══ 2. somebody who has never opened Settings is still told ══\n";

    $db->table('user_preferences')->where('user_id', $person->id)->delete();

    foreach (['leave.decided', 'task.rejected'] as $event) {
        $wants = $prefs->wantsEmail((int) $person->id, $event);
        printf("  no preference stored, %-22s -> %s  %s\n", $event,
            $wants ? 'emailed' : 'silent',
            $wants ? 'CORRECT - silence is not the default' : 'WRONG');
    }

    // ── 2. ONE EVENT OFF ────────────────────────────────────────────────────
    echo "\n══ 3. turning one event off stops that event only ══\n";

    $prefs->save((int) $person->id, $tenant, ['notify_events' => ['leave.decided' => false]]);

    printf("  leave.decided switched off      -> %s  %s\n",
        $prefs->wantsEmail((int) $person->id, 'leave.decided') ? 'emailed' : 'silent',
        !$prefs->wantsEmail((int) $person->id, 'leave.decided') ? 'CORRECT' : 'WRONG');

    printf("  task.rejected left alone        -> %s  %s\n",
        $prefs->wantsEmail((int) $person->id, 'task.rejected') ? 'emailed' : 'silent',
        $prefs->wantsEmail((int) $person->id, 'task.rejected') ? 'CORRECT - untouched' : 'WRONG');

    // ── 3. THE MASTER SWITCH ────────────────────────────────────────────────
    echo "\n══ 4. the master switch beats every per-event choice ══\n";

    $prefs->save((int) $person->id, $tenant, ['notify_email' => false]);

    $anyStillOn = collect($offered)->first(fn ($e) => $prefs->wantsEmail((int) $person->id, $e));

    printf("  email switched off entirely     -> %s  %s\n",
        $anyStillOn === null ? 'every event silent' : 'STILL ON: ' . $anyStillOn,
        $anyStillOn === null ? 'CORRECT' : 'WRONG - an event escaped the master switch');

    // And it is reversible: the per-event choices were kept, not erased.
    $prefs->save((int) $person->id, $tenant, ['notify_email' => true]);

    printf("  switched back on                -> leave.decided %s, task.rejected %s  %s\n",
        $prefs->wantsEmail((int) $person->id, 'leave.decided') ? 'emailed' : 'silent',
        $prefs->wantsEmail((int) $person->id, 'task.rejected') ? 'emailed' : 'silent',
        !$prefs->wantsEmail((int) $person->id, 'leave.decided')
            && $prefs->wantsEmail((int) $person->id, 'task.rejected')
            ? 'CORRECT - the earlier choices survived' : 'WRONG - turning email back on reset them');

    // ── 5. NO SWITCH FOR SOMETHING THAT CANNOT HAPPEN ───────────────────────
    echo "\n== 5. events with no email template are not offered as email switches ==\n";

    $emailable = \App\Services\Account\UserPreferences::emailableEvents();
    $cannot = array_values(array_diff($offered, $emailable));

    printf("  events that CAN be emailed      : %d of %d\n", count($emailable), count($offered));
    printf("  events that cannot              : %s\n", implode(', ', $cannot) ?: '(none)');

    $hasTemplate = fn (string $event, string $channel) => $db->table('g2g_notification_template')
        ->where('event_type', $event)->where('channel', $channel)->where('is_active', true)->exists();

    foreach ($cannot as $event) {
        printf("    %-24s in-app %-4s email %-4s %s\n", $event,
            $hasTemplate($event, 'inapp') ? 'yes' : 'NO',
            $hasTemplate($event, 'email') ? 'yes' : 'NO',
            !$hasTemplate($event, 'email')
                ? 'CORRECT - excluded because it truly cannot'
                : 'WRONG - excluded but it does have a template');
    }

    $everyOfferedSends = collect($emailable)->every(fn ($e) => $hasTemplate($e, 'email'));
    printf("  every offered event has an email template: %s  %s\n",
        $everyOfferedSends ? 'yes' : 'no',
        $everyOfferedSends ? 'CORRECT' : 'WRONG - a switch that cannot do anything');

    // ── 6. THE SEND PATH ITSELF ─────────────────────────────────────────────
    echo "\n== 6. the send path honours it, not just the service ==\n";

    // Force the channel on for this process only. It is false everywhere.
    $_ENV['G2G_NOTIFY_EMAIL'] = 'true';
    putenv('G2G_NOTIFY_EMAIL=true');

    $sender = app(\App\Services\Notifications\NotificationSender::class);

    printf("  email channel forced on         : %s\n", $sender->emailEnabled() ? 'yes' : 'no (skipping)');

    if ($sender->emailEnabled()) {
        // A real event row, so the composer and the idempotency key have
        // something to work with. `g2g_event` has no created_at - it is an event
        // log, so the times that matter are occurred_at and recorded_at.
        $makeEvent = function (string $tag) use ($db, $tenant) {
            $id = $db->table('g2g_event')->insertGetId([
                'event_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'type' => 'task.rejected',
                'sub_institute_id' => $tenant,
                'entity_type' => 'evidence',
                'payload' => json_encode(['evidence' => $tag]),
                'occurred_at' => now(),
                'recorded_at' => now(),
            ]);

            return $db->table('g2g_event')->where('id', $id)->first();
        };

        $event = $makeEvent('first');
        $recipient = ['user_id' => (int) $person->id, 'reason' => 'evidence'];

        /*
         * task.rejected, NOT leave.decided.
         *
         * The first version of this check used leave.decided and reported a
         * clean pass on "no email sent" - which was TRUE AND MEANINGLESS,
         * because leave.decided has no email template and can never send one.
         * The control below is what exposed it: opt back in, then assert an
         * email DOES send. It did not.
         */
        $prefs->save((int) $person->id, $tenant, ['notify_events' => ['task.rejected' => false]]);

        $delivered = $sender->send($event, $recipient, $tenant);

        printf("  task.rejected (opted out)       -> delivered on [%s]  %s\n",
            implode(', ', $delivered) ?: 'nothing',
            !in_array('email', $delivered, true) ? 'CORRECT - no email' : 'WRONG - emailed anyway');

        printf("  ...but in-app still delivered   -> %s  %s\n",
            in_array('inapp', $delivered, true) ? 'yes' : 'NO',
            in_array('inapp', $delivered, true)
                ? 'CORRECT - your own workspace still tells you'
                : 'WRONG - opting out of email silenced the product');

        // THE CONTROL. Without it the assertion above passes for any reason at
        // all, including the channel being incapable of sending anything.
        $prefs->save((int) $person->id, $tenant, ['notify_events' => ['task.rejected' => true]]);

        $event2 = $makeEvent('second');
        $delivered2 = $sender->send($event2, $recipient, $tenant);

        printf("  the same event, opted back in   -> delivered on [%s]  %s\n",
            implode(', ', $delivered2) ?: 'nothing',
            in_array('email', $delivered2, true)
                ? 'CORRECT - the gate is the preference, not a dead channel'
                : 'WRONG - it never sends, so the test above proved nothing');
    }
} finally {
    putenv('G2G_NOTIFY_EMAIL');
    unset($_ENV['G2G_NOTIFY_EMAIL']);
    DB::rollBack();
    echo "\n(rolled back - no preference, event or notification kept; Mail was faked)\n";
}
