<?php

$specs = json_decode(file_get_contents(__DIR__ . '/table_specs.json'), true);

foreach ($specs as $table => $cols) {
    echo "=== TABLE $table (Total columns: " . count($cols) . ") ===\n";
    $required = [];
    foreach ($cols as $c) {
        if ($c['Null'] === 'NO' && $c['Default'] === null && $c['Extra'] !== 'auto_increment') {
            $required[] = "{$c['Field']} ({$c['Type']})";
        }
    }
    echo "Required fields (NOT NULL, no default, not auto_inc):\n  " . (empty($required) ? 'NONE' : implode("\n  ", $required)) . "\n\n";
}
