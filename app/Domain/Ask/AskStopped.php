<?php

namespace App\Domain\Ask;

/**
 * The question could not be answered because the model could not be used (slice 8, Ask): none set up, the quota spent, the provider down, or an answer that
 * was not a usable JSON object. It is not an error for the person: it becomes an `unavailable` answer, stored like any other, and the run is closed with
 * `runStatus` (`unavailable` when no model could be asked, `failed` when it was asked and did not deliver) and `reason`, a short stable code for the run row.
 */
final class AskStopped extends \RuntimeException
{
    public function __construct(public readonly string $runStatus, public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
