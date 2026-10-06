<?php

namespace App\Services\Events;

/**
 * Is the process currently REPLAYING?
 *
 * `05-data-flow-contracts.md` §6.1 safeguard 3: in replay mode, any reactor
 * dispatch THROWS. Not a no-op - a throw.
 *
 * The difference matters. A no-op would let a rebuild run to completion while
 * silently skipping every side effect, and the operator would see success. A
 * throw stops the rebuild and names the consumer that should never have been
 * reached, which is the only way a Reactor mistyped as a Projector is caught
 * before it has done something irreversible.
 *
 * Deliberately a process-level flag rather than a parameter threaded through
 * every call: a parameter can be forgotten at one call site, and that one site is
 * where the certificate gets re-issued.
 */
class ReplayMode
{
    private static bool $active = false;

    public static function enable(): void
    {
        self::$active = true;
    }

    public static function disable(): void
    {
        self::$active = false;
    }

    public static function active(): bool
    {
        return self::$active;
    }

    /**
     * Called by every reactor before it does anything external.
     *
     * @throws \RuntimeException when replaying
     */
    public static function assertNotReplaying(string $consumer): void
    {
        if (self::$active) {
            throw new \RuntimeException(
                "Reactor [$consumer] was dispatched during replay. Reactors never run on replay "
                . '(05-data-flow-contracts.md §2.0). This means a reactor is registered as a '
                . 'projector, or a projector invoked a reactor.'
            );
        }
    }
}
