<?php
[$c, $j] = call($kernel, 'POST', '/gtm/accounts/3/score-icp', null); echo "no token: $c\n";
[$c, $j] = call($kernel, 'POST', '/gtm/accounts/3/score-icp', $E); echo "employee: $c\n";
[$c, $j] = call($kernel, 'POST', '/gtm/accounts/3/score-icp', $A); echo "admin: $c score=" . ($j['data']['score'] ?? '-') . ' ' . ($j['message'] ?? '') . "\n";
[$c, $j] = call($kernel, 'GET', '/gtm/accounts/3', $A); echo "detail: $c fit=" . ($j['data']['account']['icp_fit_score'] ?? '-') . ' analyses=' . count($j['data']['analyses'] ?? []) . "\n";
[$c, $j] = call($kernel, 'POST', '/gtm/accounts/99999/score-icp', $A); echo "missing: $c\n";
if ($O) { [$c, $j] = call($kernel, 'POST', '/gtm/accounts/3/score-icp', $O); echo "other tenant admin: $c\n"; } else { echo "no other-tenant admin available\n"; }
