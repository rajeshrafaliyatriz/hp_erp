<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Domain\Crm\Leads\LeadScorer;
use App\Domain\OutreachGate\Checks\BannedPhraseCheck;
use App\Domain\OutreachGate\Checks\LengthCheck;
use App\Domain\OutreachGate\Checks\PlaceholderCheck;
use App\Domain\OutreachGate\Draft;
use Illuminate\Support\Facades\DB;

echo "=== ADVERSARIAL STRESS TEST SUITE ===" . PHP_EOL;

// 1. Stress-test PlaceholderCheck
echo "1. Testing PlaceholderCheck sensitivity:" . PHP_EOL;
$patterns = (array) config('outreach_gate.placeholder_patterns', [
    '\{\{[^}]*\}\}',
    '\{%[^}]*%\}',
    '\$\{[^}]*\}',
    '\{\s*[a-z_][\w .-]{0,40}\}',
    '\[\s*[^\]\n]{0,25}\b(?:name|first|last|company|organi[sz]ation|school|institute|college|university|contact|recipient|customer|client|sender|title|designation|city|date|product|email)\b[^\]\n]{0,25}\]',
    '(?-i:\[[A-Z][A-Z0-9 _-]{2,}\])',
    '<\s*[a-z]+(?:[_ -][a-z]+)+\s*>',
    '(?-i:%[A-Z][A-Z0-9_]{2,}%)',
]);
$pc = new PlaceholderCheck($patterns);

$positives = [
    'Hello {{first_name}}',
    'Dear {% author_name %}',
    'Contact at ${email}',
    '{company_name}',
    '[recipient_name]',
    '[COMPANY]',
    '<first_name>',
    '%CUSTOMER_NAME%',
];

foreach ($positives as $badText) {
    $found = $pc->found($badText);
    if (empty($found)) {
        echo "  [FAIL] Did not detect placeholder in: '{$badText}'" . PHP_EOL;
    } else {
        echo "  [PASS] Successfully detected placeholder in '{$badText}': " . implode(',', $found) . PHP_EOL;
    }
}

// 2. Stress-test BannedPhraseCheck
echo PHP_EOL . "2. Testing BannedPhraseCheck sensitivity:" . PHP_EOL;
$bc = new BannedPhraseCheck(['risk-free', 'guarantee', 'act now', 'urgent attention', 'congratulations']);
$bannedDraft = new Draft(Draft::GTM, 1, 6, 'Urgent attention required', 'This is a risk-free proposal.');
$bannedRes = $bc->run($bannedDraft);
echo "  Detection result: passed=" . ($bannedRes->passed ? 'true' : 'false') . ", detail=" . $bannedRes->detail . PHP_EOL;

// 3. Stress-test LengthCheck
echo PHP_EOL . "3. Testing LengthCheck boundaries:" . PHP_EOL;
$lc = new LengthCheck(150, 1500);

$longSubjectDraft = new Draft(Draft::GTM, 1, 6, str_repeat('A', 151), 'Body');
$longSubjRes = $lc->run($longSubjectDraft);
echo "  151-char subject: passed=" . ($longSubjRes->passed ? 'true' : 'false') . ", detail=" . $longSubjRes->detail . PHP_EOL;

$longBodyDraft = new Draft(Draft::GTM, 1, 6, 'Subject', str_repeat('B', 1501));
$longBodyRes = $lc->run($longBodyDraft);
echo "  1501-char body: passed=" . ($longBodyRes->passed ? 'true' : 'false') . ", detail=" . $longBodyRes->detail . PHP_EOL;

$exactLimitDraft = new Draft(Draft::GTM, 1, 6, str_repeat('A', 150), str_repeat('B', 1500));
$exactRes = $lc->run($exactLimitDraft);
echo "  Exact limit (150/1500): passed=" . ($exactRes->passed ? 'true' : 'false') . PHP_EOL;

// 4. Stress-test LeadScorer rules
echo PHP_EOL . "4. Testing LeadScorer edge cases:" . PHP_EOL;
// Missing industry
$res1 = LeadScorer::score(['rating' => 'Hot'], 6);
echo "  Only rating=Hot: total=" . $res1['total'] . " (expected 15)" . PHP_EOL;

// Missing rating
$res2 = LeadScorer::score(['industry' => 'Education'], 6);
echo "  Only industry=Education: total=" . $res2['total'] . " (expected 25)" . PHP_EOL;

// Empty
$res3 = LeadScorer::score([], 6);
echo "  Empty attributes: total=" . $res3['total'] . " (expected 0)" . PHP_EOL;

// Case sensitivity check
$res4 = LeadScorer::score(['industry' => 'education', 'rating' => 'hot'], 6);
echo "  Lower-case 'education' / 'hot': total=" . $res4['total'] . " (expected 0 if case-sensitive, or 40 if insensitive)" . PHP_EOL;

// Tenant isolation of rules
$res5 = LeadScorer::score(['industry' => 'Education', 'rating' => 'Hot'], 9999);
echo "  Non-existent tenant 9999: total=" . $res5['total'] . " (expected 0)" . PHP_EOL;

// 5. Cross-tenant leakage adversarial test
echo PHP_EOL . "5. Cross-tenant leakage adversarial test:" . PHP_EOL;
$otherTenantSignals = DB::table('crm_signal_inbox')
    ->where('sub_institute_id', '!=', 6)
    ->whereIn('source_url', [
        'https://www.linkedin.com/posts/narendra-parvathareddy_hrms-humanresourcemanagement-hrsoftware-share-7524083165313888257--6np/',
        'https://www.linkedin.com/posts/niharrout_looking-for-a-reliable-hr-and-payroll-solution-share-7523833858241314816-IUTN/',
        'https://www.linkedin.com/posts/anjuli-s_hrms-payrollsoftware-hrtech-share-7523281777478795264-CV8R/',
    ])->count();
echo "  Target signals in other tenants: {$otherTenantSignals} (expected 0)" . PHP_EOL;

$otherTenantLeads = DB::table('crm_leads')
    ->where('sub_institute_id', '!=', 6)
    ->whereIn('lead_no', ['LD-00103', 'LD-00104', 'LD-00105'])
    ->count();
echo "  Target leads in other tenants: {$otherTenantLeads} (expected 0)" . PHP_EOL;

echo PHP_EOL . "=== ALL ADVERSARIAL CHECKS COMPLETED ===" . PHP_EOL;
