<?php

namespace App\Domain\Gtm\Outreach;

/**
 * Decides what a failed send means, from the transport's own error text. Pure.
 *
 *   transient     a temporary condition (4xx reply, connection trouble): retry with backoff
 *   rate_limited  the provider is limiting us: retry LATER and stop sending to that provider for now
 *   permanent     the message will never be accepted (5xx reply, e.g. a mailbox that does not exist): do not retry
 *   auth          our credentials were refused: retrying cannot help and every message will fail until an
 *                 administrator fixes the mail settings
 *
 * Retrying a permanent failure only repeats the damage (and risks the sender's reputation), so anything that
 * is not clearly temporary is NOT retried.
 */
final class SendFailure
{
    /** Minutes to wait before each retry; the fourth failure is final. */
    public const BACKOFF_MINUTES = [5, 30, 120];

    /** @return array{kind: string, code: ?string, retry_after_minutes: ?int} */
    public static function classify(string $message): array
    {
        $m = mb_strtolower($message);
        $code = null;
        if (preg_match('/but got code "(\d{3})"/', $message, $x) || preg_match('/\b([45]\d{2})[ -]\d\.\d+\.\d+/', $message, $x) || preg_match('/^\s*([45]\d{2})\b/', $message, $x)) {
            // NOT a bare \b[45]\d{2}\b anywhere: that read the port in "smtp.gmail.com:587" as a 5xx reply.
            $code = $x[1];
        }

        if (preg_match('/daily (user )?sending limit|sending limit exceeded|rate limit|rate-limit|too many (messages|connections|recipients|emails)|quota|throttl/i', $message)) {
            // a daily cap will not clear in minutes
            return ['kind' => 'rate_limited', 'code' => $code, 'retry_after_minutes' => str_contains($m, 'daily') ? 360 : 60];
        }
        if (in_array($code, ['530', '534', '535'], true) || str_contains($m, 'authentication')) {
            return ['kind' => 'auth', 'code' => $code, 'retry_after_minutes' => null];
        }
        if ($code !== null && $code[0] === '5') {
            return ['kind' => 'permanent', 'code' => $code, 'retry_after_minutes' => null];
        }
        if ($code !== null && $code[0] === '4') {
            return ['kind' => 'transient', 'code' => $code, 'retry_after_minutes' => null];
        }
        if (preg_match('/could not be established|connection refused|timed out|timeout|could not resolve|getaddrinfo|temporarily|try again|stream_socket_client|network is unreachable|connection reset/i', $message)) {
            return ['kind' => 'transient', 'code' => $code, 'retry_after_minutes' => null];
        }

        return ['kind' => 'permanent', 'code' => $code, 'retry_after_minutes' => null]; // unknown is NOT retried
    }

    /** Minutes until the next try after `$attemptsDone` failed attempts, or null when retries are exhausted. */
    public static function backoff(int $attemptsDone): ?int
    {
        return self::BACKOFF_MINUTES[$attemptsDone - 1] ?? null;
    }
}
