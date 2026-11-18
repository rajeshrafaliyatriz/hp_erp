<?php

for ($i = 1; $i <= 3; $i++) {
    $html = file_get_contents(__DIR__ . "/../explorer_survey_1/post{$i}.html");
    echo "=== POST $i ACTOR BLOCK ===\n";
    if (preg_match('/<section class="[^"]*profile[^"]*"[^>]*>(.*?)<\/section>/is', $html, $m)) {
        echo strip_tags($m[0]) . "\n";
    }
    if (preg_match('/<div class="[^"]*feed-shared-actor[^"]*"[^>]*>(.*?)<\/div>/is', $html, $m)) {
        echo strip_tags($m[0]) . "\n";
    }
    if (preg_match('/data-tracking-control-name="public_post_feed-actor-name".*?<\/div>/is', $html, $m)) {
        echo strip_tags($m[0]) . "\n";
    }
    // Check any text after author name
    if (preg_match('/<h3[^>]*>.*?<\/h3>\s*<h4[^>]*>(.*?)<\/h4>/is', $html, $m)) {
        echo "h4: " . trim(strip_tags($m[1])) . "\n";
    }
    // Look for actor sub-description
    if (preg_match('/public_post_feed-actor-sub-description[^>]*>(.*?)<\//is', $html, $m)) {
        echo "sub-description: " . trim(strip_tags($m[1])) . "\n";
    }
    // Look for any text between actor name and post text
    if (preg_match('/public_post_feed-actor-name.*?class="[^"]*attributed-text/is', $html, $m)) {
        echo "Between: " . trim(strip_tags($m[0])) . "\n";
    }
}
