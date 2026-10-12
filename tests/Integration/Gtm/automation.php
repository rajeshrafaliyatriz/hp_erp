<?php
use App\Domain\Gtm\GtmScheduler;
use App\Domain\Gtm\Outreach\InboundSignature;
use Illuminate\Support\Facades\{Cache, DB, Mail};
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

if (! class_exists('GtmAutoTransport')) {
    /** Records what would be delivered, or fails with a chosen SMTP message. Nothing ever leaves the machine. */
    class GtmAutoTransport extends AbstractTransport
    {
        public static array $sent = [];
        public static ?string $fail = null;
        protected function doSend(SentMessage $message): void
        {
            if (self::$fail !== null) { throw new TransportException(self::$fail); }
            self::$sent[] = $message->getOriginalMessage();
        }
        public function __toString(): string { return 'gtmauto'; }
    }
}

$origStage = DB::table('gtm_accounts')->where('id', 3)->value('stage');
$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n"; };
$req = function ($m, $u, $b = [], $tok = null) use ($kernel, $A) { Cache::flush(); return call($kernel, $m, $u, $tok ?? $A, $b); };
$tick = fn () => app(GtmScheduler::class)->tick();
$sendDue = fn () => app(GtmScheduler::class)->sendDue();
$msg = fn ($id) => DB::table('gtm_outreach_messages')->where('id', $id)->first();
$body = 'Hello, this is a verification message that is comfortably longer than twenty characters.';
$secret = 'verify-inbound-secret'; $otherTenant = 0;

Mail::extend('gtmauto', fn (array $config) => new GtmAutoTransport());
config(['mail.mailers.gtmauto' => ['transport' => 'gtmauto'], 'mail.default' => 'gtmauto', 'mail.from.address' => 'sender@example.invalid', 'app.url' => 'https://api.example.com',
    'gtm.outreach.sending_enabled' => true, 'gtm.outreach.scheduler_enabled' => true, 'gtm.outreach.daily_cap' => 50, 'gtm.inbound.secret' => $secret]);
Mail::purge();

$mk = function (string $name, string $email, ?int $account = null) use ($req) {
    [$c, $j] = $req('POST', '/gtm/accounts/' . ($account ?? 3) . '/contacts', ['full_name' => $name, 'email' => $email]);
    return $j['data']['contact']['id'];
};
/** draft -> submit -> approve (optionally scheduled) and return the id */
$approved = function (int $contact, ?string $sendAt = null, string $subject = 'Verify subject') use ($req, $body) {
    [$c, $j] = $req('POST', '/gtm/outreach', ['contact_id' => $contact, 'subject' => $subject, 'body' => $body]);
    $id = $j['data']['message']['id'];
    $req('POST', "/gtm/outreach/$id/submit");
    $req('POST', "/gtm/outreach/$id/approve", ['confirm' => true] + ($sendAt ? ['send_at' => $sendAt] : []));
    return $id;
};
$due = fn (int $id) => DB::table('gtm_outreach_messages')->where('id', $id)->update(['scheduled_for' => now()->subMinutes(2), 'next_attempt_at' => null]);

try {
    $c1 = $mk('Verify Auto One', 'verify.auto1@example.invalid');
    $c2 = $mk('Verify Auto Two', 'verify.auto2@example.invalid');
    $c3 = $mk('Verify Auto Three', 'verify.auto3@example.invalid');

    // ================= A. editing after approval withdraws the approval =================
    $m = $approved($c1);
    $hash = $msg($m)->approved_hash;
    $ok('approved with a pinned hash', $msg($m)->status === 'approved' && strlen($hash) === 64);
    [$c, $j] = $req('PATCH', "/gtm/outreach/$m", ['body' => $body]);
    $ok('a no-op save does NOT withdraw the approval', $c === 200 && $msg($m)->status === 'approved' && $msg($m)->approved_hash === $hash, [$c, $j]);
    [$c, $j] = $req('PATCH', "/gtm/outreach/$m", ['body' => $body . ' Now with an extra sentence.']);
    $row = $msg($m);
    $ok('editing approved text withdraws the approval: back to draft, hash and schedule cleared', $c === 200 && $row->status === 'draft' && $row->approved_hash === null && $row->approved_by === null && $row->scheduled_for === null, [$c, $row]);
    $ok('...and that is audited as approval_invalidated', DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'gtm.outreach.approval_invalidated')->where('entity_id', $m)->exists());
    [$c] = $req('POST', "/gtm/outreach/$m/send");                                $ok('a withdrawn message cannot be sent (409)', $c === 409, $c);
    $req('POST', "/gtm/outreach/$m/submit");
    [$c, $j] = $req('PATCH', "/gtm/outreach/$m", ['subject' => 'A different subject']);
    $ok('editing a message that is only awaiting approval returns it to draft', $c === 200 && $msg($m)->status === 'draft', [$c, $j]);
    DB::table('gtm_outreach_messages')->where('id', $m)->update(['status' => 'sent']);
    [$c, $j] = $req('PATCH', "/gtm/outreach/$m", ['body' => $body . ' again']); $ok('a sent message cannot be edited (422 locked)', $c === 422 && $j['code'] === 'locked', [$c, $j]);

    // ================= B. approve-and-schedule =================
    $x = $approved($c2);
    $req('PATCH', "/gtm/outreach/$x", ['body' => $body . ' v2']); $req('POST', "/gtm/outreach/$x/submit");
    foreach (['in the past' => now()->subHours(3)->toDateTimeString(), 'beyond 90 days' => now()->addDays(120)->toDateTimeString(), 'not a date' => 'whenever'] as $label => $at) {
        [$c, $j] = $req('POST', "/gtm/outreach/$x/approve", ['confirm' => true, 'send_at' => $at]); $ok("approve with a send time $label -> 422", $c === 422, [$c, $j]);
    }
    [$c] = $req('POST', "/gtm/outreach/$x/approve", ['confirm' => true, 'send_at' => now()->addDay()->toDateTimeString()], $E); $ok('employee cannot approve/schedule (403)', $c === 403, $c);
    [$c, $j] = $req('POST', "/gtm/outreach/$x/approve", ['confirm' => true, 'send_at' => now()->addDay()->toDateTimeString()]);
    $ok('approve with a future send time schedules it', $c === 200 && $j['data']['message']['status'] === 'approved' && $j['data']['message']['scheduled_for'] !== null, [$c, $j]);
    $ok('scheduling is audited', DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'gtm.outreach.scheduled')->where('entity_id', $x)->exists());
    [$c, $j] = $req('POST', "/gtm/outreach/$x/unschedule"); $ok('unschedule: stays approved, no send time', $c === 200 && $msg($x)->scheduled_for === null && $msg($x)->status === 'approved', [$c, $j]);
    [$c] = $req('POST', "/gtm/outreach/$x/unschedule");     $ok('unscheduling an unscheduled message -> 422', $c === 422, $c);

    // ================= C. the scheduler =================
    config(['gtm.outreach.scheduler_enabled' => false]);
    $s1 = $approved($c1, now()->addMinute()->toDateTimeString(), 'Verify S1'); $due($s1);
    $r = $sendDue(); $ok('scheduler disabled: nothing sent, says why', isset($r['skipped']) && $msg($s1)->status === 'approved', $r);
    config(['gtm.outreach.scheduler_enabled' => true]); GtmAutoTransport::$sent = [];
    $future = $approved($c3, now()->addDay()->toDateTimeString(), 'Verify Future');
    $draftSched = $req('POST', '/gtm/outreach', ['contact_id' => $c3, 'subject' => 'Verify Draft', 'body' => $body])[1]['data']['message']['id'];
    DB::table('gtm_outreach_messages')->where('id', $draftSched)->update(['scheduled_for' => now()->subHour()]);
    $cancelled = $approved($c3, now()->addMinute()->toDateTimeString(), 'Verify Cancelled'); $req('POST', "/gtm/outreach/$cancelled/cancel"); DB::table('gtm_outreach_messages')->where('id', $cancelled)->update(['scheduled_for' => now()->subHour()]);
    $r = $sendDue();
    $ok('a due, approved message is sent by the scheduler', $r['sent'] === 1 && $msg($s1)->status === 'sent' && count(GtmAutoTransport::$sent) === 1, [$r, count(GtmAutoTransport::$sent)]);
    $ok('NOT sent: a future-scheduled message, a draft with a send time, a cancelled message', $msg($future)->status === 'approved' && $msg($draftSched)->status === 'draft' && $msg($cancelled)->status === 'cancelled', [$msg($future)->status, $msg($draftSched)->status, $msg($cancelled)->status]);
    $mail = GtmAutoTransport::$sent[0] ?? null; $pid = $msg($s1)->provider_message_id;
    $ok('the email carries a Message-ID we generated, matching what we stored', $mail && $mail->getHeaders()->get('Message-ID')->getBodyAsString() === "<$pid>" && preg_match('/^gtm\.6\.' . $s1 . '\.[a-f0-9]{12}@example\.invalid$/', $pid), $pid);
    $att = DB::table('gtm_outreach_attempts')->where('message_id', $s1)->get();
    $ok('the attempt is recorded: sent, scheduled=1, attempt number 1', $att->count() === 1 && $att[0]->outcome === 'sent' && (int) $att[0]->scheduled === 1 && (int) $att[0]->attempt === 1 && (int) $msg($s1)->attempts === 1, $att->all());
    $r2 = $sendDue(); $ok('a second pass sends nothing more (no duplicate send)', $r2['sent'] === 0 && count(GtmAutoTransport::$sent) === 1, $r2);
    [$c, $j] = $req('POST', "/gtm/outreach/$s1/send"); $ok('a person cannot resend an already-sent message (409)', $c === 409, $c);

    // daily cap
    $cap1 = $approved($c1, now()->addMinute()->toDateTimeString(), 'Verify Cap1'); $due($cap1);
    $cap2 = $approved($c2, now()->addMinute()->toDateTimeString(), 'Verify Cap2'); $due($cap2);
    $sentToday = DB::table('gtm_outreach_messages')->where('sub_institute_id', 6)->where('status', 'sent')->where('sent_at', '>=', now()->startOfDay())->count();
    config(['gtm.outreach.daily_cap' => $sentToday + 1]);
    $r = $sendDue(); $ok('daily cap: exactly one more goes, the other waits', $r['sent'] === 1 && $r['deferred'] >= 1 && collect([$msg($cap1)->status, $msg($cap2)->status])->sort()->values()->all() === ['approved', 'sent'], [$r, $msg($cap1)->status, $msg($cap2)->status]);
    config(['gtm.outreach.daily_cap' => 50]); $r = $sendDue(); $ok('the deferred one goes when the cap allows', $r['sent'] === 1, $r);

    // sequence order
    $seq = ['recipient' => ['contact_id' => $c3], 'is_draft' => true, 'steps' => [['step' => 1, 'subject' => 'Verify Seq 1', 'body' => $body . ' one'], ['step' => 2, 'subject' => 'Verify Seq 2', 'body' => $body . ' two'], ['step' => 3, 'subject' => 'Verify Seq 3', 'body' => $body . ' three']]];
    $an = DB::table('gtm_analyses')->insertGetId(['sub_institute_id' => 6, 'kind' => 'outreach_draft', 'subject_type' => 'contact', 'subject_id' => $c3, 'result' => json_encode($seq), 'status' => 'success', 'is_estimate' => 1, 'created_at' => now(), 'updated_at' => now()]);
    [$c, $j] = $req('POST', '/gtm/outreach/from-analysis', ['analysis_id' => $an]); $key = $j['data']['messages'][0]['sequence_key']; [$q1, $q2, $q3] = array_column($j['data']['messages'], 'id');
    [$c, $j] = $req('POST', "/gtm/outreach/sequences/$key/approve", ['confirm' => true]);   $ok('approve a sequence whose steps are still drafts -> 422', $c === 422 && $j['code'] === 'steps_not_submitted', [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/outreach/sequences/$key/submit");                         $ok('submit a whole sequence in one action', $c === 200 && $j['data']['submitted'] === 3, [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/outreach/sequences/$key/approve");                         $ok('approve a sequence without confirm -> 409', $c === 409, $c);
    [$c] = $req('POST', "/gtm/outreach/sequences/$key/approve", ['confirm' => true], $E);     $ok('employee cannot approve a sequence (403)', $c === 403, $c);
    [$c, $j] = $req('POST', "/gtm/outreach/sequences/$key/approve", ['confirm' => true, 'schedule' => true, 'start_at' => now()->addMinutes(5)->toDateTimeString()]);
    $steps = collect($j['data']['steps'] ?? []);
    $offs = $steps->map(fn ($s) => (int) round((strtotime($s['scheduled_for']) - strtotime($steps[0]['scheduled_for'])) / 86400))->all();
    $ok('approve-all with a schedule: every step approved with its own hash, spaced as the playbook says (0, 3, 8 days)', $c === 200 && $steps->every(fn ($s) => $s['status'] === 'approved' && strlen($s['approved_hash']) === 64) && $offs === [0, 3, 8], [$c, $offs]);
    DB::table('gtm_outreach_messages')->where('id', $q2)->update(['scheduled_for' => now()->subMinute(), 'next_attempt_at' => null]);   // step 2 due, step 1 NOT sent
    GtmAutoTransport::$sent = []; $r = $sendDue();
    $ok('step 2 will not go before step 1 (deferred 10 minutes, not sent)', $r['sent'] === 0 && $msg($q2)->status === 'approved' && $msg($q2)->next_attempt_at !== null && count(GtmAutoTransport::$sent) === 0, [$r, $msg($q2)]);
    $due($q1); DB::table('gtm_outreach_messages')->where('id', $q2)->update(['next_attempt_at' => null]);
    $r = $sendDue(); $ok('step 1 goes; step 2 follows once 1 is sent', $msg($q1)->status === 'sent', $r);
    $r = $sendDue(); $ok('...and step 2 on the next pass', $msg($q2)->status === 'sent', $r);
    DB::table('gtm_activities')->insert(['sub_institute_id' => 6, 'account_id' => 3, 'contact_id' => $c3, 'type' => 'email', 'direction' => 'inbound', 'subject' => 'Re: Verify Seq 1', 'occurred_at' => now()->addSeconds(30), 'created_at' => now(), 'updated_at' => now()]);
    $due($q3); GtmAutoTransport::$sent = []; $r = $sendDue();
    $ok('a recorded reply stops the sequence: step 3 is cancelled, not sent', $msg($q3)->status === 'cancelled' && count(GtmAutoTransport::$sent) === 0 && $r['cancelled'] >= 1, [$r, $msg($q3)->status]);

    // blocked / suppressed contacts are never sent to
    $b1 = $approved($c2, now()->addMinute()->toDateTimeString(), 'Verify Blocked'); $due($b1);
    DB::table('gtm_contacts')->where('id', $c2)->update(['status' => 'bounced']);
    GtmAutoTransport::$sent = []; $r = $sendDue();
    $ok('a contact marked bounced after approval: the send is cancelled, nothing leaves', $msg($b1)->status === 'cancelled' && count(GtmAutoTransport::$sent) === 0, [$r, $msg($b1)->status]);
    DB::table('gtm_contacts')->where('id', $c2)->update(['status' => 'active']);

    // ================= D. suppression list =================
    [$c] = $req('POST', '/gtm/suppressions', ['kind' => 'email', 'value' => 'x@example.invalid'], $E);            $ok('suppression add: employee 403', $c === 403, $c);
    [$c] = $req('GET', '/gtm/suppressions', [], $E);                                                               $ok('suppression list: employee 403', $c === 403, $c);
    foreach (['bad email' => ['kind' => 'email', 'value' => 'not-an-email'], 'bad domain' => ['kind' => 'domain', 'value' => 'no_tld'], 'bad kind' => ['kind' => 'phone', 'value' => '123'], 'missing value' => ['kind' => 'email']] as $label => $b) { [$c] = $req('POST', '/gtm/suppressions', $b); $ok("suppression invalid ($label) -> 422", $c === 422, $c); }
    [$c, $j] = $req('POST', '/gtm/suppressions', ['kind' => 'email', 'value' => 'VERIFY.Auto2@Example.Invalid', 'note' => 'asked by phone']); $sup = $j['data']['item']['id'] ?? 0;
    $ok('suppress an address (normalised to lower case)', $c === 201 && $j['data']['item']['value'] === 'verify.auto2@example.invalid' && $j['data']['item']['reason'] === 'manual', [$c, $j]);
    [$c, $j] = $req('POST', '/gtm/suppressions', ['kind' => 'email', 'value' => 'verify.auto2@example.invalid']); $ok('the same address twice -> 422', $c === 422 && $j['code'] === 'already_suppressed', [$c, $j]);
    [$c, $j] = $req('POST', '/gtm/outreach', ['contact_id' => $c2, 'subject' => 'Verify S', 'body' => $body]); $ok('no draft can be written for a suppressed address (422 suppressed)', $c === 422 && $j['code'] === 'suppressed', [$c, $j]);
    [$c, $j] = $req('POST', '/gtm/agents/gtm-outreach/run', ['input' => ['account_id' => 3, 'contact_id' => $c2, 'mode' => 'email']]); $ok('the Outreach Agent refuses a suppressed address (422)', $c === 422 && str_contains($j['message'], 'suppression list'), [$c, $j]);
    $pre = $approved($c1, now()->addMinute()->toDateTimeString(), 'Verify PreSup'); $due($pre);
    [$c, $j] = $req('POST', '/gtm/suppressions', ['kind' => 'domain', 'value' => '@Example.Invalid']); $domSup = $j['data']['item']['id'] ?? 0;
    $ok('suppress a whole domain (leading @ stripped, lower-cased)', $c === 201 && $j['data']['item']['value'] === 'example.invalid', [$c, $j]);
    GtmAutoTransport::$sent = []; $r = $sendDue();
    $ok('a message approved BEFORE the domain was suppressed is not sent', $msg($pre)->status === 'cancelled' && count(GtmAutoTransport::$sent) === 0 && DB::table('gtm_outreach_attempts')->where('message_id', $pre)->where('code', 'suppressed')->exists(), [$r, $msg($pre)->status]);
    [$c, $j] = $req('GET', '/gtm/suppressions?q=example.invalid'); $ok('the list can be searched', $c === 200 && $j['data']['total'] >= 2, $j['data']['total'] ?? $c);
    [$c] = $req('DELETE', "/gtm/suppressions/$domSup", [], $E);   $ok('employee cannot remove an entry (403)', $c === 403, $c);
    [$c] = $req('DELETE', "/gtm/suppressions/$domSup");           $ok('a manual entry can be removed', $c === 200, $c);
    [$c] = $req('DELETE', "/gtm/suppressions/$sup");
    // unsubscribe link -> suppression that cannot be removed
    $unsub = Illuminate\Support\Facades\URL::temporarySignedRoute('gtm.unsubscribe', now()->addDay(), ['contact' => $c1]);
    $kernel->handle(Illuminate\Http\Request::create($unsub, 'GET'));
    $row = DB::table('gtm_suppressions')->where('sub_institute_id', 6)->where('value', 'verify.auto1@example.invalid')->first();
    $ok('the unsubscribe link also suppresses the address (reason unsubscribed)', $row && $row->reason === 'unsubscribed', $row);
    [$c, $j] = $req('DELETE', "/gtm/suppressions/{$row->id}"); $ok('an unsubscribe entry can NOT be removed (422 not_removable)', $c === 422 && $j['code'] === 'not_removable', [$c, $j]);
    DB::table('gtm_contacts')->where('id', $c1)->update(['status' => 'active']);
    [$c, $j] = $req('POST', '/gtm/contacts/import', ['csv' => "Name,Email\nVerify Imported Suppressed,verify.auto1@example.invalid\n", 'mapping' => ['full_name' => 0, 'email' => 1], 'default_account_id' => 3, 'dry_run' => true]);
    $ok('a CSV row for a suppressed address is flagged as never-to-be-emailed', $c === 200 && count($j['data']['rows'][0]['warnings']) === 1 && str_contains($j['data']['rows'][0]['warnings'][0], 'suppression'), $j['data']['rows'][0] ?? $j);
    DB::table('gtm_suppressions')->where('sub_institute_id', 6)->where('value', 'verify.auto1@example.invalid')->delete();

    // ================= E. failures: transient, permanent, rate limit, auth =================
    $f1 = $approved($c3, now()->addMinute()->toDateTimeString(), 'Verify Fail1'); $due($f1);
    GtmAutoTransport::$fail = 'Expected response code "250" but got code "451", with message "451 4.7.1 Greylisted, try again later"';
    $ladder = [];
    for ($i = 1; $i <= 4; $i++) {
        $r = $sendDue(); $row = $msg($f1);
        $ladder[] = [$row->status, (int) $row->attempts, $row->next_attempt_at ? (int) round((strtotime($row->next_attempt_at) - time()) / 60) : null];
        DB::table('gtm_outreach_messages')->where('id', $f1)->update(['next_attempt_at' => $row->status === 'approved' ? now()->subSecond() : null]);
    }
    $ok('transient failure: retried with backoff 5, 30, 120 minutes, then final failure', $ladder[0][0] === 'approved' && abs($ladder[0][2] - 5) <= 1 && abs($ladder[1][2] - 30) <= 1 && abs($ladder[2][2] - 120) <= 1 && $ladder[3][0] === 'failed' && $ladder[3][1] === 4, $ladder);
    $codes = DB::table('gtm_outreach_attempts')->where('message_id', $f1)->orderBy('id')->pluck('outcome')->all();
    $ok('every attempt is on record (3 retries, then failed)', $codes === ['retry', 'retry', 'retry', 'failed'], $codes);
    $ok('a message that is not due yet is not attempted (waits for its retry time)', (function () use ($approved, $c3, $sendDue, $msg, $req, $due) {
        $g = $approved($c3, now()->addMinute()->toDateTimeString(), 'Verify Wait'); $due($g);
        GtmAutoTransport::$fail = '451 4.7.1 try again later'; $sendDue();
        $before = DB::table('gtm_outreach_attempts')->where('message_id', $g)->count(); $sendDue(); $sendDue();
        $after = DB::table('gtm_outreach_attempts')->where('message_id', $g)->count();
        GtmAutoTransport::$fail = null; DB::table('gtm_outreach_messages')->where('id', $g)->update(['status' => 'cancelled', 'scheduled_for' => null]);
        return $before === 1 && $after === 1;
    })());
    $p1 = $approved($c3, now()->addMinute()->toDateTimeString(), 'Verify Perm'); $due($p1);
    GtmAutoTransport::$fail = 'Expected response code "250" but got code "550", with message "550 5.1.1 The email account does not exist."';
    $r = $sendDue(); $ok('permanent failure (550): failed at once, NOT retried', $msg($p1)->status === 'failed' && (int) $msg($p1)->attempts === 1 && $msg($p1)->next_attempt_at === null && $r['failed'] === 1, [$r, $msg($p1)]);
    $ok('...with the reason stored and no secrets in it', str_contains($msg($p1)->error, '550') && ! str_contains($msg($p1)->error, (string) config('mail.mailers.smtp.password')));
    $rl1 = $approved($c1, now()->addMinute()->toDateTimeString(), 'Verify RL1'); $due($rl1);
    $rl2 = $approved($c1, now()->addMinute()->toDateTimeString(), 'Verify RL2'); $due($rl2);
    DB::table('gtm_contacts')->where('id', $c1)->update(['status' => 'active']);
    GtmAutoTransport::$fail = 'Expected response code "250" but got code "550", with message "550 5.4.5 Daily user sending limit exceeded."';
    $r = $sendDue();
    $ok('provider rate limit: the message waits ~6 hours and the REST of that tenant is deferred (one attempt only)', $msg($rl1)->status === 'approved' && abs((strtotime($msg($rl1)->next_attempt_at) - time()) / 60 - 360) <= 2 && DB::table('gtm_outreach_attempts')->whereIn('message_id', [$rl1, $rl2])->count() === 1 && $r['deferred'] >= 1, [$r, $msg($rl1)->next_attempt_at]);
    DB::table('gtm_outreach_messages')->whereIn('id', [$rl1, $rl2])->update(['status' => 'cancelled', 'scheduled_for' => null]);
    $au = $approved($c3, now()->addMinute()->toDateTimeString(), 'Verify Auth'); $due($au);
    GtmAutoTransport::$fail = 'Expected response code "235" but got code "535", with message "535 5.7.8 Username and Password not accepted."';
    $r = $sendDue(); $ok('credentials refused (535): failed, not retried, and the pass stops for everyone', $msg($au)->status === 'failed' && $r['halted'] !== null, [$r, $msg($au)->status]);
    GtmAutoTransport::$fail = null;

    // stuck in `sending`
    $st = $approved($c3, null, 'Verify Stuck'); DB::table('gtm_outreach_messages')->where('id', $st)->update(['status' => 'sending', 'updated_at' => now()->subMinutes(30)]);
    GtmAutoTransport::$sent = []; $tickRes = $tick();
    $ok('a message stuck in `sending` is marked failed as interrupted - and NOT resent automatically', $msg($st)->status === 'failed' && str_contains($msg($st)->error, 'not known whether') && $tickRes['stale_recovered'] >= 1 && count(GtmAutoTransport::$sent) === 0, [$tickRes['stale_recovered'], $msg($st)]);
    $ok('the tick leaves a heartbeat (last_tick_at) and a stored summary', App\Domain\Gtm\GtmState::get('last_tick_at') !== null && is_array(json_decode(App\Domain\Gtm\GtmState::get('last_tick_summary'), true)), App\Domain\Gtm\GtmState::get('last_tick_at'));
    $ok('...and the tick reported no errors', $tickRes['errors'] === [], $tickRes['errors']);

    // ================= F. inbound webhook =================
    $post = function (array $payload, ?string $sig = null, ?string $rawOverride = null) use ($kernel, $secret) {
        $raw = $rawOverride ?? json_encode($payload);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'] + ($sig !== '' ? ['HTTP_X_GTM_SIGNATURE' => $sig ?? InboundSignature::sign($raw, $secret)] : []);
        $res = $kernel->handle(Illuminate\Http\Request::create('/api/gtm/inbound', 'POST', [], [], [], $server, $raw));
        return [$res->getStatusCode(), json_decode($res->getContent(), true)];
    };
    config(['gtm.inbound.secret' => '']); [$c] = $post(['provider' => 'verifyp', 'event_id' => 'e0', 'type' => 'reply', 'from_email' => 'a@b.co']); $ok('no secret configured -> 503, nothing accepted', $c === 503, $c);
    config(['gtm.inbound.secret' => $secret]);
    [$c] = $post(['provider' => 'verifyp', 'event_id' => 'e1', 'type' => 'reply', 'from_email' => 'a@b.co'], ''); $ok('missing signature -> 401', $c === 401, $c);
    [$c] = $post(['provider' => 'verifyp', 'event_id' => 'e1', 'type' => 'reply', 'from_email' => 'a@b.co'], 't=' . time() . ',v1=deadbeef'); $ok('wrong signature -> 401', $c === 401, $c);
    $oldRaw = json_encode(['provider' => 'verifyp', 'event_id' => 'e1', 'type' => 'reply', 'from_email' => 'a@b.co']);
    [$c] = $post([], InboundSignature::sign($oldRaw, $secret, time() - 3600), $oldRaw); $ok('a replayed (old) signed request -> 401', $c === 401, $c);
    [$c] = $post([], InboundSignature::sign('{broken', $secret), '{broken');            $ok('signed but not JSON -> 400', $c === 400, $c);
    [$c, $j] = $post(['provider' => 'Bad Provider!', 'event_id' => 'e2', 'type' => 'reply', 'from_email' => 'a@b.co']); $ok('signed but malformed (bad provider) -> 422', $c === 422, [$c, $j]);
    [$c, $j] = $post(['provider' => 'verifyp', 'event_id' => 'e3', 'type' => 'reply']);                                   $ok('signed but identifies nothing -> 422', $c === 422, [$c, $j]);
    [$c, $j] = $post(['provider' => 'verifyp', 'event_id' => 'e4', 'type' => 'explode', 'from_email' => 'a@b.co']);       $ok('signed but unknown type -> 422', $c === 422, [$c, $j]);
    $ok('no row was written for any rejected or malformed request', DB::table('gtm_inbound_events')->where('provider', 'verifyp')->count() === 0);

    // a sent message to reply to
    $rep = $approved($c1, now()->addMinute()->toDateTimeString(), 'Verify Replied'); $due($rep); GtmAutoTransport::$sent = []; $sendDue();
    $repPid = $msg($rep)->provider_message_id;
    $other = $approved($c1, now()->addDay()->toDateTimeString(), 'Verify OtherScheduled');
    [$c, $j] = $post(['provider' => 'verifyp', 'event_id' => 'reply-1', 'type' => 'reply', 'in_reply_to' => "<$repPid>", 'from_email' => 'verify.auto1@example.invalid', 'subject' => 'Re: Verify Replied', 'text' => 'Yes, interested - call me.', 'sub_institute_id' => 999]);
    $ok('a signed reply matched by Message-ID is processed (the payload cannot name a tenant)', $c === 200 && $j['data']['result'] === 'processed', [$c, $j]);
    $inb = DB::table('gtm_activities')->where('contact_id', $c1)->where('direction', 'inbound')->where('subject', 'Re: Verify Replied')->first();
    $ok('...an inbound email activity is recorded on the right contact and account', $inb && (int) $inb->account_id === 3 && (int) $inb->sub_institute_id === 6 && str_contains($inb->body, 'interested'), $inb);
    $ok('...the message is marked replied', $msg($rep)->outcome === 'replied' && $msg($rep)->outcome_at !== null);
    $ok('...other automatic sends to that person are paused for a human', $msg($other)->status === 'approved' && $msg($other)->scheduled_for === null, $msg($other));
    $ok('...and the event is stored with its outcome, matched to tenant 6', DB::table('gtm_inbound_events')->where('provider', 'verifyp')->where('provider_event_id', 'reply-1')->where('status', 'processed')->where('sub_institute_id', 6)->where('contact_id', $c1)->exists());
    [$c, $j] = $post(['provider' => 'verifyp', 'event_id' => 'reply-1', 'type' => 'reply', 'in_reply_to' => "<$repPid>", 'from_email' => 'verify.auto1@example.invalid']);
    $ok('a redelivered event is a duplicate: acknowledged, nothing applied twice', $c === 202 && $j['data']['result'] === 'duplicate' && DB::table('gtm_activities')->where('contact_id', $c1)->where('subject', 'Re: Verify Replied')->count() === 1, [$c, $j]);
    [$c, $j] = $post(['provider' => 'verifyp', 'event_id' => 'reply-2', 'type' => 'reply', 'in_reply_to' => '<gtm.6.999999.deadbeefdead@example.invalid>', 'from_email' => 'nobody.known@example.invalid']);
    $ok('an event matching nothing is stored as unmatched and changes nothing', $c === 202 && $j['data']['result'] === 'unmatched' && DB::table('gtm_inbound_events')->where('provider_event_id', 'reply-2')->value('status') === 'unmatched', [$c, $j]);
    if ($O) {
        $otherTenant = (int) DB::table('tbluser')->where('id', DB::table('personal_access_tokens')->where('name', 'gtm-verify')->orderByDesc('id')->value('tokenable_id'))->value('sub_institute_id');
        $oa = DB::table('gtm_accounts')->insertGetId(['sub_institute_id' => $otherTenant, 'name' => 'Verify Other Inbound Co', 'stage' => 'target', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        $oc = DB::table('gtm_contacts')->insertGetId(['sub_institute_id' => $otherTenant, 'account_id' => $oa, 'full_name' => 'Verify Same Email', 'email' => 'verify.shared@example.invalid', 'status' => 'active', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        $mine = $mk('Verify Shared Mine', 'verify.shared@example.invalid');
        [$c, $j] = $post(['provider' => 'verifyp', 'event_id' => 'amb-1', 'type' => 'bounce', 'bounce_type' => 'hard', 'recipient' => 'verify.shared@example.invalid']);
        $ok('an email address that exists in TWO organisations is ambiguous: unmatched, neither contact touched', $j['data']['result'] === 'unmatched' && DB::table('gtm_contacts')->whereIn('id', [$oc, $mine])->where('status', 'active')->count() === 2, [$c, $j]);
        [$c, $j] = $post(['provider' => 'verifyp', 'event_id' => 'tenant-1', 'type' => 'bounce', 'bounce_type' => 'hard', 'original_message_id' => $repPid, 'recipient' => 'verify.shared@example.invalid']);
        $ok('a bounce matched by Message-ID touches only the message\'s own organisation (the same address elsewhere is untouched)', DB::table('gtm_contacts')->where('id', $oc)->value('status') === 'active' && DB::table('gtm_contacts')->where('id', $c1)->value('status') === 'bounced', [$c, $j]);
        DB::table('gtm_contacts')->where('id', $c1)->update(['status' => 'active']); DB::table('gtm_suppressions')->where('sub_institute_id', 6)->where('value', 'verify.auto1@example.invalid')->delete();
    }
    // fallback: unique address across all organisations
    $uniq = $mk('Verify Unique Addr', 'verify.unique.addr@example.invalid');
    [$c, $j] = $post(['provider' => 'verifyp', 'event_id' => 'fb-1', 'type' => 'reply', 'from_email' => 'verify.unique.addr@example.invalid', 'subject' => 'Verify FB', 'text' => 'hello']);
    $ok('with no Message-ID, a UNIQUE address still matches its one contact', $j['data']['result'] === 'processed' && DB::table('gtm_activities')->where('contact_id', $uniq)->where('subject', 'Verify FB')->exists(), [$c, $j]);

    // bounces
    $bn = $mk('Verify Bounce Person', 'verify.bounce@example.invalid'); $bm = $approved($bn, now()->addMinute()->toDateTimeString(), 'Verify BounceMsg'); $due($bm); $sendDue(); $bpid = $msg($bm)->provider_message_id;
    $pend = $approved($bn, now()->addDay()->toDateTimeString(), 'Verify BouncePending');
    [$c, $j] = $post(['provider' => 'verifyp', 'event_id' => 'hb-1', 'type' => 'bounce', 'bounce_type' => 'hard', 'original_message_id' => "<$bpid>", 'recipient' => 'verify.bounce@example.invalid', 'reason' => '550 no such user']);
    $ok('a HARD bounce: contact marked bounced', $j['data']['result'] === 'processed' && DB::table('gtm_contacts')->where('id', $bn)->value('status') === 'bounced', [$c, $j]);
    $ok('...the address is suppressed (hard_bounce), the message marked bounced, unsent mail cancelled', DB::table('gtm_suppressions')->where('sub_institute_id', 6)->where('value', 'verify.bounce@example.invalid')->value('reason') === 'hard_bounce' && $msg($bm)->outcome === 'bounced' && $msg($pend)->status === 'cancelled', [$msg($bm)->outcome, $msg($pend)->status]);
    $sb = $mk('Verify Soft Person', 'verify.soft@example.invalid'); $sm = $approved($sb, now()->addMinute()->toDateTimeString(), 'Verify SoftMsg'); $due($sm); $sendDue(); $spid = $msg($sm)->provider_message_id;
    $held = $approved($sb, now()->addDay()->toDateTimeString(), 'Verify SoftHeld');
    foreach ([1, 2] as $k) { [$c, $j] = $post(['provider' => 'verifyp', 'event_id' => "sb-$k", 'type' => 'bounce', 'bounce_type' => 'soft', 'original_message_id' => $spid, 'reason' => '452 mailbox full']); }
    $ok('soft bounces 1 and 2: contact stays active, scheduled sends are held a day', DB::table('gtm_contacts')->where('id', $sb)->value('status') === 'active' && $msg($held)->next_attempt_at !== null && $msg($held)->status === 'approved', [$msg($held)->next_attempt_at]);
    [$c, $j] = $post(['provider' => 'verifyp', 'event_id' => 'sb-3', 'type' => 'bounce', 'bounce_type' => 'soft', 'original_message_id' => $spid, 'reason' => '452 mailbox full']);
    $ok('the THIRD soft bounce in 30 days is treated as hard (soft_bounce_limit)', DB::table('gtm_contacts')->where('id', $sb)->value('status') === 'bounced' && DB::table('gtm_suppressions')->where('sub_institute_id', 6)->where('value', 'verify.soft@example.invalid')->value('reason') === 'soft_bounce_limit' && $msg($held)->status === 'cancelled', [$c, $j]);
    $cp = $mk('Verify Complainer', 'verify.complaint@example.invalid'); $cm = $approved($cp, now()->addMinute()->toDateTimeString(), 'Verify ComplaintMsg'); $due($cm); $sendDue();
    [$c, $j] = $post(['provider' => 'verifyp', 'event_id' => 'cmp-1', 'type' => 'complaint', 'original_message_id' => $msg($cm)->provider_message_id]);
    $ok('a spam complaint: contact unsubscribed and address suppressed (complaint)', DB::table('gtm_contacts')->where('id', $cp)->value('status') === 'unsubscribed' && DB::table('gtm_suppressions')->where('sub_institute_id', 6)->where('value', 'verify.complaint@example.invalid')->value('reason') === 'complaint', [$c, $j]);

    // manual recording uses the same processor
    $mr = $mk('Verify Manual Reply', 'verify.manual@example.invalid');
    [$c] = $req('POST', "/gtm/contacts/$mr/record-event", ['type' => 'reply', 'subject' => 'Phone call'], $E);  $ok('manual record: employee 403', $c === 403, $c);
    [$c, $j] = $req('POST', "/gtm/contacts/$mr/record-event", ['type' => 'bounce']);                              $ok('a manual bounce needs a bounce_type (422)', $c === 422, $c);
    [$c, $j] = $req('POST', "/gtm/contacts/$mr/record-event", ['type' => 'reply', 'subject' => 'Verify spoke by phone', 'text' => 'They called back.']);
    $ok('an administrator can record a reply by hand: same processor, same effects', $c === 200 && $j['data']['status'] === 'processed' && DB::table('gtm_activities')->where('contact_id', $mr)->where('direction', 'inbound')->exists(), [$c, $j]);
    if ($O) { [$c] = $req('POST', "/gtm/contacts/$mr/record-event", ['type' => 'reply'], $O); $ok("another organisation cannot record an event on my contact (404)", $c === 404, $c); }
    [$c, $j] = $req('POST', "/gtm/contacts/999999/record-event", ['type' => 'reply']); $ok('unknown contact -> 404', $c === 404, $c);

    // ================= G. audit =================
    $types = DB::table('g2g_event')->where('sub_institute_id', 6)->whereIn('type', ['gtm.outreach.scheduled', 'gtm.outreach.approval_invalidated', 'gtm.contact.replied', 'gtm.contact.bounced', 'gtm.suppression.added', 'gtm.suppression.removed', 'gtm.inbound.received'])->pluck('type')->unique()->all();
    $ok('audit: scheduled, approval_invalidated, replied, bounced, suppression added/removed, inbound received', count($types) === 7, $types);
} finally {
    GtmAutoTransport::$fail = null; Mail::purge();
    DB::table('gtm_suppressions')->where('value', 'like', 'verify%')->delete();
    DB::table('gtm_contacts')->where('email', 'like', 'verify.%@example.invalid')->delete();
    DB::table('gtm_outreach_messages')->where('subject', 'like', 'Verify %')->delete();
    gtm_verify_cleanup($startedAt, $origStage);
    DB::table('gtm_inbound_events')->where('provider', 'verifyp')->delete();
    echo "\ncleaned up (only rows named 'Verify ...' and rows attached to them)\n";
}
echo "$pass passed, $fail failed\n";
