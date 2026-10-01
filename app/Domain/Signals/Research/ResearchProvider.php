<?php

namespace App\Domain\Signals\Research;

/**
 * Optional external research for the Signals Engine.
 *
 * Implementations MUST use a legitimate search/research API - no scraping, no
 * bypassing authentication. Every result carries the URL, title and retrieval time
 * so a signal that leans on it can show where the claim came from.
 */
interface ResearchProvider
{
    public function isConfigured(): bool;

    /**
     * @param  array<string, mixed>  $context  Aggregate, non-personal context only.
     * @return array<int, array{id: string, title: string, url: string, snippet: string, retrieved_at: string}>
     */
    public function search(array $context): array;
}
