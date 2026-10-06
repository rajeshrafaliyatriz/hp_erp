<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Gamma API Key (fallback)
    |--------------------------------------------------------------------------
    |
    | Per-tenant keys live in the `gamma_api` table and take precedence; this is
    | the fallback used when a sub-institute has no key of its own configured.
    */

    'api_key' => env('GAMMA_API_KEY'),

    'base_url' => env('GAMMA_BASE_URL', 'https://public-api.gamma.app/v1.0'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Only covers a single call. Generation itself is asynchronous: the create
    | call returns a generationId immediately and the client polls for the
    | result, so no request ever blocks for the full render.
    */

    'request_timeout' => (int) env('GAMMA_REQUEST_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Presentation defaults
    |--------------------------------------------------------------------------
    |
    | Mirrors the payload the previous frontend sent to Gamma so generated decks
    | keep the same house style.
    */

    'defaults' => [
        'format' => 'presentation',
        'text_mode' => 'generate',
        'card_split' => 'auto',
        'export_as' => env('GAMMA_EXPORT_AS', 'pdf'),
        'additional_instructions' => 'All slides must use clear, consistent formatting. Ensure a formal instructional tone.',
        'text_options' => [
            'amount' => 'extensive',
            'tone' => 'formal, instructional',
            'audience' => 'employees, L&D managers, HR',
            'language' => 'en',
        ],
        'image_options' => [
            'source' => 'aiGenerated',
            'model' => env('GAMMA_IMAGE_MODEL', 'imagen-4-pro'),
            'style' => 'minimal, professional',
        ],
        'card_options' => [
            'dimensions' => 'fluid',
        ],
        'sharing_options' => [
            'workspaceAccess' => 'view',
            'externalAccess' => 'noAccess',
        ],
    ],
];
