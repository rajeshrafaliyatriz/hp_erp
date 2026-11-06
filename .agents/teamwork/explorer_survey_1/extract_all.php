<?php

function extractData($num) {
    $file = __DIR__ . "/post{$num}.html";
    $html = file_get_contents($file);

    $data = [];

    // Title tag
    if (preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
        $data['page_title'] = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
    }

    // og:title
    if (preg_match('/<meta property="og:title" content="(.*?)"/is', $html, $m)) {
        $data['og_title'] = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
    }

    // og:description
    if (preg_match('/<meta property="og:description" content="(.*?)"/is', $html, $m)) {
        $data['description'] = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
    }

    // og:url / canonical
    if (preg_match('/<meta property="og:url" content="(.*?)"/is', $html, $m)) {
        $data['og_url'] = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
    }
    if (preg_match('/<link rel="canonical" href="(.*?)"/is', $html, $m)) {
        $data['canonical'] = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
    }

    // activity Urn
    if (preg_match('/urn:li:activity:(\d+)/i', $html, $m)) {
        $data['activity_urn'] = 'urn:li:activity:' . $m[1];
        $data['activity_id'] = $m[1];
    }

    // Find author information inside HTML body
    // In LinkedIn guest public post pages:
    // Look for actor name, headline, link
    if (preg_match('/data-tracking-control-name="public_post_feed-actor-name"[^>]*>(.*?)<\/a>/is', $html, $m)) {
        $data['author_name'] = trim(strip_tags($m[1]));
    } elseif (preg_match('/class="[^"]*update-components-actor__name[^"]*"[^>]*>(.*?)<\/span>/is', $html, $m)) {
        $data['author_name'] = trim(strip_tags($m[1]));
    }

    if (preg_match('/data-tracking-control-name="public_post_feed-actor-image"[^>]*href="([^"]+)"/is', $html, $m)) {
        $data['author_url'] = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
    }

    if (preg_match('/class="[^"]*update-components-actor__description[^"]*"[^>]*>(.*?)<\/span>/is', $html, $m)) {
        $data['author_headline'] = trim(strip_tags($m[1]));
    } elseif (preg_match('/data-tracking-control-name="public_post_feed-actor-sub-description"[^>]*>(.*?)<\//is', $html, $m)) {
        $data['author_sub_description'] = trim(strip_tags($m[1]));
    }

    // Also look for author or profile link in html
    if (preg_match('/href="(https:\/\/[a-z]{2,3}\.linkedin\.com\/in\/[^"?]+)/i', $html, $m)) {
        $data['author_profile_url'] = $m[1];
    }

    // Check for author description / headline
    if (preg_match('/<div class="[^"]*feed-shared-actor__description[^"]*">(.*?)<\/div>/is', $html, $m)) {
        $data['author_headline_div'] = trim(strip_tags($m[1]));
    }

    // Let's dump whatever actor elements exist
    preg_match_all('/<div class="[^"]*actor[^"]*".*?<\/div>/is', $html, $actors);
    $data['actor_snippets'] = array_map('strip_tags', array_slice($actors[0] ?? [], 0, 3));

    return $data;
}

for ($i = 1; $i <= 3; $i++) {
    echo "==================== POST $i ====================\n";
    print_r(extractData($i));
}
