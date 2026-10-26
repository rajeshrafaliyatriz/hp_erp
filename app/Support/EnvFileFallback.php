<?php

namespace App\Support;

/**
 * May a provider credential be read straight from the project's main `.env` FILE when the loaded configuration has none?
 *
 * That fallback exists for ordinary local use (a placeholder in config, the real value in the file). It must never apply to a verification run:
 * the verification environment is loaded from `.env.verify`, and reading the main `.env` beside it would let a verification run use a credential
 * that was not approved for it. In `verify` the answer is always no, and a missing credential then stays missing (the provider reports itself as
 * not configured and nothing is called).
 */
final class EnvFileFallback
{
    /** @param  list<string>  $environments  every environment name the process reports (config app.env, the framework's resolved environment) */
    public static function allowed(array $environments, bool $runningUnitTests): bool
    {
        if ($runningUnitTests) {
            return false;   // unchanged: tests never read the file
        }
        foreach ($environments as $e) {
            if (strtolower(trim($e)) === 'verify') {
                return false;
            }
        }

        return true;
    }

    /** The allowed() answer for the running application. */
    public static function allowedHere(): bool
    {
        return self::allowed([(string) config('app.env'), (string) app()->environment()], app()->runningUnitTests());
    }
}
