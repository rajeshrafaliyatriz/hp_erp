<?php

/*
|--------------------------------------------------------------------------
| AI & Intelligence — G2G
|--------------------------------------------------------------------------
|
| The configuration behind the centralised AI & Intelligence console. It is the
| G2G half of the arrangement LMS K-12 already runs: the same capability names,
| the same provider/model/credential resolution, the same route prefix — reading
| G2G's own database rather than the school ERP's.
|
| WHY THIS FILE EXISTS RATHER THAN MORE ENV VARS
|
| G2G had two provider keys in config (config/gemini.php, config/deepseek.php),
| each read directly by one caller, and nothing that could answer "which provider
| is this organisation actually calling, with whose key". That question is what
| AI Providers, Model Management and Usage & Cost are all views over, so the
| answer has to live in one place the runtime and the screens both read.
|
| Nothing here replaces config/gemini.php or config/deepseek.php. Those keep
| working exactly as they did; this block adds the *resolvable* layer above them,
| and `AiConfigurationResolver` falls back to these env values so an organisation
| with no saved credential behaves precisely as it does today.
|
*/

return [
    /*
    | Where the shared intelligence API is mounted. Matches LMS K-12's
    | `config('ai.route_prefix')` so a caller written against one product's
    | endpoint reaches the same path on the other.
    */
    'route_prefix' => env('AI_ROUTE_PREFIX', 'api/ai'),

    /*
    |--------------------------------------------------------------------------
    | Model providers
    |--------------------------------------------------------------------------
    |
    | `driver` is what an unconfigured AI module resolves to. It is deliberately
    | the last step of the precedence in `AiConfigurationResolver`, not the first:
    | a credential saved against a module wins over it, and this is only the
    | answer when nobody has said anything more specific.
    |
    | `api_type` is the value a provider's credentials are tagged with in
    | `ai_api_keys.api_type`. It exists because credential rows written at
    | different times used different conventions, and one lookup has to resolve
    | both. Rows written by the AI Providers screen use the provider key itself.
    |
    */
    'provider' => [
        'driver' => env('AI_PROVIDER', 'gemini'),

        'gemini' => [
            // Read from config/gemini.php's own env var, so a deployment that has
            // already set GEMINI_API_KEY keeps working with no .env change.
            'api_key' => env('GEMINI_API_KEY'),
            // No version segment: the REST shape is /models/{model}:generateContent.
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
            'timeout' => (int) env('GEMINI_REQUEST_TIMEOUT', 45),
            'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 2048),
            'api_type' => env('GEMINI_API_TYPE', 'gemini'),
            // Additional platform credentials for automatic failover, on top of
            // GEMINI_API_KEY: a comma-separated GEMINI_API_KEYS and/or GEMINI_API_KEY_1
            // .. GEMINI_API_KEY_20. Only used when no key is saved in `ai_api_keys`.
            'extra_api_keys' => array_values(array_filter(array_merge(
                explode(',', (string) env('GEMINI_API_KEYS', '')),
                array_map(fn ($n) => env("GEMINI_API_KEY_{$n}"), range(1, 20))
            ), fn ($key) => is_string($key) && trim($key) !== '')),
        ],

        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY'),
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'model' => env('OPENROUTER_MODEL', 'deepseek/deepseek-chat'),
            'timeout' => (int) env('OPENROUTER_TIMEOUT', 45),
            'max_output_tokens' => (int) env('OPENROUTER_MAX_OUTPUT_TOKENS', 2048),
            'api_type' => env('OPENROUTER_API_TYPE', 'OPENROUTER_API_KEY'),
        ],

        'deepseek' => [
            'api_key' => env('DEEPSEEK_API_KEY'),
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
            'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'timeout' => (int) env('DEEPSEEK_TIMEOUT_SECONDS', 600),
            'max_output_tokens' => (int) env('DEEPSEEK_MAX_OUTPUT_TOKENS', 0),
            'api_type' => env('DEEPSEEK_API_TYPE', 'DEEPSEEK_API_KEY'),
            // Send `thinking: disabled` for deepseek-flash / deepseek-v4-* so JSON answers are not
            // swallowed by reasoning tokens. See AiModelClient::callOpenAiCompatible().
            'disable_thinking' => filter_var(env('DEEPSEEK_DISABLE_THINKING', true), FILTER_VALIDATE_BOOLEAN),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Credential failover
    |--------------------------------------------------------------------------
    |
    | When an organisation has several credentials for one provider, a quota /
    | rate-limit / invalid-key failure moves the request to the next healthy one.
    | Health (cooldowns) lives in the cache. Each credential is tried at most once
    | per request, bounded by `max_attempts`.
    */
    'failover' => [
        // Modules whose credentials are reserved for them: rows bound to these modules are
        // never picked up by another module's shared-pool fallback. Default: Signals.
        'exclusive_modules' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_EXCLUSIVE_MODULES', env('SIGNALS_AI_MODULE', 'signals')))))),
        'providers' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_FAILOVER_PROVIDERS', 'gemini'))))),
        'max_attempts' => (int) env('AI_FAILOVER_MAX_ATTEMPTS', 20),
        // Provider 5xx (e.g. 503 overloaded): also move to the next key, each tried once.
        // Still bounded by max_attempts; lower this to stop sooner during a provider outage.
        'max_transient_attempts' => (int) env('AI_FAILOVER_MAX_TRANSIENT_ATTEMPTS', 20),
        // 429 with no Retry-After / retryDelay from the provider.
        'quota_cooldown_seconds' => (int) env('AI_FAILOVER_QUOTA_COOLDOWN', 60),
        // Bounds applied to a provider-supplied retry delay.
        'min_cooldown_seconds' => (int) env('AI_FAILOVER_MIN_COOLDOWN', 5),
        'max_cooldown_seconds' => (int) env('AI_FAILOVER_MAX_COOLDOWN', 3600),
        'transient_cooldown_seconds' => (int) env('AI_FAILOVER_TRANSIENT_COOLDOWN', 15),
        // Rejected/revoked key: retried later in case it was fixed, not disabled in the DB.
        'invalid_cooldown_seconds' => (int) env('AI_FAILOVER_INVALID_COOLDOWN', 3600),
    ],

    'rate_limit' => [
        'per_minute' => (int) env('AI_RATE_LIMIT_PER_MINUTE', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat lifecycle and chat actions
    |--------------------------------------------------------------------------
    |
    | lifecycle.enabled     route /ask through the twelve-stage lifecycle (trace, evidence,
    |                       proposed actions, reports). Off = the original four-step pipeline.
    | chat_actions.require_approval
    |                       a write proposed from the chat also needs an administrator's approval.
    |                       Keep in step with the frontend's NEXT_PUBLIC_CHAT_ACTION_APPROVALS.
    */
    'lifecycle' => [
        'enabled' => (bool) env('AI_LIFECYCLE_ENABLED', false),
    ],

    'chat_actions' => [
        'require_approval' => (bool) env('AI_CHAT_ACTION_APPROVALS', false),
    ],
];
