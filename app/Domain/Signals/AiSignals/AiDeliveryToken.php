<?php

namespace App\Domain\Signals\AiSignals;

/**
 * The secret a scheduled task sends with each delivery: `Authorization: Bearer g2gai_<connector id>_<48 hex>`.
 *
 * Only a SHA-256 hash of it is ever stored (inside the connector's encrypted credentials), so it cannot be read back: it is shown once, when created or
 * rotated. The connector id in the token only says which connector to check; the organisation is always the connector's own, never anything the sender says.
 * Comparing is constant-time. The token is never logged.
 */
final class AiDeliveryToken
{
    private const PATTERN = '/^g2gai_(\d{1,18})_([a-f0-9]{48})$/';

    /** @return array{token: string, hash: string, hint: string} */
    public static function generate(int $connectorId): array
    {
        $token = 'g2gai_' . $connectorId . '_' . bin2hex(random_bytes(24));

        return ['token' => $token, 'hash' => hash('sha256', $token), 'hint' => '…' . substr($token, -4)];
    }

    /** The connector id a presented token claims, or null if it is not shaped like one of ours. */
    public static function connectorIdOf(?string $token): ?int
    {
        return preg_match(self::PATTERN, (string) $token, $m) === 1 ? (int) $m[1] : null;
    }

    public static function matches(?string $token, ?string $storedHash): bool
    {
        if ($token === null || $storedHash === null || $storedHash === '' || preg_match(self::PATTERN, $token) !== 1) {
            return false;
        }

        return hash_equals($storedHash, hash('sha256', $token));
    }

    /** The token from an Authorization header value, or null. */
    public static function fromHeader(?string $header): ?string
    {
        if ($header !== null && preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
