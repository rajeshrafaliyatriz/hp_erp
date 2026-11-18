<?php

for ($i = 1; $i <= 3; $i++) {
    $html = file_get_contents(__DIR__ . "/../explorer_survey_1/post{$i}.html");
    echo "=== POST $i META TAGS ===\n";
    preg_match_all('/<meta\s+[^>]+>/i', $html, $m);
    foreach ($m[0] as $tag) {
        if (preg_match('/(author|creator|title|description|keywords|site|url)/i', $tag)) {
            echo "  " . trim($tag) . "\n";
        }
    }
}
