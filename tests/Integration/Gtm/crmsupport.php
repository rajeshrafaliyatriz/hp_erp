<?php
// Step 7 of the CRM lead automation: after a sale, support onboarding - one ticket, SLA applied, assignee notified.
// Real HTTP stack, real dev DB, real reactor + notification dispatcher. Mail goes to an in-process capture transport.
// The SLA escalation sweep is only DRY-RUN here (a live run would act on other people's real tickets on the shared database).
// Fixtures are named "Verify SP ..." and removed afterwards.
use App\Domain\Crm\Support\TicketSlaEscalator;
use App\Services\Events\{GtmWorkflowRunner, NotificationDispatcher};
use Illuminate\Support\Facades\{Cache, DB, Mail};
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

if (! class_exists('CrmSpTransport')) {
    class CrmSpTransport extends AbstractTransport
    {
        public static array $sent = [];
        protected function doSend(SentMessage $message): void { self::$sent[] = $message->getOriginalMessage(); }
        public function __toString(): string { return 'crmsp'; }
    }
}
$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n"; };
$req = function ($m, $u, $b = [], $tok = null) use ($kernel, $A) { Cache::flush(); return call($kernel, $m, $u, $tok ?? $A, $b); };
$runner = app(GtmWorkflowRunner::class); $notifier = app(NotificationDispatcher::class);
$deliver = function (string $type, int $entityId, int $tenant = 6) use ($runner) { $e = DB::table('g2g_event')->where('sub_institute_id', $tenant)->where('type', $type)->where('entity_id', $entityId)->orderByDesc('id')->first(); if ($e) { $runner->dispatch($e); } return $e; };
$events = fn (string $type, int $id) => DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', $type)->where('entity_id', $id)->orderBy('id')->get();
$mk = function (array $d) use ($req) { [$c, $j] = $req('POST', '/gtm/workflows', $d); $id = (int) ($j['data']['workflow']['id'] ?? 0); if ($id) { $req('POST', "/gtm/workflows/$id/status", ['status' => 'active']); } return [$c, $id, $j]; };
$tickets = fn () => DB::table('crm_tickets')->where('title', 'like', 'Verify SP%')->orderBy('id')->get();
$madePolicy = false; $oppIds = [];
$cleanup = function () use (&$madePolicy, $startedAt) {
    $tIds = DB::table('crm_tickets')->where('title', 'like', 'Verify SP%')->pluck('id');
    $evIds = DB::table('g2g_event')->where('entity_type', 'crm_tickets')->whereIn('entity_id', $tIds)->pluck('id');
    DB::table('g2g_notification')->whereIn('event_id', $evIds)->delete();
    DB::table('crm_ticket_comments')->whereIn('ticket_id', $tIds)->delete(); DB::table('crm_tickets')->whereIn('id', $tIds)->delete();
    $opps = DB::table('crm_opportunities')->where('name', 'like', 'Verify SP%')->pluck('id');
    $invs = DB::table('crm_invoices')->where('subject', 'like', 'Verify SP%')->pluck('id');
    DB::table('crm_invoice_payments')->whereIn('invoice_id', $invs)->delete(); DB::table('crm_invoices')->whereIn('id', $invs)->delete();
    DB::table('crm_sales_orders')->where('subject', 'like', 'Verify SP%')->delete(); DB::table('crm_quotes')->where('subject', 'like', 'Verify SP%')->delete();
    DB::table('crm_opportunity_contacts')->whereIn('opportunity_id', $opps)->delete(); DB::table('crm_opportunity_stage_history')->whereIn('opportunity_id', $opps)->delete(); DB::table('crm_opportunities')->whereIn('id', $opps)->delete();
    DB::table('crm_service_contracts')->where('subject', 'like', 'Verify SP%')->delete(); DB::table('crm_faqs')->where('question', 'like', 'Verify SP%')->delete();
    DB::table('crm_contacts')->where('email', 'like', 'verify-sp-%')->delete(); DB::table('crm_organizations')->where('name', 'like', 'Verify SP%')->delete();
    if ($madePolicy) { DB::table('crm_sla_policies')->where('sub_institute_id', 6)->where('priority', 'High')->where('created_by', 28)->where('response_hours', 1)->delete(); }
    gtm_verify_cleanup($startedAt);
};
$cleanup();

Mail::extend('crmsp', fn (array $config) => new CrmSpTransport());
config(['mail.mailers.crmsp' => ['transport' => 'crmsp'], 'mail.default' => 'crmsp', 'mail.from.address' => 'sender@example.invalid', 'app.url' => 'https://api.example.com']);
Mail::purge();
$captured = get_class(Mail::mailer()->getSymfonyTransport()) === 'CrmSpTransport' && DB::table('smtp_details')->where('sub_institute_id', 6)->count() === 0;
$ok('SAFETY: tenant-6 mail goes to the in-process capture transport', $captured);
if (! $captured) { echo "ABORT\n"; exit(1); }

try {
    // ---------------- catalogue + validation ----------------
    [$c, $j] = $req('GET', '/gtm/workflows/catalog'); $cat = $j['data'];
    $ok('catalogue: create_support_onboarding is offered, not consequential, with its parameters', isset($cat['actions']['create_support_onboarding']['params']['assign_to_user_id'], $cat['actions']['create_support_onboarding']['params']['faq_category']) && $cat['actions']['create_support_onboarding']['consequential'] === false);
    $base = ['name' => 'Verify SP Bad', 'trigger_event' => 'crm.invoice.paid', 'conditions' => []];
    $good = ['title' => 'Verify SP Onboarding {{invoice.no}}', 'assign_to_user_id' => 28];
    [$c, $j] = $req('POST', '/gtm/workflows', ['trigger_event' => 'crm.lead.created'] + ['actions' => [['type' => 'create_support_onboarding', 'params' => $good]]] + $base); $ok('validation: it cannot start from a lead trigger (no sale) (422)', $c === 422, [$c, $j['message'] ?? $j]);
    [$c, $j] = $req('POST', '/gtm/workflows', ['actions' => [['type' => 'create_support_onboarding', 'params' => ['title' => 'x']]]] + $base); $ok('validation: an assignee is required (422)', $c === 422, [$c, $j['message'] ?? $j]);
    $foreign = $other ? (int) $other->id : 999999999;
    [$c, $j] = $req('POST', '/gtm/workflows', ['actions' => [['type' => 'create_support_onboarding', 'params' => ['assign_to_user_id' => $foreign] + $good]]] + $base); $ok('validation: a user of ANOTHER organisation cannot be the assignee (422)', $c === 422, [$c, $j['message'] ?? $j]);
    [$c, $j] = $req('POST', '/gtm/workflows', ['actions' => [['type' => 'create_support_onboarding', 'params' => ['priority' => 'Nonsense'] + $good]]] + $base); $ok('validation: priority must be a Ticket Priority picklist value (422)', $c === 422, [$c, $j['message'] ?? $j]);
    [$c, $j] = $req('POST', '/gtm/workflows', ['actions' => [['type' => 'create_support_onboarding', 'params' => ['faq_category' => 'Nonsense'] + $good]]] + $base); $ok('validation: faq_category must be an FAQ category (422)', $c === 422, [$c, $j['message'] ?? $j]);

    // ---------------- fixtures: a real sale chain ----------------
    $orgId = DB::table('crm_organizations')->insertGetId(['account_no' => 'ORG-VERIFYSP', 'name' => 'Verify SP Co', 'sub_institute_id' => 6, 'assigned_to' => 28, 'created_by' => 28, 'created_at' => now(), 'updated_at' => now()]);
    $conId = DB::table('crm_contacts')->insertGetId(['contact_no' => 'CON-VERIFYSP', 'organization_id' => $orgId, 'first_name' => 'Verify', 'last_name' => 'SP Contact', 'email' => 'verify-sp-contact@example.invalid', 'sub_institute_id' => 6, 'assigned_to' => 28, 'created_by' => 28, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('crm_contacts')->insert(['contact_no' => 'CON-VERIFYSP2', 'organization_id' => $orgId, 'first_name' => 'Verify', 'last_name' => 'SP Second', 'email' => 'verify-sp-second@example.invalid', 'sub_institute_id' => 6, 'assigned_to' => 28, 'created_by' => 28, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('crm_faqs')->insert([['faq_no' => 'FAQ-VSP1', 'question' => 'Verify SP How do I log in?', 'answer' => 'x', 'category' => 'General', 'status' => 'Published', 'sub_institute_id' => 6, 'created_at' => now(), 'updated_at' => now()],
        ['faq_no' => 'FAQ-VSP2', 'question' => 'Verify SP Draft article', 'answer' => 'x', 'category' => 'General', 'status' => 'Draft', 'sub_institute_id' => 6, 'created_at' => now(), 'updated_at' => now()]]);
    DB::table('crm_service_contracts')->insert(['contract_no' => 'SC-VSP1', 'subject' => 'Verify SP Support Plan', 'organization_id' => $orgId, 'contract_status' => 'In Progress', 'sub_institute_id' => 6, 'created_at' => now(), 'updated_at' => now()]);
    [$c, $j] = $req('POST', '/crm/opportunities', ['name' => 'Verify SP Deal', 'assignedTo' => 28, 'organizationId' => $orgId, 'salesStage' => 'Negotiation']); $oid = (int) $j['data']['id']; $oppNo = $j['data']['opportunityNo'] ?? DB::table('crm_opportunities')->where('id', $oid)->value('opportunity_no');
    DB::table('crm_opportunity_contacts')->insert(['opportunity_id' => $oid, 'contact_id' => $conId, 'created_at' => now()]);
    $qId = DB::table('crm_quotes')->insertGetId(['quote_no' => 'QT-VSP1', 'subject' => 'Verify SP Quote', 'organization_id' => $orgId, 'opportunity_id' => $oid, 'quote_stage' => 'Accepted', 'total' => 100, 'subtotal' => 100, 'assigned_to' => 28, 'sub_institute_id' => 6, 'created_at' => now(), 'updated_at' => now()]);
    $soId = DB::table('crm_sales_orders')->insertGetId(['so_no' => 'SO-VSP1', 'subject' => 'Verify SP Order', 'organization_id' => $orgId, 'opportunity_id' => $oid, 'quote_id' => $qId, 'total' => 100, 'subtotal' => 100, 'assigned_to' => 28, 'sub_institute_id' => 6, 'created_at' => now(), 'updated_at' => now()]);
    $invId = DB::table('crm_invoices')->insertGetId(['invoice_no' => 'INV-VSP1', 'subject' => 'Verify SP Invoice', 'organization_id' => $orgId, 'sales_order_id' => $soId, 'total' => 100, 'subtotal' => 100, 'amount_paid' => 0, 'assigned_to' => 28, 'sub_institute_id' => 6, 'created_at' => now(), 'updated_at' => now()]);

    // ---------------- workflows ----------------
    [$c, $wfWon, $j] = $mk(['name' => 'Verify SP Closed Won Onboarding', 'trigger_event' => 'crm.opportunity.stage_changed', 'conditions' => [['field' => 'event.to', 'op' => 'eq', 'value' => 'Closed Won']],
        'actions' => [['type' => 'create_support_onboarding', 'params' => ['title' => 'Verify SP Onboarding {{opportunity.no}}', 'assign_to_user_id' => 28, 'priority' => 'High', 'category' => 'Other Problem', 'faq_category' => 'General']]]]);
    $ok('Closed-Won onboarding workflow saved', $c === 201 && $wfWon > 0, [$c, $j]);
    [$c, $wfPaid, $j] = $mk(['name' => 'Verify SP Paid Onboarding', 'trigger_event' => 'crm.invoice.paid', 'conditions' => [], 'actions' => [['type' => 'create_support_onboarding', 'params' => ['title' => 'Verify SP Onboarding {{invoice.no}}', 'assign_to_user_id' => 28]]]]);
    $ok('Paid-invoice onboarding workflow saved', $c === 201 && $wfPaid > 0, [$c, $j]);
    sleep(1);

    // ---------------- 1. Opportunity Closed Won -> one onboarding ticket ----------------
    $req('PUT', "/crm/opportunities/$oid/stage", ['salesStage' => 'Closed Won']); $deliver('crm.opportunity.stage_changed', $oid);
    $t = $tickets();
    $ok('1: exactly one onboarding ticket was opened', $t->count() === 1 && $t[0]->title === "Verify SP Onboarding $oppNo", $t->pluck('title'));
    $T = $t[0] ?? null;
    $ok('1: it is on the customer\'s Organization, with the Opportunity\'s own Contact, status Open, the rule\'s priority and category', $T && (int) $T->organization_id === $orgId && (int) $T->contact_id === $conId && $T->status === 'Open' && $T->priority === 'High' && $T->category === 'Other Problem' && (int) $T->sub_institute_id === 6, $T);
    $ok('1: it is assigned to the person the rule names', $T && (int) $T->assigned_to === 28);
    $ok('1: the Organization\'s single In Progress Service Contract is linked (never created)', $T && (int) $T->service_contract_id === (int) DB::table('crm_service_contracts')->where('contract_no', 'SC-VSP1')->value('id') && DB::table('crm_service_contracts')->where('organization_id', $orgId)->count() === 1);
    $ok('1: TRACEABILITY: the description lists the REAL Opportunity, Quote, Sales Order and Invoice numbers', $T && str_contains($T->description, "Opportunity $oppNo") && str_contains($T->description, 'Quote QT-VSP1') && str_contains($T->description, 'Sales Order SO-VSP1') && str_contains($T->description, 'Invoice INV-VSP1'), $T->description ?? null);
    $ok('1: the description lists the PUBLISHED FAQ and not the draft one', $T && str_contains($T->description, 'FAQ-VSP1 Verify SP How do I log in?') && ! str_contains($T->description, 'Draft article'), $T->description ?? null);
    $ok('1: a system comment is on the ticket and nothing was invented beyond the records', $T && DB::table('crm_ticket_comments')->where('ticket_id', $T->id)->where('comment', 'like', '[Automation]%')->exists() && str_contains($T->description, 'Nothing on this ticket was filled in beyond the records above'));
    $ce = $T ? $events('crm.ticket.created', (int) $T->id) : collect();
    $ok('1: crm.ticket.created was recorded once (idempotency key), naming the Organization', $ce->count() === 1 && $ce[0]->idempotency_key === "crm.ticket.created:6:{$T->id}" && json_decode($ce[0]->payload, true)['organization_id'] === $orgId, $ce);
    // notification
    $notifier->dispatch($ce[0]); $notifier->dispatch($ce[0]);
    $nn = DB::table('g2g_notification')->where('event_id', $ce[0]->id)->where('channel', 'inapp')->get();
    $ok('1: the assignee got ONE in-app notification (even after two dispatches) naming the ticket and linking to it', $nn->count() === 1 && (int) $nn[0]->user_id === 28 && str_contains($nn[0]->subject, $T->ticket_no) && str_contains((string) $nn[0]->action_url, "/module/crm/support/tickets/{$T->id}") && $nn[0]->recipient_reason === 'ticket_assignee', $nn);
    // idempotency
    $deliver('crm.opportunity.stage_changed', $oid); $e = DB::table('g2g_event')->where('type', 'crm.opportunity.stage_changed')->where('entity_id', $oid)->orderByDesc('id')->first(); app(App\Domain\Gtm\Workflows\WorkflowEngine::class)->handleEvent($e);
    $ok('1: IDEMPOTENT: re-delivering the event opens no second ticket', $tickets()->count() === 1 && DB::table('gtm_workflow_runs')->where('workflow_id', $wfWon)->where('entity_id', $oid)->count() === 1);
    // the same title is never opened twice even by a second run
    $r = DB::table('gtm_workflow_runs')->where('workflow_id', $wfWon)->where('entity_id', $oid)->first();
    DB::table('gtm_workflow_run_steps')->where('run_id', $r->id)->delete(); DB::table('gtm_workflow_runs')->where('id', $r->id)->update(['status' => 'queued', 'finished_at' => null]);
    app(App\Domain\Gtm\Workflows\WorkflowEngine::class)->execute((int) $r->id); $st = DB::table('gtm_workflow_run_steps')->where('run_id', $r->id)->first();
    $ok('1: even a forced re-run of the step refuses a duplicate (same title on the same Organization) and says why', $tickets()->count() === 1 && $st->status === 'skipped' && str_contains($st->result, 'already exists'), $st);

    // ---------------- 2. invoice paid -> its own onboarding (different document number), same chain facts ----------------
    [$c, $j] = $req('POST', "/crm/invoices/$invId/payments", ['amount' => 100, 'method' => 'Bank transfer', 'reference' => 'VERIFY-SP-1']);
    $ok('2: a person recorded the payment; crm.invoice.paid was announced', $c === 201 && $events('crm.invoice.paid', $invId)->count() === 1, [$c]);
    $deliver('crm.invoice.paid', $invId);
    $t = $tickets();
    $ok('2: the paid invoice opened its own ticket (title carries INV-VSP1), same Organization and Contact (found through the sale chain)', $t->count() === 2 && $t->contains(fn ($x) => $x->title === 'Verify SP Onboarding INV-VSP1' && (int) $x->organization_id === $orgId && (int) $x->contact_id === $conId), $t->pluck('title'));
    $second = $t->firstWhere('title', 'Verify SP Onboarding INV-VSP1');
    $ok('2: with no priority in the rule the ticket has none, and its description still lists the whole chain', $second && $second->priority === null && str_contains($second->description, "Opportunity $oppNo") && str_contains($second->description, 'Invoice INV-VSP1'));

    // ---------------- 3. what must NOT open a ticket ----------------
    $before = $tickets()->count();
    [$c, $j] = $req('POST', '/crm/opportunities', ['name' => 'Verify SP Orphan', 'assignedTo' => 28, 'salesStage' => 'Negotiation']); $orph = (int) $j['data']['id'];
    $req('PUT', "/crm/opportunities/$orph/stage", ['salesStage' => 'Closed Won']); $deliver('crm.opportunity.stage_changed', $orph);
    $rr = DB::table('gtm_workflow_runs')->where('workflow_id', $wfWon)->where('entity_id', $orph)->first(); $stp = $rr ? DB::table('gtm_workflow_run_steps')->where('run_id', $rr->id)->first() : null;
    $ok('3: a sale with no Organization opens NO ticket (step skipped, with the reason)', $tickets()->count() === $before && $stp && $stp->status === 'skipped' && str_contains($stp->result, 'no Organization'), $stp);
    [$c, $j] = $req('POST', '/crm/opportunities', ['name' => 'Verify SP Lost', 'assignedTo' => 28, 'organizationId' => $orgId, 'salesStage' => 'Negotiation']); $lost = (int) $j['data']['id'];
    $req('PUT', "/crm/opportunities/$lost/stage", ['salesStage' => 'Closed Lost', 'closeReason' => 'Budget']); $deliver('crm.opportunity.stage_changed', $lost);
    $ok('3: a LOST Opportunity does not trigger onboarding (the rule needs Closed Won)', DB::table('gtm_workflow_runs')->where('workflow_id', $wfWon)->where('entity_id', $lost)->count() === 0 && $tickets()->count() === $before);

    // ---------------- 4. SLA tracking on the onboarding ticket (existing mechanism, dry run) ----------------
    $policy = DB::table('crm_sla_policies')->where('sub_institute_id', 6)->where('priority', 'High')->first();
    if (! $policy) { DB::table('crm_sla_policies')->insert(['priority' => 'High', 'response_hours' => 1, 'escalate_to_user_id' => 28, 'created_by' => 28, 'sub_institute_id' => 6, 'created_at' => now(), 'updated_at' => now()]); $policy = DB::table('crm_sla_policies')->where('sub_institute_id', 6)->where('priority', 'High')->first(); $madePolicy = true; }
    [$c, $j] = $req('GET', "/crm/tickets/{$T->id}");
    $ok('4: the ticket already carries an SLA due time and status from the tenant\'s own policy for its priority', $c === 200 && $j['data']['slaDueAt'] !== null && in_array($j['data']['slaStatus'], ['within_sla', 'breached'], true), [$c, $j['data']['slaDueAt'] ?? null, $j['data']['slaStatus'] ?? null]);
    if ($policy->escalate_to_user_id) {
        DB::table('crm_tickets')->where('id', $T->id)->update(['created_at' => now()->subHours((float) $policy->response_hours + 2)]);
        $dry = TicketSlaEscalator::escalateOverdue(6, true);
        $ok('4: once its response time has passed with no response, the SLA-breach sweep (dry run) would escalate THIS ticket', collect($dry)->contains(fn ($r) => (int) $r['ticket_id'] === (int) $T->id && (int) $r['escalate_to'] === (int) $policy->escalate_to_user_id), count($dry));
    } else { echo "SKIP SLA dry-run (this tenant's High policy has no escalation contact; not changed)\n"; }

    // ---------------- 5. tenant isolation ----------------
    if ($other) {
        $ot = (int) $other->sub_institute_id;
        $oo = DB::table('crm_opportunities')->insertGetId(['opportunity_no' => 'OPP-VSPOTH', 'name' => 'Verify SP Other Tenant', 'sales_stage' => 'Closed Won', 'sub_institute_id' => $ot, 'assigned_to' => $other->id, 'created_by' => $other->id, 'created_at' => now(), 'updated_at' => now()]);
        \App\Domain\Crm\Sales\OpportunityEvents::stageChanged($ot, $oo, 'Negotiation', 'Closed Won', (int) $other->id);
        $oe = DB::table('g2g_event')->where('type', 'crm.opportunity.stage_changed')->where('entity_id', $oo)->first(); $runner->dispatch($oe);
        $ok('5: another tenant\'s Closed Won does not run tenant 6\'s onboarding workflow and opens no ticket anywhere', DB::table('gtm_workflow_runs')->where('entity_type', 'crm_opportunities')->where('entity_id', $oo)->count() === 0 && DB::table('crm_tickets')->where('title', 'like', 'Verify SP%')->where('sub_institute_id', '!=', 6)->count() === 0);
        DB::table('crm_opportunities')->where('id', $oo)->delete();
    }
} finally {
    $cleanup();
    echo 'cleanup: tickets ' . DB::table('crm_tickets')->where('title', 'like', 'Verify SP%')->count() . ', orgs ' . DB::table('crm_organizations')->where('name', 'like', 'Verify SP%')->count() . ', contacts ' . DB::table('crm_contacts')->where('email', 'like', 'verify-sp-%')->count() . ', faqs ' . DB::table('crm_faqs')->where('question', 'like', 'Verify SP%')->count() . ', contracts ' . DB::table('crm_service_contracts')->where('subject', 'like', 'Verify SP%')->count() . ', policy fixture ' . ($madePolicy ? 'removed' : 'none made') . "\n";
}
echo "\n$pass passed, $fail failed\n";
