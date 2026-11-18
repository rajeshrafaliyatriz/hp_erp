<?php

$conv = 'app/Domain/Crm/Leads/LeadConverter.php';
$facts = 'app/Domain/Crm/Leads/LeadFacts.php';
$ctl = 'app/Http/Controllers/Api/Crm/CrmLeadController.php';
$wf = 'app/Domain/Gtm/Workflows/WorkflowActions.php';
$tests = ['tests/Feature/LeadConversionTest.php', 'tests/Feature/FactsConsentRecorderTest.php'];
$t1 = ['tests/Feature/LeadConversionTest.php'];

return [
    ['id' => 'CV-A the placeholder guard is off', 'file' => $conv, 'find' => '} elseif (self::hasNoPerson($lead)) {', 'replace' => '} elseif (false) {', 'tests' => $tests, 'why' => 'a contact named after the company is made'],
    ['id' => 'CV-B the company name is not compared', 'file' => $conv, 'find' => "return \$last === \$squash(\$lead->company ?? '') || \$last === 'signal lead';", 'replace' => "return \$last === 'signal lead';", 'tests' => $tests, 'why' => 'a lead named after its company is a person'],
    ['id' => 'CV-C a first name does not make a person', 'file' => $conv, 'find' => "if (trim((string) (\$lead->first_name ?? '')) !== '') {\n            return false;\n        }", 'replace' => '', 'tests' => $tests, 'why' => 'Rahul Greenfield-School is no person'],
    ['id' => 'CV-D every lead without a first name is no person', 'file' => $conv, 'find' => "return \$last === \$squash(\$lead->company ?? '') || \$last === 'signal lead';", 'replace' => 'return true;', 'tests' => $tests, 'why' => 'a lead with only a last name, which the form allows, loses its contact (a regression)'],
    ['id' => 'CV-E a contact-only request for a lead with no person is not refused', 'file' => $ctl, 'find' => '&& ! $createOpportunity) {', 'replace' => '&& false) {', 'tests' => $t1, 'why' => 'the lead is marked converted with nothing made'],
    ['id' => 'CV-F the response does not say no contact was made', 'file' => $ctl, 'find' => "if (\$converted['contact_skipped']) {", 'replace' => 'if (false) {', 'tests' => $t1, 'why' => 'the screen is never told'],
    ['id' => 'CV-G the facts do not follow the lead', 'file' => $conv, 'find' => '$factsMoved = LeadFacts::followToContactSafely($tenant, (int) $lead->id, (int) $contactId);', 'replace' => '$factsMoved = 0;', 'tests' => $t1, 'why' => 'the evidence about a person stays on an archived lead'],
    ['id' => 'LF-A the stand-in is never moved to the contact', 'file' => $facts, 'find' => "return (new FactRepoint)->move(\$tenantId, 'contact', \$standIn, \$contactId);", 'replace' => 'return $none;', 'tests' => $t1, 'why' => 'the facts are left under an id that belongs to nobody'],
    ['id' => 'LF-B the re-label is not tenant-scoped', 'file' => $facts, 'find' => "->where('sub_institute_id', \$tenantId)->where('entity_type', 'lead')->where('entity_id', \$leadId)", 'replace' => "->where('entity_type', 'lead')->where('entity_id', \$leadId)", 'tests' => $t1, 'why' => 'another organisation\'s facts are moved'],
    ['id' => 'LF-C facts that do not apply to a person move too', 'file' => $facts, 'find' => '->whereIn(\'field\', $fields)->update', 'replace' => '->update', 'tests' => $t1, 'why' => 'the organisation\'s website becomes a person\'s fact'],
    ['id' => 'LF-D a ledger failure stops the conversion', 'file' => $facts, 'find' => '} catch (\Throwable $e) {', 'replace' => '} catch (\InvalidArgumentException $e) {', 'tests' => $t1, 'why' => 'a broken ledger blocks converting a lead'],
    ['id' => 'LF-E the re-label and the move are not one transaction', 'file' => $facts, 'find' => 'return DB::transaction(function () use ($tenantId, $leadId, $contactId, $fields, $none) {', 'replace' => 'return call_user_func(function () use ($tenantId, $leadId, $contactId, $fields, $none) {', 'tests' => $t1, 'why' => 'a failure half way leaves the facts under the stand-in id'],
    ['id' => 'ML-A a lead merge leaves the duplicate\'s facts behind', 'file' => $ctl, 'find' => "app(\\App\\Domain\\Facts\\FactRepoint::class)->move(\$identity['sub_institute_id'], 'lead', \$fromId, \$toId);", 'replace' => ';', 'tests' => $t1, 'why' => 'facts stay on an archived duplicate'],
    ['id' => 'ML-B a lead merge loses the duplicate\'s opt-out', 'file' => $ctl, 'find' => "app(\\App\\Domain\\Facts\\ConsentRecorder::class)->carryOver(\$identity['sub_institute_id'], 'lead', \$fromId, 'lead', \$toId);", 'replace' => ';', 'tests' => $t1, 'why' => 'an opted-out lead merged into an opted-in one can be emailed again'],
    ['id' => 'ML-C a lead merge fails on a host without the ledger', 'file' => $ctl, 'find' => "if (\\Illuminate\\Support\\Facades\\Schema::hasTable('g2g_entity_facts')) {", 'replace' => 'if (true) {', 'tests' => $t1, 'why' => 'merging leads breaks where the ledger migration has not run'],
    ['id' => 'WA-A the automation note names a contact that was not made', 'file' => $wf, 'find' => "\$r['contact_id'] ? \"Contact #{\$r['contact_id']}\" : 'no Contact (the lead names no person, so none was made)',", 'replace' => "\"Contact #{\$r['contact_id']}\",", 'tests' => $t1, 'why' => 'the note says "Contact #," for nothing'],
];
