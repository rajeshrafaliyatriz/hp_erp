<?php
// Step 2 of the CRM lead automation: the follow-up cadence (email, wait, next email, stop conditions, escalation).
// Real HTTP stack + real dev DB + the REAL reactor and the REAL scheduler pass (LeadFollowUp::processDue, what gtm:tick calls).
// EMAIL SAFETY: mail is captured by an in-process transport - nothing leaves the machine. Tenant 6 has no smtp_details row, so
// MailSender falls back to Mail::mailer() = that transport; the scenario REFUSES to enable sending unless it verified that.
// "Time passing" is simulated by moving crm_lead_followups.next_due_at into the past (the only clock the cadence reads).
// Every row created is named "Verify ..." and removed in the finally block.
use App\Domain\Crm\Leads\LeadFollowUp;
use App\Services\Events\GtmWorkflowRunner;
use Illuminate\Support\Facades\{Cache, DB, Mail};
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

if (! class_exists('CrmFuTransport')) {
    class CrmFuTransport extends AbstractTransport
    {
        public static array $sent = [];
        public static ?string $fail = null;
        protected function doSend(SentMessage $message): void
        {
            if (self::$fail !== null) { throw new TransportException(self::$fail); }
            self::$sent[] = $message->getOriginalMessage();
        }
        public function __toString(): string { return 'crmfu'; }
    }
}

$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n"; };
$req = function ($m, $u, $b = [], $tok = null) use ($kernel, $A) { Cache::flush(); return call($kernel, $m, $u, $tok ?? $A, $b); };
$runner = app(GtmWorkflowRunner::class);
$deliver = function (int $leadId) use ($runner) { $e = DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'crm.lead.created')->where('entity_id', $leadId)->orderByDesc('id')->first(); if ($e) { $runner->dispatch($e); } return $e; };
$fu = fn (int $leadId) => DB::table('crm_lead_followups')->where('lead_id', $leadId)->first();
$emails = fn (int $leadId) => DB::table('crm_emails as e')->join('crm_email_links as l', 'l.email_id', '=', 'e.id')->where('l.linked_type', 'LEAD')->where('l.linked_id', $leadId)->orderBy('e.id')->select('e.*')->get();
$notes = fn (int $leadId) => DB::table('crm_lead_comments')->where('lead_id', $leadId)->orderBy('id')->pluck('comment');
$tasks = fn (int $leadId) => DB::table('task_management_calendar_events')->where('linked_type', 'LEAD')->where('linked_id', $leadId)->whereNull('deleted_at')->orderBy('id')->get();
$lead = fn (int $id) => DB::table('crm_leads')->find($id);
$late = fn (int $leadId) => DB::table('crm_lead_followups')->where('lead_id', $leadId)->update(['next_due_at' => now()->subMinute()->format('Y-m-d H:i:s')]);
$sendingOn = function (bool $on) { config(['crm.email_sending_enabled' => $on]); };
$mkLead = function (string $suffix, array $extra = []) use ($req) {
    [$c, $j] = $req('POST', '/crm/leads', $extra + ['firstName' => 'Verify', 'lastName' => "FU $suffix", 'company' => "Verify FU $suffix Co", 'email' => 'verify-fu-' . strtolower(str_replace(' ', '-', $suffix)) . '@example.invalid', 'phone' => '+910000000100', 'assignedTo' => 28]);
    if (empty($j['data']['id'])) { echo "DEBUG lead create failed ($suffix): " . json_encode([$c, $j]) . "
"; }
    return (int) ($j['data']['id'] ?? 0);
};
$leadIds = []; $otherLeadIds = [];

// ---- safety preconditions for enabling (captured) sending
Mail::extend('crmfu', fn (array $config) => new CrmFuTransport());
config(['mail.mailers.crmfu' => ['transport' => 'crmfu'], 'mail.default' => 'crmfu', 'mail.from.address' => 'sender@example.invalid', 'app.url' => 'https://api.example.com', 'crm.email_daily_cap' => 50]);
Mail::purge();
$captured = get_class(Mail::mailer()->getSymfonyTransport()) === 'CrmFuTransport' && DB::table('smtp_details')->where('sub_institute_id', 6)->count() === 0;
$ok('SAFETY: mail goes to the in-process capture transport and tenant 6 has no real SMTP profile', $captured);
if (! $captured) { echo "ABORT: refusing to run with sending enabled\n"; exit(1); }

try {
    // ---------------- validation of the new action ----------------
    $good = ['subject' => 'Hello {{lead.company}}', 'body' => 'Hi - following up on {{lead.company}}.', 'intervals' => '2,4', 'max_attempts' => 3, 'window_start' => '00:00', 'window_end' => '23:59', 'timezone' => 'Asia/Kolkata', 'weekdays_only' => false];
    $wfDef = fn (array $p, string $name = 'Verify FU Bad') => ['name' => $name, 'trigger_event' => 'crm.lead.created', 'conditions' => [], 'actions' => [['type' => 'start_lead_followup', 'params' => $p]]];
    foreach ([
        'interval not numeric' => ['intervals' => '2,x'], 'interval too long' => ['intervals' => '2,90'], 'zero attempts' => ['max_attempts' => 0], 'eleven attempts' => ['max_attempts' => 11],
        'bad timezone' => ['timezone' => 'Mars/Olympus'], 'bad time' => ['window_start' => '9am'], 'window reversed' => ['window_start' => '17:00', 'window_end' => '09:00'],
        'window empty' => ['window_start' => '09:00', 'window_end' => '09:00'], 'unknown placeholder' => ['body' => 'Hi {{lead.email}}'], 'empty subject' => ['subject' => ''],
    ] as $label => $patch) {
        [$c, $j] = $req('POST', '/gtm/workflows', $wfDef($patch + $good));
        $ok("validation: $label is refused (422)", $c === 422, [$c, $j['message'] ?? $j]);
    }
    [$c, $j] = $req('GET', '/gtm/workflows/catalog');
    $ok('the catalogue offers start_lead_followup with its parameters', isset($j['data']['actions']['start_lead_followup']['params']['intervals'], $j['data']['actions']['start_lead_followup']['params']['timezone']), array_keys($j['data']['actions'] ?? []));

    // ---------------- the workflow that starts a cadence for every clean new lead ----------------
    [$c, $j] = $req('POST', '/gtm/workflows', ['name' => 'Verify Lead Follow-Up', 'trigger_event' => 'crm.lead.created',
        'conditions' => [['field' => 'lead.has_email', 'op' => 'eq', 'value' => true], ['field' => 'lead.email_opt_out', 'op' => 'eq', 'value' => false], ['field' => 'lead.duplicate_count', 'op' => 'eq', 'value' => 0]],
        'actions' => [['type' => 'start_lead_followup', 'params' => $good]]]);
    $wf = (int) ($j['data']['workflow']['id'] ?? 0);
    $ok('workflow saved', $c === 201 && $wf > 0, [$c, $j]);
    [$c] = $req('POST', "/gtm/workflows/$wf/status", ['status' => 'active']); $ok('workflow activated', $c === 200, $c);
    sleep(1);

    // ---------------- 1. sending OFF (the shipped default): prepared, honestly NOT sent ----------------
    $sendingOn(false);
    $l1 = $mkLead('One'); $leadIds[] = $l1; $deliver($l1);
    $run = DB::table('gtm_workflow_runs')->where('workflow_id', $wf)->where('entity_id', $l1)->first();
    $ok('1: the workflow run succeeded and started a cadence', $run && $run->status === 'succeeded' && $fu($l1) !== null, $run);
    $f = $fu($l1); $em = $emails($l1);
    $ok('1: ONE email was prepared (Queued) for the lead, with its subject rendered from real facts', $em->count() === 1 && $em[0]->status === 'Queued' && $em[0]->subject === 'Hello Verify FU One Co' && ! str_contains($em[0]->body, '{{') && $em[0]->to_address === 'verify-fu-one@example.invalid', $em);
    $ok('1: nothing was delivered', count(CrmFuTransport::$sent) === 0);
    $ok('1: the cadence PAUSED because sending is off - it did not pretend', $f->state === 'paused' && $f->stop_reason === 'sending_disabled' && (int) $f->attempts === 1, $f);
    $ok('1: the lead status was NOT moved to "Attempted to Contact" (nothing went out)', $lead($l1)->lead_status !== 'Attempted to Contact', $lead($l1)->lead_status);
    $ok('1: the owner was told with a note and a task', $notes($l1)->contains(fn ($n) => str_contains($n, 'paused (sending_disabled)') && str_contains($n, 'NOT sent')) && $tasks($l1)->contains(fn ($t) => str_starts_with($t->title, 'Follow-up email waiting to be sent')), [$notes($l1), $tasks($l1)->pluck('title')]);
    $r = LeadFollowUp::processDue();
    $ok('1: later ticks do not pile up drafts: still one email, still paused', $emails($l1)->count() === 1 && $fu($l1)->state === 'paused' && ($r['still_paused'] ?? 0) >= 1, [$r, $emails($l1)->count()]);

    // ---------------- 2. sending ON: the SAME queued email goes out, the cadence waits ----------------
    $sendingOn(true);
    $r = LeadFollowUp::processDue();
    $f = $fu($l1); $em = $emails($l1);
    $ok('2: the paused cadence resumed and sent the SAME prepared email (still exactly one email row)', $em->count() === 1 && $em[0]->status === 'Sent' && $em[0]->sent_at !== null && count(CrmFuTransport::$sent) === 1, [$r, $em, count(CrmFuTransport::$sent)]);
    $msg = CrmFuTransport::$sent[0] ?? null;
    $ok('2: delivered to the lead\'s real address with the rendered subject and the unsubscribe link/header', $msg && $msg->getTo()[0]->getAddress() === 'verify-fu-one@example.invalid' && $msg->getSubject() === 'Hello Verify FU One Co' && str_contains($msg->getTextBody(), 'unsubscribe') && $msg->getHeaders()->has('List-Unsubscribe'), $msg ? $msg->getSubject() : null);
    $ok('2: cadence is ACTIVE again, waiting: attempt 1, next email due in 2 days (not now)', $f->state === 'active' && (int) $f->attempts === 1 && abs(strtotime($f->next_due_at) - (time() + 2 * 86400)) < 180, [$f->state, $f->attempts, $f->next_due_at]);
    $ok('2: the lead is now "Attempted to Contact" (the follow-up-pending state) and the change is audited', $lead($l1)->lead_status === 'Attempted to Contact' && DB::table('g2g_event')->where('type', 'crm.lead.updated')->where('entity_id', $l1)->where('payload', 'like', '%Attempted to Contact%')->exists(), $lead($l1)->lead_status);
    $before = count(CrmFuTransport::$sent); $r = LeadFollowUp::processDue();
    $ok('2: ticking again before the due time does nothing (no email, no run)', count(CrmFuTransport::$sent) === $before && $emails($l1)->count() === 1);

    // ---------------- 3. time passes: attempt 2, attempt 3, then it ends WITHOUT touching the lead ----------------
    $late($l1); LeadFollowUp::processDue();
    $f = $fu($l1);
    $ok('3: attempt 2 sent after the wait; next wait is the second interval (4 days)', $emails($l1)->count() === 2 && $emails($l1)[1]->status === 'Sent' && (int) $f->attempts === 2 && abs(strtotime($f->next_due_at) - (time() + 4 * 86400)) < 180, [$emails($l1)->count(), $f->attempts, $f->next_due_at]);
    $late($l1); LeadFollowUp::processDue();
    $f = $fu($l1);
    $ok('3: attempt 3 (the last) sent; the final wait repeats the last interval', $emails($l1)->count() === 3 && (int) $f->attempts === 3 && $f->state === 'active' && abs(strtotime($f->next_due_at) - (time() + 4 * 86400)) < 180, [$emails($l1)->count(), $f->attempts]);
    $late($l1); LeadFollowUp::processDue();
    $f = $fu($l1);
    $ok('3: out of attempts and no reply: the cadence COMPLETES (no_reply) and sends no 4th email', $f->state === 'completed' && $f->stop_reason === 'no_reply' && $emails($l1)->count() === 3 && $f->next_due_at === null, $f);
    $ok('3: escalation: the owner got a task to decide, and the lead was NOT marked Lost', $tasks($l1)->contains(fn ($t) => str_starts_with($t->title, 'No reply after 3 follow-ups')) && $lead($l1)->lead_status === 'Attempted to Contact', $tasks($l1)->pluck('title'));
    $n = count(CrmFuTransport::$sent); LeadFollowUp::processDue(); $late($l1); LeadFollowUp::processDue();
    $ok('3: a completed cadence never wakes up again', count(CrmFuTransport::$sent) === $n && $fu($l1)->state === 'completed');
    $ok('3: every attempt was logged on the lead as a real note', $notes($l1)->filter(fn ($x) => str_contains($x, 'Follow-up email'))->count() >= 3);

    // ---------------- 4. idempotency ----------------
    $deliver($l1); $e = DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'crm.lead.created')->where('entity_id', $l1)->first(); app(App\Domain\Gtm\Workflows\WorkflowEngine::class)->handleEvent($e);
    $ok('4: the same lead event delivered again starts no second cadence and prepares no new email', DB::table('crm_lead_followups')->where('lead_id', $l1)->count() === 1 && $emails($l1)->count() === 3 && DB::table('gtm_workflow_runs')->where('workflow_id', $wf)->where('entity_id', $l1)->count() === 1);
    $l2 = $mkLead('Claim'); $leadIds[] = $l2; $deliver($l2); $n = count(CrmFuTransport::$sent);
    $late($l2); $a1 = LeadFollowUp::attemptById((int) $fu($l2)->id); $a2 = LeadFollowUp::attemptById((int) $fu($l2)->id);
    $ok('4: the same due attempt cannot be taken twice (atomic claim): exactly one email out of two calls', $a1 === 'sent' && $a2 === 'not_claimed' && count(CrmFuTransport::$sent) === $n + 1 && $emails($l2)->count() === 2, [$a1, $a2]);

    // ---------------- 5. stop conditions, each re-checked against the LIVE lead before the next send ----------------
    $stopCase = function (string $label, string $suffix, callable $change, string $expectReason, string $expectNote) use ($mkLead, $deliver, $fu, $emails, $late, $notes, &$leadIds, $ok) {
        $id = $mkLead($suffix); $leadIds[] = $id; $deliver($id);
        $first = $emails($id)->count(); $sentFirst = $emails($id)[0]->status ?? null;
        $change($id); $late($id); $n = count(CrmFuTransport::$sent);
        LeadFollowUp::processDue();
        $f = $fu($id);
        $ok("5 $label: the first email went out, then the cadence STOPPED ($expectReason) and sent nothing more", $first === 1 && $sentFirst === 'Sent' && $f->state === 'stopped' && $f->stop_reason === $expectReason && count(CrmFuTransport::$sent) === $n && $emails($id)->count() === 1, [$first, $sentFirst, $f->state, $f->stop_reason]);
        $ok("5 $label: the reason is recorded on the lead", $notes($id)->contains(fn ($x) => str_contains($x, 'Automatic follow-up stopped: ' . $expectNote)), $notes($id));
        LeadFollowUp::processDue(); $late($id); LeadFollowUp::processDue();
        $ok("5 $label: it stays stopped - nothing restarts it", $fu($id)->state === 'stopped' && $emails($id)->count() === 1);
        return $id;
    };
    $stopCase('lead marked Lost', 'Lost', fn ($id) => $req('PUT', "/crm/leads/$id", ['leadStatus' => 'Lost Lead']), 'lead_closed', 'the lead was marked Lost/Junk');
    $stopCase('lead marked Junk', 'Junk', fn ($id) => $req('PUT', "/crm/leads/$id", ['leadStatus' => 'Junk Lead']), 'lead_closed', 'the lead was marked Lost/Junk');
    $stopCase('a person is working it', 'Worked', fn ($id) => $req('PUT', "/crm/leads/$id", ['leadStatus' => 'Contacted']), 'lead_engaged', 'a person is already working the lead');
    $stopCase('lead pre-qualified', 'Qualified', fn ($id) => $req('PUT', "/crm/leads/$id", ['leadStatus' => 'Pre Qualified']), 'lead_engaged', 'a person is already working the lead');
    $stopCase('lead opted out', 'Optout', fn ($id) => $req('PUT', "/crm/leads/$id", ['emailOptOut' => true]), 'opted_out', 'the lead opted out of email');
    $stopCase('email address removed', 'NoMail', fn ($id) => $req('PUT', "/crm/leads/$id", ['email' => null]), 'no_email', 'the lead has no valid email address');
    $conv = $stopCase('lead converted', 'Converted', function ($id) use ($req) { [$c, $j] = $req('POST', "/crm/leads/$id/convert", ['createOrganization' => true, 'createContact' => true, 'createOpportunity' => false, 'assignedTo' => 28]); }, 'converted', 'the lead was converted');
    // a reply (the Step 3 reply handler calls this same method): state 'replied', nothing more is sent
    $lr = $mkLead('Replied'); $leadIds[] = $lr; $deliver($lr); $late($lr); $n = count(CrmFuTransport::$sent);
    $stopped = LeadFollowUp::stopFor(6, $lr, 'replied', 'replied', 'Customer replied.'); LeadFollowUp::processDue();
    $ok('5 customer replied: the cadence ends as "replied" and the next due email is NOT sent', $stopped === true && $fu($lr)->state === 'replied' && $fu($lr)->next_due_at === null && count(CrmFuTransport::$sent) === $n && $emails($lr)->count() === 1, $fu($lr));
    $ok('5 customer replied: stopping twice is harmless', LeadFollowUp::stopFor(6, $lr, 'replied', 'replied') === false);

    // ---------------- 6. failures ----------------
    $l3 = $mkLead('Fail'); $leadIds[] = $l3;
    CrmFuTransport::$fail = 'Expected response code "250" but got code "550", with message "550 5.1.1 The email account does not exist."';
    $deliver($l3); CrmFuTransport::$fail = null;
    $f = $fu($l3); $em = $emails($l3);
    $ok('6: a provider failure marks the email Failed with the reason, and PAUSES the cadence with a task for the owner', $em->count() === 1 && $em[0]->status === 'Failed' && str_contains($em[0]->failure_reason, '550') && $f->state === 'paused' && $f->stop_reason === 'send_failed' && $tasks($l3)->contains(fn ($t) => str_starts_with($t->title, 'Follow-up email waiting')), [$em, $f]);
    $n = count(CrmFuTransport::$sent); LeadFollowUp::processDue(); $late($l3); LeadFollowUp::processDue();
    $ok('6: a failed email is never auto-resent and no second email is prepared behind it', count(CrmFuTransport::$sent) === $n && $emails($l3)->count() === 1 && $fu($l3)->state === 'paused');
    $ok('6: the lead was not moved to Attempted to Contact (nothing was delivered)', $lead($l3)->lead_status !== 'Attempted to Contact');

    $sentToday = (int) DB::table('crm_emails')->where('sub_institute_id', 6)->where('status', 'Sent')->where('sent_at', '>=', now()->startOfDay())->count();
    config(['crm.email_daily_cap' => $sentToday]);
    $l4 = $mkLead('Cap'); $leadIds[] = $l4; $deliver($l4);
    $f = $fu($l4);
    $ok('6: the daily sending cap is respected: prepared, paused (daily_cap), not sent', $emails($l4)->count() === 1 && $emails($l4)[0]->status === 'Queued' && $f->state === 'paused' && $f->stop_reason === 'daily_cap', [$f, $emails($l4)]);
    config(['crm.email_daily_cap' => $sentToday + 50]); $n = count(CrmFuTransport::$sent);
    LeadFollowUp::processDue();
    $ok('6: once the cap allows, the SAME queued email is sent (no second email)', $emails($l4)->count() === 1 && $emails($l4)[0]->status === 'Sent' && count(CrmFuTransport::$sent) === $n + 1 && $fu($l4)->state === 'active', [$emails($l4), $fu($l4)->state]);

    // owner sends the prepared email by hand (Emails tab): the cadence picks it up instead of sending a duplicate
    $sendingOn(false);
    $l5 = $mkLead('Hand'); $leadIds[] = $l5; $deliver($l5);
    $emailId = (int) $emails($l5)[0]->id;
    DB::table('crm_emails')->where('id', $emailId)->update(['status' => 'Sent', 'sent_at' => now()]); // what a manual send leaves behind
    $sendingOn(true); $n = count(CrmFuTransport::$sent); LeadFollowUp::processDue();
    $ok('6: an email the owner sent by hand is recognised: cadence resumes, no duplicate is sent', count(CrmFuTransport::$sent) === $n && $emails($l5)->count() === 1 && $fu($l5)->state === 'active' && $fu($l5)->next_due_at > now()->format('Y-m-d H:i:s'), [$fu($l5)]);

    // ---------------- 7. business hours ----------------
    $tz = 'UTC'; $lateStart = now('UTC')->addHours(3)->format('H:i'); $lateEnd = now('UTC')->addHours(4)->format('H:i');
    if ($lateStart < $lateEnd) {
        $l6 = $mkLead('Hours'); $leadIds[] = $l6;
        $r = LeadFollowUp::start(6, $lead($l6), ['subject' => 'Hello {{lead.company}}', 'body' => 'Hi', 'intervals' => '1', 'max_attempts' => 2, 'window_start' => $lateStart, 'window_end' => $lateEnd, 'timezone' => $tz, 'weekdays_only' => false], 28, null, null);
        $f = $fu($l6);
        $ok('7: outside business hours the first email WAITS for the window (no email now, due at the window start)', $r['started'] === true && $r['outcome'] === 'waiting_for_business_hours' && $emails($l6)->count() === 0 && substr($f->next_due_at, 11, 5) === $lateStart, [$r, $f->next_due_at, $lateStart]);
    } else { echo "SKIP 7 (window would cross midnight at this hour)\n"; }

    // ---------------- 8. tenant isolation ----------------
    if ($other) {
        $oid = App\Domain\Crm\Leads\LeadFactory::create(['last_name' => 'Verify Other FU', 'company' => 'Verify Other FU Co', 'email' => 'verify-other-fu@example.invalid', 'assigned_to' => $other->id], (int) $other->sub_institute_id, (int) $other->id);
        $otherLeadIds[] = $oid;
        $ev = DB::table('g2g_event')->where('sub_institute_id', $other->sub_institute_id)->where('type', 'crm.lead.created')->where('entity_id', $oid)->first();
        $runner->dispatch($ev);
        $ok('8: tenant 6\'s follow-up workflow did not start a cadence for another tenant\'s lead', DB::table('crm_lead_followups')->where('lead_id', $oid)->count() === 0);
        $ok('8: no tenant-6 email is linked to the other tenant\'s lead', $emails($oid)->count() === 0);
        $r = LeadFollowUp::start((int) $other->sub_institute_id, DB::table('crm_leads')->find($oid), ['subject' => 'Hi', 'body' => 'Hi', 'intervals' => '1', 'max_attempts' => 1, 'window_start' => '00:00', 'window_end' => '23:59', 'timezone' => 'UTC', 'weekdays_only' => false], null, null, null);
        $em = $emails($oid);
        $ok('8: a cadence for the other tenant writes ITS emails and notes under ITS tenant id', $em->count() === 1 && (int) $em[0]->sub_institute_id === (int) $other->sub_institute_id && DB::table('crm_lead_comments')->where('lead_id', $oid)->where('sub_institute_id', $other->sub_institute_id)->exists(), [$r, $em]);
    }
} finally {
    $all = array_merge($leadIds, $otherLeadIds);
    $emailIds = DB::table('crm_email_links')->where('linked_type', 'LEAD')->whereIn('linked_id', $all)->pluck('email_id');
    DB::table('crm_email_links')->whereIn('email_id', $emailIds)->delete();
    DB::table('crm_emails')->whereIn('id', $emailIds)->delete();
    DB::table('crm_lead_followups')->whereIn('lead_id', $all)->delete();
    DB::table('task_management_calendar_events')->where('linked_type', 'LEAD')->whereIn('linked_id', $all)->delete();
    DB::table('crm_lead_comments')->whereIn('lead_id', $all)->delete();
    DB::table('crm_leads')->whereIn('id', $all)->delete();
    // a converted lead's Contact keeps the lead's email (its last name is "FU Converted", not "Verify ..."), so match on the email
    DB::table('crm_contacts')->where(fn ($w) => $w->where('email', 'like', 'verify-fu-%@example.invalid')->orWhere('last_name', 'like', 'Verify FU%'))->delete();
    DB::table('crm_organizations')->where('name', 'like', 'Verify FU%')->delete();
    gtm_verify_cleanup($startedAt);
    echo 'cleanup: leftover verify leads ' . DB::table('crm_leads')->where('last_name', 'like', 'Verify%')->count() . ', followups ' . DB::table('crm_lead_followups')->whereIn('lead_id', $all)->count() . ', orgs ' . DB::table('crm_organizations')->where('name', 'like', 'Verify FU%')->count() . "\n";
}
echo "\n$pass passed, $fail failed\n";
