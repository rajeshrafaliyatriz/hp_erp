<?php

namespace App\Domain\AI\Support;

use RuntimeException;

/**
 * Every credential configured for a provider is cooling down or just failed on quota.
 * The message is safe to show to users; it carries no provider text and no key.
 * Retrying immediately cannot help, so callers treat it like a quota refusal.
 */
class AiCredentialsExhaustedException extends RuntimeException
{
    public static function forProvider(string $providerLabel): self
    {
        return new self(sprintf(
            'All configured %s credentials are temporarily unavailable. Please try again later.',
            $providerLabel
        ));
    }
}
