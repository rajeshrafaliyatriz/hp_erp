<?php
use Illuminate\Support\Facades\DB;

if (DB::table('gtm_playbooks')->where('sub_institute_id', 6)->exists()) { echo "REFUSING to run: tenant 6 already has its own playbooks, and this suite customises and deletes copies of BANT. Run it on a tenant with none.\n"; return; }
$pass = 0; $fail = 0;
$ok = function ($label, $cond, $detail = '') use (&$pass, &$fail) { $cond ? $pass++ : $fail++; echo ($cond ? 'PASS' : 'FAIL') . " $label" . ($cond ? '' : " -> $detail") . "\n"; };

[$c] = call($kernel, 'GET', '/gtm/playbooks', null);            $ok('no token 401', $c === 401, $c);
[$c] = call($kernel, 'GET', '/gtm/playbooks', $E);              $ok('employee list 403', $c === 403, $c);
[$c, $j] = call($kernel, 'GET', '/gtm/playbooks', $A);          $ok('admin list 200 with 11 defaults', $c === 200 && count($j['data']['items']) >= 11, $c . ' n=' . count($j['data']['items'] ?? []));
$items = collect($j['data']['items']);
$bant = $items->firstWhere('slug', 'qualification-bant');
$ok('bant is platform + not editable', $bant && $bant['source'] === 'platform' && $bant['editable'] === false);
[$c, $j] = call($kernel, 'GET', '/gtm/playbooks?kind=methodology', $A); $ok('kind filter', collect($j['data']['items'])->every(fn ($x) => $x['kind'] === 'methodology') && count($j['data']['items']) === 2);
[$c] = call($kernel, 'PATCH', "/gtm/playbooks/{$bant['id']}", $A, ['title' => 'x']); $ok('edit platform default refused 422', $c === 422, $c);
[$c] = call($kernel, 'POST', "/gtm/playbooks/{$bant['id']}/customise", $E);              $ok('employee customise 403', $c === 403, $c);

[$c, $j] = call($kernel, 'POST', "/gtm/playbooks/{$bant['id']}/customise", $A);          $ok('customise 201', $c === 201 && $j['data']['created'] === true, $c);
$mine = $j['data']['playbook'];
$ok('copy is tenant-owned v1 editable', $mine['source'] === 'organisation' && $mine['version'] === 1 && $mine['editable'] === true && $mine['overrides_default'] === true);
[$c, $j] = call($kernel, 'POST', "/gtm/playbooks/{$bant['id']}/customise", $A);          $ok('customise idempotent', $c === 200 && $j['data']['created'] === false && $j['data']['playbook']['id'] === $mine['id'], $c);
[$c, $j] = call($kernel, 'GET', '/gtm/playbooks?kind=methodology', $A);                  $ok('override hides default (still 2 methodologies)', count($j['data']['items']) === 2 && collect($j['data']['items'])->firstWhere('slug', 'qualification-bant')['id'] === $mine['id']);

[$c, $j] = call($kernel, 'PATCH', "/gtm/playbooks/{$mine['id']}", $A, ['title' => 'BANT (our version)', 'change_note' => 'verify run']); $ok('edit bumps to v2', $c === 200 && $j['data']['playbook']['version'] === 2, $c . json_encode($j));
[$c, $j] = call($kernel, 'PATCH', "/gtm/playbooks/{$mine['id']}", $A, ['status' => 'draft']);                                     $ok('status-only edit keeps version 2', $c === 200 && $j['data']['playbook']['version'] === 2 && $j['data']['playbook']['status'] === 'draft');
call($kernel, 'PATCH', "/gtm/playbooks/{$mine['id']}", $A, ['status' => 'active']);
[$c, $j] = call($kernel, 'PATCH', "/gtm/playbooks/{$mine['id']}", $A, ['definition' => ['dimensions' => [['key' => 'only_one', 'label' => 'x', 'weight' => 1]]]]); $ok('methodology with 1 dimension rejected 422', $c === 422, $c);
[$c] = call($kernel, 'PATCH', "/gtm/playbooks/{$mine['id']}", $A, ['definition' => ['dimensions' => [['key' => 'a', 'label' => 'A', 'weight' => 1], ['key' => 'a', 'label' => 'B', 'weight' => 1]]]]); $ok('duplicate dimension key rejected', $c === 422, $c);
[$c] = call($kernel, 'PATCH', "/gtm/playbooks/{$mine['id']}", $A, ['definition' => ['dimensions' => [['key' => 'a', 'label' => 'A', 'weight' => 0], ['key' => 'b', 'label' => 'B', 'weight' => 1]]]]); $ok('zero weight rejected', $c === 422, $c);
[$c] = call($kernel, 'PATCH', "/gtm/playbooks/{$mine['id']}", $A, ['body' => 'short']);                                           $ok('too-short body rejected', $c === 422, $c);

[$c, $j] = call($kernel, 'GET', "/gtm/playbooks/{$mine['id']}", $A);                     $ok('detail has 2 versions', $c === 200 && count($j['data']['versions']) === 2, json_encode($j['data']['versions'] ?? null));
[$c, $j] = call($kernel, 'POST', "/gtm/playbooks/{$mine['id']}/versions/1/restore", $A); $ok('restore v1 -> v3 with original title', $c === 200 && $j['data']['playbook']['version'] === 3 && $j['data']['playbook']['title'] === $bant['title'], $c);
[$c] = call($kernel, 'POST', "/gtm/playbooks/{$mine['id']}/versions/99/restore", $A);   $ok('restore missing version 404', $c === 404, $c);

// create
$new = ['kind' => 'template', 'role' => 'sdr', 'stage' => 'outreach', 'slug' => 'verify-run-template', 'title' => 'Verify template', 'body' => 'A template body that is long enough to pass validation.'];
[$c, $j] = call($kernel, 'POST', '/gtm/playbooks', $E, $new);   $ok('employee create 403', $c === 403, $c);
[$c, $j] = call($kernel, 'POST', '/gtm/playbooks', $A, $new);   $ok('create 201', $c === 201 && $j['data']['playbook']['version'] === 1, $c); $newId = $j['data']['playbook']['id'] ?? 0;
[$c] = call($kernel, 'POST', '/gtm/playbooks', $A, $new);       $ok('duplicate slug 422', $c === 422, $c);
[$c] = call($kernel, 'POST', '/gtm/playbooks', $A, ['slug' => 'Bad Slug'] + $new); $ok('bad slug 422', $c === 422, $c);
[$c] = call($kernel, 'POST', '/gtm/playbooks', $A, ['kind' => 'nonsense'] + $new); $ok('bad kind 422', $c === 422, $c);
[$c] = call($kernel, 'POST', '/gtm/playbooks', $A, ['kind' => 'methodology', 'slug' => 'verify-meth'] + $new); $ok('methodology without rubric 422', $c === 422, $c);

// tenant isolation
if ($O) {
    [$c] = call($kernel, 'GET', "/gtm/playbooks/$newId", $O);                $ok('other tenant cannot read my playbook (404)', $c === 404, $c);
    [$c] = call($kernel, 'PATCH', "/gtm/playbooks/$newId", $O, ['title' => 'hijack']); $ok('other tenant cannot edit it (404)', $c === 404, $c);
    [$c] = call($kernel, 'DELETE', "/gtm/playbooks/$newId", $O);             $ok('other tenant cannot archive it (404)', $c === 404, $c);
    [$c] = call($kernel, 'POST', "/gtm/playbooks/{$mine['id']}/customise", $O); $ok('other tenant cannot copy my customised row (404)', $c === 404, $c);
    [$c, $j] = call($kernel, 'GET', '/gtm/playbooks', $O);                   $ok('other tenant sees defaults only, none of mine', collect($j['data']['items'])->every(fn ($x) => $x['source'] === 'platform'), $c);
} else { echo "SKIP tenant isolation: no other-tenant admin\n"; }

[$c] = call($kernel, 'DELETE', "/gtm/playbooks/$newId", $A);                $ok('archive 200', $c === 200, $c);
[$c, $j] = call($kernel, 'GET', '/gtm/playbooks', $A);                      $ok('archived hidden from default list', ! collect($j['data']['items'])->contains('slug', 'verify-run-template'));
[$c, $j] = call($kernel, 'GET', '/gtm/playbooks?status=archived', $A);      $ok('archived listed on request', collect($j['data']['items'])->contains('slug', 'verify-run-template'));

$types = DB::table('g2g_event')->where('sub_institute_id', 6)->where('type', 'like', 'gtm.playbook.%')->orderByDesc('id')->limit(30)->pluck('type')->unique()->values()->all();
$ok('audit events recorded', count(array_intersect(['gtm.playbook.created', 'gtm.playbook.customised', 'gtm.playbook.updated', 'gtm.playbook.restored', 'gtm.playbook.archived'], $types)) === 5, json_encode($types));

// cleanup of this run's test rows
$ids = DB::table('gtm_playbooks')->where('sub_institute_id', 6)->whereIn('slug', ['qualification-bant', 'verify-run-template'])->pluck('id');
DB::table('gtm_playbook_versions')->whereIn('playbook_id', $ids)->delete();
DB::table('gtm_playbooks')->whereIn('id', $ids)->delete();
echo "\n$pass passed, $fail failed\n";
