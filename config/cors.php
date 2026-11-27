<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Was ['*'] with supports_credentials true below — that combination let any
    // website make credentialed requests to this API. Restricted to the real
    // frontend origins this app actually runs on (see .env.example) — plus
    // g2gv0.vercel.app, the actual current deployment: the custom domain in
    // .env.example (g2g.scholarclone.com) isn't live yet, so the frontend is
    // still served from Vercel's own domain. The first restriction (this
    // domain omitted) broke every real login with a CORS-blocked preflight —
    // caught live, not in review.
    // Every origin listed here has always been allowed and still is. CORS_EXTRA_ORIGINS (comma separated, full origins such as https://app.example.com) adds
    // more WITHOUT a code change, so a frontend moved to a new domain is a setting, not a deploy. Only well-formed http(s) origins are accepted; "*" never is.
    'allowed_origins' => array_values(array_unique(array_merge([
        'https://g2g.scholarclone.com',
        'https://g2gv0.vercel.app',
        'http://localhost:3000',
        'http://localhost:3001',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:3001',
        'https://app.gapstogrowth.com',
    ], array_filter(
        array_map(fn ($o) => rtrim(trim($o), '/'), explode(',', (string) env('CORS_EXTRA_ORIGINS', ''))),
        fn ($o) => preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?$#', $o) === 1
    )))),

    'allowed_origins_patterns' => [
        '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
