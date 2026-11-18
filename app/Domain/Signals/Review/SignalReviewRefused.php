<?php

namespace App\Domain\Signals\Review;

/**
 * Why a decision on a signal (or a look at its history) was not carried out. Thrown by SignalReviewService, always before anything is written
 * or after the whole transaction has rolled back, and turned into a response by the controller:
 *
 *   not_found  the signal is not this organisation's (answered exactly like a missing one: 404)
 *   invalid    the request breaks a rule; `errors` is field => [messages] (422)
 *   unchanged  the decision would change nothing (409)
 *   conflict   the signal is not in the status the caller saw (409)
 *   unavailable  the history table is not on this server yet, so a strict decision cannot be kept (503)
 *
 * `current` is the latest history entry of the signal (null when it has none), so a screen can show whose decision it collided with.
 */
final class SignalReviewRefused extends \RuntimeException
{
    public const NOT_FOUND = 'not_found';

    public const INVALID = 'invalid';

    public const UNCHANGED = 'unchanged';

    public const CONFLICT = 'conflict';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>|null  $current
     */
    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $errors = [],
        public readonly ?array $current = null,
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'Opportunity not found');
    }

    /** @param array<string, list<string>> $errors */
    public static function invalid(array $errors): self
    {
        return new self(self::INVALID, 'Validation failed', $errors);
    }

    /** @param array<string, mixed>|null $current */
    public static function unchanged(string $message, ?array $current): self
    {
        return new self(self::UNCHANGED, $message, [], $current);
    }

    /** @param array<string, mixed>|null $current */
    public static function conflict(string $message, ?array $current): self
    {
        return new self(self::CONFLICT, $message, [], $current);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, 'Decisions cannot be kept on this server yet: the signal history has not been set up.');
    }
}
