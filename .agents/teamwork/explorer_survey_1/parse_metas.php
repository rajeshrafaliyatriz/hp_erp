<?php
foreach ([1, 2, 3] as $num) {
    echo "========================================\n";
    echo "POST $num DETAILS:\n";
    echo "========================================\n";
    $file = __DIR__ . "/post{$num}.html";
    $html = file_get_contents($file);

    // Let's find all <meta property="og:..." or <meta name="..."
    preg_match_all('/<meta\s+[^>]*>/i', $html, $metas);
    foreach ($metas[0] as $meta) {
        if (stripos($meta, 'description') !== false || stripos($meta, 'title') !== false || stripos($meta, 'author') !== false || stripos($meta, 'image') !== false || stripos($meta, 'url') !== false) {
            echo "META: " . html_entity_decode($meta, ENT_QUOTES | ENT_HTML5) . "\n";
        }
    }
}
