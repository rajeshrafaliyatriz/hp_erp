<?php

for ($i = 1; $i <= 3; $i++) {
    $html = file_get_contents(__DIR__ . "/../explorer_survey_1/post{$i}.html");
    echo "=== POST $i ===\n";
    // Check for JSON-LD
    if (preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/is', $html, $matches)) {
        foreach ($matches[1] as $jsonText) {
            $data = json_decode($jsonText, true);
            if ($data) {
                echo "JSON-LD: " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            }
        }
    }
    
    // Check for author headline / subtitle / bio in html
    preg_match_all('/<div class="[^"]*update-components-actor[^"]*"[^>]*>(.*?)<\/div>/is', $html, $m);
    if (!empty($m[0])) {
        echo "Actor HTML:\n" . strip_tags($m[0][0]) . "\n";
    }
    
    // Search for company or headline text around author name
    preg_match_all('/(headline|jobTitle|worksFor|sub-description)[^>]{0,200}/i', $html, $sub);
    echo "Matches: " . count($sub[0]) . "\n";
    foreach (array_slice($sub[0], 0, 5) as $s) {
        echo "  - $s\n";
    }
}
