<?php
// The tenant-scoped automation command `crm:automation-tick --tenant=N`.
//   php tests/Integration/Gtm/http.php crmtick
// Proves: it refuses a missing/unknown tenant; a dry run changes nothing; it delivers ONLY the named tenant's events, retries only that
// tenant's runs, advances only that tenant's follow-ups; a second pass is idempotent; a failed step is recorded and not repeated; a
// transient failure is retried and completes; a held lock stops a concurrent pass; the sweeps honour the tenant filter.
// Real dev DB, the REAL command/reactor/engine. NOTHING leaves the machine: the AI provider is faked with Http::fake and CRM email sending
// stays OFF (the follow-up proof uses the paused-then-"sent by hand" path, which sends nothing). Every row created is named "Verify ...";
// delivery uses --after-event so no pre-existing event of the shared database is ever delivered. Cleanup is in the finally block.
use App\Domain\Crm\Leads\LeadFactory;
use App\Domain\Crm\Leads\LeadFollowUp;
use App\Domain\Crm\Sales\{InvoiceSweep, OpportunitySweep};
use App\Services\Events\GtmWorkflowRunner;
use Illuminate\Support\Facades\{Artisan, Cache, DB, Http};

$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n"; };
$runner = app(GtmWorkflowRunner::class);
$tick = function (array $args) { $code = Artisan::call('crm:automation-tick', $args); return [$code, Artisan::output()]; };
$notes = fn (int $id) => DB::table('crm_lead_comments')->where('lead_id', $id)->orderBy('id')->pluck('comment');
$runsOf = fn (int $wf, int $entity) => DB::table('gtm_workflow_runs')->where('workflow_id', $wf)->where('entity_id', $entity)->orderBy('id')->get();
$ledger = fn (int $eventId) => DB::table('g2g_event_delivery')->where('event_id', $eventId)->where('consumer', GtmWorkflowRunner::CONSUMER)->first();
$event = fn (int $tenant, string $type, int $entity) => DB::table('g2g_event')->where('sub_institute_id', $tenant)->where('type', $type)->where('entity_id', $entity)->orderByDesc('id')->first();
$secret = 'verify-tick-secret';
$post = function (array $body) use ($kernel, $secret) {
    Cache::flush(); $raw = json_encode($body); $ts = time(); $h = "t=$ts,v1=" . hash_hmac('sha256', "$ts.$raw", $secret);
    $res = $kernel->handle(Illuminate\Http\Request::create('/api/crm/inbound', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_CRM_SIGNATURE' => $h], $raw));
    return [$res->getStatusCode(), json_decode($res->getContent(), true)];
};
$aiFail = function () { Http::swap(new Illuminate\Http\Client\Factory); Http::fake(['*' => Http::response(['error' => 'boom'], 500)]); };
$aiOk = function () {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['*' => function () { $t = json_encode(['classification' => 'interested', 'confidence' => 'high', 'reason' => 'asks for pricing']);
        return Http::response(['candidates' => [['content' => ['parts' => [['text' => $t]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 10],
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $t], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20]]); }]);
};
config(['crm.email_sending_enabled' => false, 'crm.inbound.secret' => $secret]);
$ok('SAFETY: CRM email sending is OFF for this scenario', config('crm.email_sending_enabled') === false);
if (! $other) { echo "ABORT: needs an administrator of a second tenant for the isolation checks\n"; exit(1); }
$T = 6; $OT = (int) $other->sub_institute_id;
$ok('the second tenant is a different tenant', $OT !== $T, [$T, $OT]);

$wfIds = []; $leadIds = []; $otherRunIds = [];
$mkWf = function (int $tenant, int $creator, string $name, string $trigger, array $actions) use (&$wfIds) {
    $id = (int) DB::table('gtm_workflows')->insertGetId(['sub_institute_id' => $tenant, 'name' => $name, 'description' => 'Verify tick', 'trigger_event' => $trigger, 'conditions' => '[]', 'actions' => json_encode($actions),
        'requires_approval' => 0, 'status' => 'active', 'max_runs_per_day' => 1000, 'created_by' => $creator, 'updated_by' => $creator, 'created_at' => now(), 'updated_at' => now(), 'activated_at' => now()->subMinutes(5)]);
    $wfIds[] = $id;
    return $id;
};
$mkLead = function (int $tenant, int $user, string $suffix) use (&$leadIds) {
    $id = LeadFactory::create(['first_name' => 'Verify', 'last_name' => "Tick $suffix", 'company' => "Verify Tick $suffix Co", 'email' => 'verify-tick-' . strtolower($suffix) . '@example.invalid', 'phone' => '+910000000300', 'assigned_to' => $user], $tenant, $user);
    $leadIds[] = $id;
    return $id;
};
$eventsAtStart = (int) DB::table('g2g_event')->max('id');

try {
    // ---------------- 1. arguments ----------------
    [$c] = $tick([]); $ok('1: no --tenant is refused (invalid, nothing processed)', $c === 2, $c);
    [$c] = $tick(['--tenant' => 'six']); $ok('1: a non-numeric tenant is refused', $c === 2, $c);
    [$c] = $tick(['--tenant' => '0']); $ok('1: tenant 0 is refused', $c === 2, $c);
    [$c, $o] = $tick(['--tenant' => '987654']); $ok('1: an unknown tenant is refused', $c === 2 && str_contains($o, 'does not exist'), [$c, $o]);
    [$c] = $tick(['--tenant' => $T, '--steps' => 'events,bogus']); $ok('1: an unknown --steps value is refused', $c === 2, $c);

    // ---------------- 2. workflows and leads for the scenario ----------------
    $wfNote = $mkWf($T, 28, 'Verify Tick Note', 'crm.lead.created', [['type' => 'add_lead_note', 'params' => ['text' => 'Verify tick note']]]);
    $wfFail = $mkWf($T, 28, 'Verify Tick Fail', 'crm.lead.created', [['type' => 'set_lead_status', 'params' => ['status' => 'NoSuchStatus']], ['type' => 'add_lead_note', 'params' => ['text' => 'Verify must-not-run']]]);
    $wfClassify = $mkWf($T, 28, 'Verify Tick Classify', 'crm.lead.replied', [['type' => 'classify_lead_reply', 'params' => new stdClass]]);
    $wfOther = $mkWf($OT, (int) $other->id, 'Verify Tick Note Other', 'crm.lead.created', [['type' => 'add_lead_note', 'params' => ['text' => 'Verify other tenant note']]]);
    $floor = (int) DB::table('g2g_event')->max('id');
    $l6 = $mkLead($T, 28, 'T6'); $lo = $mkLead($OT, (int) $other->id, 'Other');
    $e6 = $event($T, 'crm.lead.created', $l6); $eo = $event($OT, 'crm.lead.created', $lo);
    $ok('2: both leads recorded a crm.lead.created event, each under its own tenant', $e6 && $eo && (int) $e6->sub_institute_id === $T && (int) $eo->sub_institute_id === $OT);
    $ok('2: both events are undelivered before the pass', ! $ledger($e6->id) && ! $ledger($eo->id));

    // ---------------- 3. dry run changes nothing ----------------
    $before = [DB::table('g2g_event_delivery')->count(), DB::table('gtm_workflow_runs')->count(), DB::table('crm_lead_comments')->count()];
    [$c, $o] = $tick(['--tenant' => $T, '--dry-run' => true, '--after-event' => $floor]);
    $after = [DB::table('g2g_event_delivery')->count(), DB::table('gtm_workflow_runs')->count(), DB::table('crm_lead_comments')->count()];
    $ok('3: a dry run reports the waiting events and changes nothing', $c === 0 && $before === $after && str_contains($o, 'workflow events waiting'), [$before, $after, $o]);

    // ---------------- 4. delivery is confined to the tenant ----------------
    $otherLedgerBefore = DB::table('g2g_event_delivery as d')->join('g2g_event as e', 'e.id', '=', 'd.event_id')->where('d.consumer', GtmWorkflowRunner::CONSUMER)->where('e.sub_institute_id', '!=', $T)->count();
    [$c, $o] = $tick(['--tenant' => $T, '--steps' => 'events', '--after-event' => $floor]);
    $ok('4: the pass succeeds', $c === 0, [$c, $o]);
    $ok('4: tenant 6\'s event was delivered (ledger row)', (bool) $ledger($e6->id), $o);
    $ok('4: tenant 6\'s workflow ran once and left its note', $runsOf($wfNote, $l6)->count() === 1 && $runsOf($wfNote, $l6)[0]->status === 'succeeded' && $notes($l6)->filter(fn ($n) => str_contains($n, 'Verify tick note'))->count() === 1, [$runsOf($wfNote, $l6), $notes($l6)]);
    $ok('4: the OTHER tenant\'s event was NOT delivered (no ledger row)', ! $ledger($eo->id));
    $ok('4: the other tenant\'s workflow did not run and its lead has no note', $runsOf($wfOther, $lo)->count() === 0 && $notes($lo)->count() === 0, [$runsOf($wfOther, $lo)->count(), $notes($lo)]);
    $otherLedgerAfter = DB::table('g2g_event_delivery as d')->join('g2g_event as e', 'e.id', '=', 'd.event_id')->where('d.consumer', GtmWorkflowRunner::CONSUMER)->where('e.sub_institute_id', '!=', $T)->count();
    $ok('4: no event of any other tenant gained a delivery row', $otherLedgerBefore === $otherLedgerAfter, [$otherLedgerBefore, $otherLedgerAfter]);
    $ok('4: the other tenant\'s event is still waiting', $runner->pendingCountForTenant($OT, $floor) >= 1);

    // ---------------- 5. failed action: recorded, not repeated, later step not run ----------------
    $rf = $runsOf($wfFail, $l6);
    $ok('5: a step that fails for good ends the run as failed', $rf->count() === 1 && $rf[0]->status === 'failed', $rf);
    $ok('5: the step after the failed one did not run', ! $notes($l6)->contains(fn ($n) => str_contains($n, 'must-not-run')), $notes($l6));
    $ok('5: the failed event is ledgered (it is not retried by the next pass)', $ledger($e6->id) !== null);

    // ---------------- 6. duplicate delivery and empty queue ----------------
    [$c, $o] = $tick(['--tenant' => $T, '--steps' => 'events', '--after-event' => $floor]);
    $ok('6: a second pass delivers nothing and succeeds', $c === 0 && str_contains($o, '0 delivered'), $o);
    $runner->dispatch($e6);
    $ok('6: dispatching the same event again starts nothing (UNIQUE workflow+event)', $runsOf($wfNote, $l6)->count() === 1 && $notes($l6)->filter(fn ($n) => str_contains($n, 'Verify tick note'))->count() === 1);
    $ok('6: still exactly one run per workflow and nothing for the other tenant', $runsOf($wfFail, $l6)->count() === 1 && $runsOf($wfOther, $lo)->count() === 0);

    // ---------------- 7. concurrent pass ----------------
    $lock = Cache::lock("crm-automation-tick:{$T}", 60);
    $held = $lock->get();
    [$c, $o] = $tick(['--tenant' => $T, '--steps' => 'events', '--after-event' => $floor]);
    $ok('7: while another pass holds the lock, a pass does nothing and says so', $held && $c === 0 && str_contains($o, 'already running'), [$held, $c, $o]);
    $held && $lock->release();
    [$c, $o] = $tick(['--tenant' => $T, '--steps' => 'events', '--after-event' => $floor]);
    $ok('7: after the lock is released a pass runs normally', $c === 0 && str_contains($o, 'delivered'), $o);

    // ---------------- 8. transient failure -> retry (own tenant only) ----------------
    $r6 = $mkLead($T, 28, 'Reply');
    $aiFail();
    [$st] = $post(['provider' => 'tick', 'event_id' => 'verify-tick-' . bin2hex(random_bytes(5)), 'type' => 'reply', 'from_email' => 'verify-tick-reply@example.invalid', 'subject' => 'Re: hello', 'text' => 'Please send the pricing and your brochure.', 'occurred_at' => now()->toDateTimeString()]);
    $ok('8: the signed reply was accepted by the webhook', $st === 200, $st);
    $evReply = $event($T, 'crm.lead.replied', $r6);
    $ok('8: the reply recorded a crm.lead.replied event for tenant 6', (bool) $evReply);
    // an OTHER tenant run that is due a retry: a bad pass must not touch it
    $otherRun = (int) DB::table('gtm_workflow_runs')->insertGetId(['sub_institute_id' => $OT, 'workflow_id' => $wfOther, 'event_id' => (int) $eo->id, 'event_type' => 'crm.lead.created', 'entity_type' => 'crm_leads', 'entity_id' => $lo,
        'status' => 'retry_wait', 'context' => '{}', 'attempts' => 1, 'next_attempt_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);
    $otherRunIds[] = $otherRun;
    [$c, $o] = $tick(['--tenant' => $T, '--steps' => 'events', '--after-event' => $floor]);
    $rc = $runsOf($wfClassify, $r6);
    $ok('8: the AI provider failure puts the run in retry_wait (not failed, not lost)', $rc->count() === 1 && $rc[0]->status === 'retry_wait', $rc);
    $ok('8: the inbound reply is still unclassified', DB::table('crm_inbound_events')->where('lead_id', $r6)->value('classification') === null);
    $aiOk();
    DB::table('gtm_workflow_runs')->whereIn('id', $rc->pluck('id'))->update(['next_attempt_at' => now()->subMinute()]);
    // tenant 6's other retry-due runs (the user's real 3a on this Verify lead) belong to this scenario's lead too; ours is the one asserted
    [$c, $o] = $tick(['--tenant' => $T, '--steps' => 'retries']);
    $rc2 = DB::table('gtm_workflow_runs')->find($rc[0]->id);
    $ok('8: the retry pass re-ran the due run and it completed', $rc2->status === 'succeeded' && (int) $rc2->attempts === 2, $rc2);
    $ok('8: the reply was classified exactly once, and one note says so', DB::table('crm_inbound_events')->where('lead_id', $r6)->value('classification') === 'interested' && $notes($r6)->filter(fn ($n) => str_contains($n, 'The reply was read as'))->count() === 1, $notes($r6));
    $oRun = DB::table('gtm_workflow_runs')->find($otherRun);
    $ok('8: the OTHER tenant\'s due run was not touched by tenant 6\'s retry pass', $oRun->status === 'retry_wait' && (int) $oRun->attempts === 1, $oRun);

    // ---------------- 9. follow-ups advance for the named tenant only (no email is sent) ----------------
    $f6 = $mkLead($T, 28, 'FU6'); $fo = $mkLead($OT, (int) $other->id, 'FUOther');
    $cfg = ['subject' => 'Verify {{lead.company}}', 'body' => 'Verify body', 'intervals' => '2', 'max_attempts' => 2, 'window_start' => '00:00', 'window_end' => '23:59', 'timezone' => 'UTC', 'weekdays_only' => false];
    $sBefore = config('crm.email_sending_enabled');
    LeadFollowUp::start($T, DB::table('crm_leads')->find($f6), $cfg, null, null, null);
    LeadFollowUp::start($OT, DB::table('crm_leads')->find($fo), $cfg, null, null, null);
    $c6 = DB::table('crm_lead_followups')->where('lead_id', $f6)->first(); $co = DB::table('crm_lead_followups')->where('lead_id', $fo)->first();
    $ok('9: with sending off both cadences are paused with a Queued email and NOTHING was sent', $c6 && $co && $c6->state === 'paused' && $co->state === 'paused' && DB::table('crm_emails')->whereIn('id', [$c6->last_email_id, $co->last_email_id])->where('status', 'Queued')->count() === 2);
    $realDue = DB::table('crm_lead_followups')->where('sub_institute_id', $T)->whereNotIn('lead_id', $leadIds)->where(fn ($q) => $q->where(fn ($a) => $a->where('state', 'active')->whereNotNull('next_due_at')->where('next_due_at', '<=', now()))->orWhere('state', 'paused'))->count();
    if ($realDue > 0) {
        echo "SKIP 9: tenant 6 has $realDue real follow-up(s) due or paused; refusing to run the follow-up step on the shared database\n";
    } else {
        // the owner "sent" both prepared emails by hand (a DB stand-in; nothing was sent) so the cadence can move on
        foreach ([$c6, $co] as $cx) { DB::table('crm_emails')->where('id', $cx->last_email_id)->update(['status' => 'Sent', 'sent_at' => now(), 'message_id' => "verify-tick.{$cx->id}@tick.invalid"]); }
        [$c, $o] = $tick(['--tenant' => $T, '--steps' => 'followups']);
        $ok('9: tenant 6\'s cadence moved on (active, next follow-up scheduled)', ($x = DB::table('crm_lead_followups')->where('lead_id', $f6)->first()) && $x->state === 'active' && $x->next_due_at !== null, $x);
        $ok('9: the OTHER tenant\'s cadence did not move (still paused)', ($y = DB::table('crm_lead_followups')->where('lead_id', $fo)->first()) && $y->state === 'paused' && $y->next_due_at === $co->next_due_at, $y);
        $ok('9: no email was sent by the pass (no Sent email other than the two marked by hand)', DB::table('crm_emails')->whereIn('id', DB::table('crm_email_links')->where('linked_type', 'LEAD')->whereIn('linked_id', [$f6, $fo])->pluck('email_id'))->where('status', 'Sent')->count() === 2);
    }

    // ---------------- 10. sweeps honour the tenant filter (rolled back: nothing persists) ----------------
    $openOpp = fn (bool $mine) => DB::table('crm_opportunities')->whereNull('deleted_at')->where('sub_institute_id', $mine ? '=' : '!=', $T)->where(fn ($q) => $q->whereNull('sales_stage')->orWhereNotIn('sales_stage', ['Closed Won', 'Closed Lost']))->value('id');
    [$o6, $oX] = [$openOpp(true), $openOpp(false)];
    if ($o6 && $oX) {
        DB::beginTransaction();
        try {
            $r = OpportunitySweep::run(10, [$o6, $oX], $T);
            $ok('10: the Opportunity check looked at tenant 6\'s record and not the other tenant\'s', $r['checked'] === 1, $r);
        } finally { DB::rollBack(); }
    } else { echo "SKIP 10a: need an open Opportunity in tenant 6 and in another tenant (have " . json_encode([$o6, $oX]) . ")\n"; }
    $overdue = fn (bool $mine) => DB::table('crm_invoices')->whereNull('deleted_at')->where('sub_institute_id', $mine ? '=' : '!=', $T)->whereNotNull('due_date')->where('due_date', '<', now()->toDateString())->where('total', '>', 0)->whereRaw('(total - amount_paid) > 0.004')->value('id');
    [$i6, $iX] = [$overdue(true), $overdue(false)];
    if ($i6 && $iX) {
        DB::beginTransaction();
        try {
            $r = InvoiceSweep::run(10, [$i6, $iX], $T);
            $ok('10: the overdue-invoice check looked at tenant 6\'s record and not the other tenant\'s', $r['checked'] === 1, $r);
        } finally { DB::rollBack(); }
    } else { echo "SKIP 10b: need an overdue invoice in tenant 6 and in another tenant (have " . json_encode([$i6, $iX]) . ")\n"; }
} finally {
    $inbound = DB::table('crm_inbound_events')->whereIn('lead_id', $leadIds)->get();
    $emailIds = DB::table('crm_email_links')->where('linked_type', 'LEAD')->whereIn('linked_id', $leadIds)->pluck('email_id')->merge($inbound->pluck('inbound_email_id'))->filter()->unique();
    $runIds = DB::table('gtm_workflow_runs')->where('entity_type', 'crm_leads')->whereIn('entity_id', $leadIds)->pluck('id')->merge($otherRunIds)->unique();
    $evIds = DB::table('g2g_event')->where('id', '>', $eventsAtStart)->where('entity_type', 'crm_leads')->whereIn('entity_id', $leadIds)->pluck('id');
    DB::table('gtm_workflow_run_steps')->whereIn('run_id', $runIds)->delete();
    DB::table('gtm_workflow_runs')->whereIn('id', $runIds)->delete();
    DB::table('gtm_workflows')->whereIn('id', $wfIds)->delete();
    DB::table('crm_inbound_events')->whereIn('lead_id', $leadIds)->delete();
    DB::table('crm_email_links')->whereIn('email_id', $emailIds)->delete();
    DB::table('crm_emails')->whereIn('id', $emailIds)->delete();
    DB::table('crm_lead_followups')->whereIn('lead_id', $leadIds)->delete();
    DB::table('task_management_calendar_events')->where('linked_type', 'LEAD')->whereIn('linked_id', $leadIds)->delete();
    DB::table('crm_lead_comments')->whereIn('lead_id', $leadIds)->delete();
    DB::table('crm_leads')->whereIn('id', $leadIds)->delete();
    // the events this scenario's leads recorded are append-only; the ones nobody delivered would wait forever for a lead that no longer exists, so close them
    foreach ($evIds as $eid) {
        if (! DB::table('g2g_event_delivery')->where('event_id', $eid)->where('consumer', GtmWorkflowRunner::CONSUMER)->exists()) {
            DB::table('g2g_event_delivery')->insert(['event_id' => $eid, 'consumer' => GtmWorkflowRunner::CONSUMER, 'status' => 'skipped', 'attempts' => 0, 'last_error' => 'closed by the crmtick scenario cleanup', 'completed_at' => now()]);
        }
    }
    echo 'cleanup: leftover verify leads ' . DB::table('crm_leads')->where('last_name', 'like', 'Tick %')->where('company', 'like', 'Verify Tick%')->count() . ', workflows ' . DB::table('gtm_workflows')->where('name', 'like', 'Verify Tick%')->count() . "\n";
}
echo "\n$pass passed, $fail failed\n";
