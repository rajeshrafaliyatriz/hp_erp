<?php
/*
 * Test kit for walking ONE lead through the CRM lead automation by hand, from the hp_erp folder:
 *
 *   php tests/Integration/Gtm/tools/lead-automation-testkit.php status          --lead=ID     what the automation has done so far (use after every step)
 *   php tests/Integration/Gtm/tools/lead-automation-testkit.php replay-created  --lead=ID     record a fresh "lead created" event so the INTAKE workflows see an old lead
 *   php tests/Integration/Gtm/tools/lead-automation-testkit.php deliver         --lead=ID     hand this lead's (and its Opportunity/Quote/Order/Invoice/ticket's) new events to the workflows now
 *   php tests/Integration/Gtm/tools/lead-automation-testkit.php mark-sent       --lead=ID     (sending OFF) pretend the owner sent the Queued follow-up email by hand - sends NOTHING
 *   php tests/Integration/Gtm/tools/lead-automation-testkit.php advance         --lead=ID     resume this lead's cadence / make its next follow-up due and run just that attempt
 *   php tests/Integration/Gtm/tools/lead-automation-testkit.php reply           --lead=ID [--text="..."] [--kind=reply|auto|bounce|soft|complaint] [--unthreaded]   send a SIGNED test reply to the webhook
 *   php tests/Integration/Gtm/tools/lead-automation-testkit.php age-opportunity --opp=ID --days=20   pretend an Opportunity has been quiet that long, then run the weekly check for it
 *   php tests/Integration/Gtm/tools/lead-automation-testkit.php overdue-invoice --invoice=ID          run the overdue check for one invoice (give it a past due date first)
 *   php tests/Integration/Gtm/tools/lead-automation-testkit.php notify          --lead=ID     deliver in-app notifications for this lead's records (email channel forced to a null mailer)
 *
 * SAFETY: every command touches ONLY the records of the lead/opportunity/invoice you name (never other people's data on a shared database).
 * `advance` refuses to run when CRM email sending is enabled; while sending is off, it only queues follow-up work.
 * `notify` can never send email (the mailer is replaced by a null one for that command).
 */
chdir(dirname(__DIR__, 4));
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Crm\Leads\LeadFollowUp;
use App\Domain\Crm\Sales\InvoiceSweep;
use App\Domain\Crm\Sales\OpportunitySweep;
use App\Domain\Gtm\GtmAudit;
use App\Services\Events\GtmWorkflowRunner;
use App\Services\Events\NotificationDispatcher;
use Illuminate\Support\Facades\DB;

$cmd = $argv[1] ?? 'help';
$opt = [];
foreach (array_slice($argv, 2) as $a) { if (preg_match('/^--([a-z-]+)=(.*)$/s', $a, $m)) { $opt[$m[1]] = $m[2]; } elseif (preg_match('/^--([a-z-]+)$/', $a, $m)) { $opt[$m[1]] = true; } }
$die = function (string $m) { fwrite(STDERR, "ERROR: $m\n"); exit(1); };
$leadId = (int) ($opt['lead'] ?? 0);
$lead = $leadId ? DB::table('crm_leads')->find($leadId) : null;
if (in_array($cmd, ['status', 'replay-created', 'deliver', 'advance', 'mark-sent', 'reply', 'notify'], true) && ! $lead) { $die('give --lead=<id> of an existing lead'); }
$tenant = $lead ? (int) $lead->sub_institute_id : 0;

/** every record that belongs to this lead's sale, found through the real links */
$chain = function () use ($lead, $tenant) {
    $opp = $lead->converted_opportunity_id ? (int) $lead->converted_opportunity_id : null;
    $quotes = $opp ? DB::table('crm_quotes')->where('sub_institute_id', $tenant)->where('opportunity_id', $opp)->pluck('id')->all() : [];
    $sos = $opp ? DB::table('crm_sales_orders')->where('sub_institute_id', $tenant)->where('opportunity_id', $opp)->pluck('id')->all() : [];
    $invs = $sos ? DB::table('crm_invoices')->where('sub_institute_id', $tenant)->whereIn('sales_order_id', $sos)->pluck('id')->all() : [];
    $org = $lead->converted_organization_id ? (int) $lead->converted_organization_id : null;
    $tix = $org ? DB::table('crm_tickets')->where('sub_institute_id', $tenant)->where('organization_id', $org)->where('description', 'like', '%Created automatically after the sale%')->pluck('id')->all() : [];

    return ['crm_leads' => [(int) $lead->id], 'crm_opportunities' => $opp ? [$opp] : [], 'crm_quotes' => $quotes, 'crm_sales_orders' => $sos, 'crm_invoices' => $invs, 'crm_tickets' => $tix];
};
$events = function (array $chain) use ($tenant) {
    $q = DB::table('g2g_event')->where('sub_institute_id', $tenant)->where(function ($w) use ($chain) { foreach ($chain as $type => $ids) { if ($ids) { $w->orWhere(fn ($x) => $x->where('entity_type', $type)->whereIn('entity_id', $ids)); } } });

    return $q->orderBy('id')->get();
};

switch ($cmd) {
    case 'status':
        $c = $chain(); $ev = $events($c);
        echo "== LEAD #{$lead->id} {$lead->lead_no} - {$lead->company} (tenant {$tenant})\n";
        printf("status: %s | score: %s | owner: user %s | converted: %s | opted out of email: %s | email: %s | phone: %s\n", $lead->lead_status ?? '(blank)', $lead->lead_score, $lead->assigned_to, $lead->converted ? 'YES' : 'no', $lead->email_opt_out ? 'YES' : 'no', $lead->email ? 'set' : 'MISSING', ($lead->phone || $lead->mobile) ? 'set' : 'MISSING');
        $missing = \App\Domain\Crm\Leads\LeadCompleteness::missing($lead); echo 'missing details: ' . ($missing ? implode(', ', $missing) : 'none') . "\n";
        echo "source Signal id: " . ($lead->source_company_opportunity_id ?? '-') . " | Organization: " . ($lead->converted_organization_id ?? '-') . " | Contact: " . ($lead->converted_contact_id ?? '-') . " | Opportunity: " . ($lead->converted_opportunity_id ?? '-') . "\n";
        $f = DB::table('crm_lead_followups')->where('lead_id', $lead->id)->first();
        echo "\n-- FOLLOW-UP CADENCE: " . ($f ? sprintf("state=%s%s | emails prepared %d of %d | next due %s | window %s-%s %s%s", $f->state, $f->stop_reason ? " ({$f->stop_reason})" : '', $f->attempts, $f->max_attempts, $f->next_due_at ?? '-', $f->window_start, $f->window_end, $f->timezone, $f->weekdays_only ? ' weekdays only' : '') : 'none (not started)') . "\n";
        echo "\n-- EMAILS on the lead\n";
        foreach (DB::table('crm_emails as e')->join('crm_email_links as l', 'l.email_id', '=', 'e.id')->where('l.linked_type', 'LEAD')->where('l.linked_id', $lead->id)->orderBy('e.id')->get(['e.id', 'e.direction', 'e.status', 'e.subject', 'e.to_address', 'e.from_address', 'e.message_id', 'e.sent_at', 'e.failure_reason']) as $e) {
            printf("  #%d %-8s %-9s %s | %s%s%s\n", $e->id, $e->direction, $e->status, $e->subject, $e->direction === 'inbound' ? 'from ' . $e->from_address : 'to ' . $e->to_address, $e->message_id ? " | Message-ID {$e->message_id}" : '', $e->failure_reason ? " | FAILED: {$e->failure_reason}" : '');
        }
        echo "\n-- NOTES on the lead (Comments tab)\n";
        foreach (DB::table('crm_lead_comments')->where('lead_id', $lead->id)->orderBy('id')->pluck('comment') as $n) { echo '  ' . mb_substr($n, 0, 220) . "\n"; }
        echo "\n-- TASKS (Activity tab) on lead / Organization / Opportunity\n";
        foreach (DB::table('task_management_calendar_events')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->where(function ($w) use ($c, $lead) { $w->where(fn ($x) => $x->where('linked_type', 'LEAD')->where('linked_id', $lead->id)); if ($lead->converted_organization_id) { $w->orWhere(fn ($x) => $x->where('linked_type', 'ORGANIZATION')->where('linked_id', $lead->converted_organization_id)); } if ($lead->converted_opportunity_id) { $w->orWhere(fn ($x) => $x->where('linked_type', 'OPPORTUNITY')->where('linked_id', $lead->converted_opportunity_id)); } })->orderBy('id')->get(['linked_type', 'title', 'start_at', 'status', 'owner_id']) as $t) { printf("  [%s] %s | due %s | owner %s | %s\n", $t->linked_type, $t->title, $t->start_at, $t->owner_id, $t->status); }
        echo "\n-- REPLIES received\n";
        foreach (DB::table('crm_inbound_events')->where('lead_id', $lead->id)->orderBy('id')->get(['id', 'type', 'status', 'classification', 'detail']) as $r) { printf("  #%d %s %s classification=%s | %s\n", $r->id, $r->type, $r->status, $r->classification ?? '-', $r->detail); }
        echo "\n-- WORKFLOW RUNS about this lead's records (an 'Approval' is waiting where status = pending_approval)\n";
        $runs = DB::table('gtm_workflow_runs as r')->join('gtm_workflows as w', 'w.id', '=', 'r.workflow_id')->where('r.sub_institute_id', $tenant)->where(function ($w) use ($c) { foreach ($c as $type => $ids) { if ($ids) { $w->orWhere(fn ($x) => $x->where('r.entity_type', $type)->whereIn('r.entity_id', $ids)); } } })->orderBy('r.id')->get(['r.id', 'w.name', 'r.event_type', 'r.entity_type', 'r.entity_id', 'r.status', 'r.error']);
        foreach ($runs as $r) { printf("  run #%d  %-14s %s  [%s #%d]%s\n", $r->id, $r->status, $r->name, $r->event_type, $r->entity_id, $r->error ? " -> {$r->error}" : ''); }
        if ($runs->isEmpty()) { echo "  none yet. If you expected some: are the workflows ACTIVE, and did you run `deliver`? (Workflows only react to events recorded AFTER they were activated.)\n"; }
        echo "\n-- EVENTS recorded for this lead's records\n";
        foreach ($ev as $e) { $d = DB::table('g2g_event_delivery')->where('event_id', $e->id)->where('consumer', 'gtm_workflow_runner')->value('status'); printf("  #%d %s [%s #%d] delivered to workflows: %s\n", $e->id, $e->type, $e->entity_type, $e->entity_id, $d ?? 'no'); }
        echo "\n-- CHAIN: quotes " . json_encode($c['crm_quotes']) . ' sales orders ' . json_encode($c['crm_sales_orders']) . ' invoices ' . json_encode($c['crm_invoices']) . ' onboarding tickets ' . json_encode($c['crm_tickets']) . "\n";
        break;

    case 'replay-created':
        $id = GtmAudit::record('crm.lead.created', $tenant, 'crm_leads', (int) $lead->id, null, ['lead_source' => $lead->lead_source, 'from_signal' => $lead->source_company_opportunity_id !== null, 'replayed_for_testing' => true], null, "crm.lead.created:replay:{$tenant}:{$lead->id}:" . now()->format('YmdHis'));
        echo "recorded a fresh crm.lead.created event #$id for lead {$lead->id}. Now run `deliver --lead={$lead->id}` (only workflows that were ACTIVE before this moment will react).\n";
        break;

    case 'deliver':
        $runner = app(GtmWorkflowRunner::class); $n = 0;
        foreach ($events($chain()) as $e) { if (! in_array($e->type, App\Domain\Gtm\GtmEvents::triggerable(), true)) { continue; } $done = DB::table('g2g_event_delivery')->where('event_id', $e->id)->where('consumer', 'gtm_workflow_runner')->where('status', 'done')->exists(); if (! $done) { $runner->dispatch($e); $n++; echo "delivered #{$e->id} {$e->type}\n"; } }
        echo $n ? "$n event(s) handed to the workflows. Run `status --lead={$lead->id}`.\n" : "nothing new to deliver.\n";
        break;

    case 'advance':
        if (config('crm.email_sending_enabled')) { $die('CRM email sending is ON; advance is disabled because it can send through the configured transport. Turn sending OFF before using this test-kit action.'); }
        $f = DB::table('crm_lead_followups')->where('lead_id', $lead->id)->first();
        if (! $f) { $die('this lead has no follow-up cadence yet'); }
        if ($f->state === 'paused') { $o = LeadFollowUp::processLead((int) $lead->id); echo 'cadence is paused (' . $f->stop_reason . '); tried to resume THIS lead only: ' . json_encode($o) . "\n"; break; }
        if ($f->state !== 'active') { $die("cadence is {$f->state} ({$f->stop_reason}); nothing to advance"); }
        DB::table('crm_lead_followups')->where('id', $f->id)->update(['next_due_at' => now()->subMinute()->format('Y-m-d H:i:s')]);
        echo 'attempt outcome: ' . LeadFollowUp::attemptById((int) $f->id) . "\n";
        break;

    case 'mark-sent':
        // TEST SHORTCUT, clearly not a real send: stands in for "the owner sent the prepared email by hand" while CRM email sending is OFF.
        $f = DB::table('crm_lead_followups')->where('lead_id', $lead->id)->first();
        $em = $f && $f->last_email_id ? DB::table('crm_emails')->where('id', $f->last_email_id)->first() : null;
        if (! $em || $em->status !== 'Queued') { $die('this lead has no Queued follow-up email to mark (the cadence must be paused with an email waiting)'); }
        $mid = "crm.{$tenant}.{$em->id}.testkit@testkit.invalid";
        DB::table('crm_emails')->where('id', $em->id)->update(['status' => 'Sent', 'sent_at' => now(), 'message_id' => $mid, 'updated_at' => now()]);
        echo "email #{$em->id} marked Sent WITHOUT sending anything (Message-ID {$mid}). Now run `advance --lead={$lead->id}` to let the cadence pick it up and schedule the next follow-up.\n";
        break;
    case 'reply':
        $secret = (string) config('crm.inbound.secret'); if ($secret === '') { $die('CRM_INBOUND_WEBHOOK_SECRET is not set in .env (the webhook refuses everything without it)'); }
        $out = DB::table('crm_emails as e')->join('crm_email_links as l', 'l.email_id', '=', 'e.id')->where('l.linked_type', 'LEAD')->where('l.linked_id', $lead->id)->where('e.direction', 'outbound')->whereNotNull('e.message_id')->orderByDesc('e.id')->first(['e.message_id']);
        $unthreaded = isset($opt['unthreaded']);
        if (! $out && ! $unthreaded) { $die('no email with a Message-ID has actually been SENT to this lead yet - the test kit does not send test email; use --unthreaded only with a dedicated test lead'); }
        $kind = (string) ($opt['kind'] ?? 'reply'); $text = (string) ($opt['text'] ?? 'Yes, we are interested. Please send the pricing and let us know when we can talk.');
        $body = ['provider' => 'testkit', 'event_id' => 'tk-' . bin2hex(random_bytes(6)), 'in_reply_to' => $out ? '<' . $out->message_id . '>' : null, 'from_email' => $lead->email, 'subject' => 'Re: follow-up', 'text' => $text . "\n\nOn Mon, someone wrote:\n> earlier message", 'occurred_at' => now()->toDateTimeString()] + match ($kind) {
            'auto' => ['type' => 'reply', 'subject' => 'Automatic reply: Out of office', 'auto_reply' => true], 'bounce' => ['type' => 'bounce', 'bounce_type' => 'hard', 'original_message_id' => '<' . $out->message_id . '>', 'recipient' => $lead->email, 'reason' => '550 no such user'],
            'soft' => ['type' => 'bounce', 'bounce_type' => 'soft', 'original_message_id' => '<' . $out->message_id . '>', 'recipient' => $lead->email, 'reason' => 'mailbox full'], 'complaint' => ['type' => 'complaint', 'original_message_id' => '<' . $out->message_id . '>', 'recipient' => $lead->email], default => ['type' => 'reply'],
        };
        $raw = json_encode($body); $ts = time(); $sig = "t=$ts,v1=" . hash_hmac('sha256', "$ts.$raw", $secret);
        $res = $kernel->handle(Illuminate\Http\Request::create('/api/crm/inbound', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_CRM_SIGNATURE' => $sig], $raw));
        echo "webhook answered HTTP {$res->getStatusCode()}: " . $res->getContent() . "\n";
        echo $kind === 'reply' ? "Next: `deliver --lead={$lead->id}` (runs the reply workflow; classification needs your AI provider), then `status`.\n" : "Next: `status --lead={$lead->id}`.\n";
        break;

    case 'age-opportunity':
        $oid = (int) ($opt['opp'] ?? 0); $days = max(1, (int) ($opt['days'] ?? 20)); $o = DB::table('crm_opportunities')->find($oid); if (! $o) { $die('give --opp=<opportunity id>'); }
        $then = now()->subDays($days)->format('Y-m-d H:i:s');
        DB::table('crm_opportunities')->where('id', $oid)->update(['created_at' => $then, 'updated_at' => $then]);
        DB::table('crm_opportunity_stage_history')->where('opportunity_id', $oid)->update(['created_at' => $then]);
        DB::table('crm_opportunity_comments')->where('opportunity_id', $oid)->update(['created_at' => $then, 'updated_at' => $then]);
        echo "Opportunity $oid now looks $days days old with no activity. Weekly check: " . json_encode(OpportunitySweep::run(10, [$oid])) . "\nNext: deliver its event: `deliver --lead=<its lead>` and open the Opportunity's Schedule tab / the bell for the task and notification.\n";
        break;

    case 'overdue-invoice':
        $iid = (int) ($opt['invoice'] ?? 0); if (! DB::table('crm_invoices')->find($iid)) { $die('give --invoice=<invoice id>'); }
        echo 'overdue check: ' . json_encode(InvoiceSweep::run(10, [$iid])) . " (it only reports an invoice with a balance AND a due date in the past)\n";
        break;

    case 'notify':
        config(['mail.default' => 'array']); \Illuminate\Support\Facades\Mail::purge();
        $d = app(NotificationDispatcher::class); $n = 0;
        foreach ($events($chain()) as $e) { if ($d->handles($e->type)) { $d->dispatch($e); $n++; } }
        echo "$n notifying event(s) processed with a null mailer (no email can leave). Look at the bell for the owner/assignee; or: SELECT * FROM g2g_notification WHERE event_id IN (...).\n";
        foreach (DB::table('g2g_notification')->whereIn('event_id', $events($chain())->pluck('id'))->where('channel', 'inapp')->get(['user_id', 'subject', 'recipient_reason']) as $x) { echo "  in-app -> user {$x->user_id}: {$x->subject} ({$x->recipient_reason})\n"; }
        break;

    default:
        echo file_get_contents(__FILE__) ? implode("\n", array_slice(explode("\n", file_get_contents(__FILE__)), 2, 14)) . "\n" : '';
}
