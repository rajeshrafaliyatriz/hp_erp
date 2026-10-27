<?php
// Step 6 of the CRM lead automation: Opportunity -> Quote -> Sales Order -> Invoice -> payment tracking, every consequential
// step behind an administrator's approval.
// Real HTTP stack, real dev DB, real reactor + notification dispatcher + the REAL approval endpoints. Mail (the quote email and
// notifications) goes to an in-process capture transport. The automation never records a payment: payments here are made through
// the normal payment endpoint, as a person would. Fixtures are named "Verify CM ..." and removed afterwards.
use App\Domain\Crm\Sales\InvoiceSweep;
use App\Services\Events\{GtmWorkflowRunner, NotificationDispatcher};
use Illuminate\Support\Facades\{Cache, DB, Mail};
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

if (! class_exists('CrmCmTransport')) {
    class CrmCmTransport extends AbstractTransport
    {
        public static array $sent = [];
        protected function doSend(SentMessage $message): void { self::$sent[] = $message->getOriginalMessage(); }
        public function __toString(): string { return 'crmcm'; }
    }
}
$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n"; };
$req = function ($m, $u, $b = [], $tok = null) use ($kernel, $A) { Cache::flush(); return call($kernel, $m, $u, $tok ?? $A, $b); };
$runner = app(GtmWorkflowRunner::class); $notifier = app(NotificationDispatcher::class);
$deliver = function (string $type, int $entityId, int $tenant = 6) use ($runner) { $e = DB::table('g2g_event')->where('sub_institute_id', $tenant)->where('type', $type)->where('entity_id', $entityId)->orderByDesc('id')->first(); if ($e) { $runner->dispatch($e); } return $e; };
$events = fn (string $type, int $id) => DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', $type)->where('entity_id', $id)->orderBy('id')->get();
$run = fn (int $wf, int $entityId) => DB::table('gtm_workflow_runs')->where('workflow_id', $wf)->where('entity_id', $entityId)->orderByDesc('id')->first();
$approve = fn ($runRow) => $req('POST', "/gtm/workflow-runs/{$runRow->id}/approve", ['confirm' => true]);
$step = fn ($runRow) => DB::table('gtm_workflow_run_steps')->where('run_id', $runRow->id)->orderBy('step')->first();
$mk = function (array $d) use ($req) { [$c, $j] = $req('POST', '/gtm/workflows', $d); $id = (int) ($j['data']['workflow']['id'] ?? 0); if ($id) { $req('POST', "/gtm/workflows/$id/status", ['status' => 'active']); } return [$c, $id, $j]; };
$ids = ['opp' => [], 'quote' => [], 'so' => [], 'inv' => []]; $otherInv = [];
$cleanup = function () use (&$ids, &$otherInv, $startedAt) {
    $quotes = DB::table('crm_quotes')->where('subject', 'like', 'Verify CM%')->orWhere(fn ($w) => $w->whereIn('opportunity_id', $ids['opp']))->pluck('id')->merge($ids['quote'])->unique();
    $sos = DB::table('crm_sales_orders')->whereIn('quote_id', $quotes)->orWhere('subject', 'like', 'Verify CM%')->pluck('id')->merge($ids['so'])->unique();
    $invs = DB::table('crm_invoices')->whereIn('sales_order_id', $sos)->orWhere('subject', 'like', 'Verify CM%')->pluck('id')->merge($ids['inv'])->merge($otherInv)->unique();
    $evIds = DB::table('g2g_event')->where(fn ($w) => $w->where('entity_type', 'crm_invoices')->whereIn('entity_id', $invs))->orWhere(fn ($w) => $w->where('entity_type', 'crm_quotes')->whereIn('entity_id', $quotes))->orWhere(fn ($w) => $w->where('entity_type', 'crm_sales_orders')->whereIn('entity_id', $sos))->pluck('id');
    DB::table('g2g_notification')->whereIn('event_id', $evIds)->delete();
    $emailIds = DB::table('crm_email_links')->whereIn('linked_type', ['CONTACT', 'OPPORTUNITY', 'INVOICE'])->whereIn('linked_id', array_merge($ids['opp'], DB::table('crm_contacts')->where('email', 'like', 'verify-cm-%')->pluck('id')->all()))->pluck('email_id')->unique();
    DB::table('crm_email_links')->whereIn('email_id', $emailIds)->delete(); DB::table('crm_emails')->whereIn('id', $emailIds)->delete();
    DB::table('crm_invoice_payments')->whereIn('invoice_id', $invs)->delete(); DB::table('crm_invoice_status_history')->whereIn('invoice_id', $invs)->delete(); DB::table('crm_invoice_line_items')->whereIn('invoice_id', $invs)->delete(); DB::table('crm_invoices')->whereIn('id', $invs)->delete();
    DB::table('crm_sales_order_status_history')->whereIn('sales_order_id', $sos)->delete(); DB::table('crm_sales_order_line_items')->whereIn('sales_order_id', $sos)->delete(); DB::table('crm_sales_orders')->whereIn('id', $sos)->delete();
    DB::table('crm_quote_status_history')->whereIn('quote_id', $quotes)->delete(); DB::table('crm_quote_line_items')->whereIn('quote_id', $quotes)->delete(); DB::table('crm_quotes')->whereIn('id', $quotes)->delete();
    $opps = DB::table('crm_opportunities')->where('name', 'like', 'Verify CM%')->pluck('id')->merge($ids['opp'])->unique();
    DB::table('task_management_calendar_events')->where(fn ($w) => $w->where('linked_type', 'OPPORTUNITY')->whereIn('linked_id', $opps))->orWhere(fn ($w) => $w->where('linked_type', 'ORGANIZATION')->whereIn('linked_id', DB::table('crm_organizations')->where('name', 'like', 'Verify CM%')->pluck('id')))->delete();
    DB::table('crm_opportunity_products')->whereIn('opportunity_id', $opps)->delete(); DB::table('crm_opportunity_contacts')->whereIn('opportunity_id', $opps)->delete();
    DB::table('crm_opportunity_stage_history')->whereIn('opportunity_id', $opps)->delete(); DB::table('crm_opportunity_comments')->whereIn('opportunity_id', $opps)->delete(); DB::table('crm_opportunities')->whereIn('id', $opps)->delete();
    DB::table('crm_products')->where('name', 'like', 'Verify CM%')->delete(); DB::table('crm_tax_rates')->where('name', 'like', 'Verify CM%')->delete();
    DB::table('crm_contacts')->where('email', 'like', 'verify-cm-%')->delete(); DB::table('crm_organizations')->where('name', 'like', 'Verify CM%')->delete();
    gtm_verify_cleanup($startedAt);
};
$cleanup(); // a previous interrupted run

Mail::extend('crmcm', fn (array $config) => new CrmCmTransport());
config(['mail.mailers.crmcm' => ['transport' => 'crmcm'], 'mail.default' => 'crmcm', 'mail.from.address' => 'sender@example.invalid', 'app.url' => 'https://api.example.com', 'crm.email_daily_cap' => 500, 'crm.email_sending_enabled' => false]);
Mail::purge();
$captured = get_class(Mail::mailer()->getSymfonyTransport()) === 'CrmCmTransport' && DB::table('smtp_details')->where('sub_institute_id', 6)->count() === 0;
$ok('SAFETY: tenant-6 mail goes to the in-process capture transport', $captured);
if (! $captured) { echo "ABORT\n"; exit(1); }
$otherHasSmtp = $other ? DB::table('smtp_details')->where('sub_institute_id', $other->sub_institute_id)->count() > 0 : true;

try {
    // ---------------- catalogue + validation ----------------
    [$c, $j] = $req('GET', '/gtm/workflows/catalog'); $cat = $j['data'];
    $ok('catalogue: the four commercial actions are all CONSEQUENTIAL (approval) and the invoice task is not', collect(['prepare_quote', 'email_quote', 'create_sales_order', 'create_invoice'])->every(fn ($a) => ($cat['actions'][$a]['consequential'] ?? false) === true) && ($cat['actions']['create_invoice_task']['consequential'] ?? true) === false);
    $ok('catalogue: quote / sales order / invoice triggers carry their facts', isset($cat['triggers']['crm.quote.stage_changed']['fields']['quote.total'], $cat['triggers']['crm.sales_order.stage_changed']['fields']['sales_order.lines_count'], $cat['triggers']['crm.invoice.overdue']['fields']['invoice.balance_due'], $cat['triggers']['crm.invoice.paid']), array_keys($cat['triggers']));
    $ok('catalogue: there is NO action that records a payment or changes an invoice stage', ! collect(array_keys($cat['actions']))->contains(fn ($a) => preg_match('/payment|pay_|invoice_stage|record_/', $a)), array_keys($cat['actions']));
    [$c, $j] = $req('POST', '/gtm/workflows', ['name' => 'Verify CM Bad', 'trigger_event' => 'crm.lead.created', 'conditions' => [], 'actions' => [['type' => 'create_invoice', 'params' => []]]]); $ok('validation: create_invoice needs a Sales Order trigger (422)', $c === 422, [$c, $j['message'] ?? $j]);
    [$c, $j] = $req('POST', '/gtm/workflows', ['name' => 'Verify CM Bad', 'trigger_event' => 'crm.sales_order.created', 'conditions' => [], 'actions' => [['type' => 'create_invoice', 'params' => ['due_in_days' => 999]]]]); $ok('validation: due_in_days is bounded (422)', $c === 422, [$c, $j['message'] ?? $j]);
    [$c, $j] = $req('POST', '/gtm/workflows', ['name' => 'Verify CM Bad', 'trigger_event' => 'crm.quote.created', 'conditions' => [], 'actions' => [['type' => 'create_sales_order', 'params' => ['required_stage' => 'Nonsense']]]]); $ok('validation: required_stage must be a Quote stage (422)', $c === 422, [$c, $j['message'] ?? $j]);
    [$c, $j] = $req('POST', '/gtm/workflows', ['name' => 'Verify CM Bad', 'trigger_event' => 'crm.quote.created', 'conditions' => [], 'actions' => [['type' => 'email_quote', 'params' => ['subject' => 'x', 'body' => 'Hi {{quote.contact_email}}']]]]); $ok('validation: a placeholder for the contact\'s address is refused (422)', $c === 422, [$c, $j['message'] ?? $j]);

    [$c, $j] = $req('POST', '/gtm/workflows', ['name' => 'Verify CM Bad', 'trigger_event' => 'crm.opportunity.stage_changed', 'conditions' => [['field' => 'event.to', 'op' => 'eq', 'value' => 'Proposal']], 'actions' => [['type' => 'prepare_quote', 'params' => (object) []]]]);
    $ok('validation: a stage rule on a value that is not in the Sales Stage list ("Proposal") is refused, and the message lists the real stages', $c === 422 && str_contains(stripslashes(json_encode($j)), 'Proposal/Quotation'), [$c, $j['message'] ?? $j]);
    [$c, $j] = $req('POST', '/gtm/workflows', ['name' => 'Verify CM Bad', 'trigger_event' => 'crm.sales_order.stage_changed', 'conditions' => [['field' => 'event.to', 'op' => 'eq', 'value' => 'Delivered']], 'actions' => [['type' => 'create_invoice', 'params' => (object) []]]]);
    $ok('validation: "Delivered" is not a Sales Order stage here (Draft, Confirmed, Processing, Shipped, Completed, Cancelled): refused (422)', $c === 422 && str_contains(json_encode($j), 'Shipped'), [$c, $j['message'] ?? $j]);

    // ---------------- workflows ----------------
    [, $wfPrep] = $mk(['name' => 'Verify CM Prepare Quote', 'trigger_event' => 'crm.opportunity.stage_changed', 'conditions' => [['field' => 'event.to', 'op' => 'eq', 'value' => 'Proposal/Quotation']], 'actions' => [['type' => 'prepare_quote', 'params' => (object) []]]]);
    [, $wfSend] = $mk(['name' => 'Verify CM Email Quote', 'trigger_event' => 'crm.quote.created', 'conditions' => [['field' => 'quote.contact_has_email', 'op' => 'eq', 'value' => true], ['field' => 'quote.lines_count', 'op' => 'gt', 'value' => 0]], 'actions' => [['type' => 'email_quote', 'params' => ['subject' => 'Your quote {{quote.no}}', 'body' => 'Hello, please find our quote below. Reply to this email to accept or ask for changes.']]]]);
    [, $wfSo] = $mk(['name' => 'Verify CM Accepted Quote', 'trigger_event' => 'crm.quote.stage_changed', 'conditions' => [['field' => 'event.to', 'op' => 'eq', 'value' => 'Accepted']], 'actions' => [['type' => 'create_sales_order', 'params' => (object) []]]]);
    [, $wfInv] = $mk(['name' => 'Verify CM Delivered Order', 'trigger_event' => 'crm.sales_order.stage_changed', 'conditions' => [['field' => 'event.to', 'op' => 'eq', 'value' => 'Shipped']], 'actions' => [['type' => 'create_invoice', 'params' => ['due_in_days' => 30, 'required_stage' => 'Shipped']]]]);
    [, $wfOver] = $mk(['name' => 'Verify CM Invoice Overdue', 'trigger_event' => 'crm.invoice.overdue', 'conditions' => [], 'actions' => [['type' => 'create_invoice_task', 'params' => ['title' => 'Verify chase {{invoice.no}} ({{invoice.days_overdue}} days overdue)', 'due_in_days' => 0, 'description' => '{{invoice.balance_due}} {{invoice.currency}} unpaid.']]]]);
    [, $wfPaid] = $mk(['name' => 'Verify CM Invoice Paid', 'trigger_event' => 'crm.invoice.paid', 'conditions' => [], 'actions' => [['type' => 'create_invoice_task', 'params' => ['title' => 'Verify thank the customer for {{invoice.no}}', 'due_in_days' => 1]]]]);
    $ok('all Step 6 workflows saved and active', collect([$wfPrep, $wfSend, $wfSo, $wfInv, $wfOver, $wfPaid])->every(fn ($w) => $w > 0));
    sleep(1);

    // ---------------- fixtures (products with real list prices; one taxed) ----------------
    $orgId = DB::table('crm_organizations')->insertGetId(['account_no' => 'ORG-VERIFYCM', 'name' => 'Verify CM Co', 'sub_institute_id' => 6, 'assigned_to' => 28, 'created_by' => 28, 'created_at' => now(), 'updated_at' => now()]);
    $conId = DB::table('crm_contacts')->insertGetId(['contact_no' => 'CON-VERIFYCM', 'organization_id' => $orgId, 'first_name' => 'Verify', 'last_name' => 'CM Contact', 'email' => 'verify-cm-contact@example.invalid', 'sub_institute_id' => 6, 'assigned_to' => 28, 'created_by' => 28, 'created_at' => now(), 'updated_at' => now()]);
    $taxId = DB::table('crm_tax_rates')->insertGetId(['name' => 'Verify CM Tax', 'percentage' => 10, 'is_active' => 1, 'sub_institute_id' => 6, 'created_at' => now(), 'updated_at' => now()]);
    $mkProd = fn (string $n, $price, ?int $tax = null, string $cur = 'INR', int $active = 1) => DB::table('crm_products')->insertGetId(['item_type' => 'product', 'product_no' => 'PRD-VCM' . strtoupper(substr(md5($n), 0, 6)), 'name' => "Verify CM $n", 'unit_price' => $price, 'currency' => $cur, 'tax_rate_id' => $tax, 'is_active' => $active, 'sub_institute_id' => 6, 'created_at' => now(), 'updated_at' => now()]);
    $p1 = $mkProd('Widget', 100.00, $taxId); $p2 = $mkProd('Gadget', 250.50);
    $mkOpp = function (string $name, array $products = [], bool $withContact = true, ?int $org = null) use ($req, $orgId, $conId, &$ids) {
        [$c, $j] = $req('POST', '/crm/opportunities', ['name' => "Verify CM $name", 'assignedTo' => 28, 'organizationId' => $org ?? $orgId, 'salesStage' => 'Prospecting']); $id = (int) ($j['data']['id'] ?? 0); $ids['opp'][] = $id;
        foreach ($products as [$pid, $qty]) { DB::table('crm_opportunity_products')->insert(['opportunity_id' => $id, 'product_id' => $pid, 'quantity' => $qty, 'created_at' => now()]); }
        if ($withContact) { DB::table('crm_opportunity_contacts')->insert(['opportunity_id' => $id, 'contact_id' => $conId, 'created_at' => now()]); }
        return $id;
    };

    // ---------------- 1. Opportunity reaches Proposal -> a DRAFT quote is prepared (after approval) ----------------
    $o1 = $mkOpp('Deal One', [[$p1, 2], [$p2, 1]]);
    $req('PUT', "/crm/opportunities/$o1/stage", ['salesStage' => 'Proposal/Quotation']); $deliver('crm.opportunity.stage_changed', $o1);
    $r = $run($wfPrep, $o1);
    $ok('1: moving the Opportunity to Proposal queued the quote preparation FOR APPROVAL - nothing created yet', $r && $r->status === 'pending_approval' && DB::table('crm_quotes')->where('opportunity_id', $o1)->count() === 0, $r->status ?? null);
    [$c] = call($kernel, 'POST', "/gtm/workflow-runs/{$r->id}/approve", $E, ['confirm' => true]); $ok('1: an employee cannot approve (403)', $c === 403, $c);
    [$c] = $approve($r);
    $q1 = DB::table('crm_quotes')->where('opportunity_id', $o1)->first(); $ids['quote'][] = $q1->id ?? 0;
    $lines = DB::table('crm_quote_line_items')->where('quote_id', $q1->id ?? 0)->orderBy('sequence_no')->get();
    $ok('1: after approval a Quote exists for the Opportunity: stage Draft, its Organization and Contact, owner = the Opportunity owner', $c === 200 && $q1 && $q1->quote_stage === 'Draft' && (int) $q1->organization_id === $orgId && (int) $q1->contact_id === $conId && (int) $q1->assigned_to === 28 && (int) $q1->sub_institute_id === 6, [$c, $q1]);
    $ok('1: its lines are the Opportunity\'s products at their REAL list prices and tax (2 x 100.00 @10% + 1 x 250.50), nothing invented', $lines->count() === 2 && (float) $lines[0]->unit_price === 100.0 && (float) $lines[0]->quantity === 2.0 && (float) $lines[0]->tax_percent_snapshot === 10.0 && (float) $lines[0]->line_total === 220.0 && (float) $lines[1]->unit_price === 250.5 && (float) $lines[1]->line_total === 250.5 && (float) $q1->subtotal === 450.5 && (float) $q1->tax_total === 20.0 && (float) $q1->total === 470.5, [$lines, $q1]);
    // parity with the controller's own line-item maths
    [$c, $j] = $req('POST', '/crm/quotes', ['subject' => 'Verify CM Parity Quote', 'assignedTo' => 28, 'organizationId' => $orgId]); $pq = (int) $j['data']['id']; $ids['quote'][] = $pq;
    [$c, $j] = $req('PUT', "/crm/quotes/$pq/line-items", ['lineItems' => [['productId' => $p1, 'description' => 'Verify CM Widget', 'quantity' => 2, 'unitPrice' => 100, 'taxRateId' => $taxId], ['productId' => $p2, 'description' => 'Verify CM Gadget', 'quantity' => 1, 'unitPrice' => 250.5]]]);
    $pqRow = DB::table('crm_quotes')->find($pq);
    $ok('1: PARITY: the same two lines saved through the normal Quote screen API give the same subtotal, tax and total as the automatic Quote', $c === 200 && (float) $pqRow->subtotal === (float) $q1->subtotal && (float) $pqRow->tax_total === (float) $q1->tax_total && (float) $pqRow->total === (float) $q1->total, [$c, $pqRow->total, $q1->total]);
    $ok('1: the Opportunity has a note about the draft, and crm.quote.created was recorded (with the Opportunity id)', DB::table('crm_opportunity_comments')->where('opportunity_id', $o1)->where('comment', 'like', '%draft Quote%')->exists() && ($qe = $events('crm.quote.created', (int) $q1->id))->count() === 1 && json_decode($qe[0]->payload, true)['opportunity_id'] === $o1, $qe ?? null);
    // retry / second move does not make a second quote
    $req('PUT', "/crm/opportunities/$o1/stage", ['salesStage' => 'Negotiation']); $req('PUT', "/crm/opportunities/$o1/stage", ['salesStage' => 'Proposal/Quotation']);
    $e2 = DB::table('g2g_event')->where('type', 'crm.opportunity.stage_changed')->where('entity_id', $o1)->orderByDesc('id')->first(); $runner->dispatch($e2);
    $r2 = DB::table('gtm_workflow_runs')->where('workflow_id', $wfPrep)->where('entity_id', $o1)->orderByDesc('id')->first();
    if ($r2 && $r2->status === 'pending_approval') { $approve($r2); }
    $ok('1: IDEMPOTENT: moving back to Proposal and approving again prepares NO second quote (a live one exists) and says why on the Opportunity', DB::table('crm_quotes')->where('opportunity_id', $o1)->count() === 1 && DB::table('crm_opportunity_comments')->where('opportunity_id', $o1)->where('comment', 'like', '%already has a live Quote%')->exists());
    // refusals that must not guess
    $oNoProd = $mkOpp('No Products', []); $oNoPrice = $mkOpp('No Price', [[$mkProd('Unpriced', null), 1]]); $oMixed = $mkOpp('Mixed Currency', [[$p2, 1], [$mkProd('Dollar', 5, null, 'USD'), 1]]); $oNoOrg = $mkOpp('No Org', [[$p2, 1]]); DB::table('crm_opportunities')->where('id', $oNoOrg)->update(['organization_id' => null]);
    foreach ([[$oNoProd, 'no products'], [$oNoPrice, 'no usable list price for: Verify CM Unpriced'], [$oMixed, 'different currencies'], [$oNoOrg, 'no Organization']] as [$oid, $why]) {
        $req('PUT', "/crm/opportunities/$oid/stage", ['salesStage' => 'Proposal/Quotation']); $deliver('crm.opportunity.stage_changed', $oid); $rr = $run($wfPrep, $oid); if ($rr) { $approve($rr); }
        $st = $rr ? $step($rr) : null;
        $ok("1: refused when $why - no quote made, the reason is on the run and the Opportunity", $st && $st->status === 'skipped' && str_contains($st->result, $why) && DB::table('crm_quotes')->where('opportunity_id', $oid)->count() === 0 && DB::table('crm_opportunity_comments')->where('opportunity_id', $oid)->where('comment', 'like', "%{$why}%")->exists(), [$st]);
    }

    // ---------------- 2. emailing the quote: approval, sending off = honest, sending on = real send ----------------
    $deliver('crm.quote.created', (int) $q1->id); $rs = $run($wfSend, (int) $q1->id);
    $ok('2: crm.quote.created queued the email FOR APPROVAL (a quote is a commitment)', $rs && $rs->status === 'pending_approval', $rs->status ?? null);
    $approve($rs); $res = json_decode($step($rs)->result, true); $em = DB::table('crm_emails')->where('id', $res['email_id'] ?? 0)->first();
    $ok('2: with CRM email sending OFF the email is prepared (Queued) but NOT sent, and the quote stays Draft', $em && $em->status === 'Queued' && $res['sent'] === false && DB::table('crm_quotes')->find($q1->id)->quote_stage === 'Draft' && count(CrmCmTransport::$sent) === 0, [$res, $em->status ?? null]);
    $links = DB::table('crm_email_links')->where('email_id', $em->id)->get();
    $ok('2: the email is shown on the contact AND on the Opportunity (no opt-out bypass: the contact is the opt-out target)', $links->contains(fn ($l) => $l->linked_type === 'CONTACT' && (int) $l->linked_id === $conId) && $links->contains(fn ($l) => $l->linked_type === 'OPPORTUNITY' && (int) $l->linked_id === $o1));
    // a second draft quote, with sending ON
    DB::table('crm_quotes')->where('id', $q1->id)->update(['quote_stage' => 'Rejected']); // retire the first so the builder allows another
    $o2 = $mkOpp('Deal Two', [[$p2, 1]]);
    $req('PUT', "/crm/opportunities/$o2/stage", ['salesStage' => 'Proposal/Quotation']); $deliver('crm.opportunity.stage_changed', $o2); $approve($run($wfPrep, $o2));
    $q2 = DB::table('crm_quotes')->where('opportunity_id', $o2)->first(); $ids['quote'][] = $q2->id;
    config(['crm.email_sending_enabled' => true]);
    $deliver('crm.quote.created', (int) $q2->id); $rs2 = $run($wfSend, (int) $q2->id); $approve($rs2); $res2 = json_decode($step($rs2)->result, true);
    $msg = end(CrmCmTransport::$sent) ?: null;
    $ok('2: with sending ON it is delivered to the contact\'s real address, with the quote number, the line and the total, and an unsubscribe header', $res2['sent'] === true && $msg && $msg->getTo()[0]->getAddress() === 'verify-cm-contact@example.invalid' && str_contains($msg->getSubject(), $q2->quote_no) && str_contains($msg->getTextBody(), 'Verify CM Gadget') && str_contains($msg->getTextBody(), 'Total: 250.50') && $msg->getHeaders()->has('List-Unsubscribe'), [$res2, $msg ? $msg->getSubject() : null]);
    $ok('2: ONLY because it really went out was the quote moved to Sent - with history and a stage-changed event marked as workflow-caused', DB::table('crm_quotes')->find($q2->id)->quote_stage === 'Sent' && DB::table('crm_quote_status_history')->where('quote_id', $q2->id)->where('to_stage', 'Sent')->exists() && str_contains((string) $events('crm.quote.stage_changed', (int) $q2->id)->last()->metadata, 'workflow'));
    // opted-out contact
    DB::table('crm_contacts')->where('id', $conId)->update(['email_opt_out' => 1]);
    $o3 = $mkOpp('Deal Three', [[$p2, 1]]); $req('PUT', "/crm/opportunities/$o3/stage", ['salesStage' => 'Proposal/Quotation']); $deliver('crm.opportunity.stage_changed', $o3); $approve($run($wfPrep, $o3));
    $q3 = DB::table('crm_quotes')->where('opportunity_id', $o3)->first(); $ids['quote'][] = $q3->id; $n = count(CrmCmTransport::$sent);
    $deliver('crm.quote.created', (int) $q3->id); $rs3 = $run($wfSend, (int) $q3->id); $approve($rs3);
    $ok('2: a contact who opted out is NOT emailed (step skipped, quote stays Draft, nothing sent)', $step($rs3)->status === 'skipped' && count(CrmCmTransport::$sent) === $n && DB::table('crm_quotes')->find($q3->id)->quote_stage === 'Draft');
    DB::table('crm_contacts')->where('id', $conId)->update(['email_opt_out' => 0]);
    config(['crm.email_sending_enabled' => false]);

    // ---------------- 3. the customer accepts (a person records it) -> Sales Order after approval ----------------
    [$c, $j] = $req('PUT', "/crm/quotes/{$q2->id}", ['quoteStage' => 'Accepted']);
    $qs = $events('crm.quote.stage_changed', (int) $q2->id)->last();
    $ok('3: a person marking the Quote Accepted announces crm.quote.stage_changed (actor 28, not a workflow)', $c === 200 && json_decode($qs->payload, true)['to'] === 'Accepted' && (int) $qs->actor_id === 28 && ! str_contains((string) $qs->metadata, 'workflow'), [$c, $qs]);
    $deliver('crm.quote.stage_changed', (int) $q2->id); $ro = $run($wfSo, (int) $q2->id);
    $ok('3: the Sales Order waits for approval', $ro && $ro->status === 'pending_approval' && DB::table('crm_sales_orders')->where('quote_id', $q2->id)->count() === 0);
    $approve($ro); $so = DB::table('crm_sales_orders')->where('quote_id', $q2->id)->first(); $ids['so'][] = $so->id ?? 0;
    $soLines = DB::table('crm_sales_order_line_items')->where('sales_order_id', $so->id ?? 0)->get();
    $ok('3: approved: one Sales Order from the Quote - same total, same lines, owner = the Quote\'s owner, linked to the Quote and Opportunity', $so && (float) $so->total === (float) DB::table('crm_quotes')->find($q2->id)->total && $soLines->count() === 1 && (int) $so->assigned_to === 28 && (int) $so->opportunity_id === $o2 && (int) $so->organization_id === $orgId, [$so, $soLines->count()]);
    // parity with the controller's own fromQuote
    [$c, $j] = $req('POST', "/crm/sales-orders/from-quote/{$q2->id}"); $ids['so'][] = (int) ($j['data']['id'] ?? 0);
    $ok('3: PARITY: the Sales Order the normal "Create Sales Order from Quote" button makes has the same total and lines', $c === 201 && (float) DB::table('crm_sales_orders')->find($j['data']['id'])->total === (float) $so->total && DB::table('crm_sales_order_line_items')->where('sales_order_id', $j['data']['id'])->count() === $soLines->count(), [$c, $j['data']['total'] ?? null]);
    $ok('3: crm.sales_order.created was announced (by the automation and by the button) with the Quote id', $events('crm.sales_order.created', (int) $so->id)->count() === 1 && json_decode($events('crm.sales_order.created', (int) $so->id)[0]->payload, true)['quote_id'] === (int) $q2->id);
    $deliver('crm.quote.stage_changed', (int) $q2->id); $dupSo = DB::table('crm_sales_orders')->where('quote_id', $q2->id)->count();
    $ok('3: IDEMPOTENT: re-delivering the acceptance creates no further run (the one the button made is the only extra Sales Order)', DB::table('gtm_workflow_runs')->where('workflow_id', $wfSo)->where('entity_id', $q2->id)->count() === 1 && $dupSo === 2, $dupSo);
    // stale approval: quote revised before the approval arrives
    $o4 = $mkOpp('Deal Four', [[$p2, 1]]); $req('PUT', "/crm/opportunities/$o4/stage", ['salesStage' => 'Proposal/Quotation']); $deliver('crm.opportunity.stage_changed', $o4); $approve($run($wfPrep, $o4));
    $q4 = DB::table('crm_quotes')->where('opportunity_id', $o4)->first(); $ids['quote'][] = $q4->id;
    $req('PUT', "/crm/quotes/{$q4->id}", ['quoteStage' => 'Accepted']); $deliver('crm.quote.stage_changed', (int) $q4->id); $r4 = $run($wfSo, (int) $q4->id);
    $req('PUT', "/crm/quotes/{$q4->id}", ['quoteStage' => 'Revised']); $approve($r4);
    $ok('3: an approval that arrives after the customer asked for changes (Revised) creates NO Sales Order', $step($r4)->status === 'skipped' && DB::table('crm_sales_orders')->where('quote_id', $q4->id)->count() === 0);

    // ---------------- 4. delivered -> invoice after approval; never a payment ----------------
    $soId = (int) $so->id;
    [$c, $j] = $req('PUT', "/crm/sales-orders/$soId/stage", ['soStage' => 'Shipped']);
    $deliver('crm.sales_order.stage_changed', $soId); $ri = $run($wfInv, $soId);
    $ok('4: marking the Sales Order Delivered queued the invoice FOR APPROVAL', $c === 200 && $ri && $ri->status === 'pending_approval' && DB::table('crm_invoices')->where('sales_order_id', $soId)->count() === 0, [$c, $ri->status ?? null]);
    $approve($ri); $inv = DB::table('crm_invoices')->where('sales_order_id', $soId)->first(); $ids['inv'][] = $inv->id ?? 0;
    $ok('4: approved: an Invoice from the Sales Order with the same total, the due date from the rule (today + 30), owner = the order\'s owner', $inv && (float) $inv->total === (float) $so->total && $inv->due_date === now()->addDays(30)->toDateString() && (int) $inv->assigned_to === 28 && DB::table('crm_invoice_line_items')->where('invoice_id', $inv->id)->count() === 1, [$inv]);
    $ok('4: NO payment was recorded and the invoice stage was not set: amount_paid 0, no ledger rows', (float) $inv->amount_paid === 0.0 && DB::table('crm_invoice_payments')->where('invoice_id', $inv->id)->count() === 0 && $inv->invoice_stage === null);
    [$c, $j] = $req('POST', "/crm/invoices/from-sales-order/$soId"); $ids['inv'][] = (int) ($j['data']['id'] ?? 0);
    $ok('4: PARITY: the Invoice the normal "Create Invoice" button makes from the same order has the same total and lines', $c === 201 && (float) DB::table('crm_invoices')->find($j['data']['id'])->total === (float) $inv->total && DB::table('crm_invoice_line_items')->where('invoice_id', $j['data']['id'])->count() === 1, [$c]);
    $o5 = $mkOpp('Deal Five', [[$p2, 1]]); $req('PUT', "/crm/opportunities/$o5/stage", ['salesStage' => 'Proposal/Quotation']); $deliver('crm.opportunity.stage_changed', $o5); $approve($run($wfPrep, $o5));
    $q5 = DB::table('crm_quotes')->where('opportunity_id', $o5)->first(); $ids['quote'][] = $q5->id;
    [$c, $j] = $req('POST', "/crm/sales-orders/from-quote/{$q5->id}"); $so5 = (int) $j['data']['id']; $ids['so'][] = $so5;
    $req('PUT', "/crm/sales-orders/$so5/stage", ['soStage' => 'Shipped']); $deliver('crm.sales_order.stage_changed', $so5); $r5 = $run($wfInv, $so5);
    $req('PUT', "/crm/sales-orders/$so5/stage", ['soStage' => 'Cancelled']); $approve($r5);
    $ok('4: a Sales Order cancelled before the approval arrives gets NO invoice', $step($r5)->status === 'skipped' && DB::table('crm_invoices')->where('sales_order_id', $so5)->count() === 0);

    // ---------------- 5. payment tracking: a person records payments; "paid" is announced once ----------------
    $invId = (int) $inv->id;
    [$c, $j] = $req('POST', "/crm/invoices/$invId/payments", ['amount' => 100, 'method' => 'Bank transfer', 'reference' => 'VERIFY-CM-1']);
    $ok('5: a partial payment recorded by a person does NOT announce "paid"', $c === 201 && $events('crm.invoice.paid', $invId)->count() === 0, [$c, $j['message'] ?? null]);
    $rest = round((float) $inv->total - 100, 2);
    [$c, $j] = $req('POST', "/crm/invoices/$invId/payments", ['amount' => $rest, 'method' => 'Bank transfer', 'reference' => 'VERIFY-CM-2']);
    $pe = $events('crm.invoice.paid', $invId);
    $ok('5: the payment that clears the balance announces crm.invoice.paid once, with the total', $c === 201 && $pe->count() === 1 && (float) json_decode($pe[0]->payload, true)['total'] === (float) $inv->total && (int) $pe[0]->actor_id === 28, [$c, $pe]);
    $deliver('crm.invoice.paid', $invId); $deliver('crm.invoice.paid', $invId);
    $ok('5: the paid rule made one task on the Organization (idempotent)', DB::table('task_management_calendar_events')->where('linked_type', 'ORGANIZATION')->where('linked_id', $orgId)->where('title', 'like', 'Verify thank the customer for INV-%')->whereNull('deleted_at')->count() === 1 && DB::table('gtm_workflow_runs')->where('workflow_id', $wfPaid)->where('entity_id', $invId)->count() === 1);

    // ---------------- 6. overdue invoices: weekly, notified, never touched ----------------
    $past = now()->subDays(12)->toDateString();
    $mkInv = fn (string $n, array $over) => DB::table('crm_invoices')->insertGetId($over + ['invoice_no' => 'INV-VCM' . strtoupper(substr(md5($n), 0, 5)), 'subject' => "Verify CM $n", 'organization_id' => $orgId, 'currency' => 'INR', 'subtotal' => 500, 'total' => 500, 'amount_paid' => 0, 'assigned_to' => 28, 'sub_institute_id' => 6, 'created_by' => 28, 'created_at' => now(), 'updated_at' => now()]);
    $iLate = $mkInv('Late', ['due_date' => $past]); $iPartial = $mkInv('Partial', ['due_date' => $past, 'amount_paid' => 200]); $iPaid = $mkInv('PaidUp', ['due_date' => $past, 'amount_paid' => 500]);
    $iFuture = $mkInv('Future', ['due_date' => now()->addDays(5)->toDateString()]); $iNoDue = $mkInv('NoDue', ['due_date' => null]); $iZero = $mkInv('Zero', ['due_date' => $past, 'total' => 0, 'subtotal' => 0]);
    array_push($ids['inv'], $iLate, $iPartial, $iPaid, $iFuture, $iNoDue, $iZero);
    $all = [$iLate, $iPartial, $iPaid, $iFuture, $iNoDue, $iZero];
    InvoiceSweep::run(500, $all);
    $flagged = collect($all)->filter(fn ($i) => $events('crm.invoice.overdue', $i)->count() === 1)->all();
    $ok('6: only invoices with a balance past their due date are flagged (late, partial) - not paid, not future, not undated, not zero', array_values($flagged) === [$iLate, $iPartial], $flagged);
    $pl = json_decode($events('crm.invoice.overdue', $iPartial)[0]->payload, true);
    $ok('6: the payload carries the real balance (500 - 200) and 12 days', (float) $pl['balance_due'] === 300.0 && $pl['days_overdue'] === 12, $pl);
    InvoiceSweep::run(500, $all);
    $ok('6: IDEMPOTENT: a second sweep the same week records nothing new', $events('crm.invoice.overdue', $iLate)->count() === 1 && $events('crm.invoice.overdue', $iPartial)->count() === 1);
    $deliver('crm.invoice.overdue', $iLate);
    $ok('6: the workflow made a "chase" task on the Organization for the owner, with real figures in its text', DB::table('task_management_calendar_events')->where('linked_type', 'ORGANIZATION')->where('linked_id', $orgId)->where('title', 'like', 'Verify chase INV-VCM%(12 days overdue)')->where('owner_id', 28)->exists());
    $ev = $events('crm.invoice.overdue', $iLate)[0]; $notifier->dispatch($ev); $notifier->dispatch($ev);
    $nn = DB::table('g2g_notification')->where('event_id', $ev->id)->where('channel', 'inapp')->get();
    $ok('6: the OWNER was notified (once, even after dispatching twice), naming the invoice and linking to it', $nn->count() === 1 && (int) $nn[0]->user_id === 28 && str_contains($nn[0]->subject, 'INV-VCM') && str_contains((string) $nn[0]->action_url, "/module/crm/sales/invoices/$iLate") && $nn[0]->recipient_reason === 'invoice_owner', $nn);
    $ok('6: the sweep changed nothing on the invoices (balances and payments untouched)', (float) DB::table('crm_invoices')->find($iLate)->amount_paid === 0.0 && DB::table('crm_invoice_payments')->whereIn('invoice_id', $all)->count() === 0);

    // ---------------- 7. tenant isolation ----------------
    if ($other) {
        $ot = (int) $other->sub_institute_id;
        $oi = DB::table('crm_invoices')->insertGetId(['invoice_no' => 'INV-VCMOTH', 'subject' => 'Verify CM Other Tenant', 'currency' => 'INR', 'subtotal' => 90, 'total' => 90, 'amount_paid' => 0, 'due_date' => $past, 'assigned_to' => $other->id, 'sub_institute_id' => $ot, 'created_by' => $other->id, 'created_at' => now(), 'updated_at' => now()]); $otherInv[] = $oi;
        InvoiceSweep::run(500, [$oi]);
        $oe = DB::table('g2g_event')->where('type', 'crm.invoice.overdue')->where('entity_id', $oi)->first();
        $ok('7: another tenant\'s overdue invoice is reported under THAT tenant', $oe && (int) $oe->sub_institute_id === $ot);
        $runner->dispatch($oe);
        $who = app(App\Services\Notifications\RecipientResolver::class)->forEvent($oe);
        $ok('7: tenant 6\'s workflows did not run for it, and only that tenant\'s own owner would be notified (resolved, not sent)', DB::table('gtm_workflow_runs')->where('entity_type', 'crm_invoices')->where('entity_id', $oi)->count() === 0 && count($who) === 1 && (int) $who[0]['user_id'] === (int) $other->id, $who);
    }
} finally {
    $cleanup();
    echo 'cleanup: quotes ' . DB::table('crm_quotes')->where('subject', 'like', 'Verify CM%')->count() . ', sales orders ' . DB::table('crm_sales_orders')->where('subject', 'like', 'Verify CM%')->count() . ', invoices ' . DB::table('crm_invoices')->where('subject', 'like', 'Verify CM%')->count() . ', opps ' . DB::table('crm_opportunities')->where('name', 'like', 'Verify CM%')->count() . ', products ' . DB::table('crm_products')->where('name', 'like', 'Verify CM%')->count() . ', orgs ' . DB::table('crm_organizations')->where('name', 'like', 'Verify CM%')->count() . "\n";
}
echo "\n$pass passed, $fail failed\n";
