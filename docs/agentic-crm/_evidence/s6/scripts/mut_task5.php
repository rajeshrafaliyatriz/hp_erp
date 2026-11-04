<?php

$links = 'app/Domain/Crm/Leads/LeadLinks.php';
$fac = 'app/Domain/Crm/Leads/LeadFactory.php';
$ev = 'app/Domain/Crm/Leads/LeadSignalEvidence.php';
$opp = 'app/Http/Controllers/Api/Signals/OpportunityController.php';
$tests = ['tests/Feature/LeadPromotionLinksTest.php', 'tests/Feature/SignalReviewApiTest.php'];
$t1 = ['tests/Feature/LeadPromotionLinksTest.php'];

return [
    ['id' => 'LK-1 the product lookup accepts a product of any organisation', 'file' => $links, 'find' => 'if ($own > 0 && self::productVisible($tenantId, $own)) {', 'replace' => 'if ($own > 0) {', 'tests' => $t1, 'why' => 'the signal\'s own product is trusted without asking whose it is'],
    ['id' => 'LK-2 the identity lookup is not tenant-scoped', 'file' => $links, 'find' => "DB::table('g2g_org_accounts')->where('sub_institute_id', \$tenantId)->where(\$pointer, \$sourceId)", 'replace' => "DB::table('g2g_org_accounts')->where(\$pointer, \$sourceId)", 'tests' => $t1, 'why' => 'another organisation\'s identity holds this company'],
    ['id' => 'LK-3 a removed identity still holds a company', 'file' => $links, 'find' => "->where(\$pointer, \$sourceId)->whereNull('deleted_at')", 'replace' => '->where($pointer, $sourceId)', 'tests' => $t1, 'why' => 'a lead is linked to an identity that was removed'],
    ['id' => 'LK-4 shared products are not visible', 'file' => $links, 'find' => '->visibleTo($tenantId)->whereKey($productId)->exists()', 'replace' => '->where(\'sub_institute_id\', $tenantId)->whereKey($productId)->exists()', 'tests' => $t1, 'why' => 'the built-in products (organisation 0) cannot be linked'],
    ['id' => 'LK-5 the signal\'s stored fit is not resolved', 'file' => $links, 'find' => '$hit = (new ProductResolver)->forSignal($tenantId, $signal->business_fit ?? null, $signal->offer_id ?? null);', 'replace' => '$hit = null;', 'tests' => $t1, 'why' => 'a signal with only a business_fit gets no product'],
    ['id' => 'LF-5 the factory stores a link that is not the organisation\'s', 'file' => $fac, 'find' => '|| $value <= 0 || ! $visible($tenantId, $value)', 'replace' => '|| $value <= 0', 'tests' => $t1, 'why' => 'a caller\'s mistake links a lead to another organisation\'s product or identity'],
    ['id' => 'LF-6 the factory writes the links on a host that lacks the columns', 'file' => $fac, 'find' => '! in_array($column, $present, true) ||', 'replace' => '', 'tests' => $t1, 'why' => 'the code reaches a server before its migration: every promotion fails'],
    ['id' => 'OP-1 the note is not written', 'file' => $opp, 'find' => "(new RecordNote)->record(\$t, 'lead', \$leadId, \$identity['user_id'],", 'replace' => "(fn () => null)(\$t, 'lead', \$leadId, \$identity['user_id'],", 'tests' => $tests, 'why' => 'a promoted lead says nothing about where it came from'],
    ['id' => 'OP-2 a signal with a lead gets a second one', 'file' => $opp, 'find' => 'if ($existing) {', 'replace' => 'if (false) {', 'tests' => $tests, 'why' => 'the second press makes a second lead and a second note'],
    ['id' => 'OP-3 the summary is cut to 255 bytes again', 'file' => $opp, 'find' => "\$description = implode(' ', \$summaryParts);", 'replace' => "\$description = mb_strcut(implode(' ', \$summaryParts), 0, 255, 'UTF-8');", 'tests' => $t1, 'why' => 'the widened description is not used'],
    ['id' => 'OP-4 the product is not set on the lead', 'file' => $opp, 'find' => "'product_master_id' => \$productId,", 'replace' => "'product_master_id' => null,", 'tests' => $t1, 'why' => 'the lead is not filed under its product'],
    ['id' => 'OP-5 the organisation account is not set on the lead', 'file' => $opp, 'find' => "'org_account_id' => \$orgAccountId,", 'replace' => "'org_account_id' => null,", 'tests' => $t1, 'why' => 'the lead is not filed under its organisation'],
    ['id' => 'OP-6 the note is written for nobody', 'file' => $opp, 'find' => "(new RecordNote)->record(\$t, 'lead', \$leadId, \$identity['user_id'],", 'replace' => "(new RecordNote)->record(\$t, 'lead', \$leadId, null,", 'tests' => $t1, 'why' => 'the note carries no author'],
    ['id' => 'EV-1 the quote is the observed event, not the source excerpt', 'file' => $ev, 'find' => "'quote' => \$excerpt ?? \$observed,", 'replace' => "'quote' => \$observed,", 'tests' => $t1, 'why' => 'the source\'s own words are never quoted'],
    ['id' => 'EV-2 an impossible date is accepted', 'file' => $ev, 'find' => '|| ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])', 'replace' => '', 'tests' => $t1, 'why' => '2026-02-31 and 0000-00-00 are dates'],
    ['id' => 'EV-3 any address is accepted', 'file' => $ev, 'find' => "preg_match('#^https?://#i', \$url) === 1", 'replace' => 'true', 'tests' => $t1, 'why' => 'javascript: or ftp: addresses go into the note'],
    ['id' => 'EV-4 the quote is not cut to the note writer\'s room', 'file' => $ev, 'find' => 'max(0, min(self::QUOTE_MAX, $room))', 'replace' => 'self::QUOTE_MAX', 'tests' => $t1, 'why' => 'the note can be longer than the writer keeps and the fit line is cut off'],
    ['id' => 'EV-5 an observed event is passed off as a quote', 'file' => $ev, 'find' => "\$evidence['quote_kind'] === 'excerpt' ? 'Quote: \"' . \$quote . '\"' : 'What the signal observed: ' . \$quote;", 'replace' => "'Quote: \"' . \$quote . '\"';", 'tests' => $t1, 'why' => 'the note says "Quote:" for text the source never said'],
];
