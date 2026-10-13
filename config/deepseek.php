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
    | SUPERSEDES the 2026-08-27 measurement this comment used to cite.
    | `deepseek-chat` was officially discontinued 2026-07-24 — the earlier
    | measurement was unknowingly probing an already-retired alias a month
    | after its own retirement (still answering then, almost certainly via a
    | legacy-routing grace period, not a guarantee). Confirmed directly
    | against DeepSeek's own API changelog on 2026-10-06:
    |
    |   deepseek-chat         discontinued 2026-07-24 (legacy, do not use)
    |   deepseek-v4-flash     active but legacy — temporarily routed to V4.1 Flash
    |   deepseek-flash        CURRENT — DeepSeek-V4.1-Flash, released 2026-09-10,
    |                         native multimodal, reduced pricing vs v4-flash
    |   deepseek-v4-pro       active, GA 2026-08-13, enhanced agent capabilities
    |
    | The earlier "v4-flash/v4-pro return nothing parseable" finding was about
    | THOSE specific legacy names, not about `deepseek-flash` (V4.1 Flash) —
    | a different, newer model this account had not yet tested at the time.
    | Re-verify directly (don't trust a dated comment, including this one)
    | before assuming any of the above is still accurate.
    */

    'model' => env('DEEPSEEK_MODEL', 'deepseek-flash'),

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
    | Minimum Balance (USD)
    |--------------------------------------------------------------------------
    |
    | DeepSeekService refuses to send when the account balance is at or below
    | this, checked against the free /user/balance endpoint.
    |
    | DeepSeek already refuses at zero with HTTP 402. That protects DeepSeek.
    | This floor protects the account: four separate features share one small
    | balance, and a single bulk run that spends it to nothing takes assessment
    | generation, marking and course outlines down with it.
    |
    | Set to 0 to disable the check entirely.
    */

    'min_balance_usd' => (float) env('DEEPSEEK_MIN_BALANCE_USD', 1.00),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Outline generation is a single long completion, so this is deliberately
    | more generous than the default HTTP client timeout.
    */

    'request_timeout' => (int) env('DEEPSEEK_REQUEST_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Models a module's AI Stack binding may select
    |--------------------------------------------------------------------------
    |
    | The ai_models catalogue is admin-editable and is offered on every module's
    | Models tab, including entries these generators were never tuned for (for
    | example `deepseek-reasoner`, which is present in the catalogue). A module
    | binding is only honoured by ModuleDeepSeekModel when the model is listed
    | here; anything else is ignored and the default above is used instead.
    |
    | The default model is always allowed. Add further, individually verified
    | models with a comma-separated DEEPSEEK_ALLOWED_MODELS.
    */

    'allowed_models' => array_values(array_unique(array_filter(array_map(
        'trim',
        array_merge(
            [env('DEEPSEEK_MODEL', 'deepseek-flash')],
            explode(',', (string) env('DEEPSEEK_ALLOWED_MODELS', ''))
        )
    )))),
];
