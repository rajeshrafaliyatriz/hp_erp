<?php

/*
|--------------------------------------------------------------------------
| AI Signals Engine
|--------------------------------------------------------------------------
|
| Everything an operator may want to change without touching code. The AI
| provider, model and key are NOT here: signals call `AiModelClient` under the
| `signals` module, so they resolve through the same AI Providers
| configuration as every other AI feature (see config/ai.php).
*/

return [
    // Master switch for the daily scheduled run. Manual generation is unaffected.
    'schedule_enabled' => (bool) env('SIGNALS_SCHEDULE_ENABLED', true),

    // 24-hour HH:MM, interpreted in `timezone`.
    'schedule_time' => env('SIGNALS_SCHEDULE_TIME', '10:00'),
    'timezone' => env('SIGNALS_TIMEZONE', 'Asia/Kolkata'),

    // Optional comma-separated sub_institute_ids. Empty = every organisation that
    // has at least one active department.
    'tenants' => array_values(array_filter(array_map('trim', explode(',', (string) env('SIGNALS_TENANTS', ''))))),

    // Queue the Signals jobs run on. A dedicated queue keeps a `queue:work --queue=signals`
    // worker from executing unrelated jobs waiting on the default queue.
    'queue' => env('SIGNALS_QUEUE', 'signals'),

    // The AI module the call is metered and resolved under.
    // Signals has its own AI module. An organisation that configured `analytics_ai` before
    // that existed keeps it until a `signals` configuration is saved (see SignalsAiModule).
    'ai_module' => env('SIGNALS_AI_MODULE', 'signals'),
    'ai_module_legacy' => 'analytics_ai',
    'max_output_tokens' => (int) env('SIGNALS_MAX_OUTPUT_TOKENS', 4096),
    'temperature' => (float) env('SIGNALS_TEMPERATURE', 0.2),
    // Gemini thinking-token budget for signal JSON calls. 0 turns thinking off so the output
    // budget is not used up before the JSON is written; null leaves the model default.
    'thinking_budget' => env('SIGNALS_THINKING_BUDGET', 0),
    'ai_attempts' => (int) env('SIGNALS_AI_ATTEMPTS', 2),

    // Caps that bound cost and prompt size.
    'max_signals_per_run' => (int) env('SIGNALS_MAX_PER_RUN', 8),
    'max_departments_in_prompt' => (int) env('SIGNALS_MAX_DEPARTMENTS', 40),

    // A signal with the same department + type + title inside this window is a
    // repeat and is not stored again (dismissed ones included).
    'dedupe_window_days' => (int) env('SIGNALS_DEDUPE_DAYS', 14),

    // Minimum minutes between two manual runs for one organisation.
    'manual_cooldown_minutes' => (int) env('SIGNALS_MANUAL_COOLDOWN_MINUTES', 5),

    // A run row still "running" after this long is treated as dead.
    'stale_run_minutes' => (int) env('SIGNALS_STALE_RUN_MINUTES', 15),

    'types' => [
        'risk' => 'Risk',
        'opportunity' => 'Opportunity',
        'requirement' => 'Requirement',
        'compliance' => 'Compliance',
        'capacity' => 'Capacity',
        'structure' => 'Structure',
        'data_quality' => 'Data Quality',
    ],

    // External research is optional and OFF until a provider is configured.
    // Only drivers registered in ResearchProviderFactory can be selected.
    'research' => [
        'enabled' => (bool) env('SIGNALS_RESEARCH_ENABLED', false),
        'driver' => env('SIGNALS_RESEARCH_DRIVER', 'none'),
    ],
    /*
    |--------------------------------------------------------------------------
    | Company opportunity research (daily) — web search provider
    |--------------------------------------------------------------------------
    |
    | An LLM has no live web access. Research needs a real search API. Supported
    | drivers: `tavily`, `brave`. `none` (the default) means research is unavailable
    | and the UI says so; nothing is ever fabricated in its place.
    | Keys are read from the backend environment only.
    */
    'web_search' => [
        'driver' => env('SIGNALS_SEARCH_DRIVER', 'none'),
        'tavily_key' => env('TAVILY_API_KEY'),
        'brave_key' => env('BRAVE_SEARCH_API_KEY'),
        'timeout' => (int) env('SIGNALS_SEARCH_TIMEOUT', 20),
    ],

    'opportunities' => [
        // Default run time (HH:MM in `timezone`); a product profile may override it.
        'default_schedule_time' => env('SIGNALS_RESEARCH_TIME', '10:00'),
        'max_queries' => (int) env('SIGNALS_RESEARCH_MAX_QUERIES', 6),
        'results_per_query' => (int) env('SIGNALS_RESEARCH_RESULTS_PER_QUERY', 6),
        'max_sources' => (int) env('SIGNALS_RESEARCH_MAX_SOURCES', 30),
        'fetch_pages' => (int) env('SIGNALS_RESEARCH_FETCH_PAGES', 8),
        'max_opportunities' => (int) env('SIGNALS_RESEARCH_MAX_OPPORTUNITIES', 15),
        'default_recency_days' => 30,
        'manual_cooldown_minutes' => (int) env('SIGNALS_RESEARCH_COOLDOWN_MINUTES', 10),
        // How each finding is classified for the reader (separate from WHAT happened, below).
        'signal_kinds' => [
            'requirement' => 'Requirement',
            'opportunity' => 'Opportunity',
            'risk' => 'Risk',
            'gap' => 'Gap',
            'recommendation' => 'Recommendation',
            'insight' => 'Insight',
        ],
        'categories' => [
            'expansion' => 'Expansion',
            'hiring' => 'Hiring',
            'technology_change' => 'Technology change',
            'funding' => 'Funding',
            'regulatory' => 'Regulatory / compliance',
            'project' => 'New project / initiative',
            'leadership_change' => 'Leadership change',
            'pain_point' => 'Stated pain point',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Manual ingestion
    |--------------------------------------------------------------------------
    */
    'ingestion' => [
        'disk' => env('SIGNALS_INGESTION_DISK', 'local'),   // must be a PRIVATE disk
        'max_upload_kb' => (int) env('SIGNALS_INGESTION_MAX_KB', 15360),
        'max_chars' => (int) env('SIGNALS_INGESTION_MAX_CHARS', 300000),
        'chunk_chars' => (int) env('SIGNALS_INGESTION_CHUNK_CHARS', 30000),
        'max_chunks' => (int) env('SIGNALS_INGESTION_MAX_CHUNKS', 4),
        'url_max_bytes' => (int) env('SIGNALS_INGESTION_URL_MAX_BYTES', 2097152),
        'url_timeout' => (int) env('SIGNALS_INGESTION_URL_TIMEOUT', 12),
        'user_agent' => env('SIGNALS_FETCH_USER_AGENT', 'G2G-Signals-Bot/1.0'),
    ],
];
