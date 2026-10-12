<?php
use Illuminate\Support\Facades\{DB, Http};

$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : " -> $detail") . "\n"; };
$base = ['run' => DB::table('agentic_agent_runs')->max('id'), 'an' => DB::table('gtm_analyses')->max('id'), 'act' => DB::table('gtm_activities')->max('id')];
$acct = DB::table('gtm_accounts')->where('id', 3)->first();
$origStage = $acct->stage;
$sigIds = DB::table('g2g_company_opportunities')->where('sub_institute_id', 6)->where('company_id', $acct->company_id)->pluck('id')->all();
$fake = function ($stub) { Http::swap(new Illuminate\Http\Client\Factory); Http::fake(['*' => $stub]); };
$run = function ($slug, $input, $tok) use ($kernel) { Illuminate\Support\Facades\Cache::flush(); return call($kernel, 'POST', "/gtm/agents/$slug/run", $tok, ['input' => $input]); };
$gem = fn ($arr) => Http::response(['candidates' => [['content' => ['parts' => [['text' => is_string($arr) ? $arr : json_encode($arr)]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 10]]);

try {
    [$c] = call($kernel, 'GET', '/gtm/agents', null);   $ok('list: no token 401', $c === 401, $c);
    [$c] = call($kernel, 'GET', '/gtm/agents', $E);     $ok('list: employee 403', $c === 403, $c);
    [$c] = $run('gtm-prospecting', ['mode' => 'signals'], $E); $ok('run: employee 403', $c === 403, $c);
    [$c, $j] = call($kernel, 'GET', '/gtm/agents', $A);
    $ag = collect($j['data']['agents'] ?? []);
    $ok('list: 5 agents, ai_configured', $c === 200 && $ag->count() === 5 && $j['data']['ai_configured'] === true, $c);
    $ok('list: all 5 runnable now that deals exist', $ag->where('runnable', true)->count() === 5, $ag->map(fn ($a) => $a['slug'] . ($a['runnable'] ? ':y' : ':n'))->all());
    [$c, $j] = $run('gtm-deal-coach', [], $A);   $ok('deal coach without a deal -> 422 (no AI call)', $c === 422, $c);
    [$c, $j] = $run('gtm-revops', [], $A);       $ok('revops with no open deals -> no_data 422', $c === 422 && $j['code'] === 'no_data', $c);
    [$c] = $run('gtm-nonexistent', [], $A);      $ok('unknown agent 404', $c === 404, $c);

    // ---- Prospecting (real model) ----
    [$c, $j] = $run('gtm-prospecting', ['mode' => 'account', 'account_id' => 3], $A);
    $ok('prospecting account brief 200 (real model)', $c === 200 && isset($j['data']['result']['summary']), $c . ' ' . json_encode($j));
    if ($c === 200) {
        $sigIds = DB::table('g2g_company_opportunities')->where('sub_institute_id', 6)->where('company_id', $acct->company_id)->pluck('id')->all();
        $ids = array_map(fn ($r) => (int) $r['signal_id'], $j['data']['result']['ranked_signals'] ?? []);
        $ok('every cited signal is a real signal of this account', count(array_diff($ids, $sigIds)) === 0, json_encode($ids));
        $ok('playbook + provider recorded', ($j['data']['playbook']['slug'] ?? '') === 'prospecting-account-research' && ! empty($j['data']['model']));
        $rid = $j['data']['run_id'];
        $row = DB::table('agentic_agent_runs')->where('id', $rid)->first();
        $ok('run row: tenant 6, success, tokens, duration', $row && $row->sub_institute_id == 6 && $row->status === 'success' && $row->tokens_used > 0 && $row->duration_ms > 0, json_encode($row));
        $an = DB::table('gtm_analyses')->where('id', $j['data']['analysis_id'])->first();
        $ok('analysis row: estimate + playbook_id + sources', $an && $an->is_estimate == 1 && $an->playbook_id > 0 && $an->status === 'success');
        echo "   summary: " . substr($j['data']['result']['summary'], 0, 220) . "\n";
        [$c2, $j2] = call($kernel, 'GET', "/gtm/agents/runs/$rid", $A);   $ok('run detail readable', $c2 === 200 && ! empty($j2['data']['run']['output']), $c2);
        if ($O) { [$c2] = call($kernel, 'GET', "/gtm/agents/runs/$rid", $O); $ok('run detail: other tenant 404', $c2 === 404, $c2); }
    }
    [$c, $j] = $run('gtm-prospecting', ['mode' => 'account', 'account_id' => 99999], $A);  $ok('prospecting unknown account 422', $c === 422, $c);
    if ($O) { [$c, $j] = $run('gtm-prospecting', ['mode' => 'account', 'account_id' => 3], $O); $ok('prospecting: other tenant cannot brief my account (422, no data)', $c === 422 && ! isset($j['data']), $c . ' ' . ($j['message'] ?? '')); }
    [$c, $j] = $run('gtm-prospecting', ['mode' => 'signals'], $A);
    $ok('prospecting signals ranking 200 (real model)', $c === 200 && is_array($j['data']['result']['ordered'] ?? null), $c . ' ' . ($j['message'] ?? ''));
    if ($c === 200) {
        $new = DB::table('g2g_company_opportunities')->where('sub_institute_id', 6)->where('review_status', 'New')->pluck('id')->all();
        $ids = array_map(fn ($r) => (int) $r['signal_id'], $j['data']['result']['ordered']);
        $ok('ordered ids all real unreviewed signals', count(array_diff($ids, $new)) === 0 && count($ids) > 0, json_encode($ids));
    }

    // ---- invented references are stripped (provider faked) ----
    $fake($gem(['summary' => 's', 'trigger' => 't', 'ranked_signals' => [['signal_id' => 999999, 'why' => 'invented'], ['signal_id' => $sigIds[0], 'why' => 'real']], 'target_roles' => [], 'next_step' => 'n', 'missing' => []]));
    [$c, $j] = $run('gtm-prospecting', ['mode' => 'account', 'account_id' => 3], $A);
    $ok('invented signal id removed, real kept, count reported', $c === 200 && count($j['data']['result']['ranked_signals']) === 1 && $j['data']['integrity']['references_dropped'] === 1, json_encode($j['data']['integrity'] ?? $j));

    // ---- provider failure & bad output ----
    $fake(Http::response(['error' => ['message' => 'boom']], 500));
    [$c, $j] = $run('gtm-prospecting', ['mode' => 'account', 'account_id' => 3], $A);
$ok('provider 500 -> 503 ai_provider_busy, provider text not leaked', $c === 503 && $j['code'] === 'ai_provider_busy' && ! str_contains($j['message'], 'boom'), $c . ' ' . json_encode($j));
    $failRun = DB::table('agentic_agent_runs')->where('id', $j['run_id'] ?? 0)->first();
    $ok('failed run recorded as error', $failRun && $failRun->status === 'error' && $failRun->error_message);
    $ok('failed analysis recorded', DB::table('gtm_analyses')->where('id', '>', $base['an'])->where('status', 'failed')->exists());
    $fake(Http::response(['error' => ['message' => 'quota']], 429));
    [$c429, $j429] = $run('gtm-prospecting', ['mode' => 'account', 'account_id' => 3], $A);
    $ok('provider 429 -> 503 ai_rate_limited', $c429 === 503 && $j429['code'] === 'ai_rate_limited', $c429 . ' ' . json_encode($j429));
    $fake($gem('this is not json at all'));
    [$c, $j] = $run('gtm-prospecting', ['mode' => 'account', 'account_id' => 3], $A);
    $ok('non-JSON model output -> 502, nothing returned', $c === 502 && ! isset($j['data']), $c);
    Http::swap(new Illuminate\Http\Client\Factory);

    // ---- Outreach ----
    [$c, $j] = call($kernel, 'POST', '/gtm/accounts/3/contacts', $A, ['full_name' => 'Verify Contact', 'title' => 'Head of Digital', 'email' => 'verify.contact@example.invalid', 'role_in_deal' => 'decision_maker']);
    $cid = $j['data']['contact']['id'] ?? 0; $ok('test contact created', $c === 201 && $cid > 0, $c);
    $acts0 = DB::table('gtm_activities')->where('sub_institute_id', 6)->whereIn('type', ['email'])->count();
    [$c, $j] = $run('gtm-outreach', ['account_id' => 3, 'contact_id' => 99999, 'mode' => 'email'], $A);       $ok('outreach unknown contact 422', $c === 422, $c);
    [$c, $j] = $run('gtm-outreach', ['account_id' => 3, 'contact_id' => $cid, 'mode' => 'email'], $A);
    $ok('outreach email draft 200 (real model)', $c === 200 && ! empty($j['data']['result']['subject']) && ! empty($j['data']['result']['body']), $c . ' ' . json_encode($j));
    if ($c === 200) {
        $r = $j['data']['result'];
        $ok('marked as draft, recipient attached', $r['is_draft'] === true && $r['recipient']['contact_id'] === $cid);
        $urls = []; foreach (DB::table('g2g_company_opportunities')->where('company_id', $acct->company_id)->pluck('sources') as $s) { foreach (json_decode($s, true) ?: [] as $x) { $urls[] = $x['url']; } }
        $ok('every remaining claim cites a real signal URL', collect($r['claims'])->every(fn ($cl) => in_array($cl['source_url'], $urls, true)), json_encode($r['claims']));
        echo "   subject: {$r['subject']}\n   body: " . substr(str_replace("\n", ' ', $r['body']), 0, 300) . "\n";
    }
    $ok('NOTHING SENT: no email activity logged by drafting', DB::table('gtm_activities')->where('sub_institute_id', 6)->where('type', 'email')->count() === $acts0);
    [$c, $j] = $run('gtm-outreach', ['account_id' => 3, 'contact_id' => $cid, 'mode' => 'sequence'], $A);
    $ok('outreach sequence 200 with steps', $c === 200 && count($j['data']['result']['steps'] ?? []) >= 1, $c . ' ' . ($j['message'] ?? ''));
    if ($c === 200) { echo "   steps: " . count($j['data']['result']['steps']) . "\n"; }
    $fake($gem(['subject' => 'Hi', 'body' => 'Body', 'signal_used' => 424242, 'claims' => [['claim' => 'made up', 'source_url' => 'https://invented.example/x']], 'missing' => []]));
    [$c, $j] = $run('gtm-outreach', ['account_id' => 3, 'contact_id' => $cid, 'mode' => 'email'], $A);
    $ok('invented claim + signal stripped from draft', $c === 200 && $j['data']['result']['claims'] === [] && $j['data']['result']['signal_used'] === null && $j['data']['integrity']['references_dropped'] === 1, json_encode($j['data']['integrity'] ?? $j));
    $fake($gem(['subject' => '', 'body' => '', 'claims' => []]));
    [$c, $j] = $run('gtm-outreach', ['account_id' => 3, 'contact_id' => $cid, 'mode' => 'email'], $A);
    $ok('empty draft refused (502), not saved as a draft', $c === 502, $c);
    Http::swap(new Illuminate\Http\Client\Factory);
    DB::table('gtm_contacts')->where('id', $cid)->update(['status' => 'do_not_contact']);
    [$c, $j] = $run('gtm-outreach', ['account_id' => 3, 'contact_id' => $cid, 'mode' => 'email'], $A);       $ok('do_not_contact contact refused 422', $c === 422 && str_contains($j['message'], 'do_not_contact'), $c);
    DB::table('gtm_contacts')->where('id', $cid)->update(['status' => 'active']);
    $otherAcct = DB::table('gtm_accounts')->where('sub_institute_id', 6)->where('id', '!=', 3)->value('id');
    if ($otherAcct) { [$c] = $run('gtm-outreach', ['account_id' => $otherAcct, 'contact_id' => $cid, 'mode' => 'email'], $A); $ok('contact from a different account refused', $c === 422, $c); } else { echo "SKIP contact/account mismatch (only one account)\n"; }
    // no signal -> no_data
    $noSig = DB::table('gtm_accounts')->insertGetId(['sub_institute_id' => 6, 'name' => 'Verify NoSignal Co', 'stage' => 'target', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    $nc = DB::table('gtm_contacts')->insertGetId(['sub_institute_id' => 6, 'account_id' => $noSig, 'full_name' => 'No Signal', 'status' => 'active', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    [$c, $j] = $run('gtm-outreach', ['account_id' => $noSig, 'contact_id' => $nc, 'mode' => 'email'], $A);   $ok('no sourced signal -> no_data 422, no AI call', $c === 422 && $j['code'] === 'no_data', $c . ' ' . json_encode($j));

    // ---- Customer success ----
    [$c, $j] = $run('gtm-customer-success', [], $A);   $ok('CS with no customer accounts -> no_data 422', $c === 422 && $j['code'] === 'no_data', $c . ' ' . json_encode($j));
    DB::table('gtm_accounts')->where('id', 3)->update(['stage' => 'customer']);
    [$c, $j] = $run('gtm-customer-success', [], $A);
    $ok('CS with a customer account 200 (real model)', $c === 200 && is_array($j['data']['result']['accounts'] ?? null), $c . ' ' . ($j['message'] ?? ''));
    if ($c === 200) {
        $ok('CS shows measured facts beside judgement; ids valid', ($j['data']['result']['facts'][0]['account_id'] ?? 0) === 3 && collect($j['data']['result']['accounts'])->every(fn ($a) => (int) $a['account_id'] === 3));
        echo "   CS: " . json_encode($j['data']['result']['accounts'][0] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
    }
    if ($O) { [$c, $j] = $run('gtm-customer-success', [], $O); $ok('CS other tenant: own data only (no_data/422 or own), never tenant 6 account', ! str_contains(json_encode($j), 'ShikshaNext'), $c); }

    [$c, $j] = call($kernel, 'GET', '/gtm/agents/runs', $A);
    $ok('run history lists tenant runs, newest first', $c === 200 && count($j['data']['runs']) >= 5 && $j['data']['runs'][0]['id'] > $j['data']['runs'][1]['id'], $c);
if ($O) { [$c, $j] = call($kernel, 'GET', '/gtm/agents/runs', $O); $ok('run history: other tenant sees none of tenant 6 runs', $c === 200 && DB::table('agentic_agent_runs')->whereIn('id', collect($j['data']['runs'])->pluck('id'))->where('sub_institute_id', 6)->count() === 0, $c); }
    Illuminate\Support\Facades\Cache::flush(); $codes = [];
    for ($i = 0; $i < 12; $i++) { [$cc] = call($kernel, 'POST', '/gtm/agents/gtm-deal-coach/run', $A, ['input' => []]); $codes[] = $cc; }
    $ok('throttle: 11th+ run in a minute is 429', in_array(429, $codes, true) && $codes[0] === 422, json_encode($codes));
    Illuminate\Support\Facades\Cache::flush();
    $ev = DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'gtm.agent.run')->where('id', '>', 0)->count();
    $ok('audit events gtm.agent.run recorded', $ev >= 5, $ev);
} finally {
    Http::swap(new Illuminate\Http\Client\Factory);
    gtm_verify_cleanup($startedAt, $origStage ?? null);
    echo "\ncleaned up (only rows named 'Verify ...' and rows attached to them)\n";
}
echo "$pass passed, $fail failed\n";
