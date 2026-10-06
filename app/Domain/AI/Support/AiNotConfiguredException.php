<?php

namespace App\Domain\AI\Support;

use RuntimeException;

/**
 * A model call could not be made because nothing is configured to make it with.
 *
 * Its own class, rather than a generic RuntimeException, because it is the one
 * failure here that is not a fault: an organisation that has not yet saved a
 * credential is in a normal state, and the right response is to say which screen
 * fixes it rather than to report an error.
 *
 * `AiController::handle()` turns a RuntimeException into a 422 with its message
 * shown to the caller, which is exactly the treatment this needs — so the message
 * is written for an administrator to read, not for a log.
 */
class AiNotConfiguredException extends RuntimeException
{
    public static function forModule(string $moduleLabel, string $providerLabel): self
    {
        return new self(sprintf(
            '%s has no usable credential. It resolves to %s, but no API key is saved for '
            . 'that provider and none is set in the environment. Add one under '
            . 'AI & Intelligence → AI Providers.',
            $moduleLabel,
            $providerLabel
        ));
    }

    public static function providerNotDriveable(string $providerLabel): self
    {
        return new self(sprintf(
            '%s cannot be called from this platform. Choose a different provider under '
            . 'AI & Intelligence → AI Providers.',
            $providerLabel
        ));
    }
}
