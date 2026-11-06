<?php
foreach ([1, 2, 3] as $num) {
    echo "========================================\n";
    echo "POST $num DETAILS:\n";
    echo "========================================\n";
    $file = __DIR__ . "/post{$num}.html";
    if (!file_exists($file)) {
        echo "File not found: $file\n";
        continue;
    }
    $html = file_get_contents($file);
    echo "File size: " . strlen($html) . " bytes\n";

    // Extract title
    if (preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
        echo "Title: " . trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)) . "\n";
    }

    // Extract meta description
    if (preg_match('/<meta name="description" content="(.*?)"/is', $html, $m)) {
        echo "\nMeta Description:\n" . trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)) . "\n";
    }

    // Extract ld+json if present
    if (preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/is', $html, $matches)) {
        foreach ($matches[1] as $idx => $jsonStr) {
            echo "\nLD+JSON [$idx]:\n" . substr(trim($jsonStr), 0, 500) . "\n";
        }
    }

    // Extract author / actor information
    if (preg_match('/class="[^"]*actor[^"]*".*?<\/div>/is', $html, $m)) {
        echo "\nActor snippet: " . strip_tags($m[0]) . "\n";
    }
}
