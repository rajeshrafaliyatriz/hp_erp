<?php

namespace App\Domain\Signals\Connectors;

use App\Domain\Signals\Ingestion\DataConnector;

/**
 * What one organisation wants a connector to watch, read from the connector's own row (never from server environment variables).
 * The environment values in config/signals_connectors.php stay as the fallback for anything the organisation has not set.
 *
 * Every value is clamped here, so a bad save can never widen a sync beyond the platform's quota-safe limits.
 */
final class ConnectorMonitoring
{
    public const CONTENT_TYPES = ['own_posts', 'comments', 'discovery'];

    /**
     * @param  list<string>  $searchKeywords    public searches (YouTube) that look for buyers outside the connected account
     * @param  list<string>  $excludeKeywords   a post containing one of these is never analysed
     * @param  list<string>  $targetAccounts    other accounts to read where the platform allows it (Instagram business usernames)
     * @param  list<string>  $contentTypes      subset of CONTENT_TYPES
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly array $searchKeywords,
        public readonly array $excludeKeywords,
        public readonly array $targetAccounts,
        public readonly int $maxItems,
        public readonly int $searchLimit,
        public readonly int $commentVideos,
        public readonly array $contentTypes,
    ) {
    }

    public static function for(DataConnector $connector): self
    {
        $saved = $connector->monitoring_settings ?? null;

        return self::fromArray(is_array($saved) ? $saved : []);
    }

    /** @param array<string, mixed> $saved */
    public static function fromArray(array $saved): self
    {
        $clean = self::sanitize($saved);
        $queries = array_key_exists('search_keywords', $clean)
            ? $clean['search_keywords']
            : self::list((array) config('signals_connectors.youtube_search_queries', []), 80, 4);

        return new self(
            $clean['enabled'] ?? true,
            $queries,
            $clean['exclude_keywords'] ?? [],
            $clean['target_accounts'] ?? [],
            $clean['max_items'] ?? max(1, min(200, (int) config('signals_connectors.max_items', 60))),
            $clean['search_limit'] ?? 10,
            $clean['comment_videos'] ?? max(0, min(25, (int) config('signals_connectors.youtube_comment_videos', 8))),
            $clean['content_types'] ?? self::CONTENT_TYPES,
        );
    }

    /**
     * Only known keys, every value type-checked and bounded. Keys that were not sent are left out, so "not set" stays distinct from "empty".
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input): array
    {
        $out = [];
        if (array_key_exists('enabled', $input)) {
            $out['enabled'] = filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('search_keywords', $input)) {
            $out['search_keywords'] = self::list((array) $input['search_keywords'], 80, 8);
        }
        if (array_key_exists('exclude_keywords', $input)) {
            $out['exclude_keywords'] = self::list((array) $input['exclude_keywords'], 60, 30);
        }
        if (array_key_exists('target_accounts', $input)) {
            $accounts = [];
            foreach ((array) $input['target_accounts'] as $handle) {
                $handle = ltrim(trim((string) $handle), '@');
                if (preg_match('/^[A-Za-z0-9._]{1,30}$/', $handle) === 1) {
                    $accounts[strtolower($handle)] = $handle;
                }
            }
            $out['target_accounts'] = array_slice(array_values($accounts), 0, 10);
        }
        foreach (['max_items' => [1, 200], 'search_limit' => [1, 25], 'comment_videos' => [0, 25]] as $key => [$min, $max]) {
            if (array_key_exists($key, $input) && is_numeric($input[$key])) {
                $out[$key] = max($min, min($max, (int) $input[$key]));
            }
        }
        if (array_key_exists('content_types', $input)) {
            $out['content_types'] = array_values(array_intersect(self::CONTENT_TYPES, array_map('strval', (array) $input['content_types'])));
        }

        return $out;
    }

    public function allows(string $contentType): bool
    {
        return in_array($contentType, $this->contentTypes, true);
    }

    /** True when the text contains any of the organisation's exclusion keywords (case-insensitive, whole phrase). */
    public function excludes(string $text): bool
    {
        $haystack = mb_strtolower($text);
        foreach ($this->excludeKeywords as $keyword) {
            if ($keyword !== '' && str_contains($haystack, mb_strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled, 'search_keywords' => $this->searchKeywords, 'exclude_keywords' => $this->excludeKeywords,
            'target_accounts' => $this->targetAccounts, 'max_items' => $this->maxItems, 'search_limit' => $this->searchLimit,
            'comment_videos' => $this->commentVideos, 'content_types' => $this->contentTypes,
        ];
    }

    /** @return list<string> */
    private static function list(array $values, int $maxLength, int $maxCount): array
    {
        $out = [];
        foreach ($values as $value) {
            $value = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');
            if ($value !== '' && mb_strlen($value) <= $maxLength) {
                $out[mb_strtolower($value)] ??= $value;   // the first spelling wins
            }
        }

        return array_slice(array_values($out), 0, $maxCount);
    }
}
