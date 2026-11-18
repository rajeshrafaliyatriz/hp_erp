<?php

$seq = 'app/Domain/Numbering/NumberSequence.php';
$num = 'app/Domain/Crm/Leads/LeadNumber.php';
$fac = 'app/Domain/Crm/Leads/LeadFactory.php';
$tests = ['tests/Feature/NumberSequenceTest.php', 'tests/Feature/LeadNumberTest.php'];

return [
    ['id' => 'SEQ-1 claim adds nothing', 'file' => $seq, 'find' => "DB::raw('next_value + 1')", 'replace' => "DB::raw('next_value + 0')", 'tests' => $tests, 'why' => 'the counter never moves, so every claim returns the same number'],
    ['id' => 'SEQ-2 off by one on the read', 'file' => $seq, 'find' => "->value('next_value') - 1;", 'replace' => "->value('next_value');", 'tests' => $tests, 'why' => 'the claim returns the number AFTER its own'],
    ['id' => 'SEQ-3 no key filter on the increment', 'file' => $seq, 'find' => "DB::table(self::TABLE)->where('seq_key', \$key)->update(['next_value' => DB::raw('next_value + 1')", 'replace' => "DB::table(self::TABLE)->update(['next_value' => DB::raw('next_value + 1')", 'tests' => $tests, 'why' => 'a claim raises every key (one key sees another key\'s counter)'],
    ['id' => 'SEQ-4 seed ignores the callers question', 'file' => $seq, 'find' => 'max(1, $firstNumber())', 'replace' => '1', 'tests' => $tests, 'why' => 'a new key always starts at 1'],
    ['id' => 'SEQ-5 insert, not insert-ignore', 'file' => $seq, 'find' => '->insertOrIgnore(', 'replace' => '->insert(', 'tests' => $tests, 'why' => 'two requests starting a key at once: the second one fails on the primary key'],
    ['id' => 'SEQ-6 a floor can lower the counter', 'file' => $seq, 'find' => "->where('next_value', '<', \$floor)", 'replace' => '', 'tests' => $tests, 'why' => 'raiseTo sets the counter even when it is already higher'],
    ['id' => 'SEQ-7 the increment and the read are not one transaction', 'file' => $seq, 'find' => "        return DB::transaction(function () use (\$key) {\n            \$changed = DB::table(self::TABLE)->where('seq_key', \$key)->update(['next_value' => DB::raw('next_value + 1'), 'updated_at' => now()]);\n            if (\$changed === 0) {\n                return null;\n            }\n\n            return (int) DB::table(self::TABLE)->where('seq_key', \$key)->value('next_value') - 1;\n        });", 'replace' => "        return (function () use (\$key) {\n            \$changed = DB::table(self::TABLE)->where('seq_key', \$key)->update(['next_value' => DB::raw('next_value + 1'), 'updated_at' => now()]);\n            if (\$changed === 0) {\n                return null;\n            }\n\n            return (int) DB::table(self::TABLE)->where('seq_key', \$key)->value('next_value') - 1;\n        })();", 'tests' => $tests, 'why' => 'between the increment and the read another process can increment: both read the same value'],
    ['id' => 'LN-1 no check against numbers already taken', 'file' => $num, 'find' => "if (! DB::table('crm_leads')->where('lead_no', \$number)->exists()) {", 'replace' => 'if (true) {', 'tests' => $tests, 'why' => 'a number made without the counter is handed out again'],
    ['id' => 'LN-2 no fallback without the table', 'file' => $num, 'find' => 'if (! self::isMissingCounterTable($e)) {', 'replace' => 'if (true) {', 'tests' => $tests, 'why' => 'a host that has not run the migration cannot create a lead'],
    ['id' => 'LN-5 every database error falls back to the racy number', 'file' => $num, 'find' => 'if (! self::isMissingCounterTable($e)) {', 'replace' => 'if (false) {', 'tests' => $tests, 'why' => 'a lock or a deadlock quietly hands out highest-plus-one again'],
    ['id' => 'LN-3 the seed reads the lowest number, not the highest', 'file' => $num, 'find' => '$max = max($max,', 'replace' => '$max = min($max,', 'tests' => $tests, 'why' => 'the counter starts below the numbers in use'],
    ['id' => 'LN-4 a four digit number', 'file' => $num, 'find' => "str_pad((string) \$n, 5, '0', STR_PAD_LEFT)", 'replace' => "str_pad((string) \$n, 4, '0', STR_PAD_LEFT)", 'tests' => $tests, 'why' => 'the format changes'],
    ['id' => 'LF-1 the factory numbers a lead the old way (highest plus one)', 'file' => $fac, 'find' => 'LeadNumber::claim();', 'replace' => 'LeadNumber::format(LeadNumber::highest() + 1);', 'tests' => $tests, 'why' => 'the race of defect D8 is back'],
];
