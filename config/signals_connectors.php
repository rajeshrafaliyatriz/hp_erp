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
    // AI signal sources: finished signals delivered by the organisation's own scheduled AI tasks (Claude, GPT, DeepSeek, Gemini). G2G imports them; it never runs the models.
    'ai_delivery' => [
        'enabled' => (bool) env('SIGNALS_AI_DELIVERY', true),
        'max_kb' => (int) env('SIGNALS_AI_DELIVERY_MAX_KB', 2048),          // largest delivery body accepted
        'max_records' => (int) env('SIGNALS_AI_DELIVERY_MAX_RECORDS', 200),  // most signals in one delivery
        'stuck_minutes' => (int) env('SIGNALS_AI_DELIVERY_STUCK_MINUTES', 30),   // a delivery left "importing" this long (its worker died) is imported again
    ],
    // Public contact lookup for the organisation behind a signal (queued after the signal exists; never blocks or changes a signal).
    'contact_research' => [
        'enabled' => (bool) env('SIGNALS_CONTACT_RESEARCH', true),
        'max_searches' => (int) env('SIGNALS_CONTACT_MAX_SEARCHES', 6),          // search credits spent per organisation per lookup
        'daily_search_budget' => (int) env('SIGNALS_CONTACT_DAILY_SEARCHES', 40),   // searches the AUTOMATIC lookups may spend per day, all organisations together (0 = no limit); a person's Find contacts is not held to it
        'recheck_days' => (int) env('SIGNALS_CONTACT_RECHECK_DAYS', 7),          // an organisation is not searched again within this many days
        // Extra titles tried after the ones chosen for the kind of organisation (school / government or company / other); separate with | in SIGNALS_CONTACT_ROLES.
        'roles' => array_values(array_filter(array_map('trim', explode('|', (string) env('SIGNALS_CONTACT_ROLES', ''))))),
    ],
    // Email domains that are OUR OWN organisation's (comma separated, e.g. "scholarclone.com"): never shown as a prospect's contact.
    'own_contact_domains' => (string) env('SIGNALS_OWN_CONTACT_DOMAINS', ''),
    'analysis_batch_size' => (int) env('SIGNALS_CONNECTOR_BATCH_SIZE', 10),
    'sync_time_budget_seconds' => (int) env('SIGNALS_CONNECTOR_SYNC_BUDGET_SECONDS', 240),
    // YouTube: videos whose viewer comments are read, and buyer-intent searches run across public YouTube (separate with |).
    'youtube_comment_videos' => (int) env('SIGNALS_YOUTUBE_COMMENT_VIDEOS', 8),
    'youtube_search_queries' => array_values(array_filter(array_map('trim', explode('|', (string) env(
        'SIGNALS_YOUTUBE_SEARCH_QUERIES',
        'school management software India|school ERP tender|school admission fee software|school ERP implementation school|school fee collection software|school attendance management app|school ERP demo India|best school ERP for CBSE schools|school ERP vs|switching school management software|school fee management problems India|school principal ERP experience|NEP 2020 school software|school parent communication app India|school management software review|school app for attendance and fees|school group digital transformation India|school ERP implementation experience'
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
