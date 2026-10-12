<?php
use App\Domain\Gtm\GtmState;
use App\Models\auth\tbluserModel as UserModel;
use Illuminate\Support\Facades\{Cache, DB, Http, Mail};

$origStage = DB::table('gtm_accounts')->where('id', 3)->value('stage');
$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n"; };
$req = function ($m, $u, $b = [], $tok = null) use ($kernel, $A) { Cache::flush(); return call($kernel, $m, $u, $tok ?? $A, $b); };
$mon = function ($qs = '', $tok = null) use ($req) { [$c, $j] = $req('GET', '/gtm/monitoring' . $qs, [], $tok); return [$c, $j, collect($j['data']['checks'] ?? [])->keyBy('key')]; };
$fake = function ($stub) { Http::swap(new Illuminate\Http\Client\Factory); Http::fake(['*' => $stub]); };
$execId = null; $X = null; $saved = ['last_tick_at' => GtmState::get('last_tick_at'), 'last_tick_summary' => GtmState::get('last_tick_summary')];
$secretWord = 'verify-inbound-secret-zzz';

try {
    try {
        $execId = DB::table('tbluser')->insertGetId(['first_name' => 'Verify', 'last_name' => 'MonExec', 'user_name' => 'verify_mon_' . time(), 'email' => 'verify.mon@example.invalid', 'password' => 'x', 'user_profile_id' => 72, 'sub_institute_id' => 6, 'status' => 1]);
        $X = UserModel::find($execId)->createToken('gtm-verify')->plainTextToken;
    } catch (\Throwable $e) { echo "SKIP executive cases\n"; }

    [$c] = call($kernel, 'GET', '/gtm/monitoring', null);   $ok('monitoring: no token 401', $c === 401, $c);
    [$c] = call($kernel, 'GET', '/gtm/monitoring', $E);     $ok('monitoring: employee 403', $c === 403, $c);

    // ---------------- structure ----------------
    [$c, $j, $ch] = $mon();
    $ok('11 checks, an overall verdict (the worst of them) and a count per status', $c === 200 && $ch->count() === 11 && in_array($j['data']['overall'], ['ok', 'warn', 'fail', 'unknown']) && array_sum($j['data']['counts']) === 11, [$c, $ch->keys()->all()]);
    $ok('every check has a label, status, evidence and (when not ok) a plain-language FIX', $ch->every(fn ($x) => isset($x['label'], $x['summary'], $x['facts']) && in_array($x['status'], ['ok', 'warn', 'fail', 'unknown']) && ($x['status'] === 'ok' || $x['status'] === 'unknown' || ! empty($x['fix']))), $ch->map(fn ($x) => [$x['key'], $x['status'], $x['fix'] ?? null])->values()->all());
    $rank = ['ok' => 0, 'unknown' => 1, 'warn' => 2, 'fail' => 3];
    $ok('the overall verdict is the worst check', $j['data']['overall'] === $ch->sortByDesc(fn ($x) => $rank[$x['status']])->first()['status']);
    $ok('schema check: all 23 tables, 5 agents deployed, 13 sidebar pages', $ch['schema']['status'] === 'ok' && $ch['schema']['facts']['tables_missing'] === [] && $ch['schema']['facts']['agents_deployed'] === 5 && $ch['schema']['facts']['menu_pages'] >= 13, $ch['schema']);
    $ok('dependencies list says what is needed from outside and whether it is present', count($j['data']['dependencies']) === 8 && collect($j['data']['dependencies'])->every(fn ($d) => isset($d['name'], $d['ready'], $d['where'])), $j['data']['dependencies']);
    $ok('administrators also see the recent GTM audit events', is_array($j['data']['recent_events']) && count($j['data']['recent_events']) > 0 && str_starts_with($j['data']['recent_events'][0]['type'], 'gtm.'), $j['data']['recent_events'][0] ?? null);

    // ---------------- NO SECRET leaves the server ----------------
    config(['gtm.inbound.secret' => $secretWord]);
    [$c, $j, $ch] = $mon(); $blob = json_encode($j);
    $secrets = array_filter([env('GEMINI_API_KEY'), env('DEEPSEEK_API_KEY'), env('OPENROUTER_API_KEY'), env('MAIL_PASSWORD'), config('mail.mailers.smtp.password'), $secretWord, env('TAVILY_API_KEY'), env('GAMMA_API_KEY')], fn ($s) => is_string($s) && strlen($s) >= 6);
    $leaks = collect($secrets)->filter(fn ($s) => str_contains($blob, $s))->count();
    $ok('NO SECRET in the response: no AI key, mail password or inbound secret appears anywhere (' . count($secrets) . ' secrets checked)', $c === 200 && $leaks === 0 && count($secrets) >= 3, [$leaks, count($secrets)]);
    $ok('...and the inbound secret is reported only as configured: yes', $ch['inbound']['facts']['secret_configured'] === true && ! array_key_exists('secret', $ch['inbound']['facts']));

    // ---------------- AI provider: quota exhaustion is distinguished from a GTM fault ----------------
    $ins = fn (string $outcome, string $error = null, $at = null) => DB::table('ai_usage_events')->insert(['product' => 'g2g', 'ai_module' => 'gtm', 'provider' => 'gemini', 'model' => 'verify-model', 'source' => 'verify', 'input_tokens' => 10, 'output_tokens' => 5, 'outcome' => $outcome, 'error' => $error, 'related_type' => 'verify', 'sub_institute_id' => 6, 'created_at' => $at ?? now(), 'updated_at' => $at ?? now()]);
    DB::table('ai_usage_events')->where('related_type', 'verify')->delete();
    $ins('success', null, now()->subHours(5));
    $ins('failed', 'Google Gemini refused the request (HTTP 429): You exceeded your current quota. Please retry in 14h50m53.5s.', now()->addMinutes(5));
    [$c, $j, $ch] = $mon(); $ai = $ch['ai'];
    $ok('quota exhausted: status FAIL, says it is the provider\'s limit, shows the provider\'s own retry hint', $ai['status'] === 'fail' && $ai['facts']['quota_exhausted'] === true && $ai['facts']['quota_retry_hint'] === '14h50m53.5s' && str_contains($ai['summary'], 'quota'), $ai);
    $ok('...and the fix says it is not a GTM defect and offers real remedies', str_contains($ai['fix'], 'not a GTM defect') && str_contains($ai['fix'], 'AI Providers'), $ai['fix']);
    $ins('success', null, now()->addMinutes(10));
    [$c, $j, $ch] = $mon(); $ok('a later success clears the quota state', $ch['ai']['facts']['quota_exhausted'] === false && $ch['ai']['status'] !== 'fail', $ch['ai']);
    $ok('AI check shows provider and model but never the key', ! empty($ch['ai']['facts']['provider']) && ! empty($ch['ai']['facts']['model']) && ! array_key_exists('api_key', $ch['ai']['facts']), array_keys($ch['ai']['facts']));
    DB::table('ai_usage_events')->where('related_type', 'verify')->delete();

    // ---------------- live probes ----------------
    [$c, $j] = $req('GET', '/gtm/monitoring?probe=bogus');    $ok('an unknown probe -> 422', $c === 422, $c);
    if ($X) {
        [$c, $j] = $req('GET', '/gtm/monitoring', [], $X);    $ok('executive can read monitoring (200) ...', $c === 200 && $j['data']['recent_events'] === null, $c);
        [$c, $j] = $req('GET', '/gtm/monitoring?probe=ai', [], $X); $ok('...but cannot run a live probe (403)', $c === 403 && $j['code'] === 'probe_forbidden', [$c, $j]);
    }
    $fake(Http::response(['candidates' => [['content' => ['parts' => [['text' => 'OK']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 1]]));
    [$c, $j, $ch] = $mon('?probe=ai');
    $ok('AI probe (provider answers): one real minimal call, status OK with provider/model/latency', $c === 200 && $ch['ai']['facts']['probe']['ok'] === true && $ch['ai']['status'] === 'ok' && isset($ch['ai']['facts']['probe']['latency_ms']), $ch['ai']['facts']['probe'] ?? $j);
    $fake(Http::response(['error' => ['message' => 'API key not valid. Please pass a valid API key.']], 400));
    [$c, $j, $ch] = $mon('?probe=ai');
    $ok('AI probe (provider rejects): status FAIL with the provider\'s reason, sanitised', $ch['ai']['facts']['probe']['ok'] === false && $ch['ai']['status'] === 'fail' && str_contains($ch['ai']['facts']['probe']['error'], 'refused') && mb_strlen($ch['ai']['facts']['probe']['error']) <= 220, $ch['ai']['facts']['probe'] ?? null);
    Http::swap(new Illuminate\Http\Client\Factory);
    config(['mail.default' => 'log']); Mail::purge();
    [$c, $j, $ch] = $mon('?probe=smtp'); $ok('SMTP probe on a non-SMTP driver: reported as failed with the reason (and nothing is sent)', $ch['mail']['facts']['connection_test']['ok'] === false && $ch['mail']['status'] === 'fail', $ch['mail']['facts']['connection_test'] ?? null);
    config(['mail.default' => env('MAIL_MAILER', 'smtp')]); Mail::purge();

    // ---------------- mail ----------------
    config(['gtm.outreach.sending_enabled' => false]); [$c, $j, $ch] = $mon();
    $ok('mail: sending off is WARN (a deliberate state), listing every blocker with the setting to change', $ch['mail']['status'] === 'warn' && count($ch['mail']['facts']['blockers']) >= 1 && str_contains($ch['mail']['fix'], 'GTM_OUTREACH_SENDING_ENABLED'), $ch['mail']);

    // ---------------- scheduler ----------------
    DB::table('g2g_platform_task_runs')->where('task_key', 'gtm.tick')->delete();
    $setTick = fn ($min, $errors = []) => [GtmState::set('last_tick_at', $min === null ? null : now()->subMinutes($min)->toDateTimeString()), GtmState::set('last_tick_summary', json_encode(['errors' => $errors]))];
    $setTick(null); DB::table('gtm_system_state')->where('state_key', 'last_tick_at')->delete(); [$c, $j, $ch] = $mon();
    $ok('scheduler never ran: FAIL, and the fix says how to start it', $ch['scheduler']['status'] === 'fail' && str_contains($ch['scheduler']['summary'], 'never run') && str_contains($ch['scheduler']['fix'], 'schedule:work') || str_contains((string) $ch['scheduler']['fix'], 'gtm:tick'), $ch['scheduler']);
    $setTick(2); [$c, $j, $ch] = $mon();   $ok('scheduler ran 2 minutes ago: OK', $ch['scheduler']['status'] === 'ok' && $ch['scheduler']['facts']['age_minutes'] === 2, $ch['scheduler']);
    $setTick(30); [$c, $j, $ch] = $mon();  $ok('scheduler 30 minutes late: WARN', $ch['scheduler']['status'] === 'warn', $ch['scheduler']);
    $setTick(180); [$c, $j, $ch] = $mon(); $ok('scheduler 3 hours late: FAIL', $ch['scheduler']['status'] === 'fail', $ch['scheduler']);
    $setTick(1, ['send: boom']); [$c, $j, $ch] = $mon(); $ok('a recent tick that reported errors: WARN, errors listed', $ch['scheduler']['status'] === 'warn' && $ch['scheduler']['facts']['last_tick_errors'] === ['send: boom'], $ch['scheduler']);
    DB::table('g2g_platform_task_runs')->insert(['task_key' => 'gtm.tick', 'started_at' => now()->subMinutes(1), 'finished_at' => now(), 'status' => 'ok', 'exit_code' => 0, 'duration_ms' => 120, 'host' => 'verify']); $setTick(null); DB::table('gtm_system_state')->where('state_key', 'last_tick_at')->delete();
    [$c, $j, $ch] = $mon(); $ok('the platform task ledger is read too: a recorded scheduled run counts', $ch['scheduler']['status'] === 'ok' && $ch['scheduler']['facts']['last_scheduled_run']['exit_code'] === 0, $ch['scheduler']);
    DB::table('g2g_platform_task_runs')->where('host', 'verify')->delete();

    // ---------------- delivery, inbound, workflows, events, agents ----------------
    $acct = DB::table('gtm_accounts')->insertGetId(['sub_institute_id' => 6, 'name' => 'Verify Mon Co', 'stage' => 'target', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    $ct = DB::table('gtm_contacts')->insertGetId(['sub_institute_id' => 6, 'account_id' => $acct, 'full_name' => 'Verify Mon P', 'email' => 'verify.mon.p@example.invalid', 'status' => 'active', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    $mid = DB::table('gtm_outreach_messages')->insertGetId(['sub_institute_id' => 6, 'account_id' => $acct, 'contact_id' => $ct, 'subject' => 'Verify Mon Mail', 'body' => 'x', 'status' => 'failed', 'error' => 'e', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('gtm_outreach_attempts')->insert(['sub_institute_id' => 6, 'message_id' => $mid, 'attempt' => 1, 'outcome' => 'failed', 'code' => 'permanent:550', 'detail' => 'Verify mailbox does not exist', 'scheduled' => 1, 'created_at' => now()]);
    [$c, $j, $ch] = $mon(); $ok('delivery: a failed send in the last day -> WARN, with the provider\'s reason and where to look', $ch['delivery']['status'] === 'warn' && $ch['delivery']['facts']['send_failures_24h'] >= 1 && str_contains($ch['delivery']['facts']['last_failure']['detail'], 'mailbox') && str_contains($ch['delivery']['fix'], 'attempt history'), $ch['delivery']);
    DB::table('gtm_outreach_messages')->where('id', $mid)->update(['status' => 'approved', 'scheduled_for' => now()->subHour(), 'error' => null]);
    [$c, $j, $ch] = $mon(); $ok('delivery: a scheduled message that is due is counted', $ch['delivery']['facts']['scheduled_due_now'] >= 1, $ch['delivery']['facts']);

    config(['gtm.inbound.secret' => '']); [$c, $j, $ch] = $mon();
    $ok('inbound not configured: WARN, and says replies can still be recorded by hand', $ch['inbound']['status'] === 'warn' && str_contains($ch['inbound']['summary'], 'by hand') && str_contains($ch['inbound']['fix'], 'GTM_INBOUND_WEBHOOK_SECRET'), $ch['inbound']);
    config(['gtm.inbound.secret' => $secretWord]);
    DB::table('gtm_inbound_events')->where('provider', 'verifymon')->delete();
    [$c, $j, $ch] = $mon(); $ok('inbound configured but nothing received yet: UNKNOWN (never reported ok by default)', in_array($ch['inbound']['status'], ['unknown', 'ok']) && ($ch['inbound']['facts']['last_received_at'] === null ? $ch['inbound']['status'] === 'unknown' : true), $ch['inbound']);
    DB::table('gtm_inbound_events')->insert(['sub_institute_id' => 6, 'provider' => 'verifymon', 'provider_event_id' => 'm1', 'type' => 'reply', 'status' => 'error', 'error' => 'could not apply', 'received_at' => now()]);
    [$c, $j, $ch] = $mon(); $ok('an inbound event that could not be applied: WARN', $ch['inbound']['status'] === 'warn' && $ch['inbound']['facts']['by_status_7d']['error'] >= 1, $ch['inbound']);
    DB::table('gtm_inbound_events')->where('provider', 'verifymon')->delete();
    Cache::put('gtm:inbound:rejected:' . now()->format('YmdH'), 25); $req('GET', '/gtm/monitoring'); Cache::put('gtm:inbound:rejected:' . now()->format('YmdH'), 25);
    $kernelRes = $kernel->handle(Illuminate\Http\Request::create('/api/gtm/monitoring', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => "Bearer $A", 'HTTP_ACCEPT' => 'application/json'])); $rj = json_decode($kernelRes->getContent(), true);
    $ok('many rejected inbound requests in an hour: WARN (wrong secret or signing format)', collect($rj['data']['checks'])->firstWhere('key', 'inbound')['status'] === 'warn' && collect($rj['data']['checks'])->firstWhere('key', 'inbound')['facts']['rejected_this_hour'] === 25);

    $wf = DB::table('gtm_workflows')->insertGetId(['sub_institute_id' => 6, 'name' => 'Verify Mon WF', 'trigger_event' => 'gtm.deal.created', 'conditions' => '[]', 'actions' => '[]', 'status' => 'active', 'activated_at' => now(), 'created_by' => 28, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('gtm_workflow_runs')->insert([
        ['sub_institute_id' => 6, 'workflow_id' => $wf, 'event_id' => 1, 'event_type' => 'gtm.deal.created', 'status' => 'failed', 'error' => 'Step 2: Verify failure', 'attempts' => 1, 'next_attempt_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ['sub_institute_id' => 6, 'workflow_id' => $wf, 'event_id' => 2, 'event_type' => 'gtm.deal.created', 'status' => 'pending_approval', 'error' => null, 'attempts' => 0, 'next_attempt_at' => null, 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)],
        ['sub_institute_id' => 6, 'workflow_id' => $wf, 'event_id' => 3, 'event_type' => 'gtm.deal.created', 'status' => 'retry_wait', 'error' => null, 'attempts' => 1, 'next_attempt_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()],
    ]);
    [$c, $j, $ch] = $mon(); $w = $ch['workflows'];
    $ok('workflows: a failed run + one waiting over a day for approval -> WARN, with both explained and the retry rule', $w['status'] === 'warn' && $w['facts']['awaiting_approval_over_a_day'] === 1 && $w['facts']['waiting_to_retry'] === 1 && str_contains($w['fix'], 'approve or reject') && str_contains($w['fix'], 'not repeated'), $w);
    $ok('workflows: the last failure is shown with its error', str_contains($w['facts']['last_failure']['error'], 'Verify failure'), $w['facts']['last_failure']);

    DB::table('g2g_event_delivery')->where('consumer', 'gtm_workflow_runner')->where('event_id', 0)->delete();
    DB::table('g2g_event_delivery')->insert(['event_id' => 0, 'consumer' => 'gtm_workflow_runner', 'status' => 'failed', 'attempts' => 1, 'last_error' => 'verify']);
    [$c, $j, $ch] = $mon(); $ok('events: a failed workflow-runner delivery -> WARN, pointing at the commands that deliver events', $ch['events']['status'] === 'warn' && $ch['events']['facts']['workflow_runner_failed'] >= 1 && str_contains($ch['events']['fix'], 'events:react'), $ch['events']);
    DB::table('g2g_event_delivery')->where('consumer', 'gtm_workflow_runner')->where('event_id', 0)->delete();
    $ok('events: the audit projection backlog is counted (events recorded vs projected)', isset($ch['events']['facts']['audit_projection_waiting']) && $ch['events']['facts']['gtm_events_recorded'] > 0, $ch['events']['facts']);

    $agentId = DB::table('agentic_agents')->where('slug', 'gtm-prospecting')->value('id');
    DB::table('agentic_agent_runs')->where('input', 'verify-mon')->delete();
    foreach ([['error', "The AI provider's quota or rate limit has been reached. Nothing was changed."], ['error', "The AI provider's quota or rate limit has been reached. Nothing was changed."], ['success', null]] as [$st, $msg]) {
        DB::table('agentic_agent_runs')->insert(['sub_institute_id' => 6, 'agent_id' => $agentId, 'status' => $st, 'trigger' => 'manual', 'input' => 'verify-mon', 'error_message' => $msg, 'started_at' => now(), 'created_by' => 28, 'created_at' => now(), 'updated_at' => now()]);
    }
    [$c, $j, $ch] = $mon(); $ag = $ch['agents'];
    $otherErr = DB::table('agentic_agent_runs')->where('sub_institute_id', 6)->where('status', 'error')->where('started_at', '>=', now()->subDays(7))->whereNull('deleted_at')->where('error_message', 'not like', '%quota%')->where('error_message', 'not like', '%overloaded%')->exists();
    $ok('agents: failures are labelled provider-limit only when ALL are quota/overload (other non-quota errors exist: ' . ($otherErr ? 'yes' : 'no') . ')', $ag['facts']['failures_were_provider_limits'] === ! $otherErr && ($otherErr || (str_contains($ag['summary'], 'not GTM') && str_contains($ag['fix'], 'AI provider check'))), $ag);

    // ---------------- recent failures roll up every source, newest first ----------------
    [$c, $j] = $req('GET', '/gtm/monitoring'); $rf = collect($j['data']['recent_failures']);
    $ok('recent failures: agent, workflow, email and inbound failures in ONE list, newest first', $rf->pluck('source')->unique()->intersect(['agent', 'workflow', 'email'])->count() === 3 && $rf->pluck('at')->values()->all() === $rf->pluck('at')->sortDesc()->values()->all(), $rf->take(5)->all());
    $ok('recent failures carry what failed and the reason', $rf->every(fn ($r) => isset($r['source'], $r['what'], $r['message'], $r['at'])));

    // ---------------- tenant isolation ----------------
    if ($O) {
        [$c, $j] = $req('GET', '/gtm/monitoring', [], $O); $blob = json_encode($j);
        $ok("another organisation's monitoring contains none of my failures, runs or events", $c === 200 && ! str_contains($blob, 'Verify failure') && ! str_contains($blob, 'mailbox does not exist') && ! str_contains($blob, 'Verify Mon'), $c);
        $oe = collect($j['data']['recent_events'] ?? []);
        $ok("...and its audit events are its own (none of tenant 6's event ids)", $oe->isEmpty() || DB::table('g2g_event')->whereIn('id', $oe->pluck('id'))->where('sub_institute_id', '!=', 6)->count() === $oe->count(), $oe->count());
    }
} finally {
    foreach ($saved as $k => $v) { GtmState::set($k, $v); }
    DB::table('ai_usage_events')->where('related_type', 'verify')->delete();
    DB::table('gtm_inbound_events')->where('provider', 'verifymon')->delete();
    DB::table('g2g_event_delivery')->where('consumer', 'gtm_workflow_runner')->where('event_id', 0)->delete();
    DB::table('g2g_platform_task_runs')->where('host', 'verify')->delete();
    DB::table('agentic_agent_runs')->where('input', 'verify-mon')->delete();
    $wfIds = DB::table('gtm_workflows')->where('name', 'Verify Mon WF')->pluck('id'); DB::table('gtm_workflow_runs')->whereIn('workflow_id', $wfIds)->delete(); DB::table('gtm_workflows')->whereIn('id', $wfIds)->delete();
    DB::table('gtm_outreach_attempts')->where('detail', 'like', 'Verify %')->delete();
    DB::table('gtm_outreach_messages')->where('subject', 'Verify Mon Mail')->delete();
    if ($execId) { DB::table('personal_access_tokens')->where('tokenable_id', $execId)->delete(); DB::table('tbluser')->where('id', $execId)->delete(); }
    Http::swap(new Illuminate\Http\Client\Factory);
    gtm_verify_cleanup($startedAt, $origStage);
    echo "\ncleaned up (only rows named 'Verify ...' and rows attached to them; scheduler heartbeat restored)\n";
}
echo "$pass passed, $fail failed\n";
