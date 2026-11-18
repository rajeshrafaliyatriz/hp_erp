<?php

$inb = 'app/Http/Controllers/Api/Crm/CrmSignalInboxController.php';
$fac = 'app/Domain/Crm/Leads/LeadFactory.php';
$des = 'app/Domain/Crm/Leads/LeadDescription.php';
$ctl = 'app/Http/Controllers/Api/Crm/CrmLeadController.php';
$tests = ['tests/Feature/LeadCreationPathsTest.php', 'tests/Feature/LeadDescriptionTest.php'];

return [
    ['id' => 'INB-1 the linked account is read without the tenant', 'file' => $inb, 'find' => "->where('id', \$row->linked_account_id)->where('sub_institute_id', \$tenantId)->whereNull('deleted_at')", 'replace' => "->where('id', \$row->linked_account_id)->whereNull('deleted_at')", 'tests' => $tests, 'why' => 'another organisation\'s account name and id land in this organisation\'s lead and opportunity'],
    ['id' => 'INB-2 an already promoted signal is promoted again', 'file' => $inb, 'find' => "if (\$row->status === 'promoted') {", 'replace' => 'if (false) {', 'tests' => $tests, 'why' => 'a second press makes a second lead'],
    ['id' => 'INB-3 the lead and the status are not one transaction', 'file' => $inb, 'find' => '[$outcome, $promotedId] = DB::transaction(function', 'replace' => '[$outcome, $promotedId] = call_user_func(function', 'tests' => $tests, 'why' => 'a failure while marking the signal leaves the lead behind'],
    ['id' => 'INB-4 the opportunity is inserted with a column that does not exist', 'file' => $inb, 'find' => "'name' => mb_substr((string) \$row->title, 0, 191),", 'replace' => "'opportunity_name' => mb_substr((string) \$row->title, 0, 191),", 'tests' => $tests, 'why' => 'the original defect: opportunity_name is not a column'],
    ['id' => 'INB-5 the opportunity event is not recorded', 'file' => $inb, 'find' => 'OpportunityEvents::created($tenantId, $promotedId, $userId, $organisation ? (int) $organisation->id : null, false);', 'replace' => ';', 'tests' => $tests, 'why' => 'the workflows are never told an opportunity exists'],
    ['id' => 'INB-6 a direct insert into crm_leads comes back', 'file' => $inb, 'find' => '// The same factory as every other way a lead is made: a number that cannot collide, a score, and the crm.lead.created event.', 'replace' => "DB::table('crm_leads')->insert(['last_name' => 'x', 'assigned_to' => 1]);", 'tests' => $tests, 'why' => 'a lead with no number, no score and no event'],
    ['id' => 'LF-2 the factory does not score', 'file' => $fac, 'find' => "\$attributes['lead_score'] = \$scored['total'];", 'replace' => "\$attributes['lead_score'] = 0;", 'tests' => $tests, 'why' => 'no path scores a lead'],
    ['id' => 'LF-3 the factory records no event', 'file' => $fac, 'find' => "GtmAudit::record('crm.lead.created', ", 'replace' => "GtmAudit::record('crm.lead.not_created', ", 'tests' => $tests, 'why' => 'the lead workflows are never told a lead exists'],
    ['id' => 'LF-4 the factory does not fit the description', 'file' => $fac, 'find' => "\$attributes['description'] = LeadDescription::fit(\$attributes['description']);", 'replace' => "\$attributes['description'] = \$attributes['description'];", 'tests' => $tests, 'why' => 'a long description reaches the database uncut'],
    ['id' => 'LD-1 no 2,000 character cut', 'file' => $des, 'find' => "mb_substr(\$text, 0, self::MAX_CHARS, 'UTF-8')", 'replace' => '$text', 'tests' => $tests, 'why' => 'the description is as long as the caller made it'],
    ['id' => 'LD-2 no byte cut for the old column', 'file' => $des, 'find' => "\$maxBytes ?? self::maxBytes(), 'UTF-8')", 'replace' => "PHP_INT_MAX, 'UTF-8')", 'tests' => $tests, 'why' => 'a host with the old TINYTEXT gets a description the column cannot hold'],
    ['id' => 'CV-1 the form does not cap the description', 'file' => $ctl, 'find' => "'annualRevenue' => 'nullable|numeric',\n            'description' => 'nullable|string|max:' . LeadDescription::MAX_CHARS,\n            // Nullable, not required", 'replace' => "'annualRevenue' => 'nullable|numeric',\n            // Nullable, not required", 'tests' => $tests, 'why' => 'store accepts a description of any length'],
    ['id' => 'CV-2 an edit does not cap the description', 'file' => $ctl, 'find' => "'description' => 'nullable|string|max:' . LeadDescription::MAX_CHARS,\n            'assignedTo' => 'sometimes|required|integer',", 'replace' => "'assignedTo' => 'sometimes|required|integer',", 'tests' => $tests, 'why' => 'update accepts a description of any length'],
    ['id' => 'CV-3 the import does not cap the description', 'file' => $ctl, 'find' => "'description' => 'nullable|string|max:' . LeadDescription::MAX_CHARS,\n            ]);", 'replace' => "]);", 'tests' => $tests, 'why' => 'an imported row may carry a description of any length'],
];
