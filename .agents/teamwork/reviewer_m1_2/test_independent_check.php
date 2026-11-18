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

echo "=== 1. CHECKING LEAD SCORER LOGIC & FORMULA ===" . PHP_EOL;
$scoreRes = LeadScorer::score(['industry' => 'Education', 'rating' => 'Hot'], 6);
echo "Score total (Education + Hot): " . $scoreRes['total'] . PHP_EOL;
echo "Score basis: " . json_encode($scoreRes['basis']) . PHP_EOL;

$coldRes = LeadScorer::score(['industry' => 'Education', 'rating' => 'Cold'], 6);
echo "Score total (Education + Cold): " . $coldRes['total'] . PHP_EOL;

$otherRes = LeadScorer::score(['industry' => 'Retail', 'rating' => 'Warm'], 6);
echo "Score total (Retail + Warm): " . $otherRes['total'] . PHP_EOL;

if ($scoreRes['total'] !== 40) {
    echo "[FAIL] LeadScorer did not return 40!" . PHP_EOL;
} else {
    echo "[PASS] LeadScorer accurately computes 40 for industry=Education & rating=Hot" . PHP_EOL;
}

echo PHP_EOL . "=== 2. CHECKING DATABASE RECORDS & TENANT 6 ISOLATION ===" . PHP_EOL;
$targetUrls = [
    'https://www.linkedin.com/posts/narendra-parvathareddy_hrms-humanresourcemanagement-hrsoftware-share-7524083165313888257--6np/',
    'https://www.linkedin.com/posts/niharrout_looking-for-a-reliable-hr-and-payroll-solution-share-7523833858241314816-IUTN/',
    'https://www.linkedin.com/posts/anjuli-s_hrms-payrollsoftware-hrtech-share-7523281777478795264-CV8R/',
];

// Signals
$signals = DB::table('crm_signal_inbox')->whereIn('source_url', $targetUrls)->get();
echo "Found " . count($signals) . " signals:" . PHP_EOL;
$linkedOrgIds = [];
$signalIds = [];
foreach ($signals as $s) {
    $signalIds[] = $s->id;
    $linkedOrgIds[] = $s->linked_account_id;
    echo "  Signal #{$s->id}: sub_institute_id={$s->sub_institute_id}, assigned_to={$s->assigned_to}, class={$s->classification}, score={$s->relevance_score}, status={$s->status}, linked_account_id={$s->linked_account_id}, promoted_id={$s->promoted_id}" . PHP_EOL;
    if ($s->sub_institute_id != 6 || $s->assigned_to != 28) {
        echo "  [FAIL] Leakage on signal #{$s->id}!" . PHP_EOL;
    }
}

// Orgs
$orgs = DB::table('crm_organizations')->whereIn('id', $linkedOrgIds)->get();
echo "Found " . count($orgs) . " crm_organizations:" . PHP_EOL;
foreach ($orgs as $o) {
    echo "  Org #{$o->id}: '{$o->name}', sub_institute_id={$o->sub_institute_id}, assigned_to={$o->assigned_to}, created_by={$o->created_by}" . PHP_EOL;
    if ($o->sub_institute_id != 6 || $o->assigned_to != 28) {
        echo "  [FAIL] Leakage on crm_organizations #{$o->id}!" . PHP_EOL;
    }
}

// G2G Org Accounts
$g2g = DB::table('g2g_org_accounts')->whereIn('crm_organization_id', $linkedOrgIds)->get();
echo "Found " . count($g2g) . " g2g_org_accounts:" . PHP_EOL;
foreach ($g2g as $g) {
    echo "  G2G Org #{$g->id}: '{$g->name}', crm_org={$g->crm_organization_id}, gtm_acc={$g->gtm_account_id}, sub_institute_id={$g->sub_institute_id}" . PHP_EOL;
    if ($g->sub_institute_id != 6) {
        echo "  [FAIL] Leakage on g2g_org_accounts #{$g->id}!" . PHP_EOL;
    }
}

// GTM Accounts
$gtmAcc = DB::table('gtm_accounts')->whereIn('crm_organization_id', $linkedOrgIds)->get();
echo "Found " . count($gtmAcc) . " gtm_accounts:" . PHP_EOL;
$gtmAccIds = [];
foreach ($gtmAcc as $a) {
    $gtmAccIds[] = $a->id;
    echo "  GTM Acc #{$a->id}: '{$a->name}', crm_org={$a->crm_organization_id}, sub_institute_id={$a->sub_institute_id}, owner={$a->owner_user_id}" . PHP_EOL;
    if ($a->sub_institute_id != 6 || $a->owner_user_id != 28) {
        echo "  [FAIL] Leakage on gtm_accounts #{$a->id}!" . PHP_EOL;
    }
}

// CRM Contacts
$contacts = DB::table('crm_contacts')->whereIn('organization_id', $linkedOrgIds)->get();
echo "Found " . count($contacts) . " crm_contacts:" . PHP_EOL;
$authorContactIds = [];
foreach ($contacts as $c) {
    if ($c->reports_to_id === null) {
        $authorContactIds[] = $c->id;
    }
    echo "  Contact #{$c->id}: {$c->first_name} {$c->last_name} ({$c->title}), org={$c->organization_id}, reports_to={$c->reports_to_id}, sub_institute_id={$c->sub_institute_id}, assigned_to={$c->assigned_to}, consent={$c->consent_status}" . PHP_EOL;
    if ($c->sub_institute_id != 6 || $c->assigned_to != 28 || $c->consent_status !== 'consented') {
        echo "  [FAIL] Leakage or consent issue on crm_contacts #{$c->id}!" . PHP_EOL;
    }
}

// GTM Contacts
$gtmContacts = DB::table('gtm_contacts')->whereIn('account_id', $gtmAccIds)->get();
echo "Found " . count($gtmContacts) . " gtm_contacts:" . PHP_EOL;
foreach ($gtmContacts as $gc) {
    echo "  GTM Contact #{$gc->id}: {$gc->full_name} ({$gc->title}), acc={$gc->account_id}, sub_institute_id={$gc->sub_institute_id}, created_by={$gc->created_by}" . PHP_EOL;
    if ($gc->sub_institute_id != 6 || $gc->created_by != 28) {
        echo "  [FAIL] Leakage on gtm_contacts #{$gc->id}!" . PHP_EOL;
    }
}

// CRM Leads
$leads = DB::table('crm_leads')->whereIn('converted_organization_id', $linkedOrgIds)->get();
echo "Found " . count($leads) . " crm_leads:" . PHP_EOL;
foreach ($leads as $l) {
    echo "  Lead #{$l->id}: {$l->lead_no}, company='{$l->company}', pm={$l->product_master_id}, score={$l->lead_score}, sub_institute_id={$l->sub_institute_id}, assigned_to={$l->assigned_to}" . PHP_EOL;
    if ($l->sub_institute_id != 6 || $l->assigned_to != 28 || $l->product_master_id != 1 || $l->lead_score != 40) {
        echo "  [FAIL] Lead #{$l->id} validation failed!" . PHP_EOL;
    }
}

// GTM Outreach Messages
$outreach = DB::table('gtm_outreach_messages')->whereIn('account_id', $gtmAccIds)->get();
echo "Found " . count($outreach) . " gtm_outreach_messages:" . PHP_EOL;
foreach ($outreach as $m) {
    echo "  Outreach #{$m->id}: status='{$m->status}', pm={$m->product_master_id}, to={$m->to_email}, sub_institute_id={$m->sub_institute_id}, created_by={$m->created_by}" . PHP_EOL;
    if ($m->sub_institute_id != 6 || $m->created_by != 28 || $m->status !== 'pending_approval' || $m->product_master_id != 1) {
        echo "  [FAIL] Outreach #{$m->id} status/tenant issue!" . PHP_EOL;
    }
}

echo PHP_EOL . "=== 3. ADVERSARIAL STRESS TEST OF OUTREACH MESSAGES ===" . PHP_EOL;
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
$placeholderChecker = new PlaceholderCheck($patterns);
$bannedPhrases = (array) config('outreach_gate.banned_phrases', []);
$bannedChecker = new BannedPhraseCheck($bannedPhrases);
$claimMarkers = (array) config('outreach_gate.claim_markers', [
    '\d+(?:[.,]\d+)?\s*%',
    '\bpercent\b',
    '\b\d+(?:[.,]\d+)?\s*x\b',
    '\bguarantee[sd]?\b',
    '\bguaranteed\b',
    '#\s*1\b',
    '\bnumber\s+(?:one|1)\b',
    '\b(?:the\s+)?(?:best|fastest|cheapest|lowest|biggest|largest|leading|top[- ]rated|most\s+(?:trusted|popular|advanced|affordable|reliable))\b',
    '\bthe\s+only\b',
    '\bonly\s+one\b',
    '\bone\s+and\s+only\b',
    '\bno\.\s*1\b',
    '\b(?:zero|no)\s+(?:risk|downtime|errors?|mistakes?)\b',
    '\b100\s*%',
]);

foreach ($outreach as $m) {
    echo "--- Testing Outreach #{$m->id} ({$m->to_email}) ---" . PHP_EOL;
    $text = $m->subject . "\n" . $m->body;

    // Placeholders
    $ph = $placeholderChecker->found($text);
    if (!empty($ph)) {
        echo "  [FAIL] Placeholders found: " . implode(', ', $ph) . PHP_EOL;
    } else {
        echo "  [PASS] Zero placeholders found" . PHP_EOL;
    }

    // Banned phrases
    $draft = new Draft(Draft::GTM, (int)$m->id, 6, (string)$m->subject, (string)$m->body);
    $bannedRes = $bannedChecker->run($draft);
    if (!$bannedRes->passed) {
        echo "  [FAIL] Banned phrase detected: " . $bannedRes->why . PHP_EOL;
    } else {
        echo "  [PASS] Zero banned phrases" . PHP_EOL;
    }

    // Claim markers
    $foundClaims = [];
    foreach ($claimMarkers as $cm) {
        $re = '~' . str_replace('~', '\\~', $cm) . '~iu';
        if (preg_match($re, $text, $match)) {
            $foundClaims[] = $match[0];
        }
    }
    if (!empty($foundClaims)) {
        echo "  [FAIL] Claims found: " . implode(', ', $foundClaims) . PHP_EOL;
    } else {
        echo "  [PASS] Zero unapproved claims / superlatives / percentages" . PHP_EOL;
    }

    // Length check
    $subjLen = mb_strlen($m->subject);
    $bodyLen = mb_strlen($m->body);
    preg_match_all('~https?://[^\s<>"]+~i', $m->body, $links);
    $linkCount = count(array_unique($links[0] ?? []));

    echo "  Subject length: {$subjLen} / 150 -> " . ($subjLen <= 150 ? "[PASS]" : "[FAIL]") . PHP_EOL;
    echo "  Body length: {$bodyLen} / 1500 -> " . ($bodyLen <= 1500 ? "[PASS]" : "[FAIL]") . PHP_EOL;
    echo "  Link count: {$linkCount} / 1 -> " . ($linkCount <= 1 ? "[PASS]" : "[FAIL]") . PHP_EOL;
}

echo PHP_EOL . "=== 4. INTEGRITY & CODE SAFETY CHECKS ===" . PHP_EOL;
$files = [
    'database/seeders/Tenant6SignalOutreachLifecycleSeeder.php',
    'scripts/verify_tenant6_lifecycle.php',
];

foreach ($files as $f) {
    $content = file_get_contents($f);
    if (strpos($content, 'Schema::hasColumn(') !== false) {
        echo "  [FAIL] Forbidden Schema::hasColumn found in {$f}!" . PHP_EOL;
    } else {
        echo "  [PASS] No Schema::hasColumn in {$f}" . PHP_EOL;
    }

    // Check for hardcoded mock returns in verification
    if (preg_match('/assertCheck\(\s*true\s*,/', $content)) {
        echo "  [WARN] assertCheck(true, ...) pattern found in {$f}" . PHP_EOL;
    }
}

echo PHP_EOL . "=== INDEPENDENT CHECK COMPLETE ===" . PHP_EOL;
