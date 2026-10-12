<?php
[$c, $j] = call($kernel, 'GET', '/gtm/outreach/readiness?check=1', $A);
$r = $j['data'] ?? [];
echo "HTTP $c\n";
echo json_encode(array_diff_key($r, ['blockers' => 1]), JSON_PRETTY_PRINT) . "\n";
echo "blockers:\n - " . implode("\n - ", $r['blockers'] ?? []) . "\n";
