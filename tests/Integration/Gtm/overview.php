<?php
use Illuminate\Support\Facades\DB;

$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . json_encode($detail)) . "\n"; };

[$c] = call($kernel, 'GET', '/gtm/overview', null);   $ok('no token 401', $c === 401, $c);
[$c] = call($kernel, 'GET', '/gtm/overview', $E);     $ok('employee 403', $c === 403, $c);
[$c, $j] = call($kernel, 'GET', '/gtm/overview', $A);
$ok('overview 200 with deals block (not null: tables installed)', $c === 200 && is_array($j['data']['deals'] ?? null), [$c, $j['data']['deals'] ?? null]);
$ok('no deals yet: counts are real zeros', ($j['data']['deals']['open_count'] ?? -1) === 0 && ($j['data']['deals']['open_value_by_currency'] ?? 1) === [], $j['data']['deals'] ?? null);
$ok('previous measured/estimates/readiness blocks intact', isset($j['data']['measured']['accounts_total'], $j['data']['estimates'], $j['data']['readiness']['ai']), array_keys($j['data'] ?? []));

$id = DB::table('gtm_deals')->insertGetId(['sub_institute_id' => 6, 'account_id' => 3, 'name' => 'Verify Overview Deal', 'stage' => 'proposal', 'amount' => 1000, 'currency' => 'INR', 'stage_changed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
$id2 = DB::table('gtm_deals')->insertGetId(['sub_institute_id' => 6, 'account_id' => 3, 'name' => 'Verify Overview Deal 2', 'stage' => 'negotiation', 'amount' => 20, 'currency' => 'USD', 'stage_changed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
try {
    [$c, $j] = call($kernel, 'GET', '/gtm/overview', $A);
    $d = $j['data']['deals'];
    $ok('open count 2; value per currency, not summed', $d['open_count'] === 2 && (float) $d['open_value_by_currency']['INR'] === 1000.0 && (float) $d['open_value_by_currency']['USD'] === 20.0, $d);
    $ok('both deals flagged (no next step)', $d['flagged_count'] === 2 && $d['no_next_step_count'] === 2, $d);
    if ($O) { [$c, $j] = call($kernel, 'GET', '/gtm/overview', $O); $ok('other tenant overview excludes my deals', ($j['data']['deals']['open_value_by_currency']['INR'] ?? 0) !== 1000.0 && ! isset($j['data']['deals']['open_value_by_currency']['USD']) || ($j['data']['deals']['open_value_by_currency']['USD'] ?? 0) !== 20.0, $j['data']['deals'] ?? null); }
} finally {
    DB::table('gtm_deals')->whereIn('id', [$id, $id2])->delete();
}
echo "\n$pass passed, $fail failed\n";
