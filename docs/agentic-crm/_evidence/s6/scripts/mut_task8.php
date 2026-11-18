<?php

$bf = 'app/Domain/Crm/Leads/LeadLinkBackfill.php';
$cmd = 'app/Console/Commands/LeadsBackfillLinksCommand.php';
$tests = ['tests/Feature/LeadLinkBackfillTest.php'];

return [
    ['id' => 'BF-1 a dry run writes', 'file' => $bf, 'find' => 'if ($apply && $plan !== []) {', 'replace' => 'if ($plan !== []) {', 'tests' => $tests, 'why' => 'the preview changes the data'],
    ['id' => 'BF-2 a filled value is overwritten', 'file' => $bf, 'find' => '$was === null ? $update->whereNull($column) : $update->where($column, $was);', 'replace' => '$was === null ? $update : $update->where($column, $was);', 'tests' => $tests, 'why' => 'a link a person set meanwhile is replaced'],
    ['id' => 'BF-3 the signal is read without the organisation', 'file' => $bf, 'find' => "DB::table('g2g_company_opportunities')->where('id', \$signalId)->where('sub_institute_id', \$tenantId)->first()", 'replace' => "DB::table('g2g_company_opportunities')->where('id', \$signalId)->first()", 'tests' => $tests, 'why' => 'another organisation\'s signal links a lead'],
    ['id' => 'BF-5 the signal\'s company wins over the converted organisation', 'file' => $bf, 'find' => '$accountId = $byOrganisation ?? $byCompany;', 'replace' => '$accountId = $byCompany ?? $byOrganisation;', 'tests' => $tests, 'why' => 'the weaker evidence decides'],
    ['id' => 'BF-6 every organisation\'s leads are scanned', 'file' => $bf, 'find' => "DB::table('crm_leads')->where('sub_institute_id', \$tenantId)->orderBy('id')->chunk", 'replace' => "DB::table('crm_leads')->orderBy('id')->chunk", 'tests' => $tests, 'why' => 'an organisation\'s run links another organisation\'s leads'],
    ['id' => 'BF-7 updated_at is touched', 'file' => $bf, 'find' => '->update([$column => $value]);', 'replace' => '->update([$column => $value, \'updated_at\' => now()->addMinute()]);', 'tests' => $tests, 'why' => 'a backfill looks like an edit by a person'],
    ['id' => 'BF-8 a connection without the columns is not refused', 'file' => $bf, 'find' => "if (! in_array('product_master_id', \$columns, true) || ! in_array('org_account_id', \$columns, true)) {", 'replace' => 'if (false) {', 'tests' => $tests, 'why' => 'the run dies on an unknown column halfway'],
    ['id' => 'BF-9 a disagreement is not counted', 'file' => $bf, 'find' => "if (\$byOrganisation !== null && \$byCompany !== null && \$byOrganisation !== \$byCompany) {", 'replace' => 'if (false) {', 'tests' => $tests, 'why' => 'a lead whose two routes disagree is never surfaced'],
    ['id' => 'BF-10 only leads from a signal get a product', 'file' => $bf, 'find' => '} elseif ($signalId > 0 && $hasProducts) {', 'replace' => '} elseif ($hasProducts) {', 'tests' => $tests, 'why' => 'a lead with no signal is looked up with signal id 0'],
    ['id' => 'CMD-1 the command writes by default', 'file' => $cmd, 'find' => '$apply = (bool) $this->option(\'apply\');', 'replace' => '$apply = true;', 'tests' => $tests, 'why' => 'no dry run'],
    ['id' => 'CMD-2 --tenant and --all together are accepted', 'file' => $cmd, 'find' => "if (\$this->option('all') && (\$tenant !== null && \$tenant !== '')) {", 'replace' => 'if (false) {', 'tests' => $tests, 'why' => 'it is unclear which organisations a run covers'],
    ['id' => 'CMD-3 an unknown connection is accepted', 'file' => $cmd, 'find' => "if (config('database.connections.' . \$database) === null) {", 'replace' => 'if (false) {', 'tests' => $tests, 'why' => 'a typo in --database runs on whatever is left'],
    ['id' => 'CMD-4 a tenant of 0 or text is accepted', 'file' => $cmd, 'find' => "ctype_digit((string) \$tenant) && (int) \$tenant > 0) {", 'replace' => 'true) {', 'tests' => $tests, 'why' => '--tenant=abc runs on organisation 0'],
];
