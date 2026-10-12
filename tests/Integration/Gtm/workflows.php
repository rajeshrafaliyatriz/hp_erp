<?php
use App\Domain\Gtm\Workflows\WorkflowEngine;
use App\Services\Events\EventCatalogue;
use App\Services\Events\GtmWorkflowRunner;
use Illuminate\Support\Facades\{Cache, DB, Http, Mail};

$origStage = DB::table('gtm_accounts')->where('id', 3)->value('stage');
$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n"; };
$req = function ($m, $u, $b = [], $tok = null) use ($kernel, $A) { Cache::flush(); return call($kernel, $m, $u, $tok ?? $A, $b); };
$fake = function ($stub) { Http::swap(new Illuminate\Http\Client\Factory); Http::fake(['*' => $stub]); };
$gem = fn ($arr) => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($arr)]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 10]]);
$runner = app(GtmWorkflowRunner::class);
/** hand the latest matching event to the REAL reactor, exactly as events:react does */
$deliver = function (string $type, int $entityId) use ($runner) {
    $e = DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', $type)->where('entity_id', $entityId)->orderByDesc('id')->first();
    if (! $e) { return null; }
    $runner->dispatch($e);
    return $e;
};
$runsOf = fn (int $wf) => DB::table('gtm_workflow_runs')->where('workflow_id', $wf)->orderBy('id')->get();
$mkWf = function (array $def) use ($req) { [$c, $j] = $req('POST', '/gtm/workflows', $def); return [$c, $j, $j['data']['workflow']['id'] ?? 0]; };
$activate = fn (int $id) => $req('POST', "/gtm/workflows/$id/status", ['status' => 'active']);
$otherTenant = 0; $mailAttempts0 = DB::table('gtm_outreach_attempts')->count();

try {
    // ---------------- permissions + catalogue ----------------
    foreach (['/gtm/workflows', '/gtm/workflows/catalog', '/gtm/workflow-runs'] as $u) { [$c] = call($kernel, 'GET', $u, null); $ok("$u no token 401", $c === 401, $c); [$c] = call($kernel, 'GET', $u, $E); $ok("$u employee 403", $c === 403, $c); }
    [$c] = call($kernel, 'POST', '/gtm/workflows', $E, ['name' => 'x']); $ok('workflow create: employee 403', $c === 403, $c);
    [$c, $j] = $req('GET', '/gtm/workflows/catalog'); $cat = $j['data'] ?? [];
    $ok('catalogue lists only triggerable events, each with facts', $c === 200 && count($cat['triggers']) >= 14 && ! isset($cat['triggers']['gtm.deal.deleted']) && ! isset($cat['triggers']['gtm.workflow.run_failed']) && isset($cat['triggers']['gtm.deal.created']['fields']['deal.stage']), array_keys($cat['triggers'] ?? []));
    $ok('catalogue: five actions, change_account_stage is consequential, and it states the delivery delay', count($cat['actions']) === 5 && $cat['actions']['change_account_stage']['consequential'] === true && collect($cat['notes'])->contains(fn ($n) => str_contains($n, 'within minutes')), $cat['actions'] ?? null);
    $ok('no personal contact data is offered to workflows', ! collect($cat['triggers'])->flatMap(fn ($t) => array_keys($t['fields']))->contains(fn ($f) => preg_match('/\.(email|phone|notes)$/', $f)));

    // fixtures
    [$c, $j] = $req('POST', '/gtm/accounts', ['name' => 'Verify WF Co', 'industry' => 'Verify-WF']); $acct = $j['data']['account']['id'];
    $valid = ['name' => 'Verify WF1', 'trigger_event' => 'gtm.deal.created', 'conditions' => [['field' => 'deal.amount', 'op' => 'gte', 'value' => 1000], ['field' => 'account.stage', 'op' => 'eq', 'value' => 'target']],
        'actions' => [['type' => 'log_activity', 'params' => ['subject' => 'New deal {{deal.name}} ({{deal.amount}} {{deal.currency}}) at {{account.name}}']], ['type' => 'set_next_step', 'params' => ['text' => 'Qualify {{account.name}}', 'due_in_days' => 3]]]];

    // ---------------- definition validation ----------------
    foreach ([
        'unknown trigger' => ['trigger_event' => 'gtm.nonsense'] + $valid,
        'a non-triggerable event' => ['trigger_event' => 'gtm.deal.deleted'] + $valid,
        "a workflow's own run event (loop)" => ['trigger_event' => 'gtm.workflow.run_completed'] + $valid,
        'unknown fact' => ['conditions' => [['field' => 'deal.secret', 'op' => 'eq', 'value' => 1]]] + $valid,
        'a fact the trigger does not provide' => ['conditions' => [['field' => 'contact.status', 'op' => 'eq', 'value' => 'active']]] + $valid,
        'unknown operator' => ['conditions' => [['field' => 'deal.stage', 'op' => 'sql', 'value' => 1]]] + $valid,
        'missing value' => ['conditions' => [['field' => 'deal.stage', 'op' => 'eq']]] + $valid,
        'gt on text' => ['conditions' => [['field' => 'deal.name', 'op' => 'gt', 'value' => 5]]] + $valid,
        'in without a list' => ['conditions' => [['field' => 'deal.stage', 'op' => 'in', 'value' => 'won']]] + $valid,
        'no actions' => ['actions' => []] + $valid,
        'unknown action' => ['actions' => [['type' => 'drop_database', 'params' => []]]] + $valid,
        'next-step action on an account trigger' => ['trigger_event' => 'gtm.account.created', 'conditions' => [], 'actions' => [['type' => 'set_next_step', 'params' => ['text' => 'x']]]] + $valid,
        'unknown placeholder' => ['actions' => [['type' => 'log_activity', 'params' => ['subject' => '{{deal.password}}']]]] + $valid,
        'too many actions' => ['actions' => array_fill(0, 7, ['type' => 'log_activity', 'params' => ['subject' => 'x']])] + $valid,
        'agent with an input it does not take' => ['actions' => [['type' => 'run_agent', 'params' => ['agent' => 'gtm-deal-coach', 'input' => ['sql' => 1]]]]] + $valid,
        "another tenant's / unknown campaign" => ['actions' => [['type' => 'add_to_campaign', 'params' => ['campaign_id' => 999999]]]] + $valid,
        'bad stage' => ['actions' => [['type' => 'change_account_stage', 'params' => ['stage' => 'king']]]] + $valid,
        'zero runs per day' => ['max_runs_per_day' => 0] + $valid,
        'missing name' => ['name' => ''] + $valid,
    ] as $label => $body) { [$c] = $req('POST', '/gtm/workflows', $body); $ok("workflow invalid ($label) -> 422", $c === 422, $c); }

    // ---------------- lifecycle ----------------
    [$c, $j, $w1] = $mkWf($valid);
    $ok('workflow saved as a draft (never active on creation)', $c === 201 && $j['data']['workflow']['status'] === 'draft' && (int) $j['data']['workflow']['created_by'] === 28, [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/workflows/$w1/status", ['status' => 'paused']);            $ok('draft -> paused is not a move (422)', $c === 422 && $j['code'] === 'bad_transition', [$c, $j]);
    [$c] = $req('POST', "/gtm/workflows/$w1/status", ['status' => 'active'], $E);           $ok('employee cannot activate (403)', $c === 403, $c);
    [$c, $j] = $activate($w1);                                                              $ok('activate stamps activated_at', $c === 200 && $j['data']['workflow']['status'] === 'active' && $j['data']['workflow']['activated_at'] !== null, [$c, $j]);
    [$c, $j] = $req('PATCH', "/gtm/workflows/$w1", ['name' => 'edit while live']);          $ok('an ACTIVE workflow cannot be edited (422 pause_first)', $c === 422 && $j['code'] === 'pause_first', [$c, $j]);
    [$c, $j] = $req('PATCH', "/gtm/workflows/$w1", ['trigger_event' => 'gtm.deal.created']); $ok('(and the trigger can never be changed)', $c === 422, $c);
    [$c, $j, $wEdit] = $mkWf(['name' => 'Verify WF Edit'] + $valid);
    [$c, $j] = $req('PATCH', "/gtm/workflows/$wEdit", ['name' => 'Verify WF Edited', 'max_runs_per_day' => 5, 'actions' => [['type' => 'log_activity', 'params' => ['subject' => 'edited {{deal.name}}']]]]);
    $ok('a DRAFT can be edited (re-validated on save)', $c === 200 && $j['data']['workflow']['name'] === 'Verify WF Edited' && (int) $j['data']['workflow']['max_runs_per_day'] === 5 && count($j['data']['workflow']['actions']) === 1, [$c, $j]);
    [$c] = $req('PATCH', "/gtm/workflows/$wEdit", ['actions' => [['type' => 'log_activity', 'params' => ['subject' => '{{deal.nope}}']]]]); $ok('an edit that would make it invalid is refused (422)', $c === 422, $c);

    // ---------------- a workflow fires on a real event, and only for matching records ----------------
    [$c, $j] = $req('POST', '/gtm/deals', ['account_id' => $acct, 'name' => 'Verify WF Deal Big', 'amount' => 5000, 'currency' => 'INR']); $dBig = $j['data']['deal']['id'];
    [$c, $j] = $req('POST', '/gtm/deals', ['account_id' => $acct, 'name' => 'Verify WF Deal Small', 'amount' => 100, 'currency' => 'INR']); $dSmall = $j['data']['deal']['id'];
    $deliver('gtm.deal.created', $dSmall);
    $ok('a deal that fails the conditions starts nothing (no run is recorded)', $runsOf($w1)->count() === 0, $runsOf($w1)->all());
    $deliver('gtm.deal.created', $dBig);
    $runs = $runsOf($w1);
    $ok('a matching deal starts exactly one run and it succeeds', $runs->count() === 1 && $runs[0]->status === 'succeeded' && (int) $runs[0]->entity_id === $dBig && (int) $runs[0]->attempts === 1, $runs->all());
    $act = DB::table('gtm_activities')->where('deal_id', $dBig)->where('subject', 'like', 'New deal%')->first();
    $ok('action 1: the note was logged with REAL values rendered into the template, no placeholder left', $act && $act->subject === 'New deal Verify WF Deal Big (5000 INR) at Verify WF Co' && ! str_contains($act->subject, '{{') && $act->user_id === null, $act);
    $deal = DB::table('gtm_deals')->find($dBig);
    $ok('action 2: the next step and its due date (3 days) were written', $deal->next_step === 'Qualify Verify WF Co' && $deal->next_step_due === now()->addDays(3)->toDateString(), [$deal->next_step, $deal->next_step_due]);
    [$c, $j] = $req('GET', "/gtm/workflow-runs/{$runs[0]->id}"); $steps = collect($j['data']['steps']);
    $ok('run detail: both steps recorded with their results; the conditions\' evidence is stored', $c === 200 && $steps->count() === 2 && $steps->every(fn ($s) => $s['status'] === 'succeeded') && isset($steps[0]['result']['activity_id']) && count($j['data']['run']['context']['trace']) === 2, [$c, $j['data']['steps'] ?? null]);
    $deliver('gtm.deal.created', $dBig);
    $ok('IDEMPOTENT: the same event delivered again starts nothing new', $runsOf($w1)->count() === 1 && DB::table('gtm_activities')->where('deal_id', $dBig)->where('subject', 'like', 'New deal%')->count() === 1, $runsOf($w1)->count());
    $e = DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'gtm.deal.created')->where('entity_id', $dBig)->orderByDesc('id')->first();
    app(WorkflowEngine::class)->handleEvent($e);
    $ok('...even when the engine itself is called directly again (UNIQUE workflow+event)', $runsOf($w1)->count() === 1 && DB::table('gtm_activities')->where('deal_id', $dBig)->where('subject', 'like', 'New deal%')->count() === 1);
    $ok('the reactor ledger shows the event as delivered', DB::table('g2g_event_delivery')->where('consumer', 'gtm_workflow_runner')->where('event_id', $e->id)->whereIn('status', ['done', 'skipped'])->exists());
    $ok('events caused by the workflow carry source=workflow', DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'gtm.activity.logged')->where('entity_id', $act->id)->where('metadata', 'like', '%"source":"workflow"%')->exists());

    // ---------------- loop prevention ----------------
    [$c, $j, $wLoop] = $mkWf(['name' => 'Verify WF Loop', 'trigger_event' => 'gtm.account.updated', 'conditions' => [['field' => 'account.stage', 'op' => 'eq', 'value' => 'engaged']], 'actions' => [['type' => 'change_account_stage', 'params' => ['stage' => 'target']]]]);
    $activate($wLoop);
    $req('PATCH', "/gtm/accounts/$acct", ['stage' => 'engaged']); $deliver('gtm.account.updated', $acct);
    $r = $runsOf($wLoop);
    $ok('a consequential action (change stage) makes the run WAIT for approval - the account is untouched', $r->count() === 1 && $r[0]->status === 'pending_approval' && DB::table('gtm_accounts')->where('id', $acct)->value('stage') === 'engaged', $r->all());
    [$c, $j] = $req('POST', "/gtm/workflow-runs/{$r[0]->id}/approve", []);                  $ok('approving without confirm -> 409', $c === 409 && $j['code'] === 'confirmation_required', [$c, $j]);
    [$c] = $req('POST', "/gtm/workflow-runs/{$r[0]->id}/approve", ['confirm' => true], $E); $ok('an employee cannot approve (403)', $c === 403, $c);
    [$c, $j] = $req('POST', "/gtm/workflow-runs/{$r[0]->id}/approve", ['confirm' => true, 'note' => 'ok']);
    $ok('approved: it ran, the account moved, and the run is succeeded', $c === 200 && $j['data']['status'] === 'succeeded' && DB::table('gtm_accounts')->where('id', $acct)->value('stage') === 'target', [$c, $j]);
    $ok('...recorded who approved and when', DB::table('gtm_workflow_runs')->where('id', $r[0]->id)->value('decided_by') == 28 && DB::table('gtm_workflow_runs')->where('id', $r[0]->id)->value('decided_at') !== null);
    $before = $runsOf($wLoop)->count();
    $caused = DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'gtm.account.updated')->where('entity_id', $acct)->orderByDesc('id')->first();
    $ok('the account.updated event the workflow itself caused is marked source=workflow', str_contains((string) $caused->metadata, '"source":"workflow"'), $caused->metadata);
    $runner->dispatch($caused);
    $ok('NO LOOP: that event triggers nothing, even though a workflow listens for account.updated', $runsOf($wLoop)->count() === $before, $runsOf($wLoop)->count());
    [$c, $j] = $req('POST', "/gtm/workflow-runs/{$r[0]->id}/approve", ['confirm' => true]); $ok('approving a run twice -> 422 not_pending', $c === 422 && $j['code'] === 'not_pending', [$c, $j]);
    $req('POST', "/gtm/workflows/$wLoop/status", ['status' => 'paused']);

    // ---------------- reject / cancel / second approver / workflow-level approval ----------------
    [$c, $j, $wApp] = $mkWf(['name' => 'Verify WF Approval', 'trigger_event' => 'gtm.account.created', 'requires_approval' => true, 'conditions' => [['field' => 'account.industry', 'op' => 'eq', 'value' => 'Verify-WF-Approve']], 'actions' => [['type' => 'log_activity', 'params' => ['subject' => 'Approved automation for {{account.name}}']]]]);
    $activate($wApp);
    $mkAcct = function ($name) use ($req) { [$c, $j] = $req('POST', '/gtm/accounts', ['name' => $name, 'industry' => 'Verify-WF-Approve']); return $j['data']['account']['id']; };
    $a1 = $mkAcct('Verify WF A1'); $a2 = $mkAcct('Verify WF A2'); $a3 = $mkAcct('Verify WF A3'); $a4 = $mkAcct('Verify WF A4');
    foreach ([$a1, $a2, $a3, $a4] as $x) { $deliver('gtm.account.created', $x); }
    $rr = $runsOf($wApp);
    $ok('requires_approval: every matching run waits (4 pending), nothing has happened yet', $rr->count() === 4 && $rr->every(fn ($r) => $r->status === 'pending_approval') && ! DB::table('gtm_activities')->where('subject', 'like', 'Approved automation%')->exists(), $rr->pluck('status')->all());
    [$c, $j] = $req('POST', "/gtm/workflow-runs/{$rr[1]->id}/reject", []);                                $ok('reject needs a reason (422)', $c === 422 && $j['code'] === 'reason_required', [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/workflow-runs/{$rr[1]->id}/reject", ['note' => 'Not for this account']); $ok('reject with a reason: rejected, nothing executed', $c === 200 && $j['data']['status'] === 'rejected' && ! DB::table('gtm_activities')->where('account_id', $a2)->where('subject', 'like', 'Approved automation%')->exists(), [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/workflow-runs/{$rr[2]->id}/cancel");                                    $ok('a pending run can be cancelled', $c === 200 && DB::table('gtm_workflow_runs')->where('id', $rr[2]->id)->value('status') === 'cancelled', [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/workflow-runs/{$rr[2]->id}/cancel");                                    $ok('a cancelled run cannot be cancelled again (422)', $c === 422, $c);
    config(['gtm.workflows.require_second_approver' => true]);
    [$c, $j] = $req('POST', "/gtm/workflow-runs/{$rr[0]->id}/approve", ['confirm' => true]);              $ok('second approver required: the creator cannot approve their own workflow\'s run (403)', $c === 403 && $j['code'] === 'second_approver_required', [$c, $j]);
    config(['gtm.workflows.require_second_approver' => false]);
    $req('POST', "/gtm/workflows/$wApp/status", ['status' => 'paused']);
    [$c, $j] = $req('POST', "/gtm/workflow-runs/{$rr[3]->id}/approve", ['confirm' => true]);
    $ok('approving a run whose workflow was paused meanwhile: it does NOT run (cancelled: no longer active)', $c === 200 && DB::table('gtm_workflow_runs')->where('id', $rr[3]->id)->value('status') === 'cancelled' && str_contains((string) DB::table('gtm_workflow_runs')->where('id', $rr[3]->id)->value('error'), 'no longer active'), [$c, $j]);
    $req('POST', "/gtm/workflows/$wApp/status", ['status' => 'active']);
    [$c, $j] = $req('POST', "/gtm/workflow-runs/{$rr[0]->id}/approve", ['confirm' => true]);
    $ok('after re-activation the first run can be approved and executes', $c === 200 && $j['data']['status'] === 'succeeded' && DB::table('gtm_activities')->where('account_id', $a1)->where('subject', 'Approved automation for Verify WF A1')->exists(), [$c, $j]);

    // ---------------- backlog guard / pause / re-activate ----------------
    [$c, $j] = $req('POST', '/gtm/deals', ['account_id' => $acct, 'name' => 'Verify WF Old Deal', 'amount' => 9000, 'currency' => 'INR']); $dOld = $j['data']['deal']['id'];
    sleep(1);
    $req('POST', "/gtm/workflows/$w1/status", ['status' => 'paused']);
    [$c, $j] = $req('POST', '/gtm/deals', ['account_id' => $acct, 'name' => 'Verify WF While Paused', 'amount' => 9000, 'currency' => 'INR']); $dPaused = $j['data']['deal']['id'];
    $deliver('gtm.deal.created', $dPaused);
    $ok('a PAUSED workflow does not react', $runsOf($w1)->count() === 1, $runsOf($w1)->count());
    sleep(1); $activate($w1);
    $deliver('gtm.deal.created', $dOld); $deliver('gtm.deal.created', $dPaused);
    $ok('BACKLOG GUARD: after re-activation it does NOT fire on events recorded before activation (old and paused-time deals)', $runsOf($w1)->count() === 1, $runsOf($w1)->count());
    [$c, $j] = $req('POST', '/gtm/deals', ['account_id' => $acct, 'name' => 'Verify WF After', 'amount' => 9000, 'currency' => 'INR']); $dAfter = $j['data']['deal']['id'];
    $deliver('gtm.deal.created', $dAfter);
    $ok('...and does fire for a deal created after activation', $runsOf($w1)->count() === 2 && $runsOf($w1)[1]->status === 'succeeded', $runsOf($w1)->pluck('status')->all());

    // ---------------- daily limit / missing record / owner no longer an administrator ----------------
    [$c, $j, $wCap] = $mkWf(['name' => 'Verify WF Cap', 'trigger_event' => 'gtm.deal.created', 'max_runs_per_day' => 1, 'conditions' => [['field' => 'deal.name', 'op' => 'contains', 'value' => 'Cap Deal']], 'actions' => [['type' => 'log_activity', 'params' => ['subject' => 'cap {{deal.name}}']]]]);
    sleep(1); $activate($wCap);
    $capIds = [];
    foreach (['Verify WF Cap Deal 1', 'Verify WF Cap Deal 2'] as $n) { [$c, $j] = $req('POST', '/gtm/deals', ['account_id' => $acct, 'name' => $n]); $capIds[] = $j['data']['deal']['id']; $deliver('gtm.deal.created', $capIds[count($capIds) - 1]); }
    $cr = $runsOf($wCap);
    $ok('daily limit: the first run goes, the second is recorded as skipped with the reason', $cr->count() === 2 && $cr[0]->status === 'succeeded' && $cr[1]->status === 'skipped' && str_contains($cr[1]->error, 'daily limit'), $cr->pluck('status', 'error')->all());
    [$c, $j] = $req('POST', '/gtm/deals', ['account_id' => $acct, 'name' => 'Verify WF Cap Deal 3']); $dGone = $j['data']['deal']['id'];
    DB::table('gtm_workflows')->where('id', $wCap)->update(['max_runs_per_day' => 50]);
    [$c, $j, $wMiss] = $mkWf(['name' => 'Verify WF Unconditional', 'trigger_event' => 'gtm.deal.created', 'conditions' => [], 'actions' => [['type' => 'log_activity', 'params' => ['subject' => 'unconditional {{deal.name}}']]]]);
    sleep(1); $activate($wMiss);
    [$c, $j] = $req('POST', '/gtm/deals', ['account_id' => $acct, 'name' => 'Verify WF Vanishing Deal']); $dVan = $j['data']['deal']['id'];
    $req('DELETE', "/gtm/deals/$dVan"); $w1Before = $runsOf($w1)->count(); $capBefore = $runsOf($wCap)->count(); $deliver('gtm.deal.created', $dVan);
    $ok('an event for a record deleted before processing: an UNCONDITIONAL workflow records a skip ("no longer exists")', $runsOf($wMiss)->count() === 1 && $runsOf($wMiss)[0]->status === 'skipped' && str_contains($runsOf($wMiss)[0]->error, 'no longer exists'), $runsOf($wMiss)->all());
    $ok('...but workflows WITH conditions are not given a meaningless run (they could not be judged)', $runsOf($w1)->count() === $w1Before && $runsOf($wCap)->count() === $capBefore, [$runsOf($w1)->count(), $w1Before]);
    $req('POST', "/gtm/workflows/$wMiss/status", ['status' => 'paused']);
    [$c, $j] = $req('POST', '/gtm/deals', ['account_id' => $acct, 'name' => 'Verify WF Cap Deal 4']); $dOwner = $j['data']['deal']['id'];
    DB::table('gtm_workflows')->where('id', $wCap)->update(['created_by' => 29]);   // an Employee
    $deliver('gtm.deal.created', $dOwner);
    $ok('PERMISSIONS ARE RE-CHECKED AT RUN TIME: if the creator is no longer an administrator the run fails and explains why', $runsOf($wCap)->last()->status === 'failed' && str_contains($runsOf($wCap)->last()->error, 'no longer an administrator'), $runsOf($wCap)->last());
    DB::table('gtm_workflows')->where('id', $wCap)->update(['created_by' => 28]);
    [$c, $j] = $req('POST', '/gtm/workflow-runs/' . $runsOf($wCap)->last()->id . '/retry'); $ok('a failed run can be retried once the cause is fixed, and it then succeeds', $c === 200 && $j['data']['status'] === 'succeeded', [$c, $j]);
    [$c, $j] = $req('POST', '/gtm/workflow-runs/' . $runsOf($wCap)->last()->id . '/retry'); $ok('retrying a run that is not failed -> 422', $c === 422 && $j['code'] === 'not_failed', [$c, $j]);

    // ---------------- agent steps: success, transient failure with backoff, resume without repeating ----------------
    [$c, $j, $wAg] = $mkWf(['name' => 'Verify WF Agent', 'trigger_event' => 'gtm.deal.stage_changed', 'conditions' => [['field' => 'event.to', 'op' => 'eq', 'value' => 'proposal']],
        'actions' => [['type' => 'log_activity', 'params' => ['subject' => 'Coach requested for {{deal.name}}']], ['type' => 'run_agent', 'params' => ['agent' => 'gtm-deal-coach', 'input' => ['deal_id' => '@deal.id']]]]]);
    sleep(1); $activate($wAg);
    [$c, $j] = $req('POST', '/gtm/deals', ['account_id' => $acct, 'name' => 'Verify WF Agent Deal', 'amount' => 700, 'currency' => 'INR']); $dAg = $j['data']['deal']['id'];
    $req('POST', "/gtm/deals/$dAg/stage", ['stage' => 'proposal']);
    $fake(Http::response(['error' => ['message' => 'quota']], 429));
    $deliver('gtm.deal.stage_changed', $dAg);
    $r = $runsOf($wAg)->first(); $wait = $r ? (strtotime($r->next_attempt_at) - time()) / 60 : 0;
    $ok('AI quota exhausted: the run goes to retry_wait (not failed), due in ~10 minutes, error stored', $r && $r->status === 'retry_wait' && abs($wait - 10) <= 1 && str_contains($r->error, 'quota'), $r);
    $steps = DB::table('gtm_workflow_run_steps')->where('run_id', $r->id)->orderBy('step')->get();
    $ok('...step 1 (the note) succeeded and is kept; step 2 (the agent) is recorded as failed', $steps[0]->status === 'succeeded' && $steps[1]->status === 'failed', $steps->pluck('status')->all());
    $eng = app(WorkflowEngine::class);
    $notDue = $eng->retryDue();                                       $ok('the scheduler does not retry before the time', $notDue['due'] === 0, $notDue);
    $ladder = [];
    foreach ([1, 2] as $_) { DB::table('gtm_workflow_runs')->where('id', $r->id)->update(['next_attempt_at' => now()->subSecond()]); $eng->retryDue(); $row = DB::table('gtm_workflow_runs')->find($r->id); $ladder[] = [$row->status, (int) $row->attempts, (int) round((strtotime($row->next_attempt_at) - time()) / 60)]; }
    $ok('backoff ladder 10 -> 60 -> 240 minutes while the provider keeps failing', $ladder[0][0] === 'retry_wait' && abs($ladder[0][2] - 60) <= 1 && $ladder[1][0] === 'retry_wait' && abs($ladder[1][2] - 240) <= 1, $ladder);
    DB::table('gtm_workflow_runs')->where('id', $r->id)->update(['next_attempt_at' => now()->subSecond()]); $eng->retryDue();
    $ok('after the last permitted retry it is FAILED, with the reason', DB::table('gtm_workflow_runs')->where('id', $r->id)->value('status') === 'failed' && str_contains((string) DB::table('gtm_workflow_runs')->where('id', $r->id)->value('error'), 'Gave up'), DB::table('gtm_workflow_runs')->find($r->id));
    $fake($gem(['health' => 'Needs attention', 'risks' => [['risk' => 'No next step', 'evidence' => 'next_step is empty']], 'strengths' => [], 'next_actions' => [['action' => 'Call the buyer', 'why' => 'Silent']], 'missing' => []]));
    [$c, $j] = $req('POST', "/gtm/workflow-runs/{$r->id}/retry");
    $ok('provider recovered: retry succeeds', $c === 200 && $j['data']['status'] === 'succeeded', [$c, $j]);
    $ok('RESUME, NOT REPEAT: the already-succeeded note step ran exactly once across every attempt', DB::table('gtm_activities')->where('deal_id', $dAg)->where('subject', 'Coach requested for Verify WF Agent Deal')->count() === 1);
    $agentStep = DB::table('gtm_workflow_run_steps')->where('run_id', $r->id)->where('step', 2)->first();
    $ok('the agent step recorded the real agent run and analysis it produced', $agentStep->status === 'succeeded' && DB::table('agentic_agent_runs')->where('id', json_decode($agentStep->result, true)['agent_run_id'])->value('status') === 'success' && DB::table('gtm_analyses')->where('id', json_decode($agentStep->result, true)['analysis_id'])->where('kind', 'deal_coach')->exists(), $agentStep);

    // no-data is a skipped step, not a failure
    [$c, $j, $wCs] = $mkWf(['name' => 'Verify WF CS', 'trigger_event' => 'gtm.account.created', 'conditions' => [['field' => 'account.industry', 'op' => 'eq', 'value' => 'Verify-WF-CS']], 'actions' => [['type' => 'run_agent', 'params' => ['agent' => 'gtm-customer-success', 'input' => ['mode' => 'renewal_risk']]], ['type' => 'log_activity', 'params' => ['subject' => 'CS review attempted for {{account.name}}']]]]);
    sleep(1); $activate($wCs);
    $hadCustomers = DB::table('gtm_accounts')->where('sub_institute_id', 6)->where('stage', 'customer')->exists();
    [$c, $j] = $req('POST', '/gtm/accounts', ['name' => 'Verify WF CS Acct', 'industry' => 'Verify-WF-CS']); $deliver('gtm.account.created', $j['data']['account']['id']);
    $cr = $runsOf($wCs)->first(); $cs = DB::table('gtm_workflow_run_steps')->where('run_id', $cr->id)->orderBy('step')->get();
    if (! $hadCustomers) { $ok('an agent with nothing to analyse is a SKIPPED step; the run carries on and succeeds', $cr->status === 'succeeded' && $cs[0]->status === 'skipped' && $cs[1]->status === 'succeeded' && str_contains($cs[0]->result, 'customer'), [$cr->status, $cs->pluck('status')->all()]); } else { echo "SKIP no-data agent case (tenant already has customers)\n"; }

    // outreach agent + save_drafts: DRAFTS only, nothing is sent
    $sigAcct = 3;
    [$c, $j, $wOut] = $mkWf(['name' => 'Verify WF Outreach', 'trigger_event' => 'gtm.contact.created', 'conditions' => [['field' => 'contact.role_in_deal', 'op' => 'eq', 'value' => 'decision_maker'], ['field' => 'account.id', 'op' => 'eq', 'value' => $sigAcct]],
        'actions' => [['type' => 'run_agent', 'params' => ['agent' => 'gtm-outreach', 'input' => ['account_id' => '@account.id', 'contact_id' => '@contact.id', 'mode' => 'email'], 'save_drafts' => true]]]]);
    sleep(1); $activate($wOut);
    $sig = DB::table('g2g_company_opportunities')->where('sub_institute_id', 6)->where('company_id', DB::table('gtm_accounts')->where('id', 3)->value('company_id'))->value('id');
    $fake($gem(['subject' => 'Regarding your initiative', 'body' => 'A grounded draft body that is long enough to be saved.', 'signal_used' => $sig, 'claims' => [], 'missing' => []]));
    [$c, $j] = $req('POST', "/gtm/accounts/$sigAcct/contacts", ['full_name' => 'Verify WF Decider', 'email' => 'verify.wf.decider@example.invalid', 'role_in_deal' => 'decision_maker']); $ctWf = $j['data']['contact']['id'];
    $deliver('gtm.contact.created', $ctWf);
    $or = $runsOf($wOut)->first(); $draft = DB::table('gtm_outreach_messages')->where('contact_id', $ctWf)->first();
    $ok('the Outreach agent ran from a workflow and SAVED A DRAFT for a person to review', $or && $or->status === 'succeeded' && $draft && $draft->status === 'draft' && (int) $draft->created_by === 28, [$or, $draft]);
    $ok('NOTHING WAS SENT: no send attempt exists, and the draft has no approval', DB::table('gtm_outreach_attempts')->count() === $mailAttempts0 && $draft->approved_hash === null && $draft->sent_at === null && $draft->status === 'draft');
    $ok('the draft creation is audited as workflow-caused', DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'gtm.outreach.created')->where('entity_id', $draft->id)->where('metadata', 'like', '%"source":"workflow"%')->exists());

    // add_to_campaign (open at save time, closed at run time)
    [$c, $j] = $req('POST', '/gtm/campaigns', ['name' => 'Verify WF Campaign', 'kind' => 'abm']); $camp = $j['data']['campaign']['id'];
    [$c, $j, $wCamp] = $mkWf(['name' => 'Verify WF Campaign Add', 'trigger_event' => 'gtm.account.created', 'conditions' => [['field' => 'account.industry', 'op' => 'eq', 'value' => 'Verify-WF-Camp']], 'actions' => [['type' => 'add_to_campaign', 'params' => ['campaign_id' => $camp]]]]);
    sleep(1); $activate($wCamp);
    [$c, $j] = $req('POST', '/gtm/accounts', ['name' => 'Verify WF Camp Acct', 'industry' => 'Verify-WF-Camp']); $caAcct = $j['data']['account']['id']; $deliver('gtm.account.created', $caAcct);
    $ok('add_to_campaign: the new account became a target', DB::table('gtm_campaign_members')->where('campaign_id', $camp)->where('account_id', $caAcct)->exists() && $runsOf($wCamp)->first()->status === 'succeeded', $runsOf($wCamp)->first());
    $req('POST', "/gtm/campaigns/$camp/status", ['status' => 'archived', 'confirm' => true]);
    [$c, $j] = $req('POST', '/gtm/accounts', ['name' => 'Verify WF Camp Acct 2', 'industry' => 'Verify-WF-Camp']); $deliver('gtm.account.created', $j['data']['account']['id']);
    $ok('a campaign that has since closed makes the run FAIL (with the reason) instead of adding to it', $runsOf($wCamp)->last()->status === 'failed' && str_contains($runsOf($wCamp)->last()->error, 'closed') && ! DB::table('gtm_campaign_members')->where('campaign_id', $camp)->where('account_id', $j['data']['account']['id'])->exists(), $runsOf($wCamp)->last());
    $req('POST', "/gtm/workflows/$wCamp/status", ['status' => 'paused']); [$c, $j] = $req('PATCH', "/gtm/workflows/$wCamp", ['actions' => [['type' => 'add_to_campaign', 'params' => ['campaign_id' => $camp]]]]);
    $ok('...and a workflow pointing at that closed campaign cannot be edited/activated into a broken state (422)', $c === 422, [$c, $j]);

    // ---------------- dry run ----------------
    $actsBefore = DB::table('gtm_activities')->count();
    [$c, $j] = $req('POST', "/gtm/workflows/$w1/test", ['entity_id' => $dBig]);
    $ok('dry run: says it would run, shows the evidence, and renders the actions with real values', $c === 200 && $j['data']['would_run'] === true && $j['data']['dry_run'] === true && $j['data']['actions'][0]['params']['subject'] === 'New deal Verify WF Deal Big (5000 INR) at Verify WF Co' && count($j['data']['conditions']) === 2, [$c, $j]);
    $ok('...and had NO side effects', DB::table('gtm_activities')->count() === $actsBefore && DB::table('gtm_workflow_runs')->where('workflow_id', $w1)->count() === $runsOf($w1)->count());
    [$c, $j] = $req('POST', "/gtm/workflows/$w1/test", ['entity_id' => $dSmall]);   $ok('dry run on a record that fails the conditions: would_run=false, with which condition failed', $j['data']['would_run'] === false && collect($j['data']['conditions'])->contains(fn ($x) => $x['pass'] === false), $j['data'] ?? $j);
    [$c, $j] = $req('POST', "/gtm/workflows/$wLoop/test", ['entity_id' => $acct]);  $ok('dry run flags that a run would need approval (consequential action)', $c === 200 && $j['data']['needs_approval'] === true && $j['data']['actions'][0]['consequential'] === true, $j['data'] ?? $j);
    [$c] = $req('POST', "/gtm/workflows/$w1/test", ['entity_id' => 999999]);        $ok('dry run on an unknown record -> 404', $c === 404, $c);
    [$c] = $req('POST', "/gtm/workflows/$w1/test", ['entity_id' => $dBig], $E);     $ok('dry run: employee 403', $c === 403, $c);

    // ---------------- run history ----------------
    [$c, $j] = $req('GET', '/gtm/workflow-runs?status=failed'); $ok('run list: filter by status, with workflow names and per-status counts', $c === 200 && collect($j['data']['items'])->every(fn ($r) => $r['status'] === 'failed') && isset($j['data']['counts']['succeeded']), $j['data']['counts'] ?? $c);
    [$c, $j] = $req('GET', "/gtm/workflow-runs?workflow_id=$w1"); $ok('run list: filter by workflow', collect($j['data']['items'])->every(fn ($r) => (int) $r['workflow_id'] === $w1) && count($j['data']['items']) === 2, collect($runsOf($w1))->map(fn ($r) => [$r->entity_id => $r->status, 'deals' => DB::table('gtm_deals')->where('id', $r->entity_id)->value('name')])->all());
    [$c, $j] = $req('GET', "/gtm/workflows/$w1"); $ok('workflow detail: definition, whether it needs approval, allowed transitions, recent runs', $c === 200 && count($j['data']['runs']) === 2 && $j['data']['transitions'] === ['paused', 'archived'], $j['data'] ?? $j);
    [$c, $j] = $req('GET', '/gtm/workflows'); $ok('workflow list carries 7-day run counts and the trigger label', collect($j['data']['items'])->firstWhere('id', $w1)['runs_7d']['succeeded'] === 2 && collect($j['data']['items'])->firstWhere('id', $w1)['trigger_label'] === 'Deal created', collect($j['data']['items'])->firstWhere('id', $w1));

    // ---------------- tenant isolation ----------------
    if ($O) {
        $otherTenant = (int) DB::table('tbluser')->where('id', DB::table('personal_access_tokens')->where('name', 'gtm-verify')->orderByDesc('id')->value('tokenable_id'))->value('sub_institute_id');
        $oAdmin = DB::table('personal_access_tokens')->where('name', 'gtm-verify')->orderByDesc('id')->value('tokenable_id');
        $ow = DB::table('gtm_workflows')->insertGetId(['sub_institute_id' => $otherTenant, 'name' => 'Verify Other Tenant WF', 'trigger_event' => 'gtm.deal.created', 'conditions' => '[]', 'actions' => json_encode([['type' => 'log_activity', 'params' => ['subject' => 'LEAK {{deal.name}}']]]), 'status' => 'active', 'activated_at' => now()->subDay(), 'created_by' => $oAdmin, 'created_at' => now(), 'updated_at' => now()]);
        [$c, $j] = $req('POST', '/gtm/deals', ['account_id' => $acct, 'name' => 'Verify WF Isolation Deal', 'amount' => 1, 'currency' => 'INR']); $dIso = $j['data']['deal']['id']; $deliver('gtm.deal.created', $dIso);
        $ok("another organisation's ACTIVE workflow for the same event never fires on my record", DB::table('gtm_workflow_runs')->where('workflow_id', $ow)->count() === 0 && ! DB::table('gtm_activities')->where('subject', 'like', 'LEAK%')->exists());
        [$c, $j] = $req('GET', '/gtm/workflows', [], $O);      $ok("another tenant's workflow list holds none of mine", collect($j['data']['items'])->pluck('id')->intersect([$w1, $wLoop, $wApp])->isEmpty(), $c);
        foreach ([['GET', "/gtm/workflows/$w1"], ['PATCH', "/gtm/workflows/$w1"], ['POST', "/gtm/workflows/$w1/status"], ['POST', "/gtm/workflows/$w1/test"]] as [$m, $u]) { [$c] = $req($m, $u, ['name' => 'x', 'status' => 'archived', 'confirm' => true, 'entity_id' => $dBig], $O); $ok("other tenant $m " . preg_replace('#/gtm/#', '', $u) . ' -> 404', $c === 404, $c); }
        $rid = $runsOf($w1)->first()->id;
        [$c] = $req('GET', "/gtm/workflow-runs/$rid", [], $O);                         $ok("another tenant cannot read my run (404)", $c === 404, $c);
        [$c] = $req('POST', "/gtm/workflow-runs/{$rr[0]->id}/approve", ['confirm' => true], $O); $ok("another tenant cannot approve my run (404)", $c === 404, $c);
        [$c, $j] = $req('GET', '/gtm/workflow-runs', [], $O);  $ok("another tenant's run list holds none of mine", collect($j['data']['items'])->pluck('id')->intersect($runsOf($w1)->pluck('id'))->isEmpty(), $c);
        [$c] = $req('POST', "/gtm/workflows/$ow/test", ['entity_id' => $dBig], $O);    $ok("their own workflow cannot be tested against MY deal (404: the record is not theirs)", $c === 404, $c);
    }

    // ---------------- archive ----------------
    [$c, $j] = $req('POST', "/gtm/workflows/$wLoop/status", ['status' => 'active']); $req('PATCH', "/gtm/accounts/$acct", ['stage' => 'engaged']); sleep(1); $deliver('gtm.account.updated', $acct);
    $pendingBefore = DB::table('gtm_workflow_runs')->where('workflow_id', $wLoop)->where('status', 'pending_approval')->count();
    [$c, $j] = $req('POST', "/gtm/workflows/$wLoop/status", ['status' => 'archived']);  $ok('archiving needs confirmation (409)', $c === 409, $c);
    [$c, $j] = $req('POST', "/gtm/workflows/$wLoop/status", ['status' => 'archived', 'confirm' => true]);
    $ok('archiving cancels the runs that had not started, and is final', $c === 200 && $j['data']['runs_cancelled'] === $pendingBefore && DB::table('gtm_workflow_runs')->where('workflow_id', $wLoop)->where('status', 'pending_approval')->count() === 0, [$c, $j]);
    [$c] = $req('POST', "/gtm/workflows/$wLoop/status", ['status' => 'active']);       $ok('an archived workflow cannot be reactivated (422)', $c === 422, $c);
    [$c, $j] = $req('GET', '/gtm/workflows'); $ok('archived workflows leave the list', ! collect($j['data']['items'])->pluck('id')->contains($wLoop), $c);

    // ---------------- the real catalogue and the real reactor ----------------
    $inv = EventCatalogue::assertInvariants();
    $ok('EventCatalogue: every invariant holds with all GTM events registered', $inv === [], $inv);
    $gtmTypes = array_keys(App\Domain\Gtm\GtmEvents::TYPES);
    $ok('EventCatalogue: every GtmEvents type is shipped, consumed by AuditLogProjector; triggerable ones also by GtmWorkflowRunner', collect($gtmTypes)->every(fn ($t) => isset(EventCatalogue::SHIPPED[$t]['AuditLogProjector']) && (isset(EventCatalogue::SHIPPED[$t]['GtmWorkflowRunner']) === App\Domain\Gtm\GtmEvents::TYPES[$t]['triggerable'])), collect($gtmTypes)->reject(fn ($t) => isset(EventCatalogue::SHIPPED[$t]))->all());
    $ok('GtmWorkflowRunner is registered with the existing events:react command', str_contains(file_get_contents(base_path('app/Console/Commands/ReactEvents.php')), 'GtmWorkflowRunner::class'));
    $pending = $runner->pendingCount(); $first = $runner->catchUp(5000); $second = $runner->catchUp(5000);
    $ok('catchUp() drains the backlog through the real delivery path and a second pass finds nothing', $first >= 1 && $second === 0 && $runner->pendingCount() === 0, [$pending, $first, $second]);
    $ok('workflow audit events recorded: created, updated, status_changed, run_completed, run_failed, run_decided', count(array_intersect(['gtm.workflow.created', 'gtm.workflow.updated', 'gtm.workflow.status_changed', 'gtm.workflow.run_completed', 'gtm.workflow.run_failed', 'gtm.workflow.run_decided'], DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'like', 'gtm.workflow.%')->pluck('type')->unique()->all())) === 6);
} finally {
    Http::swap(new Illuminate\Http\Client\Factory);
    DB::table('gtm_workflows')->where('name', 'like', 'Verify %')->update(['status' => 'archived']);
    DB::table('gtm_outreach_messages')->where('contact_id', $ctWf ?? 0)->delete();
    gtm_verify_cleanup($startedAt, $origStage);
    DB::table('gtm_contacts')->where('email', 'like', 'verify.wf.%@example.invalid')->delete();
    echo "\ncleaned up (only rows named 'Verify ...' and rows attached to them)\n";
}
echo "$pass passed, $fail failed\n";
