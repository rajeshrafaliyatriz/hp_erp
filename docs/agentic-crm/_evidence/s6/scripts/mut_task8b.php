<?php

$bf = 'app/Domain/Crm/Leads/LeadLinkBackfill.php';
$lk = 'app/Domain/Crm/Leads/LeadLinks.php';
$tests = ['tests/Feature/LeadLinkBackfillTest.php'];

return [
    ['id' => 'DG-1 another organisation\'s identity counts as live', 'file' => $bf, 'find' => "DB::table('g2g_org_accounts')->where('sub_institute_id', \$tenantId)->whereNull('deleted_at')->whereIn('id', \$present)", 'replace' => "DB::table('g2g_org_accounts')->whereNull('deleted_at')->whereIn('id', \$present)", 'tests' => $tests, 'why' => 'a lead keeps a link to another organisation\'s identity'],
    ['id' => 'DG-2 a removed identity counts as live', 'file' => $bf, 'find' => "->where('sub_institute_id', \$tenantId)->whereNull('deleted_at')->whereIn('id', \$present)", 'replace' => "->where('sub_institute_id', \$tenantId)->whereIn('id', \$present)", 'tests' => $tests, 'why' => 'a merged-away identity is never repaired'],
    ['id' => 'DG-3 the dead account is replaced only where the value is NULL', 'file' => $bf, 'find' => '$was === null ? $update->whereNull($column) : $update->where($column, $was);', 'replace' => '$update->whereNull($column);', 'tests' => $tests, 'why' => 'a dead account is never actually replaced'],
    ['id' => 'DG-4 the replacement is not conditional', 'file' => $bf, 'find' => '$was === null ? $update->whereNull($column) : $update->where($column, $was);', 'replace' => ';', 'tests' => $tests, 'why' => 'whatever a person set meanwhile is overwritten'],
    ['id' => 'RP-1 the repoint is not scoped to the organisation', 'file' => $lk, 'find' => "DB::table('crm_leads')->where('sub_institute_id', \$tenantId)->where('org_account_id', \$fromId)", 'replace' => "DB::table('crm_leads')->where('org_account_id', \$fromId)", 'tests' => $tests, 'why' => 'a merge in one organisation moves another organisation\'s leads'],
    ['id' => 'RP-2 the repoint moves a lead onto itself', 'file' => $lk, 'find' => '|| $fromId === $toId', 'replace' => '', 'tests' => $tests, 'why' => 'harmless write: would be reported as moved leads'],
];
