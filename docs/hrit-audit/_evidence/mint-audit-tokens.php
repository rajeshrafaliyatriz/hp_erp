<?php
/**
 * Sprint 0 evidence tool. Mints one Sanctum token per role_key so the audit can
 * prove RBAC at the API rather than at the menu. Tokens are named 'hrit-audit'
 * so revoke-audit-tokens.php can delete exactly these and nothing else.
 *
 *   php artisan tinker --execute="require 'Docs/hrit-audit/_evidence/mint-audit-tokens.php';"
 */
use App\Models\auth\tbluserModel;

$targets = [
    3 => ['administrator'=>6,'auditor'=>590,'department_head'=>580,'employee'=>7,
          'executive'=>589,'hr_executive'=>579,'hr_manager'=>67,'recruiter'=>588,
          'reporting_manager'=>581,
          // Sprint 6. Vikram reports to 581 (the Reporting Manager) and sits in
          // department 1930 with 580 (the Department Head), so one request can
          // walk the whole chain. `employee` 7 cannot: nobody manages them and
          // their department is 35, so both approvers correctly answer 404 and
          // the chain is untestable through them.
          'team_employee'=>582],
    6 => ['administrator'=>28,'employee'=>63],
];

$out = [];
foreach ($targets as $tenant => $roles) {
    foreach ($roles as $role => $userId) {
        $user = tbluserModel::find($userId);
        if (!$user) { $out[] = "MISSING user $userId ($tenant/$role)"; continue; }
        $token = $user->createToken('hrit-audit')->plainTextToken;
        $out[] = "$tenant\t$role\t$userId\t$token";
    }
}
file_put_contents(base_path('Docs/hrit-audit/_evidence/tokens.tsv'), implode("\n", $out)."\n");
echo implode("\n", array_map(fn($l) => substr($l, 0, 60), $out)), "\n";
