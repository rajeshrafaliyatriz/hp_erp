<?php

namespace App\Domain\Signals\Support;

/**
 * The kind of a failed page fetch, from a fixed vocabulary. Pure: it reads the exception's own category, never its message, so nothing sensitive
 * (a URL, a header, a token) can end up in a statistic.
 *
 * Categories: invalid_url, blocked_url (scheme, credentials, port), blocked_address (not a public site), dns, timeout, connection,
 * http_401/403/404/429, http_4xx, http_5xx, non_html, too_many_redirects, budget (the run's page-request ceiling), other.
 * `robots_disallowed` is not produced here: a robots refusal is not an exception and is counted by the researcher on its own.
 */
final class FetchFailure
{
    public const CATEGORIES = ['invalid_url', 'blocked_url', 'blocked_address', 'dns', 'timeout', 'connection', 'http_401', 'http_403', 'http_404', 'http_429',
        'http_4xx', 'http_5xx', 'non_html', 'too_many_redirects', 'budget', 'other'];

    public static function category(\Throwable $e): string
    {
        if (! $e instanceof UnsafeUrlException) {
            return 'other';
        }
        if ($e->category === 'http_status') {
            $s = (int) $e->httpStatus;

            return in_array($s, [401, 403, 404, 429], true) ? 'http_' . $s : ($s >= 500 ? 'http_5xx' : 'http_4xx');
        }

        return in_array($e->category, self::CATEGORIES, true) ? $e->category : 'other';
    }

    /** timeout / dns / connection, from a transport error's own text. Only the text is inspected here; it is never stored. */
    public static function transport(\Throwable $e): string
    {
        $m = strtolower($e->getMessage());
        if (str_contains($m, 'timed out') || str_contains($m, 'curl error 28') || str_contains($m, 'timeout')) {
            return 'timeout';
        }
        if (str_contains($m, 'could not resolve') || str_contains($m, 'curl error 6') || str_contains($m, 'name or service not known')) {
            return 'dns';
        }

        return 'connection';
    }
}
