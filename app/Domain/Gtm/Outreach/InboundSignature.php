<?php

namespace App\Domain\Gtm\Outreach;

/**
 * Authenticates an inbound reply/bounce webhook. Pure.
 *
 * The sender signs `"{timestamp}.{raw body}"` with a shared secret (HMAC-SHA256) and sends
 * `X-GTM-Signature: t=<unix seconds>,v1=<hex>`. Verification needs the RAW body (not a re-encoded
 * copy), compares in constant time, rejects timestamps outside a tolerance window (so a captured
 * request cannot be replayed later), and accepts several `v1=` values so a secret can be rotated
 * without downtime. With no secret configured nothing is ever accepted.
 */
final class InboundSignature
{
    public const TOLERANCE_SECONDS = 300;

    /** @return array{ok: bool, reason: ?string} */
    public static function verify(string $rawBody, ?string $header, string $secret, ?int $now = null, int $tolerance = self::TOLERANCE_SECONDS): array
    {
        if ($secret === '') {
            return ['ok' => false, 'reason' => 'not_configured'];
        }
        if ($header === null || trim($header) === '') {
            return ['ok' => false, 'reason' => 'missing_signature'];
        }
        $timestamp = null;
        $candidates = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't' && ctype_digit($v)) {
                $timestamp = (int) $v;
            } elseif ($k === 'v1' && $v !== '') {
                $candidates[] = $v;
            }
        }
        if ($timestamp === null || $candidates === []) {
            return ['ok' => false, 'reason' => 'malformed_signature'];
        }
        if (abs(($now ?? time()) - $timestamp) > $tolerance) {
            return ['ok' => false, 'reason' => 'stale_timestamp'];
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        foreach ($candidates as $c) {
            if (hash_equals($expected, $c)) {
                return ['ok' => true, 'reason' => null];
            }
        }

        return ['ok' => false, 'reason' => 'bad_signature'];
    }

    /** What a sender computes - used by tests and by anyone writing the forwarding side. */
    public static function sign(string $rawBody, string $secret, ?int $timestamp = null): string
    {
        $t = $timestamp ?? time();

        return 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$rawBody, $secret);
    }
}
