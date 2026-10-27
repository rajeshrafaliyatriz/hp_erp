<?php
// Step 4 of the CRM lead automation: qualification -> approval -> conversion (Organization + Contact + Opportunity).
// Real HTTP stack, real dev DB, the REAL reactor/engine, the REAL approval endpoints. No email is involved.
// Every row created is named "Verify ..." (or hangs off such a lead) and removed afterwards.
use App\Domain\Crm\Leads\LeadActivity;
use App\Services\Events\GtmWorkflowRunner;
use Illuminate\Support\Facades\{Cache, DB};

$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n"; };
$req = function ($m, $u, $b = [], $tok = null) use ($kernel, $A) { Cache::flush(); return call($kernel, $m, $u, $tok ?? $A, $b); };
$runner = app(GtmWorkflowRunner::class);
$deliver = function (string $type, int $leadId, int $tenant = 6) use ($runner) { $e = DB::table('g2g_event')->where('sub_institute_id', $tenant)->where('type', $type)->where('entity_id', $leadId)->orderByDesc('id')->first(); if ($e) { $runner->dispatch($e); } return $e; };
$events = fn (string $type, int $leadId) => DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', $type)->where('entity_id', $leadId)->get();
$lead = fn (int $id) => DB::table('crm_leads')->find($id);
$notes = fn (int $id) => DB::table('crm_lead_comments')->where('lead_id', $id)->orderBy('id')->pluck('comment');
$tasks = fn (int $id) => DB::table('task_management_calendar_events')->where('linked_type', 'LEAD')->where('linked_id', $id)->whereNull('deleted_at')->get();
$runOf = fn (int $wf, int $leadId) => DB::table('gtm_workflow_runs')->where('workflow_id', $wf)->where('entity_id', $leadId)->orderByDesc('id')->first();
$mkLead = function (string $suffix, array $extra = []) use ($req) {
    [$c, $j] = $req('POST', '/crm/leads', $extra + ['firstName' => 'Verify', 'lastName' => "CV $suffix", 'company' => "Verify CV $suffix Co", 'email' => 'verify-cv-' . strtolower($suffix) . '@example.invalid', 'phone' => '+910000000300', 'assignedTo' => 28]);
    return (int) ($j['data']['id'] ?? 0);
};
$qualify = fn (int $id) => $req('PUT', "/crm/leads/$id", ['leadStatus' => 'Qualified']);
$leadIds = []; $otherLeadIds = []; $companyIds = []; $oppIds = [];

try {
    // ---------------- workflows (through the real API) ----------------
    $mk = function (array $d) use ($req) { [$c, $j] = $req('POST', '/gtm/workflows', $d); $id = (int) ($j['data']['workflow']['id'] ?? 0); if ($id) { $req('POST', "/gtm/workflows/$id/status", ['status' => 'active']); } return [$c, $id, $j]; };
    [$c, $j] = $req('GET', '/gtm/workflows/catalog'); $cat = $j['data'];
    $ok('catalogue: convert_lead is offered and is CONSEQUENTIAL (needs approval); qualified/converted triggers exist', ($cat['actions']['convert_lead']['consequential'] ?? false) === true && isset($cat['triggers']['crm.lead.qualified'], $cat['triggers']['crm.lead.converted']), array_keys($cat['actions']));
    [$c, $j] = $req('POST', '/gtm/workflows', ['name' => 'Verify CV Bad', 'trigger_event' => 'crm.lead.qualified', 'conditions' => [], 'actions' => [['type' => 'convert_lead', 'params' => ['required_status' => 'Nonsense']]]]);
    $ok('validation: required_status must be a Lead Status picklist value (422)', $c === 422, [$c, $j['message'] ?? $j]);
    [$c, $j] = $req('POST', '/gtm/workflows', ['name' => 'Verify CV Bad2', 'trigger_event' => 'crm.lead.qualified', 'conditions' => [], 'actions' => [['type' => 'convert_lead', 'params' => ['create_opportunity' => 'yes']]]]);
    $ok('validation: create_opportunity must be true/false (422)', $c === 422, [$c, $j['message'] ?? $j]);

    // criteria live in the conditions: a real email, no possible duplicates
    [$c, $wf, $j] = $mk(['name' => 'Verify CV Qualify and Convert', 'trigger_event' => 'crm.lead.qualified', 'conditions' => [['field' => 'lead.has_email', 'op' => 'eq', 'value' => true], ['field' => 'lead.duplicate_leads', 'op' => 'eq', 'value' => 0]],
        'actions' => [['type' => 'convert_lead', 'params' => ['create_opportunity' => true]]]]);
    $ok('workflow saved and activated', $c === 201 && $wf > 0, [$c, $j]);
    // no email requirement: it only picks leads from the Verify-Loose source, to exercise the missing-details guard inside the action
    [$c, $wfLoose, $j] = $mk(['name' => 'Verify CV Loose', 'trigger_event' => 'crm.lead.qualified', 'conditions' => [['field' => 'lead.source', 'op' => 'eq', 'value' => 'Verify-Loose']], 'actions' => [['type' => 'convert_lead', 'params' => ['create_opportunity' => true]]]]);
    $ok('loose workflow saved', $c === 201 && $wfLoose > 0, [$c, $j]);
    sleep(1);

    // ---------------- 1. a Signal -> lead -> Qualified -> approval -> conversion, with traceability ----------------
    $coId = DB::table('g2g_companies')->insertGetId(['sub_institute_id' => 6, 'name' => 'Verify CV Signal Co', 'normalized_name' => 'verify cv signal co', 'website' => 'https://verify-cv-signal.example.invalid', 'industry' => 'Technology', 'created_at' => now(), 'updated_at' => now()]); $companyIds[] = $coId;
    $opId = DB::table('g2g_company_opportunities')->insertGetId(['sub_institute_id' => 6, 'company_id' => $coId, 'title' => 'Verify cv signal', 'category' => 'tender', 'observed_event' => 'Verify observed', 'why_indicates_need' => 'Verify why', 'product_fit' => 'Verify fit',
        'recommended_action' => 'Verify action', 'priority' => 'High', 'qualification' => 'unvetted', 'confidence' => 'High', 'sources' => '[]', 'fingerprint' => hash('sha256', 'verify-crmconvert-' . microtime(true)), 'first_discovered_at' => now(), 'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]); $oppIds[] = $opId;
    [$c, $j] = $req('POST', "/signals/opportunities/$opId/promote-to-crm"); $s = (int) ($j['data']['lead_id'] ?? 0); $leadIds[] = $s;
    $ok('1: Promote to CRM Lead created the lead from the Signal', $c === 201 && $s > 0, [$c, $j]);
    // a person completes the missing details (the intake workflow would have asked), then qualifies it
    $req('PUT', "/crm/leads/$s", ['firstName' => 'Verify', 'lastName' => 'CV Signal Contact', 'email' => 'verify-cv-signal@example.invalid', 'phone' => '+910000000301', 'leadStatus' => 'Contacted']);
    $ok('1: before qualification nothing converts (no qualified event yet)', $events('crm.lead.qualified', $s)->count() === 0 && ! $lead($s)->converted);
    [$c, $j] = $qualify($s);
    $q = $events('crm.lead.qualified', $s);
    $ok('1: marking the lead Qualified records crm.lead.qualified (once, with the actor)', $c === 200 && $q->count() === 1 && (int) $q[0]->actor_id === 28 && json_decode($q[0]->payload, true)['from'] === 'Contacted', [$c, $q]);
    [$c, $j] = $qualify($s);
    $ok('1: saving it as Qualified again records nothing new', $events('crm.lead.qualified', $s)->count() === 1);
    $deliver('crm.lead.qualified', $s);
    $run = $runOf($wf, $s);
    $ok('1: the workflow run is created but WAITS FOR APPROVAL - nothing converted yet', $run && $run->status === 'pending_approval' && ! $lead($s)->converted && DB::table('crm_organizations')->where('name', 'Verify CV Signal Co')->count() === 0, [$run->status ?? null]);
    [$c, $j] = $req('POST', "/gtm/workflow-runs/{$run->id}/approve", []);
    $ok('1: approving without explicit confirmation is refused (409)', $c === 409, [$c, $j]);
    [$c] = call($kernel, 'POST', "/gtm/workflow-runs/{$run->id}/approve", $E, ['confirm' => true]);
    $ok('1: an employee cannot approve (403)', $c === 403, $c);
    [$c, $j] = $req('POST', "/gtm/workflow-runs/{$run->id}/approve", ['confirm' => true, 'note' => 'Verified by test']);
    $L = $lead($s);
    $ok('1: after approval the lead is converted: the SAME conversion creates Organization, Contact and Opportunity', $c === 200 && (int) $L->converted === 1 && $L->converted_organization_id && $L->converted_contact_id && $L->converted_opportunity_id, [$c, $j]);
    $org = DB::table('crm_organizations')->find($L->converted_organization_id); $con = DB::table('crm_contacts')->find($L->converted_contact_id); $opp = DB::table('crm_opportunities')->find($L->converted_opportunity_id);
    $ok('1: Organization carries the Signal company; Contact the lead\'s real details; both in tenant 6', $org->name === 'Verify CV Signal Co' && (int) $org->sub_institute_id === 6 && $con->email === 'verify-cv-signal@example.invalid' && $con->last_name === 'CV Signal Contact' && (int) $con->organization_id === (int) $org->id && (int) $con->sub_institute_id === 6, [$org, $con]);
    $ok('1: the Opportunity belongs to the Organization, is OWNED BY THE LEAD\'S OWNER, and is linked to the Contact', (int) $opp->organization_id === (int) $org->id && (int) $opp->assigned_to === (int) $L->assigned_to && (int) $opp->sub_institute_id === 6 && DB::table('crm_opportunity_contacts')->where('opportunity_id', $opp->id)->where('contact_id', $con->id)->exists(), [$opp]);
    $ok('1: the Opportunity has no invented amount or stage - the owner sets those', $opp->amount === null || (float) $opp->amount === 0.0);
    $ok('1: TRACEABILITY: Signal #' . $opId . ' -> lead -> org/contact/opportunity ids are all on the lead, and the lead note says so', (int) $L->source_company_opportunity_id === $opId && $notes($s)->contains(fn ($n) => str_contains($n, "Organization #{$L->converted_organization_id}") && str_contains($n, "Contact #{$L->converted_contact_id}") && str_contains($n, "Opportunity #{$L->converted_opportunity_id}") && str_contains($n, "Originating Signal: #{$opId}")), $notes($s));
    $cv = $events('crm.lead.converted', $s);
    $ok('1: crm.lead.converted was recorded once with the three ids and the Signal flag', $cv->count() === 1 && json_decode($cv[0]->payload, true)['opportunity_id'] === (int) $L->converted_opportunity_id && json_decode($cv[0]->payload, true)['from_signal'] === true, $cv);
    $ok('1: the run is recorded as approved by the administrator and succeeded', ($r = DB::table('gtm_workflow_runs')->find($run->id)) && $r->status === 'succeeded' && (int) $r->decided_by === 28 && $r->decision_note === 'Verified by test', $r);
    // idempotency
    $deliver('crm.lead.qualified', $s); [$c2] = $req('POST', "/gtm/workflow-runs/{$run->id}/approve", ['confirm' => true]);
    $ok('1: IDEMPOTENT: re-delivering the event starts no second run, approving twice is refused (422), and nothing is duplicated', DB::table('gtm_workflow_runs')->where('workflow_id', $wf)->where('entity_id', $s)->count() === 1 && $c2 === 422 && DB::table('crm_organizations')->where('name', 'Verify CV Signal Co')->count() === 1 && DB::table('crm_opportunities')->where('name', 'Verify CV Signal Co - Opportunity')->count() === 1, [$c2]);

    // ---------------- 2. rejection leaves the lead alone ----------------
    $a = $mkLead('Reject'); $leadIds[] = $a; $qualify($a); $deliver('crm.lead.qualified', $a); $run = $runOf($wf, $a);
    [$c, $j] = $req('POST', "/gtm/workflow-runs/{$run->id}/reject", []);
    $ok('2: rejecting needs a reason (422)', $c === 422, $c);
    [$c, $j] = $req('POST', "/gtm/workflow-runs/{$run->id}/reject", ['note' => 'Budget not confirmed']);
    $ok('2: a rejected run converts NOTHING and the lead stays Qualified, not deleted', $c === 200 && DB::table('gtm_workflow_runs')->find($run->id)->status === 'rejected' && ! $lead($a)->converted && $lead($a)->lead_status === 'Qualified' && $lead($a)->deleted_at === null);

    // ---------------- 3. an approval that arrives late is judged against the lead as it is NOW ----------------
    $b = $mkLead('Stale'); $leadIds[] = $b; $qualify($b); $deliver('crm.lead.qualified', $b); $run = $runOf($wf, $b);
    $req('PUT', "/crm/leads/$b", ['leadStatus' => 'Lost Lead']);
    [$c, $j] = $req('POST', "/gtm/workflow-runs/{$run->id}/approve", ['confirm' => true]);
    $step = DB::table('gtm_workflow_run_steps')->where('run_id', $run->id)->first();
    $ok('3: approved after the lead was marked Lost: the step is SKIPPED with the reason and nothing is created', $step && $step->status === 'skipped' && str_contains($step->result, 'not \'Qualified\'') && ! $lead($b)->converted && DB::table('crm_organizations')->where('name', 'Verify CV Stale Co')->count() === 0, [$step]);

    // ---------------- 4. missing details: held back, asked for, nothing invented ----------------
    $d = $mkLead('Loose', ['leadSource' => 'Verify-Loose', 'email' => null, 'phone' => null, 'company' => 'Verify CV Loose Co']); $leadIds[] = $d;
    $req('PUT', "/crm/leads/$d", ['phone' => '', 'email' => null]);
    $qualify($d); $deliver('crm.lead.qualified', $d); $run = $runOf($wfLoose, $d);
    $ok('4: the loose workflow queued the lead for approval', $run && $run->status === 'pending_approval', $run);
    if ($run) { $req('POST', "/gtm/workflow-runs/{$run->id}/approve", ['confirm' => true]); }
    $step = $run ? DB::table('gtm_workflow_run_steps')->where('run_id', $run->id)->first() : null;
    $ok('4: with no email and no phone the conversion is held back (step skipped), a note and a task ask for them, nothing invented, lead untouched', $step && $step->status === 'skipped' && ! $lead($d)->converted && $notes($d)->contains(fn ($n) => str_contains($n, 'Conversion was held back') && str_contains($n, 'an email address or phone number')) && $tasks($d)->contains(fn ($t) => str_starts_with($t->title, 'Complete details before conversion')) && $lead($d)->email === null, [$step, $notes($d)]);

    // ---------------- 5. an existing Organization is reused, not duplicated ----------------
    $orgId = DB::table('crm_organizations')->insertGetId(['account_no' => 'ORG-VERIFYCV', 'name' => 'Verify CV Existing Co', 'sub_institute_id' => 6, 'assigned_to' => 28, 'created_by' => 28, 'created_at' => now(), 'updated_at' => now()]);
    $e = $mkLead('Existing', ['company' => 'Verify CV Existing Co']); $leadIds[] = $e;
    $qualify($e); $deliver('crm.lead.qualified', $e); $run = $runOf($wf, $e);
    $ok('5: a lead whose company already exists as an Organization is flagged as a possible duplicate in its facts (so a rule can hold it)', json_decode($run->context, true)['values']['lead.duplicate_organizations'] === 1, json_decode($run->context, true)['values']['lead.duplicate_organizations'] ?? null);
    $req('POST', "/gtm/workflow-runs/{$run->id}/approve", ['confirm' => true]);
    $ok('5: on conversion the EXISTING Organization is reused (no second one) and the Contact is attached to it', DB::table('crm_organizations')->where('name', 'Verify CV Existing Co')->count() === 1 && (int) $lead($e)->converted_organization_id === $orgId, [$lead($e)->converted_organization_id, $orgId]);

    // ---------------- 6. criteria in the conditions decide who even gets queued ----------------
    $f = $mkLead('Dup'); $leadIds[] = $f; $g = $mkLead('Dup2', ['email' => 'verify-cv-dup@example.invalid']); $leadIds[] = $g;
    $qualify($g); $deliver('crm.lead.qualified', $g);
    $ok('6: a Qualified lead that has a duplicate lead does NOT meet the criteria (duplicate_leads = 0), so no run is created for it', $runOf($wf, $g) === null, $runOf($wf, $g));

    // ---------------- 7. the human Convert button still works and announces the same event ----------------
    $h = $mkLead('Manual'); $leadIds[] = $h;
    [$c, $j] = $req('POST', "/crm/leads/$h/convert", ['createOrganization' => true, 'createContact' => true, 'createOpportunity' => true, 'assignedTo' => 28]);
    $ok('7: Marketing -> Leads -> Convert still returns the same shape and creates org, contact and opportunity', $c === 200 && $j['message'] === 'Lead converted.' && isset($j['data']['organizationId'], $j['data']['contactId'], $j['data']['opportunityId']), [$c, $j]);
    $ok('7: it also records crm.lead.converted (so post-conversion workflows react to manual conversions too)', $events('crm.lead.converted', $h)->count() === 1 && (int) json_decode($events('crm.lead.converted', $h)[0]->payload, true)['contact_id'] === (int) $j['data']['contactId']);
    [$c, $j] = $req('POST', "/crm/leads/$h/convert", ['createOrganization' => true, 'createContact' => true, 'assignedTo' => 28]);
    $ok('7: converting twice is still refused (422)', $c === 422 && str_contains($j['message'], 'already been converted'), [$c, $j]);
    [$c, $j] = $req('POST', '/crm/leads/' . $mkLead('Nothing') . '/convert', ['createOrganization' => false, 'createContact' => false, 'assignedTo' => 28]); $leadIds[] = DB::table('crm_leads')->where('last_name', 'CV Nothing')->value('id');
    $ok('7: its validation is unchanged (nothing selected -> 422)', $c === 422, [$c, $j]);

    // ---------------- 8. tenant isolation ----------------
    if ($other) {
        $ot = (int) $other->sub_institute_id;
        $oid = App\Domain\Crm\Leads\LeadFactory::create(['last_name' => 'Verify CV Other', 'company' => 'Verify CV Other Co', 'email' => 'verify-cv-other@example.invalid', 'assigned_to' => $other->id, 'lead_status' => 'Contacted'], $ot, (int) $other->id); $otherLeadIds[] = $oid;
        DB::table('crm_leads')->where('id', $oid)->update(['lead_status' => 'Qualified']);
        LeadActivity::announceQualified($ot, (object) ['id' => $oid, 'lead_status' => 'Contacted'], 'Qualified', (int) $other->id);
        $ev = DB::table('g2g_event')->where('sub_institute_id', $ot)->where('type', 'crm.lead.qualified')->where('entity_id', $oid)->first();
        $ok('8: another tenant\'s Qualified event is recorded under THAT tenant', $ev && (int) $ev->sub_institute_id === $ot);
        $runner->dispatch($ev);
        $ok('8: tenant 6\'s conversion workflows did not run for it, and nothing in tenant 6 was created', DB::table('gtm_workflow_runs')->where('entity_type', 'crm_leads')->where('entity_id', $oid)->count() === 0 && ! $lead($oid)->converted);
    }
} finally {
    $all = array_filter(array_merge($leadIds, $otherLeadIds));
    $cids = DB::table('crm_leads')->whereIn('id', $all)->pluck('converted_contact_id')->filter(); $oids = DB::table('crm_leads')->whereIn('id', $all)->pluck('converted_opportunity_id')->filter();
    DB::table('crm_opportunity_contacts')->whereIn('opportunity_id', $oids)->delete();
    DB::table('crm_opportunity_stage_history')->whereIn('opportunity_id', $oids)->delete();
    DB::table('crm_opportunities')->whereIn('id', $oids)->delete();
    DB::table('crm_contacts')->where(fn ($w) => $w->whereIn('id', $cids)->orWhere('email', 'like', 'verify-cv-%@example.invalid'))->delete();
    DB::table('crm_organizations')->where('name', 'like', 'Verify CV%')->delete();
    DB::table('task_management_calendar_events')->where('linked_type', 'LEAD')->whereIn('linked_id', $all)->delete();
    DB::table('crm_lead_comments')->whereIn('lead_id', $all)->delete();
    DB::table('crm_leads')->whereIn('id', $all)->delete();
    DB::table('g2g_company_opportunities')->whereIn('id', $oppIds)->delete();
    DB::table('g2g_companies')->whereIn('id', $companyIds)->delete();
    gtm_verify_cleanup($startedAt);
    echo 'cleanup: leftover leads ' . DB::table('crm_leads')->where('last_name', 'like', 'Verify%')->orWhere('last_name', 'like', 'CV %')->count() . ', orgs ' . DB::table('crm_organizations')->where('name', 'like', 'Verify CV%')->count() . ', contacts ' . DB::table('crm_contacts')->where('email', 'like', 'verify-cv-%')->count() . ', opps ' . DB::table('crm_opportunities')->where('name', 'like', 'Verify CV%')->count() . "\n";
}
echo "\n$pass passed, $fail failed\n";
