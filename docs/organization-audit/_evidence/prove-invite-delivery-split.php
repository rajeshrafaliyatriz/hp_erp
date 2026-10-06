<?php
/**
 * EVIDENCE — the mailbox a link is sent to is not the account it sets.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE ACCOUNT TAKEOVER THIS PREVENTS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * A request: "send the set-password link for the CEO's account to
 * kalpesh@triz.co.in". Reasonable - that is the mailbox the CEO reads.
 *
 * With one parameter, the only way to honour it is `issue('kalpesh@triz.co.in')`.
 * And `password_reset_tokens.email` is the PRIMARY KEY of the token, which
 * `consume()` returns and `PasswordController::setPassword` then uses:
 *
 *     $user = DB::table('tbluser')->where('email', $email)->first(...)
 *
 * `kalpesh@triz.co.in` belongs to the ADMINISTRATOR OF A DIFFERENT ORGANISATION.
 * So that invite would have set THAT account's password, revoked all its tokens,
 * and handed another company's administrator to whoever opened the link.
 *
 * "Who this link is for" and "where to send it" were one argument, and they are
 * not one question. Section 2 is the assertion: the account that gets set is the
 * KEYED one, never the delivery one.
 *
 * Runs on DEV inside a transaction that is always rolled back. Mail is faked, so
 * nothing leaves the machine.
 *
 *   php artisan tinker --execute="require getcwd().'/docs/organization-audit/_evidence/prove-invite-delivery-split.php';"
 */

use App\Services\Auth\InviteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

DB::setDefaultConnection('mysql');
$db = DB::connection('mysql');

/*
 * The ARRAY transport, not Mail::fake().
 *
 * `InviteService` sends with `Mail::raw`, and `Mail::fake()`'s `assertSent` is
 * built around Mailable CLASSES - it demands a type-hinted closure and has no
 * Mailable to give one. The array transport plus a `MessageSending` listener sees
 * the actual recipients of a raw message, which is what has to be asserted here.
 *
 * Nothing leaves the machine either way: the array transport collects messages in
 * memory and delivers none.
 */
config(['mail.default' => 'array']);

$recipients = [];

Event::listen(\Illuminate\Mail\Events\MessageSending::class, function ($event) use (&$recipients) {
    foreach ($event->message->getTo() as $address) {
        $recipients[] = strtolower($address->getAddress());
    }
});

$tenant = 6;
$correct = 0;
$wrong = 0;

$ok = function (string $m) use (&$correct) { printf("  CORRECT  %s\n", $m); $correct++; };
$bad = function (string $m) use (&$wrong) { printf("  WRONG    %s\n", $m); $wrong++; };

$invites = app(InviteService::class);

DB::beginTransaction();

try {
    $profileId = $db->table('tbluserprofilemaster')
        ->where('sub_institute_id', $tenant)->where('role_key', 'employee')->value('id');

    $make = function (string $email) use ($db, $tenant, $profileId) {
        return $db->table('tbluser')->insertGetId([
            'user_profile_id' => $profileId,
            'sub_institute_id' => $tenant,
            'first_name' => 'Invite',
            'last_name' => 'Target',
            'email' => $email,
            'password' => \Illuminate\Support\Facades\Hash::make('OriginalPassword123'),
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    // The account the link is FOR, and a second account that merely owns the
    // mailbox - standing in for the other organisation's administrator.
    $subject = $make('invite.subject@example.test');
    $mailbox = $make('invite.mailbox@example.test');

    $passwordBefore = $db->table('tbluser')->where('id', $mailbox)->value('password');

    // ── 1. THE LINK GOES TO THE MAILBOX ─────────────────────────────────────
    echo "══ 1. the email is delivered to the address asked for ══\n";

    $result = $invites->issue('invite.subject@example.test', $tenant, 'invite', 'invite.mailbox@example.test');

    ($result['delivered'] ?? null) !== null
        ? $ok('the invite was created')
        : $bad('no invite: ' . json_encode($result));

    !empty($result['link'])
        ? $ok('and a link was produced')
        : $bad('no link in the result: ' . json_encode($result));

    /*
     * Where it actually went. `Mail::raw` is used by this class, so the assertion is
     * on the recipient of the sent message rather than on a Mailable class.
     */
    $sentTo = $recipients;

    in_array('invite.mailbox@example.test', $sentTo, true)
        ? $ok('and it was sent to the DELIVERY address, not the subject')
        : $bad('the mail went to ' . (implode(', ', $sentTo) ?: 'nowhere'));

    !in_array('invite.subject@example.test', $sentTo, true)
        ? $ok('and not to the subject address as well')
        : $bad('the subject also received it - two copies of one credential');

    // ── 2. BUT THE TOKEN IS KEYED ON THE SUBJECT ────────────────────────────
    //
    // The assertion this file exists for.
    echo "\n══ 2. the token sets the SUBJECT's password, never the mailbox owner's ══\n";

    $keyedOn = $db->table('password_reset_tokens')
        ->whereIn('email', ['invite.subject@example.test', 'invite.mailbox@example.test'])
        ->pluck('email');

    $keyedOn->contains('invite.subject@example.test')
        ? $ok('a token row exists for the subject')
        : $bad('no token for the subject: ' . $keyedOn->implode(', '));

    !$keyedOn->contains('invite.mailbox@example.test')
        ? $ok('and NONE for the mailbox owner, so their account is not reachable by this link')
        : $bad('a token was created for the mailbox owner - their account can be taken over');

    /*
     * Spend it, and check which row moved. This is the end-to-end proof: everything
     * above is about intent, this is about what the password write actually does.
     */
    $token = $db->table('password_reset_tokens')
        ->where('email', 'invite.subject@example.test')->value('token');

    $consumed = $invites->consume((string) $token);

    $consumed === 'invite.subject@example.test'
        ? $ok('consuming the token yields the subject address')
        : $bad('consume() returned ' . var_export($consumed, true));

    $db->table('tbluser')->where('id', $mailbox)->value('password') === $passwordBefore
        ? $ok("and the mailbox owner's password is untouched")
        : $bad("the mailbox owner's password CHANGED - this is the takeover");

    // ── 3. THE DEFAULT IS UNCHANGED FOR EVERY EXISTING CALLER ───────────────
    //
    // Two callers pass three arguments (EmployeeFactory and PasswordController).
    // If omitting the fourth changed behaviour, this would have broken invites and
    // password resets across the product.
    echo "\n══ 3. omitting the delivery address behaves exactly as before ══\n";

    // Reset the capture so this section measures only its own send.
    $recipients = [];
    $invites->issue('invite.subject@example.test', $tenant, 'invite');
    $plain = $recipients;

    in_array('invite.subject@example.test', $plain, true)
        ? $ok('with no delivery address, the mail goes to the account itself')
        : $bad('the three-argument form changed behaviour: sent to ' . (implode(', ', $plain) ?: 'nowhere'));

    // ── 4. AND THE LINK IS USABLE, OR THE CALLER IS TOLD WHY NOT ────────────
    echo "\n══ 4. a link nobody can open is reported, not sent silently ══\n";

    /*
     * FRONTEND_URL is `http://localhost:3000` on a developer machine, which produces
     * a link pointing at the RECIPIENT'S own computer. The class detects that and
     * returns an `error` rather than letting somebody send a dead link and wonder.
     *
     * This is why the CEO's invite has not been sent from here: it would have
     * arrived broken. It must be issued from the live server, where FRONTEND_URL is
     * the address people actually use.
     */
    $host = parse_url((string) config('app.frontend_url'), PHP_URL_HOST) ?: '';
    $local = in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true);

    $again = $invites->issue('invite.subject@example.test', $tenant, 'invite');

    if ($local) {
        !empty($again['error'])
            ? $ok('on a local FRONTEND_URL the caller is warned the link is unreachable')
            : $bad('a dead link was produced with no warning - FRONTEND_URL is ' . config('app.frontend_url'));
    } else {
        empty($again['error'])
            ? $ok('FRONTEND_URL is a real address, so no warning is needed')
            : $bad('unexpected warning: ' . $again['error']);
    }
} finally {
    DB::rollBack();
    echo "\n(rolled back - no account or token kept; no mail left the machine)\n";
}

printf("\n%d CORRECT, %d WRONG\n", $correct, $wrong);
