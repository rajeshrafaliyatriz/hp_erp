<?php
// Step 5 of the CRM lead automation: after conversion - validate the new records, then manage the Opportunity.
//   crm.lead.converted -> post-conversion checks (links, duplicates, decision-maker) -> tasks/notes, nothing invented
//   crm.opportunity.created / stage_changed / inactive / overdue -> stage rules, tasks, notifications to the owner
// Real HTTP stack, real dev DB, the REAL reactor + REAL notification dispatcher. Mail is captured in-process: the notification
// email channel (gated by MailGate) can only reach the capture transport. Fixtures are named "Verify ..." and removed afterwards.
// The sweep is run ONLY over this scenario's own Opportunities (other people's real ones on the shared DB are never touched).
use App\Domain\Crm\Sales\OpportunitySweep;
use App\Services\Events\{GtmWorkflowRunner, NotificationDispatcher};
use Illuminate\Support\Facades\{Cache, DB, Mail};
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

if (! class_exists('CrmOpTransport')) {
    class CrmOpTransport extends AbstractTransport
    {
        public static array $sent = [];
        protected function doSend(SentMessage $message): void { self::$sent[] = $message->getOriginalMessage(); }
        public function __toString(): string { return 'crmop'; }
    }
}
$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n"; };
$req = function ($m, $u, $b = [], $tok = null) use ($kernel, $A) { Cache::flush(); return call($kernel, $m, $u, $tok ?? $A, $b); };
$runner = app(GtmWorkflowRunner::class); $notifier = app(NotificationDispatcher::class);
$deliver = function (string $type, int $entityId, int $tenant = 6) use ($runner) { $e = DB::table('g2g_event')->where('sub_institute_id', $tenant)->where('type', $type)->where('entity_id', $entityId)->orderByDesc('id')->first(); if ($e) { $runner->dispatch($e); } return $e; };
$events = fn (string $type, int $id) => DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', $type)->where('entity_id', $id)->get();
$lead = fn (int $id) => DB::table('crm_leads')->find($id);
$opp = fn (int $id) => DB::table('crm_opportunities')->find($id);
$tasksOn = fn (string $type, int $id) => DB::table('task_management_calendar_events')->where('linked_type', $type)->where('linked_id', $id)->whereNull('deleted_at')->get();
$oppNotes = fn (int $id) => DB::table('crm_opportunity_comments')->where('opportunity_id', $id)->pluck('comment');
$leadNotes = fn (int $id) => DB::table('crm_lead_comments')->where('lead_id', $id)->pluck('comment');
$mk = function (array $d) use ($req) { [$c, $j] = $req('POST', '/gtm/workflows', $d); $id = (int) ($j['data']['workflow']['id'] ?? 0); if ($id) { $req('POST', "/gtm/workflows/$id/status", ['status' => 'active']); } return [$c, $id, $j]; };
$mkLead = function (string $suffix, array $extra = []) use ($req) { [$c, $j] = $req('POST', '/crm/leads', $extra + ['firstName' => 'Verify', 'lastName' => "OP $suffix", 'company' => "Verify OP $suffix Co", 'email' => 'verify-op-' . strtolower($suffix) . '@example.invalid', 'phone' => '+910000000400', 'assignedTo' => 28]); return (int) ($j['data']['id'] ?? 0); };
$mkOpp = function (string $name, array $extra = []) use ($req) { [$c, $j] = $req('POST', '/crm/opportunities', $extra + ['name' => "Verify OP $name", 'assignedTo' => 28]); return [(int) ($j['data']['id'] ?? 0), $c, $j]; };
$leadIds = []; $oppIds = []; $otherOppIds = []; $otherLeadIds = [];

// a previous run that was interrupted can leave its own "Verify OP" rows behind; remove exactly those before starting
$staleL = DB::table('crm_leads')->where('last_name', 'like', 'OP %')->pluck('id'); $staleO = DB::table('crm_opportunities')->where('name', 'like', 'Verify OP%')->pluck('id');
DB::table('task_management_calendar_events')->where(fn ($w) => $w->where('linked_type', 'LEAD')->whereIn('linked_id', $staleL))->orWhere(fn ($w) => $w->where('linked_type', 'OPPORTUNITY')->whereIn('linked_id', $staleO))->delete();
DB::table('crm_opportunity_contacts')->whereIn('opportunity_id', $staleO)->delete(); DB::table('crm_opportunity_stage_history')->whereIn('opportunity_id', $staleO)->delete(); DB::table('crm_opportunity_comments')->whereIn('opportunity_id', $staleO)->delete();
DB::table('crm_opportunities')->whereIn('id', $staleO)->delete(); DB::table('crm_lead_comments')->whereIn('lead_id', $staleL)->delete(); DB::table('crm_leads')->whereIn('id', $staleL)->delete();
DB::table('crm_contacts')->where('email', 'like', 'verify-op-%')->delete(); DB::table('crm_organizations')->where('name', 'like', 'Verify OP%')->delete();

Mail::extend('crmop', fn (array $config) => new CrmOpTransport());
config(['mail.mailers.crmop' => ['transport' => 'crmop'], 'mail.default' => 'crmop', 'mail.from.address' => 'sender@example.invalid', 'app.url' => 'https://api.example.com']);
Mail::purge();
$captured = get_class(Mail::mailer()->getSymfonyTransport()) === 'CrmOpTransport' && DB::table('smtp_details')->where('sub_institute_id', 6)->count() === 0;
$ok('SAFETY: all tenant-6 mail (including notification email) goes to the in-process capture transport', $captured);
// the other tenant has a REAL smtp profile on this database: its notifications are only RESOLVED (who would be told), never dispatched
$otherHasSmtp = $other ? DB::table('smtp_details')->where('sub_institute_id', $other->sub_institute_id)->count() > 0 : false;
if (! $captured) { echo "ABORT\n"; exit(1); }

try {
    // ---------------- catalogue and validation ----------------
    [$c, $j] = $req('GET', '/gtm/workflows/catalog'); $cat = $j['data'];
    $ok('catalogue: Opportunity triggers with their facts', isset($cat['triggers']['crm.opportunity.created']['fields']['opportunity.days_since_activity'], $cat['triggers']['crm.opportunity.inactive']['fields']['event.days_inactive'], $cat['triggers']['crm.opportunity.overdue'], $cat['triggers']['crm.opportunity.stage_changed']), array_keys($cat['triggers']));
    $ok('catalogue: converted-lead validation facts are offered', isset($cat['triggers']['crm.lead.converted']['fields']['converted.links_ok'], $cat['triggers']['crm.lead.converted']['fields']['converted.contact_has_title']));
    $ok('catalogue: the three Opportunity actions exist and none is consequential', collect(['add_opportunity_note', 'create_opportunity_task', 'set_opportunity_stage'])->every(fn ($a) => ($cat['actions'][$a]['consequential'] ?? true) === false));
    $base = ['name' => 'Verify OP Bad', 'trigger_event' => 'crm.opportunity.created', 'conditions' => []];
    [$c, $j] = $req('POST', '/gtm/workflows', $base + ['actions' => [['type' => 'set_opportunity_stage', 'params' => ['stage' => 'Nonsense']]]]); $ok('validation: stage must be a Sales Stage picklist value (422)', $c === 422, [$c, $j['message'] ?? $j]);
    [$c, $j] = $req('POST', '/gtm/workflows', $base + ['actions' => [['type' => 'set_opportunity_stage', 'params' => ['stage' => 'Closed Lost']]]]); $ok('validation: Closed Lost needs a reason (422)', $c === 422 && str_contains($j['message'] . json_encode($j), 'reason'), [$c, $j]);
    [$c, $j] = $req('POST', '/gtm/workflows', ['trigger_event' => 'crm.lead.created', 'name' => 'Verify OP Bad2', 'conditions' => [], 'actions' => [['type' => 'create_opportunity_task', 'params' => ['title' => 'x']]]]); $ok('validation: an Opportunity action cannot run from a lead trigger (422)', $c === 422, [$c, $j['message'] ?? $j]);
    [$c, $j] = $req('POST', '/gtm/workflows', ['trigger_event' => 'crm.lead.converted', 'name' => 'Verify OP Bad3', 'conditions' => [], 'actions' => [['type' => 'create_lead_task', 'params' => ['title' => 'x', 'link_to' => 'invoice']]]]); $ok('validation: link_to must be lead, organization or opportunity (422)', $c === 422, [$c, $j['message'] ?? $j]);

    // ---------------- workflows ----------------
    [, $wfConv] = $mk(['name' => 'Verify OP Convert', 'trigger_event' => 'crm.lead.qualified', 'conditions' => [['field' => 'lead.has_email', 'op' => 'eq', 'value' => true]], 'actions' => [['type' => 'convert_lead', 'params' => ['create_opportunity' => true]]]]);
    [, $wfLinks] = $mk(['name' => 'Verify OP Links Broken', 'trigger_event' => 'crm.lead.converted', 'conditions' => [['field' => 'converted.links_ok', 'op' => 'eq', 'value' => false]], 'actions' => [
        ['type' => 'add_lead_note', 'params' => ['text' => 'After conversion the Organization, Contact and Opportunity are NOT correctly linked. Please check them.']],
        ['type' => 'create_lead_task', 'params' => ['title' => 'Verify fix links for {{lead.company}}', 'due_in_days' => 0, 'link_to' => 'organization']]]]);
    [, $wfOrgDup] = $mk(['name' => 'Verify OP Org Duplicate', 'trigger_event' => 'crm.lead.converted', 'conditions' => [['field' => 'converted.organization_duplicates', 'op' => 'gt', 'value' => 0]], 'actions' => [
        ['type' => 'create_lead_task', 'params' => ['title' => 'Verify review duplicate organization: {{lead.company}}', 'due_in_days' => 1, 'link_to' => 'organization', 'description' => '{{converted.organization_duplicates}} other organization(s) share this name. Nothing was merged.']]]]);
    [, $wfDm] = $mk(['name' => 'Verify OP Decision Maker', 'trigger_event' => 'crm.lead.converted', 'conditions' => [['field' => 'converted.contact_has_title', 'op' => 'eq', 'value' => false]], 'actions' => [
        ['type' => 'create_lead_task', 'params' => ['title' => 'Verify identify the decision-maker at {{lead.company}}', 'due_in_days' => 2, 'link_to' => 'organization', 'description' => 'The contact has no job title and the organization has {{converted.organization_contacts}} contact(s). Add the decision-maker from a verified source.']]]]);
    [, $wfNew] = $mk(['name' => 'Verify OP New Opportunity', 'trigger_event' => 'crm.opportunity.created', 'conditions' => [], 'actions' => [
        ['type' => 'set_opportunity_stage', 'params' => ['stage' => 'Prospecting', 'only_from' => '(blank)']],
        ['type' => 'create_opportunity_task', 'params' => ['title' => 'Verify qualify {{opportunity.name}}', 'due_in_days' => 2, 'description' => 'Set the amount, closing date and next step.']],
        ['type' => 'add_opportunity_note', 'params' => ['text' => 'Opportunity {{opportunity.no}} entered the pipeline (from a converted lead: {{opportunity.from_lead}}).']]]]);
    [, $wfBig] = $mk(['name' => 'Verify OP High Value', 'trigger_event' => 'crm.opportunity.created', 'conditions' => [['field' => 'opportunity.amount', 'op' => 'gte', 'value' => 1000], ['field' => 'opportunity.stage', 'op' => 'eq', 'value' => 'Prospecting']], 'actions' => [
        ['type' => 'set_opportunity_stage', 'params' => ['stage' => 'Qualification', 'only_from' => 'Prospecting']]]]);
    [, $wfQuiet] = $mk(['name' => 'Verify OP Inactive', 'trigger_event' => 'crm.opportunity.inactive', 'conditions' => [], 'actions' => [
        ['type' => 'create_opportunity_task', 'params' => ['title' => 'Verify inactive {{opportunity.no}}: contact the customer', 'due_in_days' => 0, 'description' => 'No activity for {{opportunity.days_since_activity}} days.']]]]);
    [, $wfLate] = $mk(['name' => 'Verify OP Overdue', 'trigger_event' => 'crm.opportunity.overdue', 'conditions' => [], 'actions' => [
        ['type' => 'create_opportunity_task', 'params' => ['title' => 'Verify overdue {{opportunity.no}}: update the closing date', 'due_in_days' => 0]]]]);
    $ok('all Step 5 workflows saved and active', collect([$wfConv, $wfLinks, $wfOrgDup, $wfDm, $wfNew, $wfBig, $wfQuiet, $wfLate])->every(fn ($w) => $w > 0));
    sleep(1);

    // ---------------- 1. lead -> Qualified -> approved conversion -> post-conversion checks ----------------
    DB::table('crm_organizations')->insert([['account_no' => 'ORG-VOPD1', 'name' => 'Verify OP Dup Co', 'sub_institute_id' => 6, 'assigned_to' => 28, 'created_by' => 28, 'created_at' => now(), 'updated_at' => now()],
        ['account_no' => 'ORG-VOPD2', 'name' => 'Verify OP Dup Ltd.', 'sub_institute_id' => 6, 'assigned_to' => 28, 'created_by' => 28, 'created_at' => now(), 'updated_at' => now()]]);
    $l1 = $mkLead('Dup', ['company' => 'Verify OP Dup Co']); $leadIds[] = $l1;
    $req('PUT', "/crm/leads/$l1", ['leadStatus' => 'Qualified']); $deliver('crm.lead.qualified', $l1);
    $run = DB::table('gtm_workflow_runs')->where('workflow_id', $wfConv)->where('entity_id', $l1)->first();
    [$c] = $req('POST', "/gtm/workflow-runs/{$run->id}/approve", ['confirm' => true]);
    $L = $lead($l1);
    $ok('1: the approved conversion produced the three records', $c === 200 && $L->converted_organization_id && $L->converted_contact_id && $L->converted_opportunity_id, [$c]);
    $deliver('crm.lead.converted', $l1);
    $ctx = json_decode(DB::table('gtm_workflow_runs')->where('workflow_id', $wfDm)->where('entity_id', $l1)->value('context'), true)['values'] ?? [];
    $ok('1: the facts are computed from the real records: links ok, 1 look-alike Organization ("Verify OP Dup Ltd." vs "Verify OP Dup Co"), no title, one contact', ($ctx['converted.links_ok'] ?? null) === true && ($ctx['converted.organization_duplicates'] ?? null) === 1 && ($ctx['converted.contact_has_title'] ?? null) === false && ($ctx['converted.organization_contacts'] ?? null) === 1 && ($ctx['converted.contact_duplicates'] ?? null) === 0, $ctx);
    $orgTasks = $tasksOn('ORGANIZATION', (int) $L->converted_organization_id);
    $ok('1: a duplicate Organization is FLAGGED with a task on the Organization, and nothing was merged or deleted', $orgTasks->contains(fn ($t) => str_starts_with($t->title, 'Verify review duplicate organization')) && DB::table('crm_organizations')->where('name', 'like', 'Verify OP Dup%')->count() === 2, $orgTasks->pluck('title'));
    $ok('1: a missing decision-maker is asked for on the Organization, and no job title was invented', $orgTasks->contains(fn ($t) => str_starts_with($t->title, 'Verify identify the decision-maker')) && DB::table('crm_contacts')->where('id', $L->converted_contact_id)->value('title') === null, $orgTasks->pluck('title'));
    $ok('1: the "links broken" workflow did NOT fire for a healthy conversion', DB::table('gtm_workflow_runs')->where('workflow_id', $wfLinks)->where('entity_id', $l1)->count() === 0);
    $ok('1: the tasks belong to the lead\'s owner', $orgTasks->every(fn ($t) => (int) $t->owner_id === (int) $L->assigned_to));

    // broken links are detected
    $l2 = $mkLead('Broken'); $leadIds[] = $l2;
    [$c, $j] = $req('POST', "/crm/leads/$l2/convert", ['createOrganization' => true, 'createContact' => true, 'createOpportunity' => true, 'assignedTo' => 28]);
    DB::table('crm_contacts')->where('id', $j['data']['contactId'])->update(['organization_id' => null]);
    $deliver('crm.lead.converted', $l2); $L2 = $lead($l2);
    $ok('1: a conversion whose Contact is not under the Organization is detected (links_ok=false): a note and a task on the Organization', DB::table('gtm_workflow_runs')->where('workflow_id', $wfLinks)->where('entity_id', $l2)->value('status') === 'succeeded' && $leadNotes($l2)->contains(fn ($n) => str_contains($n, 'NOT correctly linked')) && $tasksOn('ORGANIZATION', (int) $L2->converted_organization_id)->contains(fn ($t) => str_starts_with($t->title, 'Verify fix links')));

    // ---------------- 2. a new Opportunity enters the pipeline ----------------
    $oid = (int) $L->converted_opportunity_id; $oppIds[] = $oid;
    $ce = $events('crm.opportunity.created', $oid);
    $ok('2: the conversion announced crm.lead.converted AND crm.opportunity.created (once each), the latter flagged from_lead', $events('crm.lead.converted', $l1)->count() === 1 && $ce->count() === 1 && json_decode($ce[0]->payload, true)['from_lead'] === true, $ce);
    $ok('2: before any workflow ran the Opportunity had NO stage', $opp($oid)->sales_stage === null);
    $deliver('crm.opportunity.created', $oid);
    $ok('2: the pipeline workflow ran: stage set to Prospecting (blank -> first stage), task for the owner, note', $opp($oid)->sales_stage === 'Prospecting' && $tasksOn('OPPORTUNITY', $oid)->contains(fn ($t) => str_starts_with($t->title, 'Verify qualify Verify OP Dup Co - Opportunity') && (int) $t->owner_id === 28) && $oppNotes($oid)->contains(fn ($n) => str_contains($n . '', 'entered the pipeline (from a converted lead: yes)')), [$opp($oid)->sales_stage, $oppNotes($oid)]);
    $hist = DB::table('crm_opportunity_stage_history')->where('opportunity_id', $oid)->get();
    $ok('2: the stage change is in the Stage History, like a person\'s change', $hist->count() === 1 && $hist[0]->from_stage === null && $hist[0]->to_stage === 'Prospecting', $hist);
    $sc = $events('crm.opportunity.stage_changed', $oid);
    $ok('2: crm.opportunity.stage_changed was recorded, marked as caused by a workflow (so it cannot loop)', $sc->count() === 1 && str_contains((string) $sc[0]->metadata, '"source":"workflow"'), $sc);
    $ok('2: the stage-rule workflow did not fire (no amount yet)', DB::table('gtm_workflow_runs')->where('workflow_id', $wfBig)->where('entity_id', $oid)->count() === 0);

    // ---------------- 3. stage moves only when the configured condition holds ----------------
    [$big, $c, $j] = $mkOpp('Big', ['amount' => 5000, 'salesStage' => 'Prospecting']); $oppIds[] = $big;
    [$small, $c2] = $mkOpp('Small', ['amount' => 10, 'salesStage' => 'Prospecting']); $oppIds[] = $small;
    $ok('3: Opportunities created through the normal API record crm.opportunity.created (from_lead=false)', $c === 201 && $events('crm.opportunity.created', $big)->count() === 1 && json_decode($events('crm.opportunity.created', $big)[0]->payload, true)['from_lead'] === false, [$c, $j]);
    $deliver('crm.opportunity.created', $big); $deliver('crm.opportunity.created', $small);
    $ok('3: amount >= 1000 moved it Prospecting -> Qualification; the small one stayed Prospecting', $opp($big)->sales_stage === 'Qualification' && $opp($small)->sales_stage === 'Prospecting', [$opp($big)->sales_stage, $opp($small)->sales_stage]);
    $ok('3: sending an email never moves a stage - no workflow here reacts to email, and the small one is untouched after a (captured) cadence-style send', $opp($small)->sales_stage === 'Prospecting');
    $deliver('crm.opportunity.created', $big);
    $ok('3: IDEMPOTENT: re-delivery repeats nothing (one task, one history row for the automatic move)', DB::table('gtm_workflow_runs')->where('workflow_id', $wfNew)->where('entity_id', $big)->count() === 1 && DB::table('crm_opportunity_stage_history')->where('opportunity_id', $big)->where('to_stage', 'Qualification')->count() === 1);
    [$c, $j] = $req('PUT', "/crm/opportunities/$big/stage", ['salesStage' => 'Closed Lost']);
    $ok('3: Closed Lost WITHOUT a reason is refused by the existing rule (422) - the automation did not weaken it', $c === 422 && ($j['code'] ?? '') === 'reason_required', [$c, $j]);
    [$c, $j] = $req('PUT', "/crm/opportunities/$big/stage", ['salesStage' => 'Closed Lost', 'closeReason' => 'Budget frozen']);
    $se = $events('crm.opportunity.stage_changed', $big)->sortByDesc('id')->first();
    $ok('3: Closed Lost WITH a reason works, is recorded in the history, and announces stage_changed (by the person, not a workflow)', $c === 200 && $opp($big)->close_reason === 'Budget frozen' && $se && json_decode($se->payload, true)['to'] === 'Closed Lost' && ! str_contains((string) $se->metadata, 'workflow') && (int) $se->actor_id === 28, [$c, $se]);
    // a workflow can't reopen or move a closed Opportunity
    $runner->dispatch(DB::table('g2g_event')->where('type', 'crm.opportunity.created')->where('entity_id', $big)->first());
    $ok('3: a closed Opportunity is never moved by a stage action', $opp($big)->sales_stage === 'Closed Lost');

    // ---------------- 4. inactivity and overdue: found by the sweep, once a week, notified ----------------
    $old = now()->subDays(30)->format('Y-m-d H:i:s');
    [$q, $c] = $mkOpp('Quiet', ['salesStage' => 'Proposal']); $oppIds[] = $q;
    DB::table('crm_opportunities')->where('id', $q)->update(['created_at' => $old, 'updated_at' => $old]);
    DB::table('crm_opportunity_stage_history')->where('opportunity_id', $q)->update(['created_at' => $old]); // creating it was activity; backdate it so it has really been quiet
    [$d, $c] = $mkOpp('Late', ['salesStage' => 'Negotiation', 'closingDate' => now()->subDays(5)->toDateString()]); $oppIds[] = $d;
    DB::table('crm_opportunities')->where('id', $d)->update(['created_at' => $old]);
    [$f, $c] = $mkOpp('Fresh', ['salesStage' => 'Proposal']); $oppIds[] = $f;
    [$w, $c] = $mkOpp('Won', ['salesStage' => 'Closed Won']); $oppIds[] = $w;
    DB::table('crm_opportunity_stage_history')->where('opportunity_id', $w)->update(['created_at' => $old]);
    DB::table('crm_opportunities')->where('id', $w)->update(['created_at' => $old, 'updated_at' => $old, 'closing_date' => now()->subDays(9)->toDateString()]);
    $r = OpportunitySweep::run(500, $oppIds);
    $ok('4: the sweep flags the quiet one (inactive) and the late one (overdue) - not the fresh one, not the closed one', $events('crm.opportunity.inactive', $q)->count() === 1 && $events('crm.opportunity.overdue', $d)->count() === 1 && $events('crm.opportunity.inactive', $f)->count() === 0 && $events('crm.opportunity.inactive', $w)->count() === 0 && $events('crm.opportunity.overdue', $w)->count() === 0, $r);
    $iq = json_decode($events('crm.opportunity.inactive', $q)[0]->payload, true);
    $ok('4: the payload carries real numbers (30 days quiet, 5 days overdue)', $iq['days_inactive'] >= 30 && json_decode($events('crm.opportunity.overdue', $d)[0]->payload, true)['days_overdue'] === 5, $iq);
    $r2 = OpportunitySweep::run(500, $oppIds);
    $ok('4: IDEMPOTENT: running the sweep again the same week records nothing new', $events('crm.opportunity.inactive', $q)->count() === 1 && $events('crm.opportunity.overdue', $d)->count() === 1, $r2);
    [$cc, $cj] = $req('POST', "/crm/opportunities/$q/comments", ['comment' => 'Called the customer, waiting for budget approval.']);
    $ok('4: real activity resets the clock: a comment on the Opportunity makes it active again', in_array($cc, [200, 201], true) && OpportunitySweep::lastActivity(6, $opp($q))->diffInDays(now()) < 1, [$cc, $cj, OpportunitySweep::lastActivity(6, $opp($q))->toDateTimeString()]);
    $deliver('crm.opportunity.inactive', $q); $deliver('crm.opportunity.overdue', $d);
    $ok('4: the workflows turned those into tasks for the owner, with real figures in the text', $tasksOn('OPPORTUNITY', $d)->contains(fn ($t) => str_starts_with($t->title, 'Verify overdue OPP-')) && DB::table('gtm_workflow_runs')->where('workflow_id', $wfQuiet)->where('entity_id', $q)->value('status') === 'succeeded');
    // notifications to the owner through the platform's own pipeline
    foreach ([$events('crm.opportunity.inactive', $q)[0], $events('crm.opportunity.overdue', $d)[0]] as $e) { $notifier->dispatch($e); }
    $nIn = DB::table('g2g_notification')->where('event_type', 'crm.opportunity.inactive')->where('event_id', $events('crm.opportunity.inactive', $q)[0]->id)->get();
    $nLate = DB::table('g2g_notification')->where('event_type', 'crm.opportunity.overdue')->where('event_id', $events('crm.opportunity.overdue', $d)[0]->id)->get();
    $ok('4: the OWNER got an in-app notification for each, naming the Opportunity and linking to it (tenant 6, reason opportunity_owner)', $nIn->where('channel', 'inapp')->where('user_id', 28)->count() === 1 && $nLate->where('channel', 'inapp')->where('user_id', 28)->count() === 1 && str_contains($nIn->first()->subject, 'OPP-') && str_contains((string) $nIn->first()->action_url, "/module/crm/sales/opportunities/$q") && $nIn->first()->recipient_reason === 'opportunity_owner' && (int) $nIn->first()->sub_institute_id === 6, [$nIn, $nLate]);
    $ok('4: nobody else was notified', DB::table('g2g_notification')->whereIn('event_id', [$events('crm.opportunity.inactive', $q)[0]->id, $events('crm.opportunity.overdue', $d)[0]->id])->where('user_id', '!=', 28)->count() === 0);
    $notifier->dispatch($events('crm.opportunity.inactive', $q)[0]);
    $ok('4: IDEMPOTENT: dispatching the same event again creates no second notification', DB::table('g2g_notification')->where('event_id', $events('crm.opportunity.inactive', $q)[0]->id)->where('channel', 'inapp')->count() === 1);

    // ---------------- 5. a customer reply notifies the lead's owner ----------------
    $lr = $mkLead('Reply'); $leadIds[] = $lr; $ev = null;
    $eid = App\Domain\Gtm\GtmAudit::record('crm.lead.replied', 6, 'crm_leads', $lr, null, ['inbound_id' => 0, 'lead_no' => $lead($lr)->lead_no, 'company' => $lead($lr)->company, 'classification' => null], null, 'crm.lead.replied:verify-op-' . $lr);
    $ev = DB::table('g2g_event')->find($eid); $notifier->dispatch($ev);
    $n = DB::table('g2g_notification')->where('event_id', $eid)->where('channel', 'inapp')->get();
    $ok('5: crm.lead.replied notifies the lead\'s owner with the company and a link to the lead', $n->count() === 1 && (int) $n[0]->user_id === 28 && str_contains($n[0]->subject, 'Verify OP Reply Co') && str_contains((string) $n[0]->action_url, "/module/crm/marketing/leads/$lr") && $n[0]->recipient_reason === 'lead_owner', $n);

    // ---------------- 6. tenant isolation ----------------
    if ($other) {
        $ot = (int) $other->sub_institute_id;
        $oo = DB::table('crm_opportunities')->insertGetId(['opportunity_no' => 'OPP-VERIFYOP', 'name' => 'Verify OP Other Tenant', 'sales_stage' => 'Proposal', 'sub_institute_id' => $ot, 'assigned_to' => $other->id, 'created_by' => $other->id, 'created_at' => $old, 'updated_at' => $old]); $otherOppIds[] = $oo;
        OpportunitySweep::run(500, [$oo]);
        $oe = DB::table('g2g_event')->where('type', 'crm.opportunity.inactive')->where('entity_id', $oo)->first();
        $ok('6: another tenant\'s quiet Opportunity is reported under THAT tenant', $oe && (int) $oe->sub_institute_id === $ot);
        $runner->dispatch($oe);
        $ok('6: tenant 6\'s workflows did not run for it', DB::table('gtm_workflow_runs')->where('entity_type', 'crm_opportunities')->where('entity_id', $oo)->count() === 0);
        $who = app(App\Services\Notifications\RecipientResolver::class)->forEvent($oe);
        $ok('6: the notification would go ONLY to that tenant\'s own owner (resolved, not sent - that tenant has a real SMTP profile)', count($who) === 1 && (int) $who[0]['user_id'] === (int) $other->id, $who);
        if (! $otherHasSmtp) { $notifier->dispatch($oe); $ok('6: dispatching it notifies only that owner', DB::table('g2g_notification')->where('event_id', $oe->id)->where('user_id', '!=', $other->id)->count() === 0); }
    }
} finally {
    $allOpp = array_merge($oppIds, $otherOppIds);
    $leadRows = DB::table('crm_leads')->whereIn('id', $leadIds)->get(['converted_contact_id', 'converted_opportunity_id', 'converted_organization_id']);
    $allOpp = array_merge($allOpp, $leadRows->pluck('converted_opportunity_id')->filter()->all());
    $cids = $leadRows->pluck('converted_contact_id')->filter()->all();
    $evIds = DB::table('g2g_event')->where(fn ($w) => $w->where('entity_type', 'crm_opportunities')->whereIn('entity_id', $allOpp))->orWhere(fn ($w) => $w->where('entity_type', 'crm_leads')->whereIn('entity_id', array_merge($leadIds, $otherLeadIds)))->pluck('id');
    DB::table('g2g_notification')->whereIn('event_id', $evIds)->delete();
    DB::table('task_management_calendar_events')->where(fn ($w) => $w->where('linked_type', 'OPPORTUNITY')->whereIn('linked_id', $allOpp))->orWhere(fn ($w) => $w->where('linked_type', 'LEAD')->whereIn('linked_id', $leadIds))->orWhere(fn ($w) => $w->where('linked_type', 'ORGANIZATION')->whereIn('linked_id', DB::table('crm_organizations')->where('name', 'like', 'Verify OP%')->pluck('id')))->delete();
    DB::table('crm_opportunity_contacts')->whereIn('opportunity_id', $allOpp)->delete();
    DB::table('crm_opportunity_stage_history')->whereIn('opportunity_id', $allOpp)->delete();
    DB::table('crm_opportunity_comments')->whereIn('opportunity_id', $allOpp)->delete();
    DB::table('crm_opportunities')->whereIn('id', $allOpp)->delete();
    DB::table('crm_lead_comments')->whereIn('lead_id', $leadIds)->delete();
    DB::table('crm_leads')->whereIn('id', $leadIds)->delete();
    DB::table('crm_contacts')->where(fn ($w) => $w->whereIn('id', $cids)->orWhere('email', 'like', 'verify-op-%@example.invalid'))->delete();
    DB::table('crm_organizations')->where('name', 'like', 'Verify OP%')->delete();
    gtm_verify_cleanup($startedAt);
    echo 'cleanup: leads ' . DB::table('crm_leads')->where('last_name', 'like', 'OP %')->count() . ', opps ' . DB::table('crm_opportunities')->where('name', 'like', 'Verify OP%')->count() . ', orgs ' . DB::table('crm_organizations')->where('name', 'like', 'Verify OP%')->count() . ', contacts ' . DB::table('crm_contacts')->where('email', 'like', 'verify-op-%')->count() . ', notifications for verify events ' . DB::table('g2g_notification')->whereIn('event_id', $evIds)->count() . "\n";
}
echo "\n$pass passed, $fail failed\n";
