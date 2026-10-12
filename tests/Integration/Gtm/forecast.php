<?php
use Illuminate\Support\Facades\{Cache, DB};

$origStage = DB::table('gtm_accounts')->where('id', 3)->value('stage');
if (DB::table('gtm_stage_settings')->where('sub_institute_id', 6)->exists() || DB::table('gtm_forecast_snapshots')->where('sub_institute_id', 6)->exists()) { echo "REFUSING to run: tenant 6 already has real forecast settings or snapshots, and this suite rewrites them. Run it on an empty tenant.\n"; return; }
$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n"; };
$req = function ($m, $u, $b = [], $tok = null) use ($kernel, $A) { Cache::flush(); return call($kernel, $m, $u, $tok ?? $A, $b); };
$base = ['deal' => (int) DB::table('gtm_deals')->max('id')];
$next = now()->addMonth()->format('Y-m-15'); $next2 = now()->addMonths(2)->format('Y-m-10');

try {
    [$c] = call($kernel, 'GET', '/gtm/forecast', null);                 $ok('forecast: no token 401', $c === 401, $c);
    [$c] = call($kernel, 'GET', '/gtm/forecast', $E);                   $ok('forecast: employee 403', $c === 403, $c);
    [$c] = call($kernel, 'GET', '/gtm/forecast/settings', $E);          $ok('settings read: employee 403', $c === 403, $c);
    [$c] = call($kernel, 'PUT', '/gtm/forecast/settings', $E, ['stages' => ['proposal' => ['probability' => 10]]]); $ok('settings write: employee 403', $c === 403, $c);

    [$c, $j] = $req('GET', '/gtm/forecast/settings');
    $ok('settings: nothing configured by default (no invented probabilities)', $c === 200 && $j['data']['configured'] === false && collect($j['data']['stages'])->every(fn ($s) => $s['probability'] === null && $s['configured'] === false), $j['data'] ?? $j);
    $ok('settings: history suggestions unavailable without closed deals', collect($j['data']['suggestions'])->every(fn ($s) => $s['available'] === false && $s['win_rate'] === null), $j['data']['suggestions'] ?? null);

    foreach ([
        'probability over 100' => ['stages' => ['proposal' => ['probability' => 101]]],
        'negative probability' => ['stages' => ['proposal' => ['probability' => -1]]],
        'text probability' => ['stages' => ['proposal' => ['probability' => 'high']]],
        'closed stage' => ['stages' => ['won' => ['probability' => 100]]],
        'bad category' => ['stages' => ['proposal' => ['probability' => 10, 'category' => 'sure_thing']]],
        'missing stages' => [],
    ] as $label => $b) { [$c] = $req('PUT', '/gtm/forecast/settings', $b); $ok("settings invalid ($label) -> 422", $c === 422, $c); }

    // fixtures: real deals through the API
    [$c, $j] = $req('POST', '/gtm/deals', ['account_id' => 3, 'name' => 'Verify FC A', 'amount' => 1000, 'currency' => 'INR', 'expected_close_date' => $next, 'stage' => 'proposal']); $a = $j['data']['deal']['id'];
    [$c, $j] = $req('POST', '/gtm/deals', ['account_id' => 3, 'name' => 'Verify FC B', 'amount' => 500, 'currency' => 'USD', 'expected_close_date' => $next, 'stage' => 'negotiation']); $b = $j['data']['deal']['id'];
    [$c, $j] = $req('POST', '/gtm/deals', ['account_id' => 3, 'name' => 'Verify FC C', 'expected_close_date' => $next]); $cdeal = $j['data']['deal']['id'];
    [$c, $j] = $req('POST', '/gtm/deals', ['account_id' => 3, 'name' => 'Verify FC D', 'amount' => 200, 'currency' => 'INR']); $d = $j['data']['deal']['id'];
    [$c, $j] = $req('POST', '/gtm/deals', ['account_id' => 3, 'name' => 'Verify FC E', 'amount' => 300, 'currency' => 'INR', 'expected_close_date' => $next2]); $e = $j['data']['deal']['id'];
    [$c] = $req('PATCH', "/gtm/deals/$a", ['forecast_category' => 'maybe']);   $ok('deal forecast_category invalid -> 422', $c === 422, $c);

    [$c, $j] = $req('GET', '/gtm/forecast');
    $f = $j['data']['forecast'] ?? [];
    $inr = collect($f['periods'] ?? [])->where('currency', 'INR'); $usd = collect($f['periods'] ?? [])->where('currency', 'USD');
    $ok('forecast (nothing configured): unweighted pipeline per currency, INR and USD separate', $c === 200 && (float) $inr->sum('pipeline') === 1300.0 && (float) $usd->sum('pipeline') === 500.0, $f);
    $ok('forecast (nothing configured): weighted is null, not zero', $j['data']['probabilities_configured'] === false && $inr->every(fn ($p) => $p['weighted'] === null), $inr->all());
    $ok('forecast: deals without amount / close date are counted as excluded', $f['excluded']['no_amount'] === 1 && $f['excluded']['no_close_date'] === 1, $f['excluded']);
    $ok('forecast: states it is calculated, not a prediction', str_contains($j['data']['basis'], 'calculated'));

    [$c, $j] = $req('PUT', '/gtm/forecast/settings', ['stages' => ['proposal' => ['probability' => 40, 'category' => 'best_case'], 'negotiation' => ['probability' => 75.5, 'category' => 'commit'], 'discovery' => ['probability' => '', 'category' => 'pipeline']]]);
    $ok('settings saved (200) and read back', $c === 200 && (float) $j['data']['stages']['proposal']['probability'] === 40.0 && $j['data']['stages']['negotiation']['category'] === 'commit' && $j['data']['stages']['discovery']['probability'] === null && $j['data']['configured'] === true, [$c, $j]);
    [$c, $j] = $req('GET', '/gtm/forecast'); $f = $j['data']['forecast'];
    $inr = collect($f['periods'])->where('currency', 'INR'); $usd = collect($f['periods'])->where('currency', 'USD');
    $ok('weighted = amount x configured probability (INR 1000 x 40% = 400)', (float) $inr->where('period', substr($next, 0, 7))->first()['weighted'] === 400.0, $inr->all());
    $ok('weighted USD 500 x 75.5% = 377.5, kept apart from INR', (float) $usd->first()['weighted'] === 377.5, $usd->all());
    $ok('categories: best_case includes commit; commit alone is the negotiation deal', (float) $inr->where('period', substr($next, 0, 7))->first()['best_case'] === 1000.0 && (float) $usd->first()['commit'] === 500.0 && (float) $usd->first()['best_case'] === 500.0, [$inr->all(), $usd->all()]);
    [$c] = $req('PATCH', "/gtm/deals/$a", ['forecast_category' => 'commit']);
    [$c, $j] = $req('GET', '/gtm/forecast'); $inr = collect($j['data']['forecast']['periods'])->where('currency', 'INR')->where('period', substr($next, 0, 7))->first();
    $ok('a deal-level category overrides the stage category', (float) $inr['commit'] === 1000.0, $inr);
    [$c] = $req('PATCH', "/gtm/deals/$a", ['forecast_category' => 'omitted']);
    [$c, $j] = $req('GET', '/gtm/forecast');
    $ok('an omitted deal leaves every view', (float) collect($j['data']['forecast']['periods'])->where('currency', 'INR')->sum('pipeline') === 300.0 && $j['data']['forecast']['excluded']['omitted'] === 1, $j['data']['forecast']);
    [$c] = $req('PATCH', "/gtm/deals/$a", ['forecast_category' => null]);

    // filters
    [$c, $j] = $req('GET', '/gtm/forecast?currency=USD'); $ok('filter currency=USD', collect($j['data']['forecast']['periods'])->every(fn ($p) => $p['currency'] === 'USD') && count($j['data']['forecast']['periods']) === 1, $j['data']['forecast']['periods'] ?? null);
    [$c, $j] = $req('GET', '/gtm/forecast?stage=negotiation'); $ok('filter stage', (float) collect($j['data']['forecast']['periods'])->sum('pipeline') === 500.0, $j['data']['forecast']['periods'] ?? null);
    DB::table('gtm_deals')->where('id', $e)->update(['owner_user_id' => 29]);
    [$c, $j] = $req('GET', '/gtm/forecast?owner_user_id=29'); $ok('filter owner', (float) collect($j['data']['forecast']['periods'])->sum('pipeline') === 300.0, $j['data']['forecast']['periods'] ?? null);
    $dept = DB::table('tbluser')->where('id', 29)->value('department_id');
    [$c, $j] = $req('GET', "/gtm/forecast?department_id=$dept"); $ok("filter team (department $dept = the owner's department)", (float) collect($j['data']['forecast']['periods'])->sum('pipeline') === 300.0, $j['data']['forecast']['periods'] ?? null);
    [$c, $j] = $req('GET', '/gtm/forecast?department_id=999999'); $ok('filter team with nobody in it -> empty, not an error', $c === 200 && $j['data']['forecast']['periods'] === [], $c);
    [$c, $j] = $req('GET', '/gtm/forecast?from=' . now()->addMonths(2)->format('Y-m-01') . '&to=' . now()->addMonths(3)->format('Y-m-28'));
    $ok('filter date window', (float) collect($j['data']['forecast']['periods'])->sum('pipeline') === 300.0 && $j['data']['forecast']['overdue']['count'] >= 0, $j['data']['forecast'] ?? null);
    foreach (['to before from' => '?from=2027-05-01&to=2027-01-01', 'bad date' => '?from=banana', 'lowercase currency' => '?currency=inr', 'closed stage' => '?stage=won', 'window over 36 months' => '?from=2026-01-01&to=2030-01-01'] as $label => $qs) { [$c] = $req('GET', "/gtm/forecast$qs"); $ok("filter invalid ($label) -> 422", $c === 422, $c); }

    // actuals vs calculated
    [$c, $j] = $req('POST', "/gtm/deals/$b/stage", ['stage' => 'won', 'confirm' => true]);
    [$c, $j] = $req('GET', '/gtm/forecast'); $d2 = $j['data'];
    $ok('a won deal leaves the forecast and appears under ACTUALS (separate blocks)', collect($d2['forecast']['periods'])->where('currency', 'USD')->isEmpty() && (float) ($d2['actuals']['won']['value_by_currency']['USD'] ?? 0) === 500.0 && $d2['actuals']['won']['count'] === 1, $d2['actuals']);
    $ok('accuracy unavailable with no snapshots, and says why', $d2['accuracy']['available'] === false && str_contains($d2['accuracy']['reason'], 'snapshot'), $d2['accuracy']);

    // snapshots and accuracy
    $n = app(App\Domain\Gtm\Forecast\ForecastService::class)->snapshot(6);
    $ok('snapshot writes one row per month+currency', $n >= 1 && DB::table('gtm_forecast_snapshots')->where('sub_institute_id', 6)->where('snapshot_date', now()->toDateString())->count() === $n, $n);
    $n2 = app(App\Domain\Gtm\Forecast\ForecastService::class)->snapshot(6);
    $ok('snapshot is idempotent for the day', DB::table('gtm_forecast_snapshots')->where('sub_institute_id', 6)->where('snapshot_date', now()->toDateString())->count() === $n2, $n2);
    [$c, $j] = $req('GET', '/gtm/forecast'); $ok('accuracy still unavailable: no finished month has an earlier snapshot', $j['data']['accuracy']['available'] === false, $j['data']['accuracy']);
    $lastMonth = now()->subMonth()->format('Y-m'); $before = now()->subMonth()->startOfMonth()->subDays(3)->toDateString();
    DB::table('gtm_forecast_snapshots')->insert(['sub_institute_id' => 6, 'snapshot_date' => $before, 'period' => $lastMonth, 'currency' => 'USD', 'deal_count' => 1, 'pipeline_value' => 900, 'best_case_value' => 900, 'commit_value' => 1000, 'weighted_value' => 700, 'created_at' => now()]);
    DB::table('gtm_deals')->where('id', $b)->update(['closed_at' => now()->subMonth()->format('Y-m-15 10:00:00')]);
    [$c, $j] = $req('GET', '/gtm/forecast'); $acc = $j['data']['accuracy'];
    $row = collect($acc['periods'])->firstWhere('period', $lastMonth);
    $ok('accuracy compares the snapshot taken BEFORE the month with what was actually won (500 / 1000 = 50%)', $acc['available'] === true && $row && (float) $row['actual_won'] === 500.0 && (float) $row['forecast_commit'] === 1000.0 && (float) $row['commit_attainment_pct'] === 50.0 && $row['snapshot_date'] === $before, $acc);

    // isolation
    if ($O) { [$c, $j] = $req('GET', '/gtm/forecast', [], $O); $ok("another tenant's forecast contains none of my deals", $c === 200 && (float) collect($j['data']['forecast']['periods'])->where('currency', 'USD')->sum('pipeline') !== 500.0 && collect($j['data']['forecast']['periods'])->sum('pipeline') != 1300.0, $j['data']['forecast']['periods'] ?? $c);
        [$c, $j] = $req('GET', '/gtm/forecast/settings', [], $O); $ok("another tenant sees no probabilities of mine", $j['data']['configured'] === false, $j['data'] ?? $c);
        [$c] = $req('PUT', '/gtm/forecast/settings', ['stages' => ['proposal' => ['probability' => 99]]], $O);
        $ok("another tenant's admin writes only their own settings", $c === 200 && (float) DB::table('gtm_stage_settings')->where('sub_institute_id', 6)->where('stage', 'proposal')->value('probability') === 40.0, $c);
        DB::table('gtm_stage_settings')->where('sub_institute_id', '!=', 6)->where('probability', 99)->delete(); }

    [$c, $j] = $req('GET', '/gtm/directory'); $ok('directory lists tenant users + departments, names only', $c === 200 && count($j['data']['users']) >= 5 && ! str_contains(json_encode($j['data']['users'][0]), 'email') && count($j['data']['departments']) > 0, $c);
    [$c] = call($kernel, 'GET', '/gtm/directory', $E);  $ok('directory: employee 403', $c === 403, $c);
    $ok('audit: gtm.forecast.settings_updated recorded', DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'gtm.forecast.settings_updated')->exists());
} finally {
    DB::table('gtm_stage_settings')->where('sub_institute_id', 6)->delete();
    DB::table('gtm_forecast_snapshots')->where('sub_institute_id', 6)->delete();
    gtm_verify_cleanup($startedAt, $origStage ?? null);
    echo "\ncleaned up (only rows named 'Verify ...' and rows attached to them)\n";
}
echo "$pass passed, $fail failed\n";
