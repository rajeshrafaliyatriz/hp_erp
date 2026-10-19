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
    'permissions' => [
        // Sales & Marketing unification, Phase 2: when true, Signals routes
        // are gated by the admin-configurable tblgroupwise_rights_g2g matrix
        // (via App\Support\ModuleGate::signals()) instead of the blunt
        // profile:admin,hr / profile:admin,hr,manager role gate. OFF by
        // default - flipping it needs no code deploy, only a config:cache +
        // restart. See Docs/sales-marketing-merge/00-PROGRESS.md.
        'use_menu_rights' => filter_var(env('SIGNALS_USE_MENU_RIGHTS', false), FILTER_VALIDATE_BOOLEAN),
    ],

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

    // Two descriptions of the same buyer + trigger that share at least this share of their words are the same event.
    'dedupe_similarity' => (float) env('SIGNALS_DEDUPE_SIMILARITY', 0.6),

    // Search budget for discovery (config/signals_icp.php holds the searches themselves).
    'discovery' => [
        // The model may propose this many follow-up searches from what the first round found. One round, never recursive. 0 = off.
        'followup_queries' => (int) env('SIGNALS_FOLLOWUP_QUERIES', 2),
        // A search already run for this organisation within this many hours is not paid for again. 0 = off.
        'query_cache_hours' => (int) env('SIGNALS_QUERY_CACHE_HOURS', 12),
        // Pages that cannot be about an Indian school (no education word, or foreign and nothing Indian) are not sent to the model.
        'screen_off_topic' => (bool) env('SIGNALS_SCREEN_OFF_TOPIC', true),
    ],

    // Direct reading of a target school's OWN verified website (OfficialSiteDiscovery). Bounded: never a crawl.
    'official_site' => [
        'max_pages' => (int) env('SIGNALS_OFFICIAL_MAX_PAGES', 6),           // home page + the best 5 internal pages
        'max_schools_per_run' => (int) env('SIGNALS_OFFICIAL_MAX_SCHOOLS', 3),
        'timeout' => (int) env('SIGNALS_OFFICIAL_TIMEOUT', 8),
        'delay_ms' => (int) env('SIGNALS_OFFICIAL_DELAY_MS', 300),
        'recheck_hours' => (int) env('SIGNALS_OFFICIAL_RECHECK_HOURS', 12),  // a page fetched within this many hours is not fetched again
    ],

    // Minimum minutes between two manual runs for one organisation.
    'manual_cooldown_minutes' => (int) env('SIGNALS_MANUAL_COOLDOWN_MINUTES', 5),

    // A run row still "running" after this long is treated as dead.
    // TEMPORARY demonstration aid (signals:demo-fixture import). Off unless a person switches it on for a demonstration, and off again afterwards.
    'demo_fixture' => ['enabled' => (bool) env('SIGNALS_DEMO_FIXTURE_ENABLED', false)],

    'stale_run_minutes' => (int) env('SIGNALS_STALE_RUN_MINUTES', 15),

    // A run is bounded in time, whatever the providers do. Optional steps (more searches, more pages, follow-up and rewrite AI calls) stop once the
    // run has used their share of this budget, and the run finishes as "partial" with a note, instead of hanging. The queue job's own limit (900 s)
    // is the backstop, so keep this well below it.
    'run_budget_seconds' => (int) env('SIGNALS_RUN_BUDGET_SECONDS', 600),
    // One AI request may take this long (seconds). A timed-out request is NOT retried: retrying a timeout doubles the wait. (The provider default,
    // DeepSeek's 600 s, let a single call block a run for many minutes.)
    'ai_timeout' => (int) env('SIGNALS_AI_TIMEOUT', 90),
    // A queued run that no worker has started after this many seconds is closed as failed (worker_not_running) instead of spinning forever.
    'queue_pickup_seconds' => (int) env('SIGNALS_QUEUE_PICKUP_SECONDS', 180),
    // How long the per-organisation run lock may be held if its holder dies (a killed worker never releases it). Above the 900 s job limit.
    'run_lock_seconds' => (int) env('SIGNALS_RUN_LOCK_SECONDS', 1000),

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
        'min_interval_ms' => (int) env('SIGNALS_SEARCH_MIN_INTERVAL_MS', 300),
    ],

    'opportunities' => [
        // Default run time (HH:MM in `timezone`); a product profile may override it.
        'default_schedule_time' => env('SIGNALS_RESEARCH_TIME', '10:00'),
        'max_queries' => (int) env('SIGNALS_RESEARCH_MAX_QUERIES', 8),
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

    /*
    |--------------------------------------------------------------------------
    | Market (demand-side) import
    |--------------------------------------------------------------------------
    | The structured daily-scan feed: POST /api/signals/market/import and
    | `php artisan signals:import-market`. See docs/signals-market-import-schema.md.
    */
    // Offer matching for imported signals. Readiness never changes a score.
    // Automatic daily research. OFF by default: research runs only when a person presses Generate Signals.
    'scheduled_research' => (bool) env('SIGNALS_SCHEDULED_RESEARCH', false),

    // Products the scheduled research runs for (keys from config/signals_products.php). Default: ScholarClone only.
    'products' => array_values(array_filter(array_map('trim', explode(',', (string) env('SIGNALS_PRODUCTS', 'scholarclone'))))),

    // true = skip product-offer matching for new company opportunities (default false: matching runs).
    'scholar_only' => (bool) env('SIGNALS_SCHOLAR_ONLY', false),

    'matching' => [
        'code_boost' => (int) env('SIGNALS_MATCH_CODE_BOOST', 25),       // per matching need code
        'segment_bonus' => (int) env('SIGNALS_MATCH_SEGMENT_BONUS', 15), // once, if the buyer segment matches
        'store_min_score' => (int) env('SIGNALS_MATCH_STORE_MIN_SCORE', 30), // import stores matches at or above this
    ],

    'market_import' => [
        'max_records' => (int) env('SIGNALS_MARKET_IMPORT_MAX_RECORDS', 500),
        'max_upload_kb' => (int) env('SIGNALS_MARKET_IMPORT_MAX_KB', 5120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ranked feed: the lead card's rules (one place: QualificationGate)
    |--------------------------------------------------------------------------
    | Every writer (web research, market import) passes through QualificationGate, so these
    | thresholds and word lists are enforced once. They are a starting point to tune.
    | score_total is the sum of the seven 1-5 scores (7 to 35).
    */
    // Organisations that research with the Scholar K-12 ICP pack (config/signals_icp.php) instead of the generic
    // product-profile queries. Comma-separated sub_institute_ids. Empty = nobody, so nothing changes by default.
    'icp' => [
        'scholar_k12_tenants' => array_values(array_filter(array_map('trim', explode(',', (string) env('SIGNALS_ICP_SCHOLAR_TENANTS', ''))))),
        // A repeat run on the same day takes the next slice of the search catalogue instead of repeating the same searches. Cost-neutral.
        'rotate_repeat_runs' => (bool) env('SIGNALS_ROTATE_REPEAT_RUNS', true),
        // A target school with no verified website is not searched by name (that returned social-media noise and never a signal); its search slot goes
        // to the catalogue. Set false to search every due school as before.
        'account_search_needs_website' => (bool) env('SIGNALS_ACCOUNT_SEARCH_NEEDS_WEBSITE', true),
    ],

    'card' => [
        // Section assignment (SectionAssigner). CONTEXT items name a company but no buyer
        // (a competitor move, a market-level event) and are never scored into the other two.
        'sections' => [
            'act_now_min_score' => (int) env('SIGNALS_ACT_NOW_MIN_SCORE', 22),   // with CONFIRMED or INFERENCE evidence
            'watching_min_score' => (int) env('SIGNALS_WATCHING_MIN_SCORE', 15), // below this a non-hypothesis item is not surfaced
            'act_now_evidence' => ['confirmed', 'inference'],
        ],

        // Evidence derivation (EvidenceGrader).
        'evidence' => [
            // Sources whose host ends with one of these are primary for the organisation they
            // govern or issue for. A company's own domain is primary for that company (checked
            // in code against the buyer's website / name).
            'primary_host_suffixes' => ['.gov.in', '.nic.in', '.gov', '.edu', '.ac.in', '.edu.in', '.gov.uk', '.europa.eu'],
            // Independent domains that must report the same fact before a non-primary source can
            // support INFERENCE.
            'corroboration_domains' => 2,
        ],

        // Sell lines that say nothing. Matched on the normalised line; a line is also rejected
        // when, after removing these terms and filler words, fewer than `min_specific_words` remain.
        'sell_line' => [
            'min_specific_words' => 3,
            'vague_terms' => [
                'ai', 'artificial intelligence', 'ai solution', 'ai solutions', 'ai platform', 'digital transformation',
                'our platform', 'our solution', 'our solutions', 'our product', 'our products', 'our offering', 'platform',
                'solution', 'solutions', 'product', 'products', 'technology', 'software', 'services', 'service',
                'innovation', 'transformation', 'automation', 'analytics', 'cloud', 'erp', 'hrms', 'lms', 'saas',
                'end to end', 'end-to-end', 'next generation', 'cutting edge', 'state of the art', 'world class',
                'comprehensive', 'integrated', 'scalable', 'robust', 'seamless', 'holistic', 'enterprise',
            ],
            'filler_words' => ['a', 'an', 'the', 'of', 'for', 'to', 'and', 'or', 'with', 'in', 'on', 'our', 'your', 'their', 'its', 'we', 'can', 'could', 'offer', 'provide', 'sell', 'pitch', 'propose', 'deliver', 'implement', 'deploy'],
        ],

        // Plain-English rules for the face of the card (PlainLanguageRules, enforced by the gate on
        // researched leads). The face is: what happened, what it means for us, what to do.
        'plain' => [
            'max_sentence_words' => (int) env('SIGNALS_CARD_MAX_SENTENCE_WORDS', 20),
            'so_what_min_sentences' => 2,
            'so_what_min_words' => 8,
            'max_sentences' => ['headline' => 1, 'what_happened' => 3, 'likely_problem' => 3, 'soft_marketing_angle' => 3],
            // Whole words / phrases that must not appear on the face. They may appear in the
            // collapsed detail. ("confirmed" is allowed in prose: it is only banned as a chip.)
            'banned_terms' => [
                'entry point', 'inference', 'inferred', 'hypothesis', 'qualification', 'qualified lead', 'signal', 'signals',
                'evidence level', 'pipeline', 'scoring', 'score', 'buying trigger', 'enum',
            ],
        ],

        // Urgency band stored in `urgency`: act_this_week | act_this_month | nurture.
        'urgency' => [
            'this_week_days' => 7,
            'this_month_days' => 30,
            'this_week_min_score' => 5,   // score_urgency
            'this_month_min_score' => 3,
        ],

        // entry_point = 'other' needs a stated reason; if more than this share of recent
        // items land there the entry-point list is wrong and should be revisited.
        'entry_point_other_max_share' => (float) env('SIGNALS_ENTRY_OTHER_MAX_SHARE', 0.10),
    ],
];
