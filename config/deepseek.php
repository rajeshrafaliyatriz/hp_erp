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
    | `deepseek-chat` is an alias, not a model. It resolves to whichever chat
    | model the account's endpoint currently serves.
    |
    | MEASURED 2026-08-27, because a rate card is not a substitute for a probe.
    | DeepSeek's public docs list only `deepseek-v4-flash` and `deepseek-v4-pro`
    | and announce that `deepseek-chat` retired on 2026-07-24 — but on THIS
    | account and base_url all three names still return HTTP 200, and they do
    | not behave the same:
    |
    |   deepseek-chat       443 in / 252 out — valid JSON, 3 of 3 tasks, finish=stop
    |   deepseek-v4-flash   522 in / 3000 out — NOTHING parseable, finish=length
    |   deepseek-v4-pro     522 in / 3000 out — NOTHING parseable, finish=length
    |
    | The v4 names consumed their entire output allowance and returned no usable
    | content, at 8x and 24x the cost for zero result. So `deepseek-chat` is kept
    | deliberately, not by inertia.
    |
    | If it ever stops resolving, re-run that comparison before switching — the
    | v4 models need different handling (larger max_tokens, and possibly no JSON
    | mode), not just a different string here.
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
];
