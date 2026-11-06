<?php

require __DIR__ . '/../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\OutreachGate\Checks\PlaceholderCheck;
use App\Domain\OutreachGate\Checks\BannedPhraseCheck;

$patterns = config('outreach_gate.placeholder_patterns', []);
$placeholderCheck = new PlaceholderCheck($patterns);

$badText1 = "Hi {{first_name}}, welcome to {{company}}!";
$badText2 = "Hello {name}, your account is ready.";
$badText3 = "Dear [First Name], please see [COMPANY] details.";
$badText4 = "Hello <first_name>, greetings!";
$badText5 = "Hello %FIRSTNAME%, greetings!";
$badText6 = "Hello \${name}, greetings!";
$goodText = "Hi Narendra, we noticed your post regarding HRMS requirements for your 30-50 member team at VN Softverg.";

echo "Test badText1: " . implode(', ', $placeholderCheck->found($badText1)) . "\n";
echo "Test badText2: " . implode(', ', $placeholderCheck->found($badText2)) . "\n";
echo "Test badText3: " . implode(', ', $placeholderCheck->found($badText3)) . "\n";
echo "Test badText4: " . implode(', ', $placeholderCheck->found($badText4)) . "\n";
echo "Test badText5: " . implode(', ', $placeholderCheck->found($badText5)) . "\n";
echo "Test badText6: " . implode(', ', $placeholderCheck->found($badText6)) . "\n";
echo "Test goodText: " . (count($placeholderCheck->found($goodText)) === 0 ? "PASSED (0 placeholders)" : "FAILED") . "\n";

$banned = config('outreach_gate.banned_phrases', []);
$bannedCheck = new BannedPhraseCheck($banned);

$badBanned = "Act now for a limited time offer! This is 100% free.";
$goodBanned = "We would love to share how ScholarClone handles payroll and attendance.";

echo "Test badBanned: " . $bannedCheck->run(new \App\Domain\OutreachGate\Draft(1, 1, 1, null, 'Subject', $badBanned, []))->why . "\n";
echo "Test goodBanned: " . $bannedCheck->run(new \App\Domain\OutreachGate\Draft(1, 1, 1, null, 'Subject', $goodBanned, []))->why . "\n";
