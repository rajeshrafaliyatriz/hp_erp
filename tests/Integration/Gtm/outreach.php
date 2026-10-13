<?php
use Illuminate\Support\Facades\{Cache, DB, Mail};
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class GtmCaptureTransport extends AbstractTransport
{
    public static array $sent = [];
    protected function doSend(SentMessage $message): void { self::$sent[] = $message->getOriginalMessage(); }
    public function __toString(): string { return 'capture'; }
}

$pass = 0; $fail = 0;
config(['gtm.outreach.sending_enabled' => false, 'gtm.outreach.scheduler_enabled' => false]); // pin the state under test; .env may differ
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n"; };
$base = ['act' => DB::table('gtm_activities')->max('id'), 'an' => DB::table('gtm_analyses')->max('id'), 'msg' => (int) DB::table('gtm_outreach_messages')->max('id'), 'contact' => (int) DB::table('gtm_contacts')->max('id'), 'acct' => (int) DB::table('gtm_accounts')->max('id')];
$req = function ($m, $u, $b = [], $tok = null) use ($kernel, $A) { Cache::flush(); return call($kernel, $m, $u, $tok ?? $A, $b); };
$setMail = function (array $over) { config($over); Mail::purge(); };
$otherTenant = 0; $otherAcct = 0; $otherContact = 0;

Mail::extend('capture', fn (array $config) => new GtmCaptureTransport());
config(['mail.mailers.capture' => ['transport' => 'capture']]);

try {
    // ================= permissions =================
    foreach ([['GET', '/gtm/outreach'], ['GET', '/gtm/outreach/readiness'], ['POST', '/gtm/outreach'], ['POST', '/gtm/contacts/import/preview'], ['POST', '/gtm/contacts/import']] as [$m, $u]) {
        [$c] = call($kernel, $m, $u, null);  $ok("$m $u no token 401", $c === 401, $c);
        [$c] = call($kernel, $m, $u, $E, ['csv' => 'a,b']);   $ok("$m $u employee 403", $c === 403, $c);
    }

    // ================= readiness (real environment, nothing sent) =================
    [$c, $j] = $req('GET', '/gtm/outreach/readiness');
    $r = $j['data'] ?? [];
    $ok('readiness: reports mailer/from, platform gate, sending switch, cap', $c === 200 && isset($r['mailer'], $r['from'], $r['platform_gate_allows'], $r['gtm_sending_enabled'], $r['daily_cap']), $r);
    $ok('readiness: GTM sending is OFF by default -> can_send false with a plain-language blocker', $r['gtm_sending_enabled'] === false && $r['can_send'] === false && count($r['blockers']) >= 1, $r);
    $ok('readiness: local APP_URL is flagged (unsubscribe link unreachable)', $r['unsubscribe_link_ok'] === false && str_contains((string) $r['unsubscribe_problem'], 'APP_URL'), $r);
    $ok('readiness never exposes the SMTP password', ! str_contains(json_encode($r), (string) config('mail.mailers.smtp.password')) || config('mail.mailers.smtp.password') === '', 'leak');

    // ================= fixtures =================
    [$c, $j] = $req('POST', '/gtm/accounts/3/contacts', ['full_name' => 'Verify Recipient', 'title' => 'Head of Digital', 'email' => 'verify.recipient@example.invalid']);
    $K = $j['data']['contact']['id'] ?? 0;  $ok('fixture contact created', $c === 201 && $K > 0, [$c, $j]);
    [$c, $j] = $req('POST', '/gtm/accounts/3/contacts', ['full_name' => 'Verify NoEmail']);  $KN = $j['data']['contact']['id'] ?? 0;
    $KB = DB::table('gtm_contacts')->insertGetId(['sub_institute_id' => 6, 'account_id' => 3, 'full_name' => 'Verify Blocked', 'email' => 'verify.blocked@example.invalid', 'status' => 'do_not_contact', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    if ($O) {
        $otherTenant = (int) DB::table('tbluser')->where('id', DB::table('personal_access_tokens')->where('name', 'gtm-verify')->orderByDesc('id')->value('tokenable_id'))->value('sub_institute_id');
        $otherAcct = DB::table('gtm_accounts')->insertGetId(['sub_institute_id' => $otherTenant, 'name' => 'Verify Other Co', 'stage' => 'target', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        $otherContact = DB::table('gtm_contacts')->insertGetId(['sub_institute_id' => $otherTenant, 'account_id' => $otherAcct, 'full_name' => 'Verify Other Person', 'email' => 'other.person@example.invalid', 'status' => 'active', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    }
    $body = 'Hello, this is a verification message that is comfortably longer than twenty characters.';

    // ================= drafting & validation =================
    foreach ([
        'missing subject' => ['contact_id' => $K, 'subject' => '', 'body' => $body],
        'short body' => ['contact_id' => $K, 'subject' => 'Hi', 'body' => 'too short'],
        'unknown contact' => ['contact_id' => 999999, 'subject' => 'Hi', 'body' => $body],
        'blocked contact (do_not_contact)' => ['contact_id' => $KB, 'subject' => 'Hi', 'body' => $body],
    ] as $label => $b) { [$c] = $req('POST', '/gtm/outreach', $b); $ok("draft invalid ($label) -> 422", $c === 422, $c); }
    if ($otherContact) { [$c] = $req('POST', '/gtm/outreach', ['contact_id' => $otherContact, 'subject' => 'Hi', 'body' => $body]); $ok("draft for another tenant's contact -> 422", $c === 422, $c); }
    [$c] = $req('POST', '/gtm/outreach', ['contact_id' => $K, 'subject' => 'Hi', 'body' => $body, 'deal_id' => 999999]); $ok('draft with a deal not on this account -> 422', $c === 422, $c);
    [$c, $j] = $req('POST', '/gtm/outreach', ['contact_id' => $K, 'subject' => 'Verify subject', 'body' => $body]);
    $M = $j['data']['message']['id'] ?? 0;  $ok('draft created in status draft', $c === 201 && $j['data']['message']['status'] === 'draft', [$c, $j]);

    // ================= approval lifecycle =================
    [$c, $j] = $req('POST', '/gtm/outreach', ['contact_id' => $KN, 'subject' => 'No email', 'body' => $body]); $MN = $j['data']['message']['id'] ?? 0;
    [$c, $j] = $req('POST', "/gtm/outreach/$MN/submit");       $ok('submit for a contact with no email -> 422 no_recipient', $c === 422 && $j['code'] === 'no_recipient', [$c, $j]);
    [$c] = $req('POST', "/gtm/outreach/$M/approve", ['confirm' => true]); $ok('approve a DRAFT (never submitted) -> 422', $c === 422, $c);
    [$c] = $req('POST', "/gtm/outreach/$M/send");              $ok('send a draft -> 409 not_approved', $c === 409, $c);
    [$c, $j] = $req('POST', "/gtm/outreach/$M/submit");        $ok('submit -> pending_approval', $c === 200 && $j['data']['message']['status'] === 'pending_approval', [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/outreach/$M/send");          $ok('send while pending -> 409', $c === 409, $c);
    [$c, $j] = $req('POST', "/gtm/outreach/$M/approve");       $ok('approve without confirm -> 409 confirmation_required', $c === 409 && $j['code'] === 'confirmation_required', [$c, $j]);
    config(['gtm.outreach.require_second_approver' => true]);
    [$c, $j] = $req('POST', "/gtm/outreach/$M/approve", ['confirm' => true]); $ok('second approver required: author cannot approve own message (403)', $c === 403 && $j['code'] === 'second_approver_required', [$c, $j]);
    config(['gtm.outreach.require_second_approver' => false]);
    [$c] = $req('POST', "/gtm/outreach/$M/approve", ['confirm' => true], $E); $ok('employee cannot approve (403)', $c === 403, $c);
    [$c, $j] = $req('POST', "/gtm/outreach/$M/approve", ['confirm' => true, 'note' => 'ok']);
    $ok('approve with confirm -> approved, hash pinned', $c === 200 && $j['data']['message']['status'] === 'approved' && strlen((string) $j['data']['message']['approved_hash']) === 64, [$c, $j]);
    [$c, $j] = $req('POST', '/gtm/outreach', ['contact_id' => $K, 'subject' => 'Verify Mx', 'body' => $body]); $Mx = $j['data']['message']['id']; $req('POST', "/gtm/outreach/$Mx/submit"); $req('POST', "/gtm/outreach/$Mx/approve", ['confirm' => true]);
    [$c, $j] = $req('PATCH', "/gtm/outreach/$Mx", ['body' => $body . ' edited']); $ok('editing approved text WITHDRAWS the approval (back to draft, hash cleared)', $c === 200 && $j['data']['message']['status'] === 'draft' && $j['data']['message']['approved_hash'] === null, [$c, $j]);

    // ================= send safeguards (each refuses, nothing leaves) =================
    $setMail(['mail.default' => 'capture', 'mail.from.address' => 'sender@example.invalid']);
    GtmCaptureTransport::$sent = [];
    [$c, $j] = $req('POST', "/gtm/outreach/$M/send");   $ok('send refused while GTM sending is OFF (403 sending_disabled)', $c === 403 && $j['code'] === 'sending_disabled', [$c, $j]);
    config(['gtm.outreach.sending_enabled' => true]);
    [$c, $j] = $req('POST', "/gtm/outreach/$M/send");   $ok('send refused while APP_URL is local (422 unsubscribe_unreachable)', $c === 422 && $j['code'] === 'unsubscribe_unreachable', [$c, $j]);
    config(['app.url' => 'https://api.example.com']);
    $setMail(['mail.default' => 'log']);
    [$c, $j] = $req('POST', "/gtm/outreach/$M/send");   $ok('send refused on a log/array mail driver (422 mailer_not_delivering)', $c === 422 && $j['code'] === 'mailer_not_delivering', [$c, $j]);
    $setMail(['mail.default' => 'capture']);
    DB::table('gtm_contacts')->where('id', $K)->update(['status' => 'bounced']);
    [$c, $j] = $req('POST', "/gtm/outreach/$M/send");   $ok('send refused when the contact is now bounced (422 contact_blocked)', $c === 422 && $j['code'] === 'contact_blocked', [$c, $j]);
    DB::table('gtm_contacts')->where('id', $K)->update(['status' => 'active']);
    DB::table('gtm_outreach_messages')->where('id', $M)->update(['body' => $body . ' (tampered after approval)']);
    [$c, $j] = $req('POST', "/gtm/outreach/$M/send");   $ok('send refused when text differs from what was approved (409 content_changed)', $c === 409 && $j['code'] === 'content_changed', [$c, $j]);
    DB::table('gtm_outreach_messages')->where('id', $M)->update(['body' => $body]);
    DB::table('gtm_outreach_messages')->where('id', $M)->update(['status' => 'sending']);
    [$c, $j] = $req('POST', "/gtm/outreach/$M/send");   $ok('a message already being sent cannot be claimed again (409)', $c === 409, [$c, $j]);
    DB::table('gtm_outreach_messages')->where('id', $M)->update(['status' => 'approved']);
    config(['gtm.outreach.daily_cap' => 0]);
    [$c, $j] = $req('POST', "/gtm/outreach/$M/send");   $ok('daily cap enforced (429)', $c === 429 && $j['code'] === 'daily_cap', [$c, $j]);
    config(['gtm.outreach.daily_cap' => 50]);
    $ok('NOTHING was delivered by any refused attempt', count(GtmCaptureTransport::$sent) === 0, count(GtmCaptureTransport::$sent));
    $actsBefore = DB::table('gtm_activities')->where('sub_institute_id', 6)->where('type', 'email')->count();

    // tenant not allowed by MailGate: other tenant's approved message must be refused
    if ($otherContact) {
        $om = new App\Domain\Gtm\GtmOutreachMessage(['sub_institute_id' => $otherTenant, 'account_id' => $otherAcct, 'contact_id' => $otherContact, 'subject' => 'x', 'body' => $body, 'status' => 'approved']);
        $om->approved_hash = $om->contentHash(); $om->save();
        [$c, $j] = $req('POST', "/gtm/outreach/{$om->id}/send", [], $O);
        $ok('MailGate: a tenant not on the platform allow-list cannot send (403 mail_disabled)', $c === 403 && $j['code'] === 'mail_disabled', [$c, $j]);
        $ok('...and nothing was delivered', count(GtmCaptureTransport::$sent) === 0);
    }

    // ================= the real send (captured transport) =================
    [$c, $j] = $req('POST', "/gtm/outreach/$M/send");
    $ok('approved + all gates open -> sent', $c === 200 && $j['data']['message']['status'] === 'sent' && $j['data']['message']['sent_at'], [$c, $j]);
    $mail = GtmCaptureTransport::$sent[0] ?? null;
    $ok('exactly one email captured, addressed to the contact', count(GtmCaptureTransport::$sent) === 1 && $mail && $mail->getTo()[0]->getAddress() === 'verify.recipient@example.invalid', count(GtmCaptureTransport::$sent));
    $ok('subject and approved body are what was sent', $mail && $mail->getSubject() === 'Verify subject' && str_contains($mail->getTextBody(), $body));
    $ok('every email carries the unsubscribe link in body and List-Unsubscribe headers', $mail && str_contains($mail->getTextBody(), '/api/gtm/unsubscribe/') && $mail->getHeaders()->has('List-Unsubscribe') && $mail->getHeaders()->has('List-Unsubscribe-Post'));
    $act = DB::table('gtm_activities')->where('sub_institute_id', 6)->where('type', 'email')->where('contact_id', $K)->orderByDesc('id')->first();
    $ok('send recorded as an OUTBOUND email activity on the contact/account', $act && $act->direction === 'outbound' && (int) $act->account_id === 3 && DB::table('gtm_activities')->where('sub_institute_id', 6)->where('type', 'email')->count() === $actsBefore + 1, $act);
    [$c, $j] = $req('POST', "/gtm/outreach/$M/send"); $ok('a sent message cannot be sent twice (409)', $c === 409 && count(GtmCaptureTransport::$sent) === 1, [$c, count(GtmCaptureTransport::$sent)]);
    [$c] = $req('POST', "/gtm/outreach/$M/cancel");   $ok('a sent message cannot be cancelled (422)', $c === 422, $c);

    // ================= reject / edit / cancel =================
    [$c, $j] = $req('POST', '/gtm/outreach', ['contact_id' => $K, 'subject' => 'To be rejected', 'body' => $body]); $M2 = $j['data']['message']['id'];
    $req('POST', "/gtm/outreach/$M2/submit");
    [$c] = $req('POST', "/gtm/outreach/$M2/reject", []);                      $ok('reject needs a reason (422)', $c === 422, $c);
    [$c, $j] = $req('POST', "/gtm/outreach/$M2/reject", ['note' => 'Tone is off']); $ok('reject with reason -> rejected', $c === 200 && $j['data']['message']['status'] === 'rejected' && $j['data']['message']['decision_note'] === 'Tone is off', [$c, $j]);
    [$c, $j] = $req('PATCH', "/gtm/outreach/$M2", ['body' => $body . ' Revised.']); $ok('editing a rejected message returns it to draft', $c === 200 && $j['data']['message']['status'] === 'draft', [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/outreach/$M2/cancel"); $ok('cancel a draft', $c === 200 && $j['data']['message']['status'] === 'cancelled', [$c, $j]);

    // ================= delivery failure is recorded honestly =================
    [$c, $j] = $req('POST', '/gtm/outreach', ['contact_id' => $K, 'subject' => 'Will fail', 'body' => $body]); $M3 = $j['data']['message']['id'];
    $req('POST', "/gtm/outreach/$M3/submit"); $req('POST', "/gtm/outreach/$M3/approve", ['confirm' => true]);
    $actsBefore = DB::table('gtm_activities')->where('sub_institute_id', 6)->where('type', 'email')->count();
    $setMail(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.password' => 'hunter2-secret']);
    [$c, $j] = $req('POST', "/gtm/outreach/$M3/send");
    $ok('SMTP failure -> 502 send_failed, message marked failed with a reason', $c === 502 && $j['code'] === 'send_failed' && $j['data']['message']['status'] === 'failed' && ! empty($j['data']['message']['error']), [$c, $j]);
    $ok('the SMTP password never appears in the stored error', ! str_contains((string) DB::table('gtm_outreach_messages')->where('id', $M3)->value('error'), 'hunter2-secret'));
    $ok('a failed send is NOT counted as an email activity', DB::table('gtm_activities')->where('sub_institute_id', 6)->where('type', 'email')->count() === $actsBefore);
    $setMail(['mail.default' => 'capture']);
    [$c, $j] = $req('POST', "/gtm/outreach/$M3/send"); $ok('a failed message can be retried and then sends', $c === 200 && $j['data']['message']['status'] === 'sent', [$c, $j]);

    // ================= sequences from an agent result =================
    $seq = ['recipient' => ['contact_id' => $K], 'is_draft' => true, 'steps' => [
        ['step' => 1, 'subject' => 'Seq 1', 'body' => $body . ' one'], ['step' => 2, 'subject' => 'Seq 2', 'body' => $body . ' two'], ['step' => 3, 'subject' => 'Seq 3', 'body' => $body . ' three']]];
    $an = DB::table('gtm_analyses')->insertGetId(['sub_institute_id' => 6, 'kind' => 'outreach_draft', 'subject_type' => 'contact', 'subject_id' => $K, 'result' => json_encode($seq), 'status' => 'success', 'is_estimate' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $anOther = DB::table('gtm_analyses')->insertGetId(['sub_institute_id' => 6, 'kind' => 'icp_fit', 'subject_type' => 'account', 'subject_id' => 3, 'result' => '{}', 'status' => 'success', 'is_estimate' => 1, 'created_at' => now(), 'updated_at' => now()]);
    [$c] = $req('POST', '/gtm/outreach/from-analysis', ['analysis_id' => 999999]);     $ok('from-analysis unknown -> 404', $c === 404, $c);
    [$c] = $req('POST', '/gtm/outreach/from-analysis', ['analysis_id' => $anOther]);   $ok('from-analysis of a non-outreach analysis -> 404', $c === 404, $c);
    if ($O) { [$c] = $req('POST', '/gtm/outreach/from-analysis', ['analysis_id' => $an], $O); $ok("from-analysis of another tenant's draft -> 404", $c === 404, $c); }
    [$c, $j] = $req('POST', '/gtm/outreach/from-analysis', ['analysis_id' => $an]);
    $msgs = $j['data']['messages'] ?? [];
    $ok('sequence saved as 3 linked drafts, steps 1-3', $c === 201 && count($msgs) === 3 && count(array_unique(array_column($msgs, 'sequence_key'))) === 1 && array_column($msgs, 'step') === [1, 2, 3] && collect($msgs)->every(fn ($m) => $m['status'] === 'draft'), [$c, $j]);
    $dues = array_map(fn ($m) => (int) abs(\Illuminate\Support\Carbon::parse($m['due_at'])->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($msgs[0]['due_at'])->startOfDay())), $msgs);
    $ok('follow-up due dates come from the playbook (0, 3, 8 days)', $dues === [0, 3, 8], $dues);
    [$c] = $req('POST', '/gtm/outreach/from-analysis', ['analysis_id' => $an]);        $ok('the same draft cannot be saved twice (409)', $c === 409, $c);
    [$s1, $s2, $s3] = array_column($msgs, 'id');
    foreach ([$s1, $s2, $s3] as $sid) { $req('POST', "/gtm/outreach/$sid/submit"); $req('POST', "/gtm/outreach/$sid/approve", ['confirm' => true]); }
    GtmCaptureTransport::$sent = [];
    [$c, $j] = $req('POST', "/gtm/outreach/$s2/send");  $ok('step 2 cannot go before step 1 (409 sequence_order)', $c === 409 && $j['code'] === 'sequence_order', [$c, $j]);
    [$c] = $req('POST', "/gtm/outreach/$s1/send");      $ok('step 1 sends', $c === 200, $c);
    [$c] = $req('POST', "/gtm/outreach/$s2/send");      $ok('then step 2 sends', $c === 200, $c);
    DB::table('gtm_activities')->insert(['sub_institute_id' => 6, 'account_id' => 3, 'contact_id' => $K, 'type' => 'email', 'direction' => 'inbound', 'subject' => 'Re: Seq 1', 'occurred_at' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now()]);
    [$c, $j] = $req('POST', "/gtm/outreach/$s3/send");  $ok('a recorded reply stops the sequence (409 reply_recorded)', $c === 409 && $j['code'] === 'reply_recorded', [$c, $j]);
    $ok('only the two permitted steps were delivered', count(GtmCaptureTransport::$sent) === 2, count(GtmCaptureTransport::$sent));

    // ================= unsubscribe =================
    [$c, $j] = $req('POST', '/gtm/outreach', ['contact_id' => $K, 'subject' => 'Pending when unsubscribed', 'body' => $body]); $M4 = $j['data']['message']['id'];
    $req('POST', "/gtm/outreach/$M4/submit");
    $url = Illuminate\Support\Facades\URL::temporarySignedRoute('gtm.unsubscribe', now()->addDay(), ['contact' => $K]);
    $raw = fn ($u) => $kernel->handle(Illuminate\Http\Request::create($u, 'GET'));
    $tampered = preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeef', $url);
    $ok('tampered unsubscribe link is refused (403)', $raw($tampered)->getStatusCode() === 403);
    $ok('link for a different contact id is refused (403)', $raw(str_replace("/unsubscribe/$K", '/unsubscribe/' . ($K + 1), $url))->getStatusCode() === 403);
    $res = $raw($url);
    $ok('valid link -> 200 page, contact becomes unsubscribed', $res->getStatusCode() === 200 && str_contains($res->getContent(), 'unsubscribed') && DB::table('gtm_contacts')->where('id', $K)->value('status') === 'unsubscribed', $res->getStatusCode());
    $ok('unsent messages to that contact were cancelled', DB::table('gtm_outreach_messages')->where('id', $M4)->value('status') === 'cancelled');
    $ok('unsubscribe is idempotent', $raw($url)->getStatusCode() === 200);
    [$c, $j] = $req('POST', '/gtm/outreach', ['contact_id' => $K, 'subject' => 'After opt-out', 'body' => $body]); $ok('no new draft can be written for an unsubscribed contact (422)', $c === 422 && $j['code'] === 'contact_blocked', [$c, $j]);

    // ================= tenant isolation of messages =================
    if ($O) {
        foreach ([['PATCH', "/gtm/outreach/$M2"], ['POST', "/gtm/outreach/$M2/submit"], ['POST', "/gtm/outreach/$M2/approve"], ['POST', "/gtm/outreach/$M2/reject"], ['POST', "/gtm/outreach/$M2/cancel"], ['POST', "/gtm/outreach/$M2/send"]] as [$m, $u]) {
            [$c] = $req($m, $u, ['confirm' => true, 'note' => 'hijack', 'body' => $body], $O); $ok("other tenant $m " . substr($u, strrpos($u, '/')) . ' -> 404', $c === 404, $c);
        }
        [$c, $j] = $req('GET', '/gtm/outreach', [], $O); $ok('other tenant list contains none of my messages', collect($j['data']['items'])->pluck('id')->intersect([$M, $M2, $M3, $s1])->isEmpty(), $c);
    }
    [$c, $j] = $req('GET', '/gtm/outreach'); $ok('list returns messages with contact/account names and a count per status', $c === 200 && isset($j['data']['counts']['sent']) && collect($j['data']['items'])->every(fn ($m) => isset($m['contact_name'], $m['account_name'])), $j['data']['counts'] ?? $c);
    [$c, $j] = $req('GET', '/gtm/outreach?status=sent'); $ok('status filter', collect($j['data']['items'])->every(fn ($m) => $m['status'] === 'sent') && count($j['data']['items']) >= 4, count($j['data']['items'] ?? []));
    $types = DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'like', 'gtm.outreach.%')->pluck('type')->unique()->all();
    $ok('audit events: created, updated, submitted, approved, rejected, cancelled, sent, send_failed', count(array_intersect(['gtm.outreach.created', 'gtm.outreach.updated', 'gtm.outreach.submitted', 'gtm.outreach.approved', 'gtm.outreach.rejected', 'gtm.outreach.cancelled', 'gtm.outreach.sent', 'gtm.outreach.send_failed'], $types)) === 8, $types);
    $ok('audit: unsubscribe recorded', DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'gtm.contact.unsubscribed')->exists());

    // ================= CSV import =================
    DB::table('gtm_contacts')->where('id', $K)->update(['status' => 'active']);
    $existing = DB::table('gtm_contacts')->insertGetId(['sub_institute_id' => 6, 'account_id' => 3, 'full_name' => 'Verify Existing Person', 'email' => 'existing.person@example.invalid', 'status' => 'active', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    $acctName = DB::table('gtm_accounts')->where('id', 3)->value('name');
    $csv = "Name,Email,Job Title,Phone,LinkedIn,Role,Company,Notes\n"
        . "Verify Ada Import,ada.import@example.invalid,CTO,+91 99999 00000,https://www.linkedin.com/in/ada,Decision Maker,\"$acctName\",\"Line one, with comma\"\n"
        . "Verify Bob Import,BOB.IMPORT@example.invalid,CFO,,not-a-url,Wizard,\"$acctName\",\n"
        . "Verify Bob Again,bob.import@example.invalid,CFO,,,,\"$acctName\",\n"
        . "Verify Existing Person,existing.person@example.invalid,,,,,\"$acctName\",\n"
        . ",nameless@example.invalid,,,,,\"$acctName\",\n"
        . "Verify Bad Email,not-an-email,,,,,\"$acctName\",\n"
        . "Verify Nowhere Person,nowhere@example.invalid,,,,,\"Verify Import Co\",\n"
        . "Verify Cell Formula,=HYPERLINK(\"http://x\"),,,,,\"$acctName\",\n";
    [$c] = $req('POST', '/gtm/contacts/import/preview', ['csv' => '']);                   $ok('preview: empty file -> 422', $c === 422, $c);
    [$c] = $req('POST', '/gtm/contacts/import/preview', ['csv' => "Name,Email\n"]);       $ok('preview: header only (no rows) -> 422', $c === 422, $c);
    [$c, $j] = $req('POST', '/gtm/contacts/import/preview', ['csv' => $csv]);
    $sm = $j['data']['suggested_mapping'] ?? [];
    $ok('preview: headers, sample, row count, and a correct suggested mapping', $c === 200 && $j['data']['row_count'] === 8 && $sm['full_name'] === 0 && $sm['email'] === 1 && $sm['title'] === 2 && $sm['phone'] === 3 && $sm['linkedin_url'] === 4 && $sm['role_in_deal'] === 5 && $sm['company'] === 6 && $sm['notes'] === 7, [$c, $sm]);
    $map = ['full_name' => 0, 'email' => 1, 'title' => 2, 'phone' => 3, 'linkedin_url' => 4, 'role_in_deal' => 5, 'company' => 6, 'notes' => 7];
    $contactsBefore = DB::table('gtm_contacts')->where('sub_institute_id', 6)->count(); $acctsBefore = DB::table('gtm_accounts')->where('sub_institute_id', 6)->count();

    [$c] = $req('POST', '/gtm/contacts/import', ['csv' => $csv, 'mapping' => ['email' => 1], 'dry_run' => true]);   $ok('import: no name column mapped -> 422', $c === 422, $c);
    [$c] = $req('POST', '/gtm/contacts/import', ['csv' => $csv, 'mapping' => ['full_name' => 0, 'email' => 1], 'dry_run' => true]); $ok('import: no way to place contacts (no company/default account) -> 422', $c === 422, $c);
    if ($otherAcct) { [$c] = $req('POST', '/gtm/contacts/import', ['csv' => $csv, 'mapping' => $map, 'default_account_id' => $otherAcct, 'dry_run' => true]); $ok("import: another tenant's account as default -> 422", $c === 422, $c); }
    [$c, $j] = $req('POST', '/gtm/contacts/import', ['csv' => $csv, 'mapping' => $map, 'dry_run' => true]);
    $rows = collect($j['data']['rows'] ?? []);  $sum = $j['data']['summary'] ?? [];
    $ok('dry run: classifies every row (valid / duplicate / invalid) and says it did not commit', $c === 200 && $j['data']['committed'] === false && $sum['rows'] === 8, $j['data'] ?? $j);
    $ok('dry run: Ada ok; role normalised; Bob ok with two warnings (role + linkedin)', $rows->firstWhere('name', 'Verify Ada Import')['status'] === 'ok' && count($rows->firstWhere('name', 'Verify Bob Import')['warnings']) === 2, $rows->take(2)->all());
    $ok('dry run: duplicate within the file detected (case-insensitive email)', $rows->firstWhere('name', 'Verify Bob Again')['status'] === 'duplicate' && str_contains($rows->firstWhere('name', 'Verify Bob Again')['errors'][0], 'row 3'), $rows->firstWhere('name', 'Verify Bob Again'));
    $ok('dry run: duplicate of an existing contact detected', $rows->firstWhere('name', 'Verify Existing Person')['status'] === 'duplicate', $rows->firstWhere('name', 'Verify Existing Person'));
    $ok('dry run: nameless row and bad email are invalid', $rows->firstWhere('line', 6)['status'] === 'invalid' && $rows->firstWhere('name', 'Verify Bad Email')['status'] === 'invalid');
    $ok('dry run: unknown company without create flag is invalid', $rows->firstWhere('name', 'Verify Nowhere Person')['status'] === 'invalid', $rows->firstWhere('name', 'Verify Nowhere Person'));
    $ok('dry run: a formula-looking value is not a valid email, so that row is rejected', $rows->firstWhere('name', 'Verify Cell Formula')['status'] === 'invalid');
    $ok('DRY RUN WROTE NOTHING', DB::table('gtm_contacts')->where('sub_institute_id', 6)->count() === $contactsBefore && DB::table('gtm_accounts')->where('sub_institute_id', 6)->count() === $acctsBefore);
    [$c, $j] = $req('POST', '/gtm/contacts/import', ['csv' => $csv, 'mapping' => $map, 'dry_run' => true, 'create_missing_accounts' => true]);
    $ok('dry run with create flag: unknown company becomes an account to create', collect($j['data']['rows'])->firstWhere('name', 'Verify Nowhere Person')['status'] === 'ok' && $j['data']['accounts_to_create'] === ['Verify Import Co'], $j['data']['accounts_to_create'] ?? $j);
    [$c, $j] = $req('POST', '/gtm/contacts/import', ['csv' => $csv, 'mapping' => $map, 'create_missing_accounts' => true]);
    $ok('omitting dry_run does NOT commit (a commit must be explicit)', $j['data']['committed'] === false && DB::table('gtm_contacts')->where('sub_institute_id', 6)->count() === $contactsBefore, $j['data']['committed'] ?? $j);

    [$c, $j] = $req('POST', '/gtm/contacts/import', ['csv' => $csv, 'mapping' => $map, 'dry_run' => false, 'create_missing_accounts' => true]);
    $ok('commit: 3 imported (Ada, Bob, Nowhere), 1 account created, 5 rejected', $c === 200 && $j['data']['committed'] === true && $j['data']['summary']['imported'] === 3 && $j['data']['summary']['accounts_created'] === 1 && $j['data']['summary']['duplicate'] === 2 && $j['data']['summary']['invalid'] === 3, $j['data']['summary'] ?? $j);
    $ada = DB::table('gtm_contacts')->where('sub_institute_id', 6)->where('email', 'ada.import@example.invalid')->first();
    $ok('commit: stored cleanly (source import, active, role normalised, email lower-cased, account placed)', $ada && $ada->source === 'import' && $ada->status === 'active' && $ada->role_in_deal === 'decision_maker' && (int) $ada->account_id === 3 && $ada->notes === 'Line one, with comma', $ada);
    $bob = DB::table('gtm_contacts')->where('sub_institute_id', 6)->where('email', 'bob.import@example.invalid')->first();
    $ok('commit: unknown role and non-URL LinkedIn were left blank, not stored', $bob && $bob->role_in_deal === null && $bob->linkedin_url === null, $bob);
    $np = DB::table('gtm_contacts')->where('sub_institute_id', 6)->where('email', 'nowhere@example.invalid')->first();
    $newAcct = DB::table('gtm_accounts')->where('sub_institute_id', 6)->where('name', 'Verify Import Co')->first();
    $ok('commit: the created account has source import and holds its contact', $newAcct && $newAcct->source === 'import' && $np && (int) $np->account_id === (int) $newAcct->id, $newAcct);
    [$c, $j] = $req('POST', '/gtm/contacts/import', ['csv' => $csv, 'mapping' => $map, 'dry_run' => false, 'create_missing_accounts' => true]);
    $ok('re-importing the same file is idempotent (0 imported, no duplicate accounts)', $j['data']['summary']['imported'] === 0 && DB::table('gtm_accounts')->where('sub_institute_id', 6)->where('name', 'Verify Import Co')->count() === 1, $j['data']['summary'] ?? $j);
    if ($O) { [$c, $j] = $req('POST', '/gtm/contacts/import', ['csv' => "Name,Email\nVerify Ada Import,ada.import@example.invalid\n", 'mapping' => ['full_name' => 0, 'email' => 1], 'default_account_id' => $otherAcct, 'dry_run' => true], $O);
        $ok("another tenant's import does not see my emails as duplicates (no cross-tenant leak)", $c === 200 && $j['data']['rows'][0]['status'] === 'ok', $j['data'] ?? $j); }
    // delimiter / BOM / quoted newline
    $csv2 = "\xEF\xBB\xBFFull Name;E-mail;Organization\n\"Verify Semi Colon\";semi.colon@example.invalid;\"$acctName\"\n\"Multi\nLine\";multi.line@example.invalid;\"$acctName\"\n";
    [$c, $j] = $req('POST', '/gtm/contacts/import', ['csv' => $csv2, 'mapping' => ['full_name' => 0, 'email' => 1, 'company' => 2], 'dry_run' => true]);
    $ok('semicolon delimiter, BOM and a quoted newline parse correctly', $c === 200 && $j['data']['summary']['valid'] === 2, $j['data'] ?? $j);
    config(['gtm.import.max_rows' => 2]);
    [$c] = $req('POST', '/gtm/contacts/import/preview', ['csv' => $csv]);  $ok('a file over the row limit is refused (422)', $c === 422, $c);
    config(['gtm.import.max_rows' => 2000]);
    $ok('audit: gtm.contacts.imported recorded', DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'gtm.contacts.imported')->exists());
} finally {
    Mail::purge();
    gtm_verify_cleanup($startedAt, $origStage ?? null);
    echo "\ncleaned up (only rows named 'Verify ...' and rows attached to them)\n";
}
echo "$pass passed, $fail failed\n";
