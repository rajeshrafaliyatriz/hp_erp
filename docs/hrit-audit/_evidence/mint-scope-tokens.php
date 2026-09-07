<?php
// Sprint 1 verification: tokens for three employees who HAVE leave, so Self
// scope can be shown to select the right rows rather than always returning 0.
use App\Models\auth\tbluserModel;
$out = [];
foreach ([12, 54, 150] as $userId) {
    $user = tbluserModel::find($userId);
    $out[] = "3\temp$userId\t$userId\t" . $user->createToken('hrit-audit')->plainTextToken;
}
file_put_contents(base_path('Docs/hrit-audit/_evidence/tokens.tsv'),
    implode("\n", $out) . "\n", FILE_APPEND);
echo "minted " . count($out) . "\n";
