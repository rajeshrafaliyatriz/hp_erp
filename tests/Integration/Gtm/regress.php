<?php
use Illuminate\Support\Facades\DB;
$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : ' -> ' . json_encode($detail)) . "\n"; };

[$c, $j] = call($kernel, 'GET', '/ai/configuration/options', $A);
$ok('existing AI configuration options endpoint still 200', $c === 200, $c);
$mods = collect($j['data']['modules'] ?? $j['modules'] ?? []);
$ok('registry: "gtm" is now listed, and the pre-existing modules are all still there', $mods->contains(fn ($m) => ($m['key'] ?? null) === 'gtm') && $mods->contains(fn ($m) => ($m['key'] ?? null) === 'signals') && $mods->contains(fn ($m) => ($m['key'] ?? null) === 'conversational_ai') && $mods->contains(fn ($m) => ($m['key'] ?? null) === 'analytics_ai'), $mods->pluck('key')->all());
[$c] = call($kernel, 'GET', '/signals/status', $A);      $ok('Signals /status still 200', $c === 200, $c);
[$c] = call($kernel, 'GET', '/signals', $A);             $ok('Signals list still 200', $c === 200, $c);
[$c, $j] = call($kernel, 'GET', '/ai/configuration', $A); $ok('AI configuration list still 200', $c === 200, $c);
$stack = collect(DB::table('ai_modules')->where('status', 1)->whereNotNull('capabilities')->pluck('module_key') ?? []);
$ok('GTM did NOT become a per-module AI Stack entry (no ai_modules row for gtm)', ! DB::table('ai_modules')->where('module_key', 'gtm')->exists());
$ok('GTM agents are not in any module AI Stack Automations list (sub_module gtm is not an ai_modules key)', DB::table('agentic_agents')->where('sub_module', 'gtm')->count() === 5);
echo "\n$pass passed, $fail failed\n";
