<?php

return [
    'max_items' => (int) env('SIGNALS_CONNECTOR_MAX_ITEMS', 25),
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
        'redirect_uri' => env('META_OAUTH_REDIRECT_URI', env('APP_URL') . '/api/signals/ingestion/connectors/oauth/meta/callback'),
    ],
    'frontend_return_url' => env('SIGNALS_CONNECTORS_FRONTEND_URL', env('FRONTEND_URL', 'http://localhost:3000/ingestion')),
];
