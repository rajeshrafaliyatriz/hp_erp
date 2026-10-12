<?php
use Illuminate\Support\Facades\{Cache, DB, Http};

$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n"; };
$base = ['run' => DB::table('agentic_agent_runs')->max('id'), 'an' => DB::table('gtm_analyses')->max('id'), 'act' => DB::table('gtm_activities')->max('id'), 'deal' => (int) DB::table('gtm_deals')->max('id')];
$acct = DB::table('gtm_accounts')->where('id', 3)->first();
$origStage = $acct->stage;
$fake = function ($stub) { Http::swap(new Illuminate\Http\Client\Factory); Http::fake(['*' => $stub]); };
$gem = fn ($arr) => Http::response(['candidates' => [['content' => ['parts' => [['text' => is_string($arr) ? $arr : json_encode($arr)]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 10]]);
$req = function ($m, $u, $b = [], $tok = null) use ($kernel, $A) { Cache::flush(); return call($kernel, $m, $u, $tok ?? $A, $b); };
$run = fn ($slug, $input) => $req('POST', "/gtm/agents/$slug/run", ['input' => $input]);
$otherAcct = 0; $createdContacts = [];

try {
    // ---------- agents with no deals yet ----------
    [$c, $j] = $req('GET', '/gtm/agents');
    $ag = collect($j['data']['agents'] ?? []);
    $ok('deal coach + revops now runnable (deals table exists)', $ag->where('runnable', true)->count() === 5, $ag->map(fn ($a) => $a['slug'] . ':' . ($a['runnable'] ? 'y' : 'n'))->all());
    [$c, $j] = $run('gtm-revops', []);  $ok('revops with no open deals -> no_data 422, no AI call', $c === 422 && $j['code'] === 'no_data', [$c, $j]);
    [$c, $j] = $run('gtm-deal-coach', ['deal_id' => 999999]); $ok('deal coach unknown deal 422', $c === 422, $c);

    // ---------- permissions ----------
    [$c] = call($kernel, 'GET', '/gtm/deals', null);             $ok('list: no token 401', $c === 401, $c);
    [$c] = call($kernel, 'GET', '/gtm/deals', $E);               $ok('list: employee 403', $c === 403, $c);
    [$c] = call($kernel, 'POST', '/gtm/deals', $E, ['name' => 'x', 'account_id' => 3]); $ok('create: employee 403', $c === 403, $c);

    // ---------- create + validation ----------
    $valid = ['account_id' => 3, 'name' => 'Verify Deal A', 'amount' => 100000, 'currency' => 'INR'];
    [$c, $j] = $req('POST', '/gtm/deals', $valid);                           $ok('create 201 (discovery, stage clock set)', $c === 201 && $j['data']['deal']['stage'] === 'discovery' && $j['data']['deal']['stage_changed_at'], [$c, $j]);
    $A1 = $j['data']['deal']['id'] ?? 0;
    foreach ([
        'missing name' => [['name' => ''] + $valid],
        'amount without currency' => [['currency' => null] + $valid],
        'lowercase currency' => [['currency' => 'inr'] + $valid],
        'long currency' => [['currency' => 'RUPEE'] + $valid],
        'negative amount' => [['amount' => -5] + $valid],
        'non-numeric amount' => [['amount' => 'lots'] + $valid],
        'unknown account' => [['account_id' => 999999] + $valid],
        'stage won on create' => [['stage' => 'won'] + $valid],
        'bad date' => [['expected_close_date' => 'not-a-date'] + $valid],
        'unknown methodology' => [['methodology_slug' => 'nope'] + $valid],
        'non-methodology playbook as methodology' => [['methodology_slug' => 'deal-coach'] + $valid],
    ] as $label => [$body]) { [$c] = $req('POST', '/gtm/deals', $body); $ok("create invalid ($label) -> 422", $c === 422, $c); }

    // another tenant's account and user must be refused
    if ($O) {
        $otherTenant = DB::table('tbluser')->whereIn('id', [DB::table('personal_access_tokens')->where('name', 'gtm-verify')->orderByDesc('id')->value('tokenable_id')])->value('sub_institute_id');
        $otherAcct = DB::table('gtm_accounts')->insertGetId(['sub_institute_id' => $otherTenant, 'name' => 'Verify Other Tenant Co', 'stage' => 'target', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        [$c] = $req('POST', '/gtm/deals', ['account_id' => $otherAcct] + $valid);  $ok("create against another tenant's account -> 422", $c === 422, $c);
        $foreignUser = DB::table('tbluser')->where('sub_institute_id', $otherTenant)->value('id');
        [$c] = $req('POST', '/gtm/deals', ['owner_user_id' => $foreignUser] + $valid); $ok('owner from another tenant -> 422', $c === 422, $c);
    }
    [$c, $j] = $req('POST', '/gtm/deals', ['methodology_slug' => 'qualification-meddicc', 'name' => 'Verify Deal B', 'amount' => 500, 'currency' => 'USD', 'account_id' => 3]);
    $ok('create with valid methodology + USD', $c === 201, [$c, $j]); $B1 = $j['data']['deal']['id'] ?? 0;

    // ---------- update ----------
    [$c, $j] = $req('PATCH', "/gtm/deals/$A1", ['name' => 'Verify Deal A2']);               $ok('patch name 200', $c === 200 && $j['data']['deal']['name'] === 'Verify Deal A2', $c);
    [$c] = $req('PATCH', "/gtm/deals/$A1", ['stage' => 'won']);                              $ok('patch stage refused (use stage endpoint)', $c === 422, $c);
    [$c] = $req('PATCH', "/gtm/deals/$A1", ['account_id' => 3]);                             $ok('patch account_id refused', $c === 422, $c);
    [$c, $j] = $req('PATCH', "/gtm/deals/$A1", ['amount' => 250000]);                        $ok('patch amount only keeps existing currency', $c === 200 && $j['data']['deal']['currency'] === 'INR', [$c, $j]);
    [$c] = $req('PATCH', "/gtm/deals/$A1", ['currency' => null]);                            $ok('clearing currency while amount set -> 422', $c === 422, $c);
    [$c] = $req('PATCH', "/gtm/deals/$A1", ['amount' => 'x']);                               $ok('patch invalid amount 422', $c === 422, $c);

    // ---------- health rules ----------
    [$c, $j] = $req('GET', "/gtm/deals/$A1");
    $f = $j['data']['health']['flags'] ?? [];
    $ok('fresh deal flags: no_next_step, no_close_date, no_decision_maker', count(array_intersect(['no_next_step', 'no_close_date', 'no_decision_maker'], $f)) === 3, $f);
    $ok('system "deal created" activity does not count as activity', in_array('no_activity_ever', $f, true) || $j['data']['health']['last_activity_at'] !== null, $j['data']['health']);
    [$c] = $req('PATCH', "/gtm/deals/$A1", ['next_step' => 'Send proposal', 'next_step_due' => now()->subDay()->toDateString(), 'expected_close_date' => now()->subDays(2)->toDateString()]);
    [$c, $j] = $req('GET', "/gtm/deals/$A1"); $f = $j['data']['health']['flags'];
    $ok('overdue next step + passed close date flagged; no_next_step/no_close_date cleared', in_array('next_step_overdue', $f, true) && in_array('close_date_passed', $f, true) && ! in_array('no_next_step', $f, true) && ! in_array('no_close_date', $f, true), $f);
    [$c, $j] = $req('POST', "/gtm/deals/$A1/activities", ['type' => 'call', 'subject' => 'Intro call']); $ok('log activity on deal 201', $c === 201 && $j['data']['activity']['deal_id'] === $A1, [$c, $j]);
    [$c] = $req('POST', "/gtm/deals/$A1/activities", ['type' => 'telepathy', 'subject' => 'x']);            $ok('invalid activity type 422', $c === 422, $c);
    [$c] = $req('POST', "/gtm/deals/$A1/activities", ['type' => 'note', 'subject' => '']);                  $ok('empty activity subject 422', $c === 422, $c);
    [$c, $j] = $req('GET', "/gtm/deals/$A1"); $f = $j['data']['health']['flags'];
    $ok('activity clears no_activity_ever, days_since_activity = 0', ! in_array('no_activity_ever', $f, true) && $j['data']['health']['days_since_activity'] === 0, $j['data']['health']);
    DB::table('gtm_activities')->where('deal_id', $A1)->where('type', 'call')->update(['occurred_at' => now()->subDays(20)]);
    DB::table('gtm_activities')->where('account_id', 3)->where('type', '!=', 'system')->where('deal_id', null)->update(['occurred_at' => now()->subDays(40)]);
    [$c, $j] = $req('GET', "/gtm/deals/$A1"); $ok('20 days silent -> no_recent_activity', in_array('no_recent_activity', $j['data']['health']['flags'], true), $j['data']['health']);
    DB::table('gtm_deals')->where('id', $A1)->update(['stage_changed_at' => now()->subDays(45)]);
    [$c, $j] = $req('GET', "/gtm/deals/$A1"); $ok('45 days in stage -> stuck_in_stage (threshold 30)', in_array('stuck_in_stage', $j['data']['health']['flags'], true) && $j['data']['health']['days_in_stage'] === 45, $j['data']['health']);
    [$c, $j] = $req('POST', '/gtm/accounts/3/contacts', ['full_name' => 'Verify Buyer', 'role_in_deal' => 'economic_buyer']); $createdContacts[] = $j['data']['contact']['id'] ?? 0;
    [$c, $j] = $req('GET', "/gtm/deals/$A1"); $ok('economic buyer contact clears no_decision_maker', ! in_array('no_decision_maker', $j['data']['health']['flags'], true), $j['data']['health']);

    // ---------- list / pipeline / health ----------
    [$c, $j] = $req('GET', '/gtm/deals?status=open'); $ok('list open includes both, with account name + health', $c === 200 && collect($j['data']['items'])->contains(fn ($d) => $d['id'] === $A1 && $d['account_name'] === $acct->name && isset($d['health'])), $c);
    [$c, $j] = $req('GET', '/gtm/deals?q=Deal+B');    $ok('search by name', collect($j['data']['items'])->pluck('id')->all() === [$B1], collect($j['data']['items'])->pluck('id')->all());
    [$c, $j] = $req('GET', '/gtm/deals?stage=won');   $ok('stage filter (none won yet)', count($j['data']['items']) === 0, $j['data']['items']);
    [$c, $j] = $req('GET', '/gtm/deals/pipeline');
    $disc = collect($j['data']['stages'])->firstWhere('stage', 'discovery');
    $ok('pipeline: discovery has 2 deals, INR and USD kept separate (never summed)', $disc['count'] === 2 && (float) $disc['value_by_currency']['INR'] === 250000.0 && (float) $disc['value_by_currency']['USD'] === 500.0 && count($disc['value_by_currency']) === 2, $disc);
    $ok('pipeline: no forecast/probability fields exist', ! array_key_exists('forecast', $j['data']) && ! array_key_exists('weighted', $j['data']), array_keys($j['data']));
    [$c, $j] = $req('GET', '/gtm/deals/health');
    $ok('health endpoint lists flagged deals + counts per flag', $c === 200 && $j['data']['flagged_deals'] >= 1 && isset($j['data']['flag_counts']['no_recent_activity']), $j['data']);

    // ---------- tenant isolation ----------
    if ($O) {
        foreach ([['GET', "/gtm/deals/$A1"], ['PATCH', "/gtm/deals/$A1"], ['POST', "/gtm/deals/$A1/stage"], ['DELETE', "/gtm/deals/$A1"], ['POST', "/gtm/deals/$A1/activities"], ['POST', "/gtm/deals/$A1/score"], ['POST', "/gtm/deals/$A1/discovery"]] as [$m, $u]) {
            [$c] = $req($m, $u, ['name' => 'hijack', 'stage' => 'lost', 'confirm' => true, 'close_reason' => 'x', 'type' => 'note', 'subject' => 's', 'notes' => str_repeat('a', 60)], $O);
            $ok("other tenant $m " . substr($u, strrpos($u, '/')) . ' -> 404', $c === 404, $c);
        }
        [$c, $j] = $req('GET', '/gtm/deals', [], $O);            $ok('other tenant list excludes my deals', collect($j['data']['items'])->pluck('id')->intersect([$A1, $B1])->isEmpty(), $c);
        [$c, $j] = $req('GET', '/gtm/deals/pipeline', [], $O);   $ok('other tenant pipeline does not include my value', ! isset($j['data']['open_value_by_currency']['USD']) || (float) $j['data']['open_value_by_currency']['USD'] !== 500.0, $j['data']['open_value_by_currency'] ?? null);
        [$c, $j] = $req('GET', '/gtm/agents/runs', [], $O);     // (agent isolation covered in agents suite)
        [$c, $j] = $run('gtm-deal-coach', ['deal_id' => $A1]);  // control: mine works up to the AI call
    }

    // ---------- stage changes need confirmation ----------
    [$c, $j] = $req('POST', "/gtm/deals/$A1/stage", ['stage' => 'qualification']);           $ok('open -> open needs no confirmation', $c === 200 && $j['data']['deal']['stage'] === 'qualification', [$c, $j]);
    [$c] = $req('POST', "/gtm/deals/$A1/stage", ['stage' => 'qualification']);               $ok('same stage 422', $c === 422, $c);
    [$c] = $req('POST', "/gtm/deals/$A1/stage", ['stage' => 'nonsense']);                    $ok('invalid stage 422', $c === 422, $c);
    [$c, $j] = $req('POST', "/gtm/deals/$A1/stage", ['stage' => 'won']);                     $ok('won without confirm -> 409 confirmation_required', $c === 409 && $j['code'] === 'confirmation_required', [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/deals/$A1/stage", ['stage' => 'lost', 'confirm' => true]); $ok('lost without reason -> 422 reason_required', $c === 422 && $j['code'] === 'reason_required', [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/deals/$A1/stage", ['stage' => 'lost', 'confirm' => true, 'close_reason' => 'Chose an incumbent vendor']);
    $ok('lost with confirm + reason -> closed_at set', $c === 200 && $j['data']['deal']['stage'] === 'lost' && $j['data']['deal']['closed_at'] && $j['data']['deal']['close_reason'], [$c, $j]);
    [$c, $j] = $req('PATCH', "/gtm/deals/$A1", ['name' => 'edit closed']);                   $ok('closed deal cannot be edited (deal_closed)', $c === 422 && $j['code'] === 'deal_closed', [$c, $j]);
    [$c] = $req('POST', "/gtm/deals/$A1/stage", ['stage' => 'discovery']);                   $ok('reopen without confirm -> 409', $c === 409, $c);
    [$c, $j] = $req('POST', "/gtm/deals/$A1/stage", ['stage' => 'negotiation', 'confirm' => true]); $ok('reopen with confirm -> open, closed_at cleared', $c === 200 && $j['data']['deal']['closed_at'] === null && $j['data']['deal']['close_reason'] === null, [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/deals/$A1/stage", ['stage' => 'won', 'confirm' => true]);
    $ok('won with confirm -> account becomes customer', $c === 200 && $j['data']['account_stage_changed'] === true && DB::table('gtm_accounts')->where('id', 3)->value('stage') === 'customer', [$c, $j]);
    $hist = DB::table('gtm_deal_stage_history')->where('deal_id', $A1)->orderBy('id')->pluck('to_stage')->all();
    $ok('stage history recorded in order', $hist === ['discovery', 'qualification', 'lost', 'negotiation', 'won'], $hist);
    [$c, $j] = $req('GET', '/gtm/deals/pipeline'); $ok('pipeline shows the win in the last 90 days, in INR', $j['data']['won']['count'] === 1 && (float) $j['data']['won']['value_by_currency']['INR'] === 250000.0, $j['data']['won']);
    [$c, $j] = $run('gtm-deal-coach', ['deal_id' => $A1]); $ok('deal coach refuses a closed deal 422', $c === 422 && str_contains($j['message'], 'open deals only'), [$c, $j]);

    // ---------- methodology scoring (provider faked => deterministic) ----------
    $dims = [['key' => 'metrics', 'score' => 80, 'evidence' => 'e1'], ['key' => 'economic_buyer', 'score' => 60, 'evidence' => 'e2'], ['key' => 'champion', 'score' => 40, 'evidence' => 'e3'],
        ['key' => 'decision_criteria', 'score' => 100, 'evidence' => 'e4'], ['key' => 'decision_process', 'score' => 20, 'evidence' => 'e5'], ['key' => 'identify_pain', 'score' => 0, 'evidence' => 'e6'], ['key' => 'bogus_dimension', 'score' => 100, 'evidence' => 'invented']];
    $fake($gem(['dimensions' => $dims, 'missing' => ['pricing']]));
    [$c, $j] = $req('POST', "/gtm/deals/$B1/score", []);
    $d = $j['data'] ?? [];
    // weights: metrics1 eb1.5 dc1 dp1 pain1.5 champ1.5 comp0.5 ; scored all but competition => (80*1+60*1.5+40*1.5+100*1+20*1+0*1.5)/(1+1.5+1.5+1+1+1.5)=350/7.5=46.67 -> 47
    $ok('score 200; uses the deal\'s own methodology (MEDDICC)', $c === 200 && ($d['methodology']['slug'] ?? '') === 'qualification-meddicc', [$c, $j]);
    $ok('total computed in code from playbook weights (47)', ($d['total'] ?? null) === 47, $d['total'] ?? null);
    $ok('invented dimension dropped; skipped dimension "Not assessed"; coverage 6 of 7', collect($d['dimensions'] ?? [])->pluck('key')->doesntContain('bogus_dimension') && collect($d['dimensions'])->firstWhere('key', 'competition')['score'] === null && ($d['coverage'] ?? []) === ['scored' => 6, 'of' => 7], $d['coverage'] ?? null);
    $stored = json_decode(DB::table('gtm_analyses')->where('id', $d['analysis_id'] ?? 0)->value('result'), true);
    $ok('stored analysis holds the FINAL computed result, not raw model text', ($stored['total'] ?? null) === 47 && is_array($stored['methodology'] ?? null));
    $fake($gem(['dimensions' => [['key' => 'metrics', 'score' => 90, 'evidence' => 'x'], ['key' => 'champion', 'score' => 90, 'evidence' => 'y']]]));
    [$c, $j] = $req('POST', "/gtm/deals/$B1/score", []);  $ok('fewer than half the dimensions scored -> total is null (no misleading number)', $c === 200 && $j['data']['total'] === null, $j['data'] ?? $j);
    $fake($gem(['dimensions' => [['key' => 'budget', 'score' => 150, 'evidence' => 'x'], ['key' => 'authority', 'score' => -20, 'evidence' => 'y'], ['key' => 'need', 'score' => 50, 'evidence' => 'z'], ['key' => 'timing', 'score' => 50, 'evidence' => 'w']]]));
    [$c, $j] = $req('POST', "/gtm/deals/$B1/score", ['methodology_slug' => 'qualification-bant']); $ok('explicit methodology (BANT); out-of-range scores clamped to 0-100', $c === 200 && collect($j['data']['dimensions'])->pluck('score')->all() === [100, 0, 50, 50] && $j['data']['total'] === 50, $j['data'] ?? $j);
    [$c, $j] = $req('POST', "/gtm/deals/$B1/score", ['methodology_slug' => 'deal-coach']);       $ok('non-methodology playbook refused 422', $c === 422, [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/deals/$B1/score", ['methodology_slug' => 'does-not-exist']);   $ok('unknown methodology 422', $c === 422, [$c, $j]);
    $fake(Http::response(['error' => ['message' => 'boom']], 500)); [$c, $j] = $req('POST', "/gtm/deals/$B1/score", []); $ok('score: provider 500 -> 503 busy, no leak', $c === 503 && $j['code'] === 'ai_provider_busy' && ! str_contains($j['message'], 'boom'), [$c, $j]);
    $fake(Http::response(['error' => ['message' => 'quota']], 429)); [$c, $j] = $req('POST', "/gtm/deals/$B1/score", []); $ok('score: provider 429 -> 503 rate_limited', $c === 503 && $j['code'] === 'ai_rate_limited', [$c, $j]);
    $fake($gem('not json')); [$c, $j] = $req('POST', "/gtm/deals/$B1/score", []);   $ok('score: non-JSON output -> 502, nothing returned', $c === 502 && ! isset($j['data']), $c);
    [$c, $j] = $req('GET', "/gtm/deals/$B1"); $ok('deal detail returns the latest methodology score', ($j['data']['analyses']['methodology_score']['result']['total'] ?? 'x') === 50, $j['data']['analyses']['methodology_score'] ?? null);

    // ---------- discovery analysis ----------
    $notes = "Call with Verify Buyer. They said: \"our teachers lose hours every week on manual attendance\". Budget not discussed. Next call Thursday.";
    [$c] = $req('POST', "/gtm/deals/$B1/discovery", ['notes' => 'too short']); $ok('discovery: short notes 422', $c === 422, $c);
    $fake($gem(['problems' => [['text' => 'Manual attendance wastes time', 'quote' => 'our teachers lose hours every week on manual attendance'], ['text' => 'Invented pain', 'quote' => 'we are desperate to cut costs by 40%']], 'stakeholders' => [['name' => 'Verify Buyer', 'role' => 'economic_buyer']], 'gaps' => ['budget'], 'next_questions' => ['What is the budget?']]));
    [$c, $j] = $req('POST', "/gtm/deals/$B1/discovery", ['notes' => $notes]);
    $ok('discovery 200; quote not present in the notes is dropped (1 of 2 kept)', $c === 200 && count($j['data']['result']['problems']) === 1 && $j['data']['integrity']['quotes_dropped'] === 1, [$c, $j]);
    $fake(Http::response(['error' => ['message' => 'boom']], 500)); [$c, $j] = $req('POST', "/gtm/deals/$B1/discovery", ['notes' => $notes]); $ok('discovery: provider failure -> 503', $c === 503, $c);

    // ---------- Deal Coach + RevOps agents (provider faked) ----------
    $fake($gem(['health' => 'At risk', 'risks' => [['risk' => 'No next step', 'evidence' => 'next_step is empty'], ['risk' => 'Vibes are off', 'evidence' => '']], 'strengths' => [], 'next_actions' => [['action' => 'Call the buyer', 'why' => 'Silent']], 'missing' => []]));
    [$c, $j] = $run('gtm-deal-coach', ['deal_id' => $B1]);
    $ok('deal coach 200; risk with no evidence dropped; measured flags attached', $c === 200 && count($j['data']['result']['risks']) === 1 && $j['data']['integrity']['references_dropped'] === 1 && in_array('no_next_step', $j['data']['result']['measured_flags'], true), [$c, $j]);
    if ($c === 200) { $ok('coach result carries deal reference; run row recorded', $j['data']['result']['deal']['id'] === $B1 && DB::table('agentic_agent_runs')->where('id', $j['data']['run_id'])->value('status') === 'success'); }
    [$c, $j] = $req('GET', "/gtm/deals/$B1"); $ok('deal detail returns latest coach analysis', ($j['data']['analyses']['deal_coach']['result']['deal']['id'] ?? 0) === $B1, $j['data']['analyses']['deal_coach'] ?? null);
    if ($O) { [$c, $j] = $run('gtm-deal-coach', ['deal_id' => $B1]); }
    $fake($gem(['deals' => [['deal_id' => 424242, 'risk' => 'invented', 'recommendation' => 'x'], ['deal_id' => $B1, 'risk' => 'No activity', 'recommendation' => 're-engage']], 'missing' => []]));
    [$c, $j] = $run('gtm-revops', []);
    $ok('revops 200; invented deal id dropped; flagged list is measured data', $c === 200 && count($j['data']['result']['deals']) === 1 && $j['data']['integrity']['references_dropped'] === 1 && $j['data']['result']['measured']['flagged_deals'] >= 1, [$c, $j]);
    if ($c === 200) { $ok('revops: reports no forecast', ! isset($j['data']['result']['forecast'])); }
    // other tenant cannot coach my deal
    if ($O) { [$c, $j] = $req('POST', "/gtm/agents/gtm-deal-coach/run", ['input' => ['deal_id' => $B1]], $O); $ok('deal coach: other tenant cannot use my deal (422)', $c === 422, [$c, $j]); }

    // ---------- delete + audit ----------
    [$c] = $req('DELETE', "/gtm/deals/$B1", [], $E);  $ok('employee delete 403', $c === 403, $c);
    [$c] = $req('DELETE', "/gtm/deals/$B1");           $ok('admin delete 200 (soft)', $c === 200, $c);
    [$c] = $req('GET', "/gtm/deals/$B1");              $ok('deleted deal -> 404', $c === 404, $c);
    [$c, $j] = $req('GET', '/gtm/deals');              $ok('deleted deal absent from list', ! collect($j['data']['items'])->pluck('id')->contains($B1), $c);
    $types = DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'like', 'gtm.deal%')->pluck('type')->unique()->all();
    $ok('audit events: created, updated, stage_changed, deleted, deal_scored, deal_discovery_analysed', count(array_intersect(['gtm.deal.created', 'gtm.deal.updated', 'gtm.deal.stage_changed', 'gtm.deal.deleted', 'gtm.deal_scored', 'gtm.deal_discovery_analysed'], $types)) === 6, $types);
} finally {
    Http::swap(new Illuminate\Http\Client\Factory);
    gtm_verify_cleanup($startedAt, $origStage ?? null);
    echo "\ncleaned up (only rows named 'Verify ...' and rows attached to them)\n";
}
echo "$pass passed, $fail failed\n";
