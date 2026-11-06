<?php

for ($i = 1; $i <= 3; $i++) {
    $html = file_get_contents(__DIR__ . "/../explorer_survey_1/post{$i}.html");
    echo "==================================== POST $i ====================================\n";
    if (preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/is', $html, $matches)) {
        $data = json_decode($matches[1], true);
        echo "Headline: " . ($data['headline'] ?? '') . "\n";
        echo "Date Published: " . ($data['datePublished'] ?? '') . "\n";
        echo "Author: " . json_encode($data['author'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        echo "Comment Count: " . ($data['commentCount'] ?? '') . "\n";
        echo "Article Body:\n" . ($data['articleBody'] ?? '') . "\n\n";
    }
}
