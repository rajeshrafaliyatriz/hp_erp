<?php
// Meeting scheduling for a lead whose reply asks for a call.
//   php tests/Integration/Gtm/http.php crmmeeting
// A: the slot finder (LeadMeeting::freeSlots) against REAL calendar rows (Verify events are added to the owner's real calendar to force known clashes).
// B: the whole chain through the REAL webhook, reactor, engine, tenant-scoped command and approval endpoint:
//    reply "can we schedule a call?" -> classified meeting_requested -> times proposed (note, task, DRAFT email, event) -> approval run held ->
//    approved -> a [Tentative] event on the owner's calendar; nothing is sent, no attendee is added, repeats create nothing.
// NOTHING leaves the machine: CRM email sending is OFF, the AI provider is faked with Http::fake (this proves wiring and guards, NOT model
// quality). Every row is named "Verify Mtg ..." and removed in the finally block.
use App\Domain\Crm\Leads\{LeadFactory, LeadMeeting};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Artisan, Cache, DB, Http};

$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n"; };
$req = function ($m, $u, $b = [], $tok = null) use ($kernel, $A) { Cache::flush(); return call($kernel, $m, $u, $tok ?? $A, $b); };
$tick = function (int $after) { $c = Artisan::call('crm:automation-tick', ['--tenant' => 6, '--after-event' => $after, '--steps' => 'events,retries']); return [$c, Artisan::output()]; };
$notes = fn (int $id) => DB::table('crm_lead_comments')->where('lead_id', $id)->orderBy('id')->pluck('comment');
$secret = 'verify-mtg-secret';
config(['crm.email_sending_enabled' => false, 'crm.inbound.secret' => $secret]);
$ok('SAFETY: CRM email sending is OFF for this scenario', config('crm.email_sending_enabled') === false);
$app = new DateTimeZone((string) config('app.timezone')); $IST = 'Asia/Kolkata'; $tzI = new DateTimeZone($IST);
$utc = fn (CarbonImmutable $d) => $d->setTimezone($app)->format('Y-m-d H:i:s');
$syear = App\Domain\Crm\Leads\LeadActivity::currentSyear(6);
$leadIds = []; $wfIds = []; $evtIds = [];
$block = function (string $title, CarbonImmutable $s, CarbonImmutable $e, bool $allDay = false, int $owner = 28) use (&$evtIds, $utc, $syear) {
    $id = (int) DB::table('task_management_calendar_events')->insertGetId(['sub_institute_id' => 6, 'syear' => $syear, 'title' => "Verify Mtg $title", 'start_at' => $utc($s), 'end_at' => $utc($e), 'all_day' => $allDay ? 1 : 0,
        'status' => 'Planned', 'visibility' => 'PUBLIC', 'owner_id' => $owner, 'created_by' => 28, 'created_at' => now(), 'updated_at' => now()]);
    $evtIds[] = $id;
    return $id;
};
$cfg = ['timezone' => $IST, 'window_start' => '09:00', 'window_end' => '17:00', 'duration_minutes' => 30, 'days_ahead' => 6, 'slots' => 5, 'weekdays_only' => true, 'min_notice_hours' => 0, 'buffer_minutes' => 0];
$inIst = fn (string $appTime) => CarbonImmutable::parse($appTime, $app)->setTimezone($tzI);
$overlapsReal = function (string $start, string $end, int $owner = 28) {
    $att = DB::table('task_management_calendar_attendees')->where('user_id', $owner)->pluck('event_id');
    return DB::table('task_management_calendar_events')->where('sub_institute_id', 6)->whereNull('deleted_at')->where(fn ($q) => $q->where('owner_id', $owner)->orWhereIn('id', $att))
        ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['Cancelled', 'Canceled']))->where('start_at', '<', $end)->where('end_at', '>', $start)->where('all_day', 0)->exists();
};

try {
    // ================= A. slot finder =================
    $mon = CarbonImmutable::parse('next monday', $tzI)->setTime(6, 0);
    $monIst = fn (int $h, int $m = 0) => $mon->setTime($h, $m);
    $morning = $block('morning meeting', $monIst(9), $monIst(10, 30));
    $res = LeadMeeting::freeSlots(6, 28, $cfg, $mon);
    $s = $res['slots'];
    $ok('A1: it returns slots (the owner\'s real calendar has room)', count($s) >= 1, $res);
    $ok('A2: no returned slot overlaps ANY timed entry on the owner\'s real calendar', collect($s)->every(fn ($x) => ! $overlapsReal($x['start'], $x['end'])), $s);
    $ok('A3: Monday\'s slot starts at or after 10:30 (the 09:00-10:30 Verify meeting is respected)', ($m = collect($s)->first(fn ($x) => $inIst($x['start'])->isSameDay($mon))) ? $inIst($m['start'])->format('H:i') >= '10:30' : true, $s);
    $ok('A4: every slot is inside 09:00-17:00 in the configured time zone and is a weekday', collect($s)->every(fn ($x) => ($t = $inIst($x['start']))->format('H:i') >= '09:00' && $inIst($x['end'])->format('H:i') <= '17:00' && ! $t->isWeekend()), $s);
    $ok('A5: at most one slot per day, at most the number asked for', count($s) === collect($s)->map(fn ($x) => $inIst($x['start'])->toDateString())->unique()->count() && count($s) <= 5);
    $ok('A6: every slot is 30 minutes long', collect($s)->every(fn ($x) => (strtotime($x['end']) - strtotime($x['start'])) === 1800));
    $tue = $mon->addDay();
    $allDay = $block('all-day tuesday', $tue->startOfDay(), $tue->endOfDay(), true);
    $s2 = LeadMeeting::freeSlots(6, 28, $cfg, $mon)['slots'];
    $ok('A7: an all-day entry removes that whole day', collect($s2)->every(fn ($x) => ! $inIst($x['start'])->isSameDay($tue)), $s2);
    // an event owned by someone else, with the owner as attendee, blocks the owner
    $wed = $mon->addDays(2);
    $shared = $block('attendee meeting', $wed->setTime(9, 0), $wed->setTime(17, 0), false, (int) $employee->id);
    DB::table('task_management_calendar_attendees')->insert(['event_id' => $shared, 'user_id' => 28, 'status' => 'Accepted', 'created_at' => now(), 'updated_at' => now()]);
    $s3 = LeadMeeting::freeSlots(6, 28, $cfg, $mon)['slots'];
    $ok('A8: an event where the owner is an ATTENDEE blocks the owner (Wednesday 09:00-17:00 is gone)', collect($s3)->every(fn ($x) => ! $inIst($x['start'])->isSameDay($wed)), $s3);
    $ok('A9: the same event blocks its OWNER too (the employee who owns Wednesday\'s meeting has no Wednesday slot)', collect(LeadMeeting::freeSlots(6, (int) $employee->id, $cfg, $mon)['slots'])->every(fn ($x) => ! $inIst($x['start'])->isSameDay($wed)));
    $fri = $mon->subDays(3)->setTime(16, 45);
    $s4 = LeadMeeting::freeSlots(6, 28, ['slots' => 1, 'min_notice_hours' => 0] + $cfg, $fri)['slots'];
    $ok('A10: asked on Friday 16:45 it skips the weekend and the closed evening', $s4 === [] || (! ($t = $inIst($s4[0]['start']))->isWeekend() && ! $t->isSameDay($fri)), $s4);
    $ok('A11: nothing is proposed in the past (min notice honoured)', collect(LeadMeeting::freeSlots(6, 28, ['min_notice_hours' => 4, 'slots' => 3, 'days_ahead' => 3] + $cfg)['slots'])->every(fn ($x) => strtotime($x['start']) >= time() + 4 * 3600 - 1));

    // ================= B. whole chain =================
    foreach ([['Verify Mtg Classify', 'crm.lead.replied', [['type' => 'classify_lead_reply', 'params' => new stdClass]], []],
              ['Verify Mtg Propose', 'crm.lead.reply_classified', [['type' => 'propose_lead_meeting', 'params' => ['duration_minutes' => 30, 'days_ahead' => 10, 'slots' => 3, 'window_start' => '09:00', 'window_end' => '17:00', 'timezone' => $IST, 'weekdays_only' => true]]], [['field' => 'event.classification', 'op' => 'eq', 'value' => 'meeting_requested']]],
              ['Verify Mtg Book', 'crm.lead.meeting_proposed', [['type' => 'book_lead_meeting', 'params' => new stdClass]], [['field' => 'event.slot_count', 'op' => 'gt', 'value' => 0]]]] as [$name, $trig, $acts, $conds]) {
        [$c, $j] = $req('POST', '/gtm/workflows', ['name' => $name, 'trigger_event' => $trig, 'conditions' => $conds, 'actions' => $acts]);
        $ok("B0: workflow '$name' is accepted by the catalogue validation", $c === 201 || $c === 200, [$c, $j['message'] ?? $j]);
        $wid = (int) DB::table('gtm_workflows')->where('sub_institute_id', 6)->where('name', $name)->orderByDesc('id')->value('id');
        $ok("B0: workflow '$name' exists after creation", $wid > 0, $j);
        if ($wid) { $wfIds[] = $wid; DB::table('gtm_workflows')->where('id', $wid)->update(['status' => 'active', 'activated_at' => now()->subMinutes(5)]); }
    }
    // validation: a bad time zone and a trigger that cannot carry the fields are refused
    [$c] = $req('POST', '/gtm/workflows', ['name' => 'Verify Mtg Bad', 'trigger_event' => 'crm.lead.reply_classified', 'conditions' => [], 'actions' => [['type' => 'propose_lead_meeting', 'params' => ['timezone' => 'Mars/Olympus']]]]);
    $ok('B0: a bad time zone is refused (422)', $c === 422, $c);
    [$c] = $req('POST', '/gtm/workflows', ['name' => 'Verify Mtg Bad2', 'trigger_event' => 'crm.lead.created', 'conditions' => [], 'actions' => [['type' => 'propose_lead_meeting', 'params' => new stdClass]]]);
    $ok('B0: proposing times from a trigger without a reply is refused (422)', $c === 422, $c);
    [$c] = $req('POST', '/gtm/workflows', ['name' => 'Verify Mtg Bad3', 'trigger_event' => 'crm.lead.created', 'conditions' => [], 'actions' => [['type' => 'book_lead_meeting', 'params' => new stdClass]]]);
    $ok('B0: holding a time from a trigger without a proposal is refused (422)', $c === 422, $c);
    $bookDef = DB::table('gtm_workflows')->where('name', 'Verify Mtg Book')->where('sub_institute_id', 6)->first();
    $ok('B0: the booking workflow is treated as needing approval (consequential action)', $bookDef && App\Domain\Gtm\Workflows\WorkflowCatalog::hasConsequential(json_decode($bookDef->actions, true)));

    [$c, $j] = $req('POST', '/crm/leads', ['firstName' => 'Verify', 'lastName' => 'Mtg Lead', 'company' => 'Verify Mtg Co', 'email' => 'verify-mtg-lead@example.invalid', 'phone' => '+910000000400', 'assignedTo' => 28]);
    $L = (int) ($j['data']['id'] ?? 0); $leadIds[] = $L;
    $ok('B1: the lead was created through the real API', $L > 0, [$c, $j]);
    $floor = (int) DB::table('g2g_event')->where('entity_type', 'crm_leads')->where('entity_id', $L)->min('id') - 1;
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['*' => function () { $t = json_encode(['classification' => 'meeting_requested', 'confidence' => 'high', 'reason' => 'asks for a call']);
        return Http::response(['candidates' => [['content' => ['parts' => [['text' => $t]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 10],
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $t], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20]]); }]);
    $body = ['provider' => 'mtg', 'event_id' => 'verify-mtg-' . bin2hex(random_bytes(5)), 'type' => 'reply', 'from_email' => 'verify-mtg-lead@example.invalid', 'subject' => 'Re: hello', 'text' => 'Can we schedule a call next week to discuss?', 'occurred_at' => now()->toDateTimeString()];
    Cache::flush(); $raw = json_encode($body); $ts = time();
    $res = $kernel->handle(Illuminate\Http\Request::create('/api/crm/inbound', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_CRM_SIGNATURE' => "t=$ts,v1=" . hash_hmac('sha256', "$ts.$raw", $secret)], $raw));
    $ok('B2: the signed reply was accepted', $res->getStatusCode() === 200, $res->getContent());
    [$c, $o] = $tick($floor);
    $ok('B3: one tenant-scoped pass carried the reply through classification and the proposal (several rounds)', $c === 0, $o);
    $in = DB::table('crm_inbound_events')->where('lead_id', $L)->first();
    $ok('B4: the reply was classified meeting_requested', $in && $in->classification === 'meeting_requested', $in);
    $mp = DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'crm.lead.meeting_proposed')->where('entity_id', $L)->get();
    $pl = $mp->count() === 1 ? json_decode($mp[0]->payload, true) : [];
    $ok('B5: exactly one crm.lead.meeting_proposed event, with real slots', $mp->count() === 1 && ($pl['slot_count'] ?? 0) >= 1, [$mp->count(), $pl]);
    if ($mp->count() !== 1) {
        foreach (DB::table('gtm_workflow_runs as r')->join('gtm_workflows as w', 'w.id', '=', 'r.workflow_id')->where('r.entity_id', $L)->orderBy('r.id')->get(['r.id', 'w.name', 'r.event_type', 'r.status', 'r.error']) as $r) {
            echo '  DEBUG run #' . $r->id . ' ' . $r->name . ' [' . $r->event_type . '] ' . $r->status . ' ' . $r->error . "
";
            foreach (DB::table('gtm_workflow_run_steps')->where('run_id', $r->id)->get(['step', 'action', 'status', 'result', 'error']) as $st) { echo '     step ' . $st->step . ' ' . $st->action . ' ' . $st->status . ' ' . mb_substr((string) ($st->error ?: $st->result), 0, 200) . "
"; }
        }
        foreach (DB::table('g2g_event')->where('entity_id', $L)->where('entity_type', 'crm_leads')->orderBy('id')->get(['id', 'type']) as $e) { echo '  DEBUG event #' . $e->id . ' ' . $e->type . ' ledger=' . (DB::table('g2g_event_delivery')->where('event_id', $e->id)->where('consumer', 'gtm_workflow_runner')->value('status') ?? 'none') . "
"; }
    }
    $ok('B6: no proposed slot overlaps anything on the owner\'s real calendar, and all are in business hours (IST)', collect($pl['slots'] ?? [])->every(fn ($x) => ! $overlapsReal($x['start'], $x['end']) && ($t = $inIst($x['start']))->format('H:i') >= '09:00' && $inIst($x['end'])->format('H:i') <= '17:00' && ! $t->isWeekend()), $pl['slots'] ?? []);
    $ok('B7: one proposal note on the lead listing the times, saying nothing was booked or sent', $notes($L)->filter(fn ($n) => str_contains($n, 'Times that are free') && str_contains($n, 'Nothing has been booked or sent'))->count() === 1, $notes($L));
    $tasks = DB::table('task_management_calendar_events')->where('linked_type', 'LEAD')->where('linked_id', $L)->where('title', 'like', 'Meeting requested:%')->whereNull('deleted_at')->get();
    $ok('B8: one task for the owner (user 28) to review the proposal', $tasks->count() === 1 && (int) $tasks[0]->owner_id === 28 && str_contains((string) $tasks[0]->description, $inIst($pl['slots'][0]['start'])->format('H:i')), $tasks);
    $drafts = DB::table('crm_emails as e')->join('crm_email_links as l', 'l.email_id', '=', 'e.id')->where('l.linked_type', 'LEAD')->where('l.linked_id', $L)->where('e.body', 'like', '%' . LeadMeeting::MARKER . '%')->select('e.*')->get();
    $ok('B9: exactly one DRAFT email offering the times, addressed to the lead, never sent', $drafts->count() === 1 && $drafts[0]->status === 'Draft' && $drafts[0]->to_address === 'verify-mtg-lead@example.invalid' && $drafts[0]->direction === 'outbound', $drafts);
    $ok('B10: the lead has no Queued or Sent email at all (nothing left the system)', DB::table('crm_emails as e')->join('crm_email_links as l', 'l.email_id', '=', 'e.id')->where('l.linked_type', 'LEAD')->where('l.linked_id', $L)->whereIn('e.status', ['Queued', 'Sent'])->count() === 0);
    $bookRun = DB::table('gtm_workflow_runs')->where('workflow_id', $bookDef->id)->where('entity_id', $L)->first();
    $ok('B11: the booking run is HELD for approval (pending_approval)', $bookRun && $bookRun->status === 'pending_approval', $bookRun);
    $ok('B12: before approval nothing is on the calendar for this lead', DB::table('task_management_calendar_events')->where('linked_type', 'LEAD')->where('linked_id', $L)->where('title', 'like', '[Tentative]%')->count() === 0);

    [$c] = call($kernel, 'POST', "/gtm/workflow-runs/{$bookRun->id}/approve", $E, ['confirm' => true]);
    $ok('B13: an employee cannot approve (403)', $c === 403, $c);
    [$c] = $req('POST', "/gtm/workflow-runs/{$bookRun->id}/approve", []);
    $ok('B13: approval without explicit confirmation is refused (409)', $c === 409, $c);
    [$c, $j] = $req('POST', "/gtm/workflow-runs/{$bookRun->id}/approve", ['confirm' => true, 'note' => 'Verified by test']);
    $ok('B14: an administrator approves and the run succeeds', $c === 200 && DB::table('gtm_workflow_runs')->find($bookRun->id)->status === 'succeeded', [$c, $j]);
    $hold = DB::table('task_management_calendar_events')->where('linked_type', 'LEAD')->where('linked_id', $L)->where('title', 'like', '[Tentative]%')->whereNull('deleted_at')->get();
    $ok('B15: one [Tentative] event on the owner\'s calendar at the first proposed time, in the tenant academic year', $hold->count() === 1 && (int) $hold[0]->owner_id === 28 && $hold[0]->start_at === $pl['slots'][0]['start'] && $hold[0]->syear === $syear, $hold);
    $ok('B16: no attendee was added and no invitation exists (nothing to send)', $hold->count() === 1 && DB::table('task_management_calendar_attendees')->where('event_id', $hold[0]->id)->count() === 0 && str_contains((string) $hold[0]->description, 'NO invitation was sent'));
    $ok('B17: the booking is on the lead\'s Comments tab and audited (crm.lead.meeting_booked)', $notes($L)->filter(fn ($n) => str_contains($n, 'tentative meeting was put'))->count() === 1 && DB::table('g2g_event')->where('type', 'crm.lead.meeting_booked')->where('entity_id', $L)->count() === 1);

    // ---- idempotency
    [$c] = $req('POST', "/gtm/workflow-runs/{$bookRun->id}/approve", ['confirm' => true]);
    $ok('B18: approving the same run again is refused (422)', $c === 422, $c);
    [$c, $o] = $tick($floor);
    $ok('B19: another pass delivers nothing new and creates nothing', $c === 0 && DB::table('g2g_event')->where('type', 'crm.lead.meeting_proposed')->where('entity_id', $L)->count() === 1 && DB::table('task_management_calendar_events')->where('linked_type', 'LEAD')->where('linked_id', $L)->where('title', 'like', 'Meeting requested:%')->count() === 1 && DB::table('task_management_calendar_events')->where('linked_type', 'LEAD')->where('linked_id', $L)->where('title', 'like', '[Tentative]%')->count() === 1);
    $again = LeadMeeting::propose(6, $L, (int) $in->id, ['timezone' => $IST]);
    $ok('B20: calling the proposal again for the same reply is a no-op (already proposed)', $again['already'] === true && $notes($L)->filter(fn ($n) => str_contains($n, 'Times that are free'))->count() === 1);
    $dup = null;
    try { LeadMeeting::book(6, $L, $pl['slots'][0]['start'], $pl['slots'][0]['end'], $IST, 28); } catch (DomainException $e) { $dup = $e->getMessage(); }
    $ok('B21: booking the same time again is refused (no duplicate meeting)', $dup !== null && str_contains($dup, 'already on the calendar'), $dup);

    // ---- a clash that appears after the proposal: booking is refused with a reason, never a double booking
    $sl2 = $pl['slots'][1] ?? null;
    if ($sl2) {
        $clash = $block('late clash', CarbonImmutable::parse($sl2['start'], $app), CarbonImmutable::parse($sl2['end'], $app));
        $dup = null;
        try { LeadMeeting::book(6, $L, $sl2['start'], $sl2['end'], $IST, 28); } catch (DomainException $e) { $dup = $e->getMessage(); }
        $ok('B22: if the time was taken after the proposal, booking is refused with a reason', $dup !== null && str_contains($dup, 'now has something at that time'), $dup);
    }
    // ---- no free time at all: honest "none found", no draft, no invented slot; booking has nothing to book
    $q = (int) DB::table('crm_leads')->where('id', $L)->value('id');
    $res0 = LeadMeeting::freeSlots(6, 28, ['window_start' => '09:00', 'window_end' => '09:20', 'duration_minutes' => 30] + $cfg, $mon);
    $ok('B23: a business window shorter than the meeting yields NO slots (nothing is invented)', $res0['slots'] === [], $res0);
    $dup = null;
    try { LeadMeeting::book(6, $L, '', '', $IST, 28); } catch (DomainException $e) { $dup = $e->getMessage(); }
    $ok('B24: booking with no proposed time is refused', $dup !== null && str_contains($dup, 'no usable time'), $dup);
    $dup = null;
    try { LeadMeeting::book(6, $L, now()->subDay()->format('Y-m-d H:i:s'), now()->subDay()->addMinutes(30)->format('Y-m-d H:i:s'), $IST, 28); } catch (DomainException $e) { $dup = $e->getMessage(); }
    $ok('B25: booking a time in the past is refused', $dup !== null && str_contains($dup, 'already passed'), $dup);

    // ---- a lead that opted out gets no proposal; a lead with no owner gets a note and no proposal
    [$c, $j] = $req('POST', '/crm/leads', ['firstName' => 'Verify', 'lastName' => 'Mtg NoOwner', 'company' => 'Verify Mtg NoOwner Co', 'email' => 'verify-mtg-noowner@example.invalid', 'phone' => '+910000000401', 'assignedTo' => 28]);
    $L2 = (int) ($j['data']['id'] ?? 0); $leadIds[] = $L2;
    $in2 = (int) DB::table('crm_inbound_events')->insertGetId(['sub_institute_id' => 6, 'provider' => 'mtg', 'provider_event_id' => 'verify-mtg-' . bin2hex(random_bytes(5)), 'type' => 'reply', 'status' => 'processed', 'lead_id' => $L2, 'received_at' => now(), 'processed_at' => now(), 'payload' => '{}']);
    $inactive = DB::table('tbluser')->where('sub_institute_id', 6)->where('status', '!=', 1)->value('id');
    if ($inactive) {
        DB::table('crm_leads')->where('id', $L2)->update(['assigned_to' => $inactive]);
        $thrown = null;
        try { LeadMeeting::propose(6, $L2, $in2, ['timezone' => $IST]); } catch (DomainException $e) { $thrown = $e->getMessage(); }
        $ok('B26: a lead whose owner is no longer active is not given a proposal, and the lead says why', $thrown !== null && $notes($L2)->contains(fn ($n) => str_contains($n, 'no active owner')) && DB::table('g2g_event')->where('type', 'crm.lead.meeting_proposed')->where('entity_id', $L2)->count() === 0, [$thrown, $notes($L2)]);
    } else { echo "SKIP B26: tenant 6 has no inactive user to act as a departed owner
"; }
    DB::table('crm_leads')->where('id', $L2)->update(['assigned_to' => 28, 'email_opt_out' => 1]);
    $thrown = null;
    try { LeadMeeting::propose(6, $L2, $in2, ['timezone' => $IST]); } catch (DomainException $e) { $thrown = $e->getMessage(); }
    $ok('B27: an opted-out lead is given no proposal and no draft email', $thrown !== null && str_contains($thrown, 'opted out') && DB::table('crm_email_links')->where('linked_type', 'LEAD')->where('linked_id', $L2)->count() === 0, $thrown);
    $thrown = null;
    try { LeadMeeting::propose(1, $L, (int) $in->id, ['timezone' => $IST]); } catch (DomainException $e) { $thrown = $e->getMessage(); }
    $ok('B28: tenant isolation: proposing for tenant 6\'s lead under another tenant id finds nothing', $thrown !== null && str_contains($thrown, 'no longer available'), $thrown);
} finally {
    $inbound = DB::table('crm_inbound_events')->whereIn('lead_id', $leadIds)->get();
    $emailIds = DB::table('crm_email_links')->where('linked_type', 'LEAD')->whereIn('linked_id', $leadIds)->pluck('email_id')->merge($inbound->pluck('inbound_email_id'))->filter()->unique();
    $runIds = DB::table('gtm_workflow_runs')->where('entity_type', 'crm_leads')->whereIn('entity_id', $leadIds)->pluck('id');
    $hold = DB::table('task_management_calendar_events')->where(fn ($q) => $q->where('linked_type', 'LEAD')->whereIn('linked_id', $leadIds))->pluck('id');
    DB::table('task_management_calendar_attendees')->whereIn('event_id', $evtIds)->delete();
    DB::table('task_management_calendar_events')->whereIn('id', $evtIds)->delete();
    DB::table('task_management_calendar_events')->whereIn('id', $hold)->delete();
    DB::table('gtm_workflow_run_steps')->whereIn('run_id', $runIds)->delete();
    DB::table('gtm_workflow_runs')->whereIn('id', $runIds)->delete();
    DB::table('gtm_workflows')->whereIn('id', $wfIds)->delete();
    DB::table('gtm_workflows')->where('sub_institute_id', 6)->where('name', 'like', 'Verify Mtg%')->delete();
    DB::table('crm_inbound_events')->whereIn('lead_id', $leadIds)->delete();
    DB::table('crm_email_links')->whereIn('email_id', $emailIds)->delete();
    DB::table('crm_emails')->whereIn('id', $emailIds)->delete();
    DB::table('crm_lead_followups')->whereIn('lead_id', $leadIds)->delete();
    DB::table('crm_lead_comments')->whereIn('lead_id', $leadIds)->delete();
    DB::table('crm_leads')->whereIn('id', $leadIds)->delete();
    foreach (DB::table('g2g_event')->where('entity_type', 'crm_leads')->whereIn('entity_id', $leadIds)->pluck('id') as $eid) {
        if (! DB::table('g2g_event_delivery')->where('event_id', $eid)->where('consumer', 'gtm_workflow_runner')->exists()) {
            DB::table('g2g_event_delivery')->insert(['event_id' => $eid, 'consumer' => 'gtm_workflow_runner', 'status' => 'skipped', 'attempts' => 0, 'last_error' => 'closed by the crmmeeting scenario cleanup', 'completed_at' => now()]);
        }
    }
    echo 'cleanup: Verify Mtg calendar rows ' . DB::table('task_management_calendar_events')->where('title', 'like', 'Verify Mtg%')->count() . ', leads ' . DB::table('crm_leads')->where('company', 'like', 'Verify Mtg%')->count() . ', workflows ' . DB::table('gtm_workflows')->where('name', 'like', 'Verify Mtg%')->count() . "\n";
}
echo "\n$pass passed, $fail failed\n";
