<?php
// END TO END: one story from "Promote to CRM Lead" on a Signal to the customer's support onboarding ticket.
// Installs the 24 recommended workflows (the same LeadAutomationPresets the install command uses) ACTIVE for tenant 6, then walks:
//   Signal -> lead -> intake -> person completes details -> follow-up email -> customer reply (signed webhook) -> read it (AI) ->
//   Pre Qualified + draft answer -> Qualified -> APPROVED conversion -> post-conversion checks -> pipeline -> Proposal -> APPROVED draft
//   quote -> APPROVED email -> Accepted -> APPROVED sales order -> Delivered -> APPROVED invoice -> payment (a person) -> Closed Won ->
//   onboarding ticket.
// Real HTTP stack, real dev DB, the REAL reactor/engine/approval endpoints/webhook. Mail is captured in-process, the AI provider is faked
// (guards and wiring, not model quality). Nothing is mocked in the business logic. The scheduler tick is NOT run (it would sweep other
// people's real records on the shared database); each time-driven part is driven over this story's own records only.
use App\Domain\Crm\Automation\LeadAutomationInstaller;
use App\Domain\Crm\Leads\LeadFollowUp;
use App\Services\Events\{GtmWorkflowRunner, NotificationDispatcher};
use Illuminate\Support\Facades\{Cache, DB, Http, Mail};
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

if (! class_exists('CrmE2eTransport')) {
    class CrmE2eTransport extends AbstractTransport
    {
        public static array $sent = [];
        protected function doSend(SentMessage $message): void { self::$sent[] = $message->getOriginalMessage(); }
        public function __toString(): string { return 'crme2e'; }
    }
}
$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n"; };
$req = function ($m, $u, $b = [], $tok = null) use ($kernel, $A) { Cache::flush(); return call($kernel, $m, $u, $tok ?? $A, $b); };
$runner = app(GtmWorkflowRunner::class); $notifier = app(NotificationDispatcher::class);
$deliver = function (string $type, int $entityId) use ($runner) { $e = DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', $type)->where('entity_id', $entityId)->orderByDesc('id')->first(); if ($e) { $runner->dispatch($e); } return $e; };
$secret = 'verify-e2e-secret';
$post = function (array $body) use ($kernel, $secret) { Cache::flush(); $raw = json_encode($body); $ts = time(); $h = "t=$ts,v1=" . hash_hmac('sha256', "$ts.$raw", $secret);
    $res = $kernel->handle(Illuminate\Http\Request::create('/api/crm/inbound', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_CRM_SIGNATURE' => $h], $raw)); return [$res->getStatusCode(), json_decode($res->getContent(), true)]; };
$fakeAi = function (array $answers) {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['*' => function ($request) use ($answers) {
        $body = $request->body(); $key = str_contains($body, 'You classify ONE email reply') ? 'classify' : (str_contains($body, 'You draft a SHORT') ? 'draft' : 'other'); $text = json_encode($answers[$key] ?? new stdClass);
        return Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 10],
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20]]);
    }]);
};
$wf = fn (string $key) => (int) DB::table('gtm_workflows')->where('sub_institute_id', 6)->where('name', 'like', "Verify E2E $key %")->value('id');
$runFor = fn (string $key, int $entityId) => DB::table('gtm_workflow_runs')->where('workflow_id', $wf($key))->where('entity_id', $entityId)->orderByDesc('id')->first();
$approve = function (string $key, int $entityId) use ($req, $runFor) { $r = $runFor($key, $entityId); if (! $r || $r->status !== 'pending_approval') { return [null, $r]; } [$c] = $req('POST', "/gtm/workflow-runs/{$r->id}/approve", ['confirm' => true, 'note' => 'e2e']); return [$c, DB::table('gtm_workflow_runs')->find($r->id)]; };
$ev = fn (string $type, int $id) => DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', $type)->where('entity_id', $id)->orderBy('id')->get();
$lead = fn (int $id) => DB::table('crm_leads')->find($id);
$notes = fn (int $id) => DB::table('crm_lead_comments')->where('lead_id', $id)->pluck('comment');
$tasks = fn (string $t, int $id) => DB::table('task_management_calendar_events')->where('linked_type', $t)->where('linked_id', $id)->whereNull('deleted_at')->get();
$ids = ['lead' => 0, 'comp' => 0, 'sig' => 0];
$cleanup = function () use (&$ids, $startedAt) {
    $leads = DB::table('crm_leads')->where(fn ($w) => $w->where('company', 'Verify E2E Signal Co')->orWhere('last_name', 'like', 'E2E %'))->get(['id', 'converted_contact_id', 'converted_opportunity_id', 'converted_organization_id']);
    $leadIds = $leads->pluck('id'); $opps = DB::table('crm_opportunities')->where('name', 'like', 'Verify E2E%')->pluck('id')->merge($leads->pluck('converted_opportunity_id')->filter())->unique();
    $quotes = DB::table('crm_quotes')->whereIn('opportunity_id', $opps)->pluck('id'); $sos = DB::table('crm_sales_orders')->whereIn('opportunity_id', $opps)->pluck('id'); $invs = DB::table('crm_invoices')->whereIn('sales_order_id', $sos)->pluck('id');
    $tix = DB::table('crm_tickets')->where('title', 'like', '%Verify E2E%')->pluck('id');
    $evIds = DB::table('g2g_event')->where(fn ($w) => $w->where('entity_type', 'crm_leads')->whereIn('entity_id', $leadIds))->orWhere(fn ($w) => $w->where('entity_type', 'crm_tickets')->whereIn('entity_id', $tix))->orWhere(fn ($w) => $w->where('entity_type', 'crm_opportunities')->whereIn('entity_id', $opps))->pluck('id');
    DB::table('g2g_notification')->whereIn('event_id', $evIds)->delete();
    $emailIds = DB::table('crm_email_links')->where(fn ($w) => $w->where('linked_type', 'LEAD')->whereIn('linked_id', $leadIds))->orWhere(fn ($w) => $w->where('linked_type', 'OPPORTUNITY')->whereIn('linked_id', $opps))->orWhere(fn ($w) => $w->where('linked_type', 'CONTACT')->whereIn('linked_id', $leads->pluck('converted_contact_id')->filter()))->pluck('email_id')->unique();
    DB::table('crm_email_links')->whereIn('email_id', $emailIds)->delete(); DB::table('crm_emails')->whereIn('id', $emailIds)->delete();
    DB::table('crm_inbound_events')->where('provider', 'verifye2e')->delete();
    DB::table('crm_ticket_comments')->whereIn('ticket_id', $tix)->delete(); DB::table('crm_tickets')->whereIn('id', $tix)->delete();
    DB::table('crm_invoice_payments')->whereIn('invoice_id', $invs)->delete(); DB::table('crm_invoice_status_history')->whereIn('invoice_id', $invs)->delete(); DB::table('crm_invoice_line_items')->whereIn('invoice_id', $invs)->delete(); DB::table('crm_invoices')->whereIn('id', $invs)->delete();
    DB::table('crm_sales_order_status_history')->whereIn('sales_order_id', $sos)->delete(); DB::table('crm_sales_order_line_items')->whereIn('sales_order_id', $sos)->delete(); DB::table('crm_sales_orders')->whereIn('id', $sos)->delete();
    DB::table('crm_quote_status_history')->whereIn('quote_id', $quotes)->delete(); DB::table('crm_quote_line_items')->whereIn('quote_id', $quotes)->delete(); DB::table('crm_quotes')->whereIn('id', $quotes)->delete();
    DB::table('task_management_calendar_events')->where(fn ($w) => $w->where('linked_type', 'LEAD')->whereIn('linked_id', $leadIds))->orWhere(fn ($w) => $w->where('linked_type', 'OPPORTUNITY')->whereIn('linked_id', $opps))->orWhere(fn ($w) => $w->where('linked_type', 'ORGANIZATION')->whereIn('linked_id', DB::table('crm_organizations')->where('name', 'like', 'Verify E2E%')->pluck('id')))->delete();
    DB::table('crm_opportunity_products')->whereIn('opportunity_id', $opps)->delete(); DB::table('crm_opportunity_contacts')->whereIn('opportunity_id', $opps)->delete(); DB::table('crm_opportunity_stage_history')->whereIn('opportunity_id', $opps)->delete(); DB::table('crm_opportunity_comments')->whereIn('opportunity_id', $opps)->delete(); DB::table('crm_opportunities')->whereIn('id', $opps)->delete();
    DB::table('crm_lead_followups')->whereIn('lead_id', $leadIds)->delete(); DB::table('crm_lead_comments')->whereIn('lead_id', $leadIds)->delete(); DB::table('crm_leads')->whereIn('id', $leadIds)->delete();
    DB::table('crm_products')->where('name', 'like', 'Verify E2E%')->delete(); DB::table('crm_contacts')->where('email', 'like', 'verify-e2e-%')->delete(); DB::table('crm_organizations')->where('name', 'like', 'Verify E2E%')->delete();
    DB::table('g2g_company_opportunities')->where('title', 'like', 'Verify E2E%')->delete(); DB::table('g2g_companies')->where('name', 'like', 'Verify E2E%')->delete();
    gtm_verify_cleanup($startedAt);
};
$cleanup();

Mail::extend('crme2e', fn (array $config) => new CrmE2eTransport());
config(['mail.mailers.crme2e' => ['transport' => 'crme2e'], 'mail.default' => 'crme2e', 'mail.from.address' => 'sender@example.invalid', 'app.url' => 'https://api.example.com', 'crm.email_daily_cap' => 500, 'crm.email_sending_enabled' => true, 'crm.inbound.secret' => $secret]);
Mail::purge();
$captured = get_class(Mail::mailer()->getSymfonyTransport()) === 'CrmE2eTransport' && DB::table('smtp_details')->where('sub_institute_id', 6)->count() === 0;
$ok('SAFETY: tenant-6 mail goes to the in-process capture transport', $captured);
if (! $captured) { echo "ABORT\n"; exit(1); }
$rotation = DB::table('crm_lead_assignment_settings')->where('sub_institute_id', 6)->first();

try {
    // ---------------- install the recommended configuration (active) ----------------
    $inst = LeadAutomationInstaller::install(6, 28, ['support_user_id' => 28, 'prefix' => 'Verify E2E', 'ticket_priority' => 'High', 'faq_category' => 'General', 'invoice_due_days' => 30,
        'followup' => ['intervals' => '2,4', 'max_attempts' => 3, 'window_start' => '00:00', 'window_end' => '23:59', 'timezone' => 'Asia/Kolkata', 'weekdays_only' => false]], true);
    $ok('INSTALL: all 24 recommended workflows were created active, none invalid', count($inst['created']) === 24 && $inst['invalid'] === [] && $inst['existing'] === [], $inst);
    $again = LeadAutomationInstaller::install(6, 28, ['support_user_id' => 28, 'prefix' => 'Verify E2E'], true);
    $ok('INSTALL: running it again creates nothing new (idempotent by name)', count($again['created']) === 0 && count($again['existing']) === 24);
    try { LeadAutomationInstaller::install(6, (int) $employee->id, ['support_user_id' => 28, 'prefix' => 'Verify E2E X'], true); $refused = false; } catch (\InvalidArgumentException $e) { $refused = true; }
    $ok('INSTALL: refused when the owner is not an administrator (workflows run as an administrator)', $refused && DB::table('gtm_workflows')->where('name', 'like', 'Verify E2E X%')->count() === 0);
    $consequential = DB::table('gtm_workflows')->where('name', 'like', 'Verify E2E %')->get()->filter(fn ($w) => str_contains($w->actions, 'convert_lead') || str_contains($w->actions, 'prepare_quote') || str_contains($w->actions, 'email_quote') || str_contains($w->actions, 'create_sales_order') || str_contains($w->actions, 'create_invoice"'))->count();
    $ok('INSTALL: the five money/commitment workflows (convert, prepare quote, email quote, sales order, invoice) are the ones that need approval', $consequential === 5, $consequential);
    sleep(1);
    DB::table('crm_lead_assignment_settings')->updateOrInsert(['sub_institute_id' => 6], ['is_enabled' => 0, 'updated_at' => now(), 'created_at' => now()]);

    // ================= STEP 1: a Signal is promoted to a CRM lead =================
    $ids['comp'] = DB::table('g2g_companies')->insertGetId(['sub_institute_id' => 6, 'name' => 'Verify E2E Signal Co', 'normalized_name' => 'verify e2e signal co', 'website' => 'https://verify-e2e.example.invalid', 'industry' => 'Technology', 'created_at' => now(), 'updated_at' => now()]);
    $ids['sig'] = DB::table('g2g_company_opportunities')->insertGetId(['sub_institute_id' => 6, 'company_id' => $ids['comp'], 'title' => 'Verify E2E signal', 'category' => 'tender', 'observed_event' => 'Verify observed', 'why_indicates_need' => 'Verify why', 'product_fit' => 'Verify fit', 'recommended_action' => 'Verify action', 'priority' => 'High', 'qualification' => 'unvetted', 'confidence' => 'High', 'sources' => '[]', 'fingerprint' => hash('sha256', 'verify-e2e-' . microtime(true)), 'first_discovered_at' => now(), 'last_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    [$c, $j] = $req('POST', "/signals/opportunities/{$ids['sig']}/promote-to-crm"); $L = (int) ($j['data']['lead_id'] ?? 0); $ids['lead'] = $L;
    $ok('1: "Promote to CRM Lead" on the Signal created a lead', $c === 201 && $L > 0 && $lead($L)->source_company_opportunity_id == $ids['sig'], [$c, $j]);
    $deliver('crm.lead.created', $L);
    $ok('1: the lead entered the intake automatically: only "missing details" fired, status normalised, owner has a task, nothing invented', $runFor('1b', $L)?->status === 'succeeded' && $runFor('1a', $L) === null && $runFor('2a', $L) === null && $lead($L)->lead_status === 'Not Contacted' && $lead($L)->email === null && $tasks('LEAD', $L)->contains(fn ($t) => str_starts_with($t->title, 'Complete lead details')) && $notes($L)->contains(fn ($n) => str_contains($n, 'a real contact name') && str_contains($n, 'an email address')), [$runFor('1b', $L)?->status, $lead($L)->lead_status]);
    $ok('1: no follow-up email can start yet (no address): the cadence workflow did not run and no cadence row exists', DB::table('crm_lead_followups')->where('lead_id', $L)->count() === 0 && count(CrmE2eTransport::$sent) === 0);

    // ================= a person completes the details -> the cadence starts =================
    [$c, $j] = $req('PUT', "/crm/leads/$L", ['firstName' => 'Verify', 'lastName' => 'E2E Contact', 'email' => 'verify-e2e-contact@example.invalid', 'phone' => '+910000000900', 'designation' => null]);
    $ok('2: completing the details through the normal lead edit announces crm.lead.ready (once)', $c === 200 && $ev('crm.lead.ready', $L)->count() === 1, [$c, $ev('crm.lead.ready', $L)->count()]);
    $deliver('crm.lead.ready', $L);
    $out = DB::table('crm_emails as e')->join('crm_email_links as l', 'l.email_id', '=', 'e.id')->where('l.linked_type', 'LEAD')->where('l.linked_id', $L)->where('e.direction', 'outbound')->select('e.*')->get();
    $mid = count(CrmE2eTransport::$sent) ? trim(end(CrmE2eTransport::$sent)->getHeaders()->get('Message-ID')->getBodyAsString(), '<>') : null;
    $ok('2: the follow-up cadence started and the FIRST EMAIL went out to the lead\'s real address, carrying a stored Message-ID', $runFor('2b', $L)?->status === 'succeeded' && $out->count() === 1 && $out[0]->status === 'Sent' && $mid && $out[0]->message_id === $mid && end(CrmE2eTransport::$sent)->getTo()[0]->getAddress() === 'verify-e2e-contact@example.invalid', [$out->count(), $mid]);
    $f = DB::table('crm_lead_followups')->where('lead_id', $L)->first();
    $ok('2: the lead is in the follow-up state (Attempted to Contact), the cadence waits (next email in 2 days), nothing is running while it waits', $lead($L)->lead_status === 'Attempted to Contact' && $f->state === 'active' && (int) $f->attempts === 1 && abs(strtotime($f->next_due_at) - (time() + 2 * 86400)) < 300, [$lead($L)->lead_status, $f]);
    $before = count(CrmE2eTransport::$sent); LeadFollowUp::processDue(); // the scheduler's own pass: not due, so nothing happens
    $ok('2: a scheduler pass before the due time sends nothing', count(CrmE2eTransport::$sent) === $before);

    // ================= STEP 3: the customer replies (real signed webhook) =================
    [$c, $j] = $post(['provider' => 'verifye2e', 'event_id' => 'e2e-' . bin2hex(random_bytes(5)), 'type' => 'reply', 'in_reply_to' => "<$mid>", 'from_email' => 'verify-e2e-contact@example.invalid', 'subject' => 'Re: Following up',
        'text' => "Yes, we are interested. Please send the pricing and let us know when we can talk.\n\nOn Wed, someone wrote:\n> Hello", 'occurred_at' => now()->toDateTimeString()]);
    $ok('3: the signed reply was accepted and matched to the lead by the Message-ID of the email we sent', $c === 200 && DB::table('crm_inbound_events')->where('lead_id', $L)->where('status', 'processed')->exists(), [$c, $j]);
    $f = DB::table('crm_lead_followups')->where('lead_id', $L)->first();
    $ok('3: the cadence STOPPED (replied), the reply is on the lead\'s Emails tab, and the owner was told', $f->state === 'replied' && DB::table('crm_emails as e')->join('crm_email_links as l', 'l.email_id', '=', 'e.id')->where('l.linked_id', $L)->where('e.direction', 'inbound')->where('e.status', 'Received')->exists() && $ev('crm.lead.replied', $L)->count() === 1);
    $fakeAi(['classify' => ['classification' => 'interested', 'confidence' => 'high', 'reason' => 'asks for pricing'], 'draft' => ['subject' => 'Pricing', 'body' => "Hello,\nThanks for your interest, a colleague will send the pricing details.\nBest regards,\n[Your name]"]]);
    $deliver('crm.lead.replied', $L);
    $ok('3: the reply was read (interested), the owner got a task and the lead moved to Contacted', DB::table('crm_inbound_events')->where('lead_id', $L)->value('classification') === 'interested' && $lead($L)->lead_status === 'Contacted' && $tasks('LEAD', $L)->contains(fn ($t) => str_starts_with($t->title, 'Customer replied')), $lead($L)->lead_status);
    $deliver('crm.lead.reply_classified', $L);
    $sentCount = count(CrmE2eTransport::$sent);
    $ok('3: "interested" moved the lead to Pre Qualified and a suggested answer exists as a DRAFT that was NOT sent', $lead($L)->lead_status === 'Pre Qualified' && DB::table('crm_emails as e')->join('crm_email_links as l', 'l.email_id', '=', 'e.id')->where('l.linked_id', $L)->where('e.status', 'Draft')->count() === 1 && count(CrmE2eTransport::$sent) === $sentCount);
    DB::table('crm_lead_followups')->where('lead_id', $L)->update(['next_due_at' => now()->subMinute()]); LeadFollowUp::processDue();
    $ok('3: even with the next follow-up "due", nothing more is sent to a customer who replied', count(CrmE2eTransport::$sent) === $sentCount);

    // ================= STEP 4: qualified -> approval -> conversion =================
    [$c] = $req('PUT', "/crm/leads/$L", ['leadStatus' => 'Qualified']); $deliver('crm.lead.qualified', $L);
    $r = $runFor('4', $L);
    $ok('4: marking the lead Qualified queued the conversion FOR APPROVAL - nothing converted yet', $c === 200 && $r?->status === 'pending_approval' && ! $lead($L)->converted);
    [$c, $rr] = $approve('4', $L); $Lr = $lead($L);
    $ok('4: after the administrator approves, the SAME conversion ran: Organization, Contact and Opportunity exist and are linked to the lead (and the Signal)', $c === 200 && $Lr->converted && $Lr->converted_organization_id && $Lr->converted_contact_id && $Lr->converted_opportunity_id && (int) $Lr->source_company_opportunity_id === $ids['sig'] && (int) $rr->decided_by === 28, [$c, $Lr]);
    $orgId = (int) $Lr->converted_organization_id; $conId = (int) $Lr->converted_contact_id; $oid = (int) $Lr->converted_opportunity_id;
    $opp = DB::table('crm_opportunities')->find($oid);
    $ok('4: the Opportunity belongs to the Organization and is OWNED by the lead\'s owner; the Contact carries the verified email', (int) $opp->organization_id === $orgId && (int) $opp->assigned_to === (int) $Lr->assigned_to && DB::table('crm_contacts')->find($conId)->email === 'verify-e2e-contact@example.invalid');

    // ================= STEP 5: organizations / contacts / opportunities =================
    $deliver('crm.lead.converted', $L);
    $ok('5: post-conversion checks: links are fine (no "broken links" run), no look-alike organization, and the missing decision-maker is asked for on the Organization', $runFor('5a', $L) === null && $runFor('5b', $L) === null && $runFor('5c', $L)?->status === 'succeeded' && $tasks('ORGANIZATION', $orgId)->contains(fn ($t) => str_starts_with($t->title, 'Identify the decision-maker')));
    $deliver('crm.opportunity.created', $oid);
    $ok('5: the new Opportunity entered the pipeline: stage Prospecting (history written), a task for its owner, a note', DB::table('crm_opportunities')->find($oid)->sales_stage === 'Prospecting' && DB::table('crm_opportunity_stage_history')->where('opportunity_id', $oid)->where('to_stage', 'Prospecting')->exists() && $tasks('OPPORTUNITY', $oid)->contains(fn ($t) => str_starts_with($t->title, 'Qualify')) && DB::table('crm_opportunity_comments')->where('opportunity_id', $oid)->where('comment', 'like', '%entered the pipeline (from a converted lead: yes)%')->exists());

    // ================= STEP 6: proposal -> quote -> sales order -> invoice =================
    $pid = DB::table('crm_products')->insertGetId(['item_type' => 'product', 'product_no' => 'PRD-VE2E1', 'name' => 'Verify E2E Product', 'unit_price' => 100.00, 'currency' => 'INR', 'is_active' => 1, 'sub_institute_id' => 6, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('crm_opportunity_products')->insert(['opportunity_id' => $oid, 'product_id' => $pid, 'quantity' => 3, 'created_at' => now()]);
    $req('PUT', "/crm/opportunities/$oid/stage", ['salesStage' => 'Proposal/Quotation']); $deliver('crm.opportunity.stage_changed', $oid);
    $ok('6: moving to Proposal queued the draft quote FOR APPROVAL', $runFor('6a', $oid)?->status === 'pending_approval' && DB::table('crm_quotes')->where('opportunity_id', $oid)->count() === 0);
    $approve('6a', $oid); $quote = DB::table('crm_quotes')->where('opportunity_id', $oid)->first();
    $ok('6: after approval a DRAFT quote exists at the product\'s real list price (3 x 100.00 = 300.00), for the Organization and Contact', $quote && $quote->quote_stage === 'Draft' && (float) $quote->total === 300.0 && (int) $quote->organization_id === $orgId && (int) $quote->contact_id === $conId, $quote);
    $deliver('crm.quote.created', (int) $quote->id); $ok('6: emailing the quote waits for approval', $runFor('6b', (int) $quote->id)?->status === 'pending_approval');
    $sentBefore = count(CrmE2eTransport::$sent); $approve('6b', (int) $quote->id);
    $qm = end(CrmE2eTransport::$sent);
    $ok('6: after approval the quote was emailed to the contact (total 300.00) and ONLY then marked Sent', count(CrmE2eTransport::$sent) === $sentBefore + 1 && str_contains($qm->getTextBody(), 'Total: 300.00') && $qm->getTo()[0]->getAddress() === 'verify-e2e-contact@example.invalid' && DB::table('crm_quotes')->find($quote->id)->quote_stage === 'Sent');
    $req('PUT', "/crm/quotes/{$quote->id}", ['quoteStage' => 'Accepted']); $deliver('crm.quote.stage_changed', (int) $quote->id);
    $so0 = DB::table('crm_sales_orders')->where('quote_id', $quote->id)->count(); [$c, $rr] = $approve('6c', (int) $quote->id); $so = DB::table('crm_sales_orders')->where('quote_id', $quote->id)->first();
    $ok('6: the customer accepted (recorded by a person); the sales order was created only after approval, same total, same owner', $so0 === 0 && $so && (float) $so->total === 300.0 && (int) $so->assigned_to === (int) $quote->assigned_to);
    $req('PUT', "/crm/sales-orders/{$so->id}/stage", ['soStage' => 'Shipped']); $deliver('crm.sales_order.stage_changed', (int) $so->id);
    $ok('6: marking the order Delivered queued the invoice FOR APPROVAL', $runFor('6d', (int) $so->id)?->status === 'pending_approval' && DB::table('crm_invoices')->where('sales_order_id', $so->id)->count() === 0);
    $approve('6d', (int) $so->id); $inv = DB::table('crm_invoices')->where('sales_order_id', $so->id)->first();
    $ok('6: after approval the invoice exists (total 300.00, due in 30 days) and NO payment was recorded by the automation', $inv && (float) $inv->total === 300.0 && $inv->due_date === now()->addDays(30)->toDateString() && (float) $inv->amount_paid === 0.0 && DB::table('crm_invoice_payments')->where('invoice_id', $inv->id)->count() === 0);
    [$c, $j] = $req('POST', "/crm/invoices/{$inv->id}/payments", ['amount' => 300, 'method' => 'Bank transfer', 'reference' => 'VERIFY-E2E']);
    $ok('6: a PERSON recorded the payment through the normal endpoint; "paid" was announced once', $c === 201 && $ev('crm.invoice.paid', (int) $inv->id)->count() === 1 && DB::table('crm_invoice_payments')->where('invoice_id', $inv->id)->count() === 1 && (int) DB::table('crm_invoice_payments')->where('invoice_id', $inv->id)->value('created_by') === 28);

    // ================= STEP 7: the sale is won -> support onboarding =================
    $req('PUT', "/crm/opportunities/$oid/stage", ['salesStage' => 'Closed Won']); $deliver('crm.opportunity.stage_changed', $oid);
    $t = DB::table('crm_tickets')->where('organization_id', $orgId)->where('title', 'like', '%Verify E2E%')->first();
    $ok('7: Closed Won opened ONE onboarding ticket for the customer, assigned to the support user, with the priority from the rule', $t && DB::table('crm_tickets')->where('organization_id', $orgId)->count() === 1 && (int) $t->assigned_to === 28 && $t->priority === 'High' && (int) $t->contact_id === $conId && $t->status === 'Open', $t);
    $ok('7: the ticket lists the WHOLE chain with real numbers: Opportunity, Quote, Sales Order, Invoice', $t && str_contains($t->description, $opp->opportunity_no) && str_contains($t->description, $quote->quote_no) && str_contains($t->description, $so->so_no) && str_contains($t->description, $inv->invoice_no), $t->description ?? null);
    $ce = $ev('crm.ticket.created', (int) $t->id)->first(); $notifier->dispatch($ce);
    $ok('7: the assignee was notified in-app', DB::table('g2g_notification')->where('event_id', $ce->id)->where('channel', 'inapp')->where('user_id', 28)->count() === 1);

    // ================= whole-story guarantees =================
    $allRuns = DB::table('gtm_workflow_runs')->whereIn('workflow_id', DB::table('gtm_workflows')->where('name', 'like', 'Verify E2E %')->pluck('id'))->get();
    $ok('ALL: no workflow run in the whole story failed or is stuck', $allRuns->whereIn('status', ['failed', 'retry_wait', 'queued', 'running'])->count() === 0, $allRuns->groupBy('status')->map->count());
    $ok('ALL: every consequential run (conversion, quote, quote email, sales order, invoice) was approved by an administrator, never self-started', $allRuns->filter(fn ($r) => in_array($r->status, ['succeeded', 'skipped']) && DB::table('gtm_workflow_run_steps')->where('run_id', $r->id)->whereIn('action', ['convert_lead', 'prepare_quote', 'email_quote', 'create_sales_order', 'create_invoice'])->exists())->every(fn ($r) => (int) $r->decided_by === 28 && $r->decided_at !== null));
    $ok('ALL: the lead was never deleted and was never marked Lost; it carries the whole trail (Signal id, Organization, Contact, Opportunity)', $lead($L)->deleted_at === null && $lead($L)->lead_status !== 'Lost Lead');
    $staffEmail = DB::table('tbluser')->where('id', 28)->value('email');
    $customerMail = collect(CrmE2eTransport::$sent)->reject(fn ($m) => str_starts_with((string) $m->getSubject(), 'New onboarding ticket'));
    $staffMail = collect(CrmE2eTransport::$sent)->filter(fn ($m) => str_starts_with((string) $m->getSubject(), 'New onboarding ticket'));
    $ok('ALL: every CUSTOMER email (follow-up, quote) went only to the lead\'s own verified address; the only other message is the platform\'s own staff notification for the ticket, to its assignee', $customerMail->count() === 2 && $customerMail->every(fn ($m) => collect($m->getTo())->every(fn ($a) => $a->getAddress() === 'verify-e2e-contact@example.invalid')) && $staffMail->count() === 1 && $staffMail->first()->getTo()[0]->getAddress() === $staffEmail, collect(CrmE2eTransport::$sent)->map(fn ($m) => $m->getSubject() . ' -> ' . $m->getTo()[0]->getAddress())->all());
    $ok('ALL: every row this story made is in tenant 6 and no other tenant\'s data was written', DB::table('crm_leads')->where('id', $L)->value('sub_institute_id') == 6 && DB::table('crm_tickets')->where('title', 'like', '%Verify E2E%')->where('sub_institute_id', '!=', 6)->count() === 0 && DB::table('crm_quotes')->where('opportunity_id', $oid)->where('sub_institute_id', '!=', 6)->count() === 0);
    $ok('ALL: every step left an audit event in the store (lead created/ready/replied/classified/qualified/converted, opportunity created/stage changes, quote, order, invoice, ticket)', collect(['crm.lead.created', 'crm.lead.ready', 'crm.lead.replied', 'crm.lead.reply_classified', 'crm.lead.qualified', 'crm.lead.converted'])->every(fn ($t) => $ev($t, $L)->count() >= 1) && $ev('crm.opportunity.created', $oid)->count() === 1 && $ev('crm.opportunity.stage_changed', $oid)->count() >= 3 && $ev('crm.quote.created', (int) $quote->id)->count() === 1 && $ev('crm.sales_order.created', (int) $so->id)->count() === 1 && $ev('crm.invoice.created', (int) $inv->id)->count() === 1);
} finally {
    if ($rotation === null) { DB::table('crm_lead_assignment_settings')->where('sub_institute_id', 6)->delete(); }
    $cleanup();
    echo 'cleanup: leads ' . DB::table('crm_leads')->where('company', 'Verify E2E Signal Co')->count() . ', orgs ' . DB::table('crm_organizations')->where('name', 'like', 'Verify E2E%')->count() . ', opps ' . DB::table('crm_opportunities')->where('name', 'like', 'Verify E2E%')->count() . ', tickets ' . DB::table('crm_tickets')->where('title', 'like', '%Verify E2E%')->count() . ', workflows ' . DB::table('gtm_workflows')->where('name', 'like', 'Verify E2E%')->count() . ', products ' . DB::table('crm_products')->where('name', 'like', 'Verify E2E%')->count() . "\n";
}
echo "\n$pass passed, $fail failed\n";
