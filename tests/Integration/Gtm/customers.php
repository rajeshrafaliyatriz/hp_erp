<?php
use Illuminate\Support\Facades\{Cache, DB, Http};

$origStage = DB::table('gtm_accounts')->where('id', 3)->value('stage');
$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n"; };
$req = function ($m, $u, $b = [], $tok = null) use ($kernel, $A) { Cache::flush(); return call($kernel, $m, $u, $tok ?? $A, $b); };
$fake = function ($stub) { Http::swap(new Illuminate\Http\Client\Factory); Http::fake(['*' => $stub]); };
$gem = fn ($arr) => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($arr)]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 10]]);
$d = fn (int $n) => now()->addDays($n)->toDateString();
$otherTenant = 0; $otherAcct = 0;

try {
    foreach (['/gtm/customers', '/gtm/customers/alerts', '/gtm/renewals'] as $u) { [$c] = call($kernel, 'GET', $u, null); $ok("$u no token 401", $c === 401, $c); [$c] = call($kernel, 'GET', $u, $E); $ok("$u employee 403", $c === 403, $c); }
    [$c] = call($kernel, 'POST', '/gtm/renewals', $E, ['account_id' => 3, 'name' => 'x', 'renewal_date' => $d(30)]); $ok('renewal create: employee 403', $c === 403, $c);

    // fixtures through the API
    [$c, $j] = $req('POST', '/gtm/accounts', ['name' => 'Verify Cust A', 'industry' => 'Verify-Edu']); $a = $j['data']['account']['id'];
    [$c, $j] = $req('POST', '/gtm/accounts', ['name' => 'Verify Prospect B']); $b = $j['data']['account']['id'];   // stays a target
    [$c, $j] = $req('POST', '/gtm/accounts', ['name' => 'Verify Cust C']); $cc = $j['data']['account']['id'];
    foreach ([$a, $cc] as $id) { $req('PATCH', "/gtm/accounts/$id", ['stage' => 'customer']); }
    [$c, $j] = $req('POST', "/gtm/accounts/$a/contacts", ['full_name' => 'Verify Cust Decider', 'email' => 'verify.cust@example.invalid', 'role_in_deal' => 'decision_maker']); $ctA = $j['data']['contact']['id'];
    if ($O) {
        $otherTenant = (int) DB::table('tbluser')->where('id', DB::table('personal_access_tokens')->where('name', 'gtm-verify')->orderByDesc('id')->value('tokenable_id'))->value('sub_institute_id');
        $otherAcct = DB::table('gtm_accounts')->insertGetId(['sub_institute_id' => $otherTenant, 'name' => 'Verify Other Customer', 'stage' => 'customer', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---------- health with NO data: honest, not zero ----------
    [$c, $j] = $req('GET', '/gtm/customers'); $row = collect($j['data']['items'])->firstWhere('account_id', $cc);
    $ok('a customer with no records: band unknown, score null, and it says what is missing', $c === 200 && $row['health']['band'] === 'unknown' && $row['health']['score'] === null && count($row['health']['missing']) >= 3, $row);
    $ok('non-customers never appear', ! collect($j['data']['items'])->pluck('account_id')->contains($b), $j['data']['summary']);
    $ok('the response explains the method and weights', str_contains($j['data']['method'], 'never scored as zero') && array_sum($j['data']['weights']) === 100, $j['data']['weights']);

    // ---------- renewal validation ----------
    foreach ([
        'a prospect (not a customer)' => ['account_id' => $b, 'name' => 'R', 'renewal_date' => $d(30)],
        'unknown account' => ['account_id' => 999999, 'name' => 'R', 'renewal_date' => $d(30)],
        'missing name' => ['account_id' => $a, 'renewal_date' => $d(30)],
        'missing date' => ['account_id' => $a, 'name' => 'R'],
        'bad date' => ['account_id' => $a, 'name' => 'R', 'renewal_date' => 'soon'],
        'amount without currency' => ['account_id' => $a, 'name' => 'R', 'renewal_date' => $d(30), 'amount' => 100],
        'lowercase currency' => ['account_id' => $a, 'name' => 'R', 'renewal_date' => $d(30), 'amount' => 100, 'currency' => 'inr'],
        'negative amount' => ['account_id' => $a, 'name' => 'R', 'renewal_date' => $d(30), 'amount' => -1, 'currency' => 'INR'],
        'owner from nowhere' => ['account_id' => $a, 'name' => 'R', 'renewal_date' => $d(30), 'owner_user_id' => 999999],
        'deal that is not won' => ['account_id' => $a, 'name' => 'R', 'renewal_date' => $d(30), 'deal_id' => 999999],
    ] as $label => $body) { [$c] = $req('POST', '/gtm/renewals', $body); $ok("renewal invalid ($label) -> 422", $c === 422, $c); }
    if ($otherAcct) { [$c] = $req('POST', '/gtm/renewals', ['account_id' => $otherAcct, 'name' => 'R', 'renewal_date' => $d(30)]); $ok("renewal on another tenant's account -> 422", $c === 422, $c); }

    // ---------- create + the renewal pipeline (per currency) ----------
    [$c, $j] = $req('POST', '/gtm/renewals', ['account_id' => $a, 'name' => 'Verify Licence 2026', 'amount' => 1000, 'currency' => 'INR', 'renewal_date' => $d(20)]); $r1 = $j['data']['renewal']['id'] ?? ($j['data']['renewal']['id'] ?? 0);
    $ok('renewal created as upcoming', $c === 201 && $r1 > 0 && ($j['data']['renewal']['status'] ?? $j['data']['renewal']['status'] ?? '') === 'upcoming', [$c, $j]);
    [$c, $j] = $req('POST', '/gtm/renewals', ['account_id' => $cc, 'name' => 'Verify Support', 'amount' => 50, 'currency' => 'USD', 'renewal_date' => $d(75)]); $r2 = $j['data']['renewal']['id'] ?? ($j['data']['renewal']['id'] ?? 0);
    [$c, $j] = $req('POST', '/gtm/renewals', ['account_id' => $cc, 'name' => 'Verify Overdue', 'renewal_date' => $d(-5)]); $r3 = $j['data']['renewal']['id'] ?? ($j['data']['renewal']['id'] ?? 0);
    [$c, $j] = $req('GET', '/gtm/renewals'); $p = $j['data']['pipeline'];
    $mine = collect($j['data']['items'])->whereIn('id', [$r1, $r2, $r3]);
    $ok('renewals list sorted by date with account names', $c === 200 && $mine->count() === 3 && $mine->pluck('account_name')->contains('Verify Cust A'), $mine->all());
    $ok('pipeline buckets: overdue / due 30 / due 90, value kept per currency', $p['overdue']['count'] >= 1 && $p['due_30']['count'] >= 1 && $p['due_90']['count'] >= 1 && (float) ($p['due_30']['value_by_currency']['INR'] ?? 0) >= 1000.0 && (float) ($p['due_90']['value_by_currency']['USD'] ?? 0) >= 50.0, $p);
    $ok('a renewal with no amount is counted as such, never valued', $p['overdue']['without_amount'] >= 1, $p['overdue']);
    [$c, $j] = $req('GET', "/gtm/renewals?within_days=30"); $ok('filter: due within 30 days', collect($j['data']['items'])->pluck('id')->contains($r1) && ! collect($j['data']['items'])->pluck('id')->contains($r2), collect($j['data']['items'])->pluck('id')->all());
    [$c, $j] = $req('GET', "/gtm/renewals?account_id=$cc"); $ok('filter: by account', collect($j['data']['items'])->every(fn ($r) => (int) $r['account_id'] === $cc), $c);

    // ---------- update ----------
    [$c, $j] = $req('PATCH', "/gtm/renewals/$r1", ['amount' => 1200]);   $ok('amount-only edit keeps the currency', $c === 200 && (float) $j['data']['renewal']['amount'] === 1200.0 && $j['data']['renewal']['currency'] === 'INR', [$c, $j]);
    [$c] = $req('PATCH', "/gtm/renewals/$r1", ['currency' => null]);      $ok('clearing the currency while an amount exists -> 422', $c === 422, $c);
    [$c] = $req('PATCH', "/gtm/renewals/$r1", ['account_id' => $cc]);     $ok('a renewal cannot move accounts -> 422', $c === 422, $c);

    // ---------- measured health with real activity ----------
    DB::table('gtm_activities')->insert([
        ['sub_institute_id' => 6, 'account_id' => $a, 'contact_id' => $ctA, 'type' => 'email', 'direction' => 'outbound', 'subject' => 'Verify out', 'occurred_at' => now()->subDays(40), 'created_at' => now(), 'updated_at' => now()],
        ['sub_institute_id' => 6, 'account_id' => $a, 'contact_id' => $ctA, 'type' => 'email', 'direction' => 'inbound', 'subject' => 'Verify in', 'occurred_at' => now()->subDays(3), 'created_at' => now(), 'updated_at' => now()],
    ]);
    [$c, $j] = $req('GET', "/gtm/customers/$a"); $h = $j['data']['health'];
    $ok('customer detail: scored from real activity, contacts and the renewal', $c === 200 && $h['score'] !== null && $h['components']['recency']['value'] === 3 && $h['components']['responsiveness']['value'] === 1 && $h['components']['coverage']['score'] >= 75 && $h['components']['renewal']['value'] === 20, $h);
    $ok('...every component carries its explanation and weight', collect($h['components'])->every(fn ($x) => isset($x['detail'], $x['weight'])), $h['components']);
    $ok('...renewal 20 days away and not in progress is a stated risk', collect($h['risks'])->contains(fn ($r) => str_contains($r, 'not yet in progress')), $h['risks']);
    $ok('customer detail lists the account\'s renewals and recent activity', count($j['data']['renewals']) === 1 && count($j['data']['activities']) >= 2, $j['data']['renewals'] ?? null);
    [$c] = $req('GET', "/gtm/customers/$b");     $ok('a prospect is not a customer: 404', $c === 404, $c);
    [$c, $j] = $req('POST', "/gtm/renewals/$r1/status", ['status' => 'in_progress']);
    [$c, $j] = $req('GET', "/gtm/customers/$a"); $ok('once the renewal is in progress the renewal component improves and the risk disappears', $j['data']['health']['components']['renewal']['score'] === 80 && ! collect($j['data']['health']['risks'])->contains(fn ($r) => str_contains($r, 'not yet in progress')), $j['data']['health']['components']['renewal']);

    // ---------- alerts ----------
    [$c, $j] = $req('GET', '/gtm/customers/alerts'); $al = $j['data'];
    $ok('alerts: the overdue renewal is listed', collect($al['renewals'])->contains(fn ($r) => $r['renewal_id'] === $r3 && $r['kind'] === 'overdue' && $r['days'] === -5), $al['renewals']);
    $ok('alerts: an upcoming renewal inside the window with no work started is listed', collect($al['renewals'])->contains(fn ($r) => $r['renewal_id'] === $r2) === false /* 75 days: outside the 60-day window */, $al['renewals']);
    DB::table('gtm_renewals')->where('id', $r2)->update(['renewal_date' => $d(25)]);
    [$c, $j] = $req('GET', '/gtm/customers/alerts'); $ok('alerts: ...and moves in when its date is within the window', collect($j['data']['renewals'])->contains(fn ($r) => $r['renewal_id'] === $r2 && $r['kind'] === 'due_soon_not_started'), $j['data']['renewals']);
    $ok('alerts: the in-progress renewal is NOT an alert (someone is on it)', ! collect($j['data']['renewals'])->contains(fn ($r) => $r['renewal_id'] === $r1), $j['data']['renewals']);

    // ---------- the daily sweep emits events, once ----------
    $svc = app(App\Domain\Gtm\Customers\CustomerService::class);
    $s1 = $svc->sweep(6); $s2 = $svc->sweep(6);
    $ok('sweep emits an event per alerting renewal', $s1['renewal_events'] >= 2, $s1);
    $ok('sweep is idempotent within its period (second run announces nothing new)', $s2 === ['renewal_events' => 0, 'customer_events' => 0], $s2);
    $ok('events carry source=sweep (not a person) and an idempotency key', DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'gtm.renewal.due_soon')->whereNotNull('idempotency_key')->where('metadata', 'like', '%sweep%')->exists());

    // ---------- snapshots and trend ----------
    $n = $svc->snapshot(6);
    $ok('snapshot stores one row per customer for today (idempotent)', $n >= 2 && $svc->snapshot(6) === $n && DB::table('gtm_health_snapshots')->where('sub_institute_id', 6)->where('account_id', $a)->count() === 1, $n);
    [$c, $j] = $req('GET', '/gtm/customers'); $ok('with one snapshot there is no trend (never extrapolated)', collect($j['data']['items'])->firstWhere('account_id', $a)['trend'] === null, collect($j['data']['items'])->firstWhere('account_id', $a));
    DB::table('gtm_health_snapshots')->insert(['sub_institute_id' => 6, 'account_id' => $a, 'snapshot_date' => now()->subDays(7)->toDateString(), 'score' => 40, 'band' => 'watch', 'components' => '{}', 'created_at' => now()]);
    $now = collect($req('GET', '/gtm/customers')[1]['data']['items'])->firstWhere('account_id', $a);
    $ok('with two snapshots the trend is the real change between them', $now['trend'] !== null && $now['trend']['from'] === 40 && $now['trend']['change'] === $now['trend']['to'] - 40, $now['trend']);
    [$c, $j] = $req('GET', "/gtm/customers/$a"); $ok('customer detail carries the score history', count($j['data']['history']) === 2, $j['data']['history']);

    // ---------- renewal lifecycle ----------
    [$c, $j] = $req('POST', "/gtm/renewals/$r1/status", ['status' => 'in_progress']);     $ok('in_progress twice -> 422', $c === 422, $c);
    [$c, $j] = $req('POST', "/gtm/renewals/$r1/status", ['status' => 'renewed']);           $ok('renewed without confirm -> 409', $c === 409 && $j['code'] === 'confirmation_required', [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/renewals/$r1/status", ['status' => 'renewed', 'confirm' => true, 'next_renewal_date' => 'yesterday']); $ok('a bad next-renewal date -> 422', $c === 422, $c);
    [$c, $j] = $req('POST', "/gtm/renewals/$r1/status", ['status' => 'renewed', 'confirm' => true, 'next_renewal_date' => $d(365)]);
    $ok('renewed with confirm + a next date: closed, and the NEXT renewal is created upcoming', $c === 200 && $j['data']['renewal']['status'] === 'renewed' && $j['data']['next_renewal_id'] > 0 && DB::table('gtm_renewals')->where('id', $j['data']['next_renewal_id'])->value('status') === 'upcoming', [$c, $j]);
    [$c, $j] = $req('PATCH', "/gtm/renewals/$r1", ['name' => 'edit']);                      $ok('a closed renewal cannot be edited (422 renewal_closed)', $c === 422 && $j['code'] === 'renewal_closed', [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/renewals/$r1/status", ['status' => 'churned', 'confirm' => true, 'note' => 'x']); $ok('a closed renewal cannot change status (422)', $c === 422, $c);
    [$c, $j] = $req('POST', "/gtm/renewals/$r3/status", ['status' => 'churned', 'confirm' => true]); $ok('churn without a reason -> 422 reason_required', $c === 422 && $j['code'] === 'reason_required', [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/renewals/$r3/status", ['status' => 'cancelled', 'confirm' => true, 'next_renewal_date' => $d(10)]); $ok('a next date only applies to renewed -> 422', $c === 422, $c);
    [$c, $j] = $req('POST', "/gtm/renewals/$r3/status", ['status' => 'churned', 'confirm' => true, 'note' => 'Moved to a competitor']);
    $stillOpen = DB::table('gtm_renewals')->where('account_id', $cc)->whereNull('deleted_at')->whereIn('status', ['upcoming', 'in_progress'])->exists();
    $ok('churn with reason: because another renewal is still open the account stays a customer', $c === 200 && $stillOpen && $j['data']['account_stage_changed_to'] === null && DB::table('gtm_accounts')->where('id', $cc)->value('stage') === 'customer', [$c, $j]);
    [$c, $j] = $req('POST', "/gtm/renewals/$r2/status", ['status' => 'churned', 'confirm' => true, 'note' => 'Contract ended']);
    $ok('churning the LAST open renewal moves the account to churned (recorded as an activity)', $c === 200 && $j['data']['account_stage_changed_to'] === 'churned' && DB::table('gtm_accounts')->where('id', $cc)->value('stage') === 'churned' && DB::table('gtm_activities')->where('account_id', $cc)->where('subject', 'like', '%renewal churned%')->exists(), [$c, $j]);
    [$c, $j] = $req('GET', '/gtm/customers'); $ok('a churned account leaves the customer list', ! collect($j['data']['items'])->pluck('account_id')->contains($cc), $c);
    [$c] = $req('DELETE', "/gtm/renewals/$r2", [], $E);   $ok('employee cannot delete a renewal (403)', $c === 403, $c);
    [$c] = $req('DELETE', "/gtm/renewals/$r2");           $ok('delete a renewal', $c === 200, $c);
    [$c] = $req('POST', "/gtm/renewals/$r2/status", ['status' => 'in_progress']); $ok('a deleted renewal is gone (404)', $c === 404, $c);

    // ---------- Customer Success agent (fake provider) ----------
    $req('PATCH', "/gtm/accounts/$cc", ['stage' => 'customer']);
    DB::table('gtm_accounts')->where('id', 3)->update(['stage' => 'customer']);
    $fake($gem(['accounts' => [['account_id' => $a, 'risk' => 'Watch', 'evidence' => 'Renewal in progress; replies recorded', 'action' => 'Confirm the renewal date'], ['account_id' => 424242, 'risk' => 'invented', 'evidence' => 'x', 'action' => 'y']], 'missing' => []]));
    [$c, $j] = $req('POST', '/gtm/agents/gtm-customer-success/run', ['input' => ['mode' => 'renewal_risk']]);
    $prompt = json_encode((Http::recorded()->first()[0] ?? null)?->data());
    $ok('renewal_risk 200; an invented account is dropped (1 reported)', $c === 200 && collect($j['data']['result']['accounts'])->pluck('account_id')->diff([$a, $cc, 3])->isEmpty() && $j['data']['integrity']['references_dropped'] === 1, [$c, $j]);
    $ok('the result carries MEASURED health beside the model text', collect($j['data']['result']['facts'])->contains(fn ($f) => $f['account_id'] === $a && $f['score'] !== null && isset($f['band'])), $j['data']['result']['facts'] ?? null);
    $ok('the model was told what the rules measured, and what is NOT recorded (product_usage)', str_contains($prompt, 'measured_health') && str_contains($prompt, 'product_usage') && str_contains($prompt, 'do not recompute'), substr($prompt, 0, 200));
    $sigAcct3 = DB::table('g2g_company_opportunities')->where('sub_institute_id', 6)->where('company_id', DB::table('gtm_accounts')->where('id', 3)->value('company_id'))->value('id');
    $fake($gem(['opportunities' => [
        ['account_id' => 3, 'signal_id' => $sigAcct3, 'opportunity' => 'A second product for the state programme', 'evidence' => 'The signal shows 11 solutions moving to execution'],
        ['account_id' => 3, 'signal_id' => 999999, 'opportunity' => 'Invented signal', 'evidence' => 'x'],
        ['account_id' => $a, 'signal_id' => $sigAcct3, 'opportunity' => "Another customer's signal", 'evidence' => 'x'],
        ['account_id' => 3, 'signal_id' => $sigAcct3, 'opportunity' => 'No evidence', 'evidence' => ''],
    ], 'missing' => []]));
    [$c, $j] = $req('POST', '/gtm/agents/gtm-customer-success/run', ['input' => ['mode' => 'expansion']]);
    $ok('expansion keeps ONLY opportunities resting on that customer\'s own signal (3 of 4 removed)', $c === 200 && count($j['data']['result']['opportunities']) === 1 && $j['data']['integrity']['references_dropped'] === 3 && ! empty($j['data']['result']['opportunities'][0]['source_urls']), [$c, $j]);
    DB::table('gtm_accounts')->where('id', 3)->update(['stage' => $origStage]);
    $req('PATCH', "/gtm/accounts/$cc", ['stage' => 'target']); $req('PATCH', "/gtm/accounts/$a", ['stage' => 'target']);
    [$c, $j] = $req('POST', '/gtm/agents/gtm-customer-success/run', ['input' => ['mode' => 'renewal_risk']]); $ok('no customers at all -> no_data 422, the model is not called', $c === 422 && $j['code'] === 'no_data', [$c, $j]);
    $req('PATCH', "/gtm/accounts/$a", ['stage' => 'customer']);
    [$c, $j] = $req('POST', '/gtm/agents/gtm-customer-success/run', ['input' => ['mode' => 'expansion']]); $ok('expansion with no signal on any customer -> no_data 422', $c === 422 && $j['code'] === 'no_data', [$c, $j]);

    // ---------- tenant isolation ----------
    if ($O) {
        [$c, $j] = $req('GET', '/gtm/customers', [], $O);          $ok("another tenant's customer list contains none of mine", collect($j['data']['items'])->pluck('account_id')->intersect([$a, $cc])->isEmpty(), $c);
        [$c] = $req('GET', "/gtm/customers/$a", [], $O);           $ok("another tenant cannot open my customer (404)", $c === 404, $c);
        [$c, $j] = $req('GET', '/gtm/renewals', [], $O);           $ok("another tenant's renewal list holds none of mine", collect($j['data']['items'])->pluck('id')->intersect([$r1, $r2, $r3])->isEmpty(), $c);
        foreach ([['PATCH', "/gtm/renewals/$r3"], ['POST', "/gtm/renewals/$r3/status"], ['DELETE', "/gtm/renewals/$r3"]] as [$m, $u]) { [$c] = $req($m, $u, ['name' => 'x', 'status' => 'renewed', 'confirm' => true], $O); $ok("another tenant $m renewal -> 404", $c === 404, $c); }
        [$c, $j] = $req('GET', '/gtm/customers/alerts', [], $O);    $ok("another tenant's alerts hold none of my renewals", collect($j['data']['renewals'])->pluck('renewal_id')->intersect([$r1, $r2, $r3])->isEmpty(), $c);
    }
    $types = DB::table('g2g_event')->where('sub_institute_id', 6)->whereIn('type', ['gtm.renewal.created', 'gtm.renewal.updated', 'gtm.renewal.status_changed', 'gtm.renewal.due_soon'])->pluck('type')->unique()->all();
    $ok('audit events: renewal created / updated / status_changed / due_soon', count($types) === 4, $types);
} finally {
    Http::swap(new Illuminate\Http\Client\Factory);
    gtm_verify_cleanup($startedAt, $origStage);
    echo "\ncleaned up (only rows named 'Verify ...' and rows attached to them)\n";
}
echo "$pass passed, $fail failed\n";
