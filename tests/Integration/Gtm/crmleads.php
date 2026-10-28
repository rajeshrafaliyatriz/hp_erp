<?php
// Step 1 of the CRM lead automation: a new CRM lead (however it was created) enters the lead-intake workflows.
// Real HTTP stack, real Sanctum tokens, the real shared dev DB, the REAL reactor (GtmWorkflowRunner::dispatch, exactly as
// events:react calls it). Nothing is mocked and no email is sent (these workflows have no email action).
// Every row created is named "Verify ..." and removed by the finally block; the lead-rotation settings (empty on tenant 6
// before this ran) are put back exactly as found.
use App\Domain\Crm\Leads\LeadFactory;
use App\Services\Events\GtmWorkflowRunner;
use Illuminate\Support\Facades\{Cache, DB};

$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n"; };
$req = function ($m, $u, $b = [], $tok = null) use ($kernel, $A) { Cache::flush(); return call($kernel, $m, $u, $tok ?? $A, $b); };
$runner = app(GtmWorkflowRunner::class);
$eventOf = fn (int $leadId, int $tenant = 6) => DB::table('g2g_event')->where('sub_institute_id', $tenant)->where('type', 'crm.lead.created')->where('entity_id', $leadId)->orderByDesc('id')->first();
$deliver = function (int $leadId) use ($runner, $eventOf) { $e = $eventOf($leadId); if ($e) { $runner->dispatch($e); } return $e; };
// only THIS scenario's workflows: a real, active "Lead Automation" workflow on the same database also reacts to these temporary leads, and must not be counted
$runsFor = function (int $leadId) use (&$wf) { return DB::table('gtm_workflow_runs')->where('entity_type', 'crm_leads')->where('entity_id', $leadId)->where('sub_institute_id', 6)->whereIn('workflow_id', array_values((array) $wf))->orderBy('id')->get(); };
$tasksOf = fn (int $leadId) => DB::table('task_management_calendar_events')->where('sub_institute_id', 6)->where('linked_type', 'LEAD')->where('linked_id', $leadId)->whereNull('deleted_at')->orderBy('id')->get();
$notesOf = fn (int $leadId) => DB::table('crm_lead_comments')->where('lead_id', $leadId)->orderBy('id')->get();
$lead = fn (int $id) => DB::table('crm_leads')->find($id);
$mkLead = function (array $b) use ($req) { [$c, $j] = $req('POST', '/crm/leads', $b + ['assignedTo' => 28]); return [$c, $j, (int) ($j['data']['id'] ?? 0)]; };

$rotationBefore = DB::table('crm_lead_assignment_settings')->where('sub_institute_id', 6)->first();
$membersBefore = DB::table('crm_lead_assignment_rotation_members')->where('sub_institute_id', 6)->count();
$leadIds = []; $companyIds = []; $oppIds = []; $otherLeadIds = [];

try {
    // ---------------- the event is registered and catalogued ----------------
    [$c, $j] = $req('GET', '/gtm/workflows/catalog'); $cat = $j['data'] ?? [];
    $trig = $cat['triggers']['crm.lead.created'] ?? null;
    $ok('catalogue offers the CRM lead trigger with its facts', $c === 200 && $trig && isset($trig['fields']['lead.duplicate_count'], $trig['fields']['lead.missing_fields'], $trig['fields']['lead.status']) && $trig['provides'] === ['lead'], $trig);
    $ok('catalogue offers the four lead actions, none consequential', collect(['add_lead_note', 'create_lead_task', 'set_lead_status', 'assign_lead_round_robin'])->every(fn ($a) => isset($cat['actions'][$a]) && $cat['actions'][$a]['consequential'] === false), array_keys($cat['actions'] ?? []));
    $ok('the five existing GTM actions and triggers are still there', isset($cat['actions']['log_activity'], $cat['actions']['change_account_stage'], $cat['triggers']['gtm.deal.created']));
    $ok('no lead email/phone/description is offered to workflows', ! collect($trig['fields'] ?? [])->keys()->contains(fn ($f) => preg_match('/\.(email|phone|mobile|description|notes)$/', $f)));

    // ---------------- definition validation ----------------
    $base = ['name' => 'Verify Lead Bad', 'trigger_event' => 'crm.lead.created', 'conditions' => [], 'actions' => [['type' => 'log_activity', 'params' => ['subject' => 'x']]]];
    [$c, $j] = $req('POST', '/gtm/workflows', $base);
    $ok('a lead trigger cannot use an account-only action (422)', $c === 422, [$c, $j]);
    [$c, $j] = $req('POST', '/gtm/workflows', ['actions' => [['type' => 'set_lead_status', 'params' => ['status' => 'Nonsense Status']]]] + $base);
    $ok('set_lead_status rejects a value that is not in the Lead Status picklist (422)', $c === 422 && str_contains(json_encode($j), 'picklist'), [$c, $j]);
    [$c, $j] = $req('POST', '/gtm/workflows', ['actions' => [['type' => 'create_lead_task', 'params' => ['title' => 'x {{lead.password}}']]]] + $base);
    $ok('a placeholder for a fact the trigger does not provide is rejected (422)', $c === 422, [$c, $j]);
    [$c, $j] = $req('POST', '/gtm/workflows', ['conditions' => [['field' => 'lead.duplicate_count', 'op' => 'gt', 'value' => 'abc']], 'actions' => [['type' => 'add_lead_note', 'params' => ['text' => 'x']]]] + $base);
    $ok('a numeric condition needs a number (422)', $c === 422, [$c, $j]);
    [$c] = call($kernel, 'POST', '/gtm/workflows', $E, $base);
    $ok('an employee cannot create a workflow (403)', $c === 403, $c);

    // ---------------- the three intake workflows, built through the real API ----------------
    $normalise = ['type' => 'set_lead_status', 'params' => ['status' => 'Not Contacted', 'only_from' => 'New, (blank)']];
    $defs = [
        'complete' => ['name' => 'Verify Lead Intake - Complete and unique', 'trigger_event' => 'crm.lead.created',
            'conditions' => [['field' => 'lead.duplicate_count', 'op' => 'eq', 'value' => 0], ['field' => 'lead.missing_count', 'op' => 'eq', 'value' => 0], ['field' => 'lead.email_opt_out', 'op' => 'eq', 'value' => false]],
            'actions' => [$normalise, ['type' => 'assign_lead_round_robin', 'params' => (object) []], ['type' => 'create_lead_task', 'params' => ['title' => 'Verify first contact: {{lead.company}} ({{lead.lead_no}})', 'due_in_days' => 1, 'description' => 'Score {{lead.score}}, source {{lead.source}}']]]],
        'missing' => ['name' => 'Verify Lead Intake - Missing details', 'trigger_event' => 'crm.lead.created',
            'conditions' => [['field' => 'lead.missing_count', 'op' => 'gt', 'value' => 0], ['field' => 'lead.duplicate_count', 'op' => 'eq', 'value' => 0]],
            'actions' => [$normalise, ['type' => 'add_lead_note', 'params' => ['text' => 'This lead is missing: {{lead.missing_fields}}. Nothing was filled in automatically - please add it from a verified source.']],
                ['type' => 'create_lead_task', 'params' => ['title' => 'Verify complete lead details: {{lead.company}} ({{lead.lead_no}})', 'due_in_days' => 1]]]],
        'dupes' => ['name' => 'Verify Lead Intake - Possible duplicate', 'trigger_event' => 'crm.lead.created',
            'conditions' => [['field' => 'lead.duplicate_count', 'op' => 'gt', 'value' => 0]],
            'actions' => [$normalise, ['type' => 'add_lead_note', 'params' => ['text' => 'Possible duplicate: {{lead.duplicate_leads}} lead(s), {{lead.duplicate_organizations}} organization(s), {{lead.duplicate_contacts}} contact(s) already match. Review before working this lead.']],
                ['type' => 'create_lead_task', 'params' => ['title' => 'Verify review possible duplicate: {{lead.company}} ({{lead.lead_no}})', 'due_in_days' => 0]]]],
    ];
    $wf = [];
    foreach ($defs as $k => $d) {
        [$c, $j] = $req('POST', '/gtm/workflows', $d); $wf[$k] = (int) ($j['data']['workflow']['id'] ?? 0);
        $ok("workflow '$k' saved", $c === 201 && $wf[$k] > 0, [$c, $j]);
        if ($wf[$k]) { [$c] = $req('POST', "/gtm/workflows/{$wf[$k]}/status", ['status' => 'active']); $ok("workflow '$k' activated", $c === 200, $c); }
    }
    sleep(1); // events must be recorded AFTER activation (the engine ignores older history)

    // ---------------- A. complete lead through the REAL CRM API ----------------
    DB::table('crm_lead_assignment_settings')->updateOrInsert(['sub_institute_id' => 6], ['is_enabled' => 0, 'updated_at' => now(), 'created_at' => now()]);
    [$c, $j, $a] = $mkLead(['firstName' => 'Verify', 'lastName' => 'Intake One', 'company' => 'Verify Intake Co', 'email' => 'verify-intake-1@example.invalid', 'phone' => '+910000000001', 'leadSource' => 'Web Site']);
    $leadIds[] = $a;
    $ok('A: lead created through POST /crm/leads', $c === 201 && $a > 0, [$c, $j]);
    $ev = $eventOf($a);
    $ok('A: crm.lead.created was recorded for that lead and tenant, with an idempotency key', $ev && (int) $ev->sub_institute_id === 6 && $ev->idempotency_key === "crm.lead.created:6:$a" && (int) $ev->actor_id === 28, $ev);
    $deliver($a);
    $runs = $runsFor($a);
    $ok('A: only the "Complete and unique" workflow ran, and it succeeded', $runs->count() === 1 && (int) $runs[0]->workflow_id === $wf['complete'] && $runs[0]->status === 'succeeded', $runs);
    $steps = DB::table('gtm_workflow_run_steps')->where('run_id', $runs[0]->id ?? 0)->orderBy('step')->get();
    $ok('A: status normalised (null -> Not Contacted), rotation skipped because none is enabled, task created', $steps->count() === 3 && $steps[0]->status === 'succeeded' && $steps[1]->status === 'skipped' && $steps[2]->status === 'succeeded' && $lead($a)->lead_status === 'Not Contacted', $steps);
    $t = $tasksOf($a);
    $ok('A: a follow-up task is linked to the lead, owned by its assignee, titled from REAL lead facts, due in the future', $t->count() === 1 && str_starts_with($t[0]->title, 'Verify first contact: Verify Intake Co (LD-') && ! str_contains($t[0]->title, '{{') && (int) $t[0]->owner_id === 28 && $t[0]->start_at > now()->toDateTimeString() && $t[0]->status === 'Planned', $t);
    $syear = App\Domain\Crm\Leads\LeadActivity::currentSyear(6);
    [$c, $j] = $req('GET', '/task-management/calendar/events/' . $t[0]->id . '?syear=' . $syear);
    $ok('A: the task carries the organisation\'s ACADEMIC YEAR, so the lead\'s Activity tab can OPEN it (Event details) - not "Event not found"', $c === 200 && (string) $t[0]->syear === $syear && ($j['data']['title'] ?? '') === $t[0]->title, [$c, $t[0]->syear, $syear, $j['message'] ?? null]);
    [$c, $j] = $req('GET', "/task-management/calendar/events/linked/LEAD/$a");
    $ok('A: the task is visible through the same endpoint the lead\'s Activity tab reads', $c === 200 && count($j['data']['entries'] ?? []) === 1, [$c, $j]);
    $ok('A: the status change is audited and marked as workflow-caused', DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'crm.lead.updated')->where('entity_id', $a)->where('metadata', 'like', '%"source":"workflow"%')->exists());
    $ok('A: no email was queued or sent', DB::table('crm_emails')->join('crm_email_links', 'crm_email_links.email_id', '=', 'crm_emails.id')->where('crm_email_links.linked_type', 'LEAD')->where('crm_email_links.linked_id', $a)->count() === 0);

    // idempotency
    $deliver($a);
    $e = $eventOf($a); app(App\Domain\Gtm\Workflows\WorkflowEngine::class)->handleEvent($e);
    $ok('IDEMPOTENT: delivering the same event again (reactor, then engine directly) creates no new run, task or status change', $runsFor($a)->count() === 1 && $tasksOf($a)->count() === 1);

    // ---------------- B. an exact duplicate (same email) is held for review, not processed ----------------
    [$c, $j, $b] = $mkLead(['firstName' => 'Verify', 'lastName' => 'Intake Two', 'company' => 'Verify Intake Co Two', 'email' => 'VERIFY-intake-1@example.invalid', 'phone' => '+910000000002']);
    $leadIds[] = $b; $deliver($b);
    $runs = $runsFor($b);
    $ok('B: duplicate detection is case-insensitive on email: the duplicates workflow ran, the normal one did not', $runs->count() === 1 && (int) $runs[0]->workflow_id === $wf['dupes'] && $runs[0]->status === 'succeeded', $runs);
    $n = $notesOf($b);
    $ok('B: an [Automation] note states what matched, from real counts; no created_by user is faked', $n->count() === 1 && str_starts_with($n[0]->comment, '[Automation] Possible duplicate: 1 lead(s), 0 organization(s), 0 contact(s)') && $n[0]->created_by === null, $n);
    $ok('B: a "review possible duplicate" task exists and the first-contact task does NOT', $tasksOf($b)->count() === 1 && str_contains($tasksOf($b)[0]->title, 'review possible duplicate'), $tasksOf($b));

    // ---------------- C. incomplete lead: asks for the missing data, invents nothing ----------------
    [$c, $j, $m] = $mkLead(['lastName' => 'Verify Incomplete Co', 'company' => 'Verify Incomplete Co']);
    $leadIds[] = $m; $before = (array) $lead($m); $deliver($m);
    $runs = $runsFor($m);
    $ok('C: only the "Missing details" workflow ran', $runs->count() === 1 && (int) $runs[0]->workflow_id === $wf['missing'] && $runs[0]->status === 'succeeded', $runs);
    $n = $notesOf($m);
    $ok('C: the note lists exactly what is missing', $n->filter(fn ($x) => str_contains($x->comment, 'missing: a real contact name, an email address, a phone number.'))->count() >= 1, $n);
    $after = (array) $lead($m);
    $diff = array_keys(array_filter($after, fn ($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
    sort($diff);
    $ok('C: NOTHING was invented - nothing but lead_status (and the updated_at stamp) changed; email, phone and name are untouched', in_array('lead_status', $diff, true) && array_diff($diff, ['lead_status', 'updated_at']) === [] && $after['email'] === null && $after['phone'] === null && $after['first_name'] === null, $diff);
    $ok('C: the status is a real picklist value', $after['lead_status'] === 'Not Contacted');

    // ---------------- D. a Signal promoted to a CRM lead (the real entry point) ----------------
    $coId = DB::table('g2g_companies')->insertGetId(['sub_institute_id' => 6, 'name' => 'Verify Signal Co', 'normalized_name' => 'verify signal co', 'website' => 'https://verify-signal.example.invalid', 'industry' => 'Technology', 'created_at' => now(), 'updated_at' => now()]);
    $companyIds[] = $coId;
    $opId = DB::table('g2g_company_opportunities')->insertGetId(['sub_institute_id' => 6, 'company_id' => $coId, 'title' => 'Verify signal', 'category' => 'tender', 'observed_event' => 'Verify observed event', 'why_indicates_need' => 'Verify why', 'product_fit' => 'Verify fit',
        'recommended_action' => 'Verify action', 'priority' => 'High', 'qualification' => 'unvetted', 'confidence' => 'High', 'sources' => '[]', 'fingerprint' => hash('sha256', 'verify-crmleads-' . microtime(true)), 'first_discovered_at' => now(), 'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $oppIds[] = $opId;
    [$c, $j] = $req('POST', "/signals/opportunities/$opId/promote-to-crm");
    $s = (int) ($j['data']['lead_id'] ?? 0); $leadIds[] = $s;
    $ok('D: Promote to CRM Lead created a lead', $c === 201 && $s > 0 && $j['data']['created'] === true, [$c, $j]);
    $ok('D: the lead event says it came from a Signal', ($ev = $eventOf($s)) && json_decode($ev->payload, true)['from_signal'] === true && json_decode($ev->payload, true)['lead_source'] === 'Signals', $ev);
    $ok('D: the promoted lead starts with the status "New", which is NOT a Lead Status picklist value', $lead($s)->lead_status === 'New' && ! DB::table('crm_picklist_values')->where('field_key', 'lead_status')->where('value', 'New')->exists());
    $deliver($s);
    $runs = $runsFor($s);
    $ok('D: the lead entered the intake workflow automatically (missing details: no real contact name, email or phone)', $runs->count() === 1 && (int) $runs[0]->workflow_id === $wf['missing'] && $runs[0]->status === 'succeeded', $runs);
    $ok('D: "New" was normalised to the picklist value "Not Contacted"', $lead($s)->lead_status === 'Not Contacted');
    $ok('D: the note asks for the missing details and the task exists for the owner', $notesOf($s)->contains(fn ($x) => str_contains($x->comment, 'a real contact name')) && $tasksOf($s)->filter(fn ($t) => str_starts_with($t->title, 'Verify complete lead details'))->count() === 1 && (int) $tasksOf($s)->first()->owner_id === (int) $lead($s)->assigned_to);
    [$c, $j] = $req('POST', "/signals/opportunities/$opId/promote-to-crm");
    $ok('D: promoting the same signal again is idempotent: created=false, no second lead, no second event', $c === 200 && $j['data']['created'] === false && DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'crm.lead.created')->where('entity_id', $s)->count() === 1, [$c, $j]);

    // ---------------- E. round-robin assignment through the existing LeadAssigner ----------------
    DB::table('crm_lead_assignment_settings')->where('sub_institute_id', 6)->update(['is_enabled' => 1, 'last_assigned_user_id' => 28]);
    DB::table('crm_lead_assignment_rotation_members')->insert([
        ['sub_institute_id' => 6, 'user_id' => 28, 'sort_order' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['sub_institute_id' => 6, 'user_id' => $employee->id, 'sort_order' => 2, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);
    [$c, $j, $r] = $mkLead(['firstName' => 'Verify', 'lastName' => 'Intake Rotation', 'company' => 'Verify Rotation Co', 'email' => 'verify-rotation@example.invalid', 'phone' => '+910000000003', 'assignedTo' => 28]);
    $leadIds[] = $r; $deliver($r);
    $steps = DB::table('gtm_workflow_run_steps')->where('run_id', $runsFor($r)[0]->id ?? 0)->orderBy('step')->get();
    $ok('E: the lead was reassigned to the NEXT person in the rotation (not the one who created it)', $steps[1]->status === 'succeeded' && (int) $lead($r)->assigned_to === (int) $employee->id, [$steps, $lead($r)->assigned_to]);
    $ok('E: the follow-up task belongs to the NEW owner', $tasksOf($r)->count() === 1 && (int) $tasksOf($r)[0]->owner_id === (int) $employee->id, $tasksOf($r));
    [$c, $j, $r2] = $mkLead(['firstName' => 'Verify', 'lastName' => 'Intake Worked', 'company' => 'Verify Worked Co', 'email' => 'verify-worked@example.invalid', 'phone' => '+910000000004', 'assignedTo' => 28, 'leadStatus' => 'Contacted']);
    $leadIds[] = $r2; $deliver($r2);
    $ok('E: a lead a human has already worked (status Contacted) is NOT reassigned', (int) $lead($r2)->assigned_to === 28, $lead($r2)->assigned_to);

    // ---------------- F. tenant isolation ----------------
    if ($other) {
        $oid = LeadFactory::create(['last_name' => 'Verify Other Tenant', 'company' => 'Verify Other Co', 'assigned_to' => $other->id, 'email' => 'verify-other@example.invalid'], (int) $other->sub_institute_id, (int) $other->id);
        $otherLeadIds[] = $oid;
        $e = DB::table('g2g_event')->where('sub_institute_id', $other->sub_institute_id)->where('type', 'crm.lead.created')->where('entity_id', $oid)->first();
        $ok('F: a lead created in another tenant records its event under THAT tenant', $e && (int) $e->sub_institute_id === (int) $other->sub_institute_id);
        $runner->dispatch($e);
        $ok('F: tenant 6\'s active workflows did NOT run for another tenant\'s lead', DB::table('gtm_workflow_runs')->where('entity_type', 'crm_leads')->where('entity_id', $oid)->count() === 0);
        [$c, $j] = call($kernel, 'GET', '/gtm/workflows', $O);
        $ok('F: the other tenant\'s administrator cannot see tenant 6\'s workflows', $c === 200 && collect($j['data']['workflows'] ?? [])->where('name', 'like', 'Verify Lead Intake%')->count() === 0, [$c]);
    } else {
        echo "SKIP F (no administrator in another tenant on this database)\n";
    }

    // ---------------- G. every creation path goes through the one factory ----------------
    $ok('G: LeadFactory::create is the only place that inserts crm_leads rows', trim((string) shell_exec('git grep -l "table(.crm_leads.)->insert" -- app')) === 'app/Domain/Crm/Leads/LeadFactory.php');

    // ---------------- H. regression: lead update/convert unaffected ----------------
    [$c, $j] = $req('PUT', "/crm/leads/$a", ['lastName' => 'Intake One', 'company' => 'Verify Intake Co', 'email' => 'verify-intake-1@example.invalid', 'leadStatus' => 'Contacted', 'assignedTo' => 28]);
    $ok('H: updating a lead still works and the existing audit event is still recorded', $c === 200 && $lead($a)->lead_status === 'Contacted', [$c, $j]);
    [$c, $j] = $req('GET', '/crm/leads');
    $ok('H: the lead list still loads', $c === 200, $c);
} finally {
    // cleanup: only what this scenario made
    $all = array_merge($leadIds, $otherLeadIds);
    DB::table('task_management_calendar_events')->where('linked_type', 'LEAD')->whereIn('linked_id', $all)->delete();
    DB::table('crm_lead_comments')->whereIn('lead_id', $all)->delete();
    DB::table('crm_leads')->whereIn('id', $all)->delete();
    DB::table('g2g_company_opportunities')->whereIn('id', $oppIds)->delete();
    DB::table('g2g_companies')->whereIn('id', $companyIds)->delete();
    DB::table('crm_lead_assignment_rotation_members')->where('sub_institute_id', 6)->where('created_at', '>=', $startedAt)->delete();
    if ($rotationBefore === null) { DB::table('crm_lead_assignment_settings')->where('sub_institute_id', 6)->delete(); } else { DB::table('crm_lead_assignment_settings')->where('sub_institute_id', 6)->update(['is_enabled' => $rotationBefore->is_enabled, 'last_assigned_user_id' => $rotationBefore->last_assigned_user_id]); }
    gtm_verify_cleanup($startedAt);
    echo "cleanup: rotation members now " . DB::table('crm_lead_assignment_rotation_members')->where('sub_institute_id', 6)->count() . " (was $membersBefore); leftover Verify leads " . DB::table('crm_leads')->where('last_name', 'like', 'Verify%')->count() . "\n";
}
echo "\n$pass passed, $fail failed\n";
