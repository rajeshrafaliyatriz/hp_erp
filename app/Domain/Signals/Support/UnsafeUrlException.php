<?php

namespace App\Domain\Signals\Support;

/**
 * A URL or page that must not be fetched, or could not be. The message is safe to show to the user.
 *
 * `category` says WHY in a fixed vocabulary (see FetchFailure), so a run can count failures by kind without reading or storing a message.
 * `httpStatus` is set only for category `http_status`. Existing callers that pass only a message keep working (category `other`).
 */
class UnsafeUrlException extends \RuntimeException
{
    public function __construct(string $message = '', public readonly string $category = 'other', public readonly ?int $httpStatus = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
