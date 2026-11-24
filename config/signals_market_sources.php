<?php

/*
 * Market-source adapters: official APIs and public feeds only. Everything defaults to OFF.
 *
 * Credentials are read from the environment by name (YOUTUBE_API_KEY, REDDIT_CLIENT_ID, REDDIT_CLIENT_SECRET, GOOGLE_CSE_KEY, GOOGLE_CSE_CX). This file
 * never contains a value, and nothing in the engine prints, logs or returns one. LinkedIn, Instagram, Facebook, X and TikTok are never read directly:
 * they appear only as lead hints from a search provider's results (see PlatformPolicy).
 */
return [
    'enabled' => (bool) env('SIGNALS_MARKET_SOURCES', false),
    'max_total_items' => 100,

    'youtube' => [
        'enabled' => (bool) env('SIGNALS_YOUTUBE', false),
        'api_key' => env('YOUTUBE_API_KEY'),
        'max_results' => 15,
        'max_requests' => 5,
    ],
    'reddit' => [
        'enabled' => (bool) env('SIGNALS_REDDIT', false),
        'client_id' => env('REDDIT_CLIENT_ID'),
        'client_secret' => env('REDDIT_CLIENT_SECRET'),
        'user_agent' => env('REDDIT_USER_AGENT', 'g2g-signals/1.0 (market research; contact via account owner)'),
        'max_results' => 15,
        'max_requests' => 5,
    ],
    'google_cse' => [
        'enabled' => (bool) env('SIGNALS_GOOGLE_CSE', false),
        'api_key' => env('GOOGLE_CSE_KEY'),
        'cx' => env('GOOGLE_CSE_CX'),
        'max_results' => 10,
        'max_requests' => 5,
    ],

    // Owners add PUBLIC RSS/Atom or government tender feed URLs here (one string each). None are shipped on purpose: no URL is guessed.
    'feeds' => [],

    // Searches per run sent to the API adapters (the first N searches of the ICP plan). Feeds are read in full.
    'queries_per_run' => 4,
];
