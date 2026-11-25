<?php

// Where the browser lands after provider sign-in. SIGNALS_CONNECTORS_FRONTEND_URL wins and is used as-is.
// FRONTEND_URL is the site ORIGIN shared with other features, so the Ingestion page path is appended to it
// (unless it already carries a path). Plain statements, not a closure, so `config:cache` can serialise this.
$connectorReturnUrl = trim((string) env('SIGNALS_CONNECTORS_FRONTEND_URL', ''));
if ($connectorReturnUrl === '') {
    $frontendOrigin = trim((string) env('FRONTEND_URL', ''));
    $frontendPath = $frontendOrigin === '' ? '' : trim((string) parse_url($frontendOrigin, PHP_URL_PATH), '/');
    $connectorReturnUrl = $frontendOrigin === ''
        ? 'http://localhost:3000/ingestion'
        : ($frontendPath === '' ? rtrim($frontendOrigin, '/') . '/ingestion' : $frontendOrigin);
}

return [
    'max_items' => (int) env('SIGNALS_CONNECTOR_MAX_ITEMS', 60),
    // Posts analysed per AI call, and the seconds after which a sync stops starting new batches (the rest waits for the next sync).
    'analysis_batch_size' => (int) env('SIGNALS_CONNECTOR_BATCH_SIZE', 10),
    'sync_time_budget_seconds' => (int) env('SIGNALS_CONNECTOR_SYNC_BUDGET_SECONDS', 240),
    // YouTube: videos whose viewer comments are read, and buyer-intent searches run across public YouTube (separate with |).
    'youtube_comment_videos' => (int) env('SIGNALS_YOUTUBE_COMMENT_VIDEOS', 8),
    'youtube_search_queries' => array_values(array_filter(array_map('trim', explode('|', (string) env(
        'SIGNALS_YOUTUBE_SEARCH_QUERIES',
        'school management software India|school ERP tender|school admission fee software|school ERP implementation school|school fee collection software|school attendance management app'
    ))))),
    // Upper bound on provider API pages one sync reads (each page is one request of up to 30 s). Stops a feed whose posts are
    // mostly filtered out (e.g. a Facebook Page feed full of other people's posts) from paging without limit.
    'max_fetch_pages' => (int) env('SIGNALS_CONNECTOR_MAX_FETCH_PAGES', 10),
    'schedule_enabled' => (bool) env('SIGNALS_CONNECTOR_SCHEDULE_ENABLED', true),
    'google' => [
        'client_id' => env('YOUTUBE_OAUTH_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_OAUTH_CLIENT_SECRET'),
        'redirect_uri' => env('YOUTUBE_OAUTH_REDIRECT_URI', env('APP_URL') . '/api/signals/ingestion/connectors/oauth/youtube/callback'),
    ],
    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'version' => env('LINKEDIN_API_VERSION', '202610'),
        'redirect_uri' => env('LINKEDIN_OAUTH_REDIRECT_URI', env('APP_URL') . '/api/signals/ingestion/connectors/oauth/linkedin/callback'),
    ],
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'graph_version' => env('META_GRAPH_API_VERSION', 'v26.0'),
        // "Facebook Login for Business" apps: the id of the saved Configuration that lists the permissions (one for both, or one per type).
        'login_config_id' => env('META_LOGIN_CONFIG_ID'),
        'login_config_ids' => [
            'facebook_page' => env('META_LOGIN_CONFIG_ID_FACEBOOK'),
            'instagram_business' => env('META_LOGIN_CONFIG_ID_INSTAGRAM'),
        ],
        'redirect_uri' => env('META_OAUTH_REDIRECT_URI', env('APP_URL') . '/api/signals/ingestion/connectors/oauth/meta/callback'),
    ],
    'frontend_return_url' => $connectorReturnUrl,
    // True only when SIGNALS_CONNECTORS_FRONTEND_URL was set on purpose: then it is always used. Otherwise the site the person started from is.
    'frontend_return_is_explicit' => trim((string) env('SIGNALS_CONNECTORS_FRONTEND_URL', '')) !== '',
];
