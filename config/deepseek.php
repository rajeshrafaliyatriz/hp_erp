<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | DeepSeek API Key
    |--------------------------------------------------------------------------
    |
    | Your key from https://platform.deepseek.com/api_keys. Course generation
    | calls DeepSeek directly rather than proxying through OpenRouter, which is
    | what the previous frontend did.
    */

    'api_key' => env('DEEPSEEK_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    |
    | DeepSeek exposes an OpenAI-compatible surface, so the chat endpoint is
    | {base_url}/chat/completions.
    */

    'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),

    /*
    |--------------------------------------------------------------------------
    | Model
    |--------------------------------------------------------------------------
    |
    | `deepseek-chat` always points at DeepSeek's current flagship chat model,
    | so it tracks new releases without a code change. Set DEEPSEEK_MODEL in
    | .env to pin a specific version instead. `deepseek-reasoner` is the
    | reasoning variant.
    */

    'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),

    /*
    |--------------------------------------------------------------------------
    | Generation defaults
    |--------------------------------------------------------------------------
    */

    'max_tokens' => (int) env('DEEPSEEK_MAX_TOKENS', 4000),
    'temperature' => (float) env('DEEPSEEK_TEMPERATURE', 0.7),
    'top_p' => (float) env('DEEPSEEK_TOP_P', 0.9),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Outline generation is a single long completion, so this is deliberately
    | more generous than the default HTTP client timeout.
    */

    'request_timeout' => (int) env('DEEPSEEK_REQUEST_TIMEOUT', 120),
];
