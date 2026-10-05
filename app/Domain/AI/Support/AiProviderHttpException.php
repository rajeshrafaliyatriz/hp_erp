<?php

namespace App\Domain\AI\Support;

use RuntimeException;

/**
 * A provider answered with an error status. Still a RuntimeException with the same
 * message as before (`AiFailureClassifier` and `AiController::handle()` read that), but
 * it also carries the structured parts failover needs, so nothing has to re-parse text.
 */
class AiProviderHttpException extends RuntimeException
{
    /**
     * @param  list<string>  $quotaIds  Google QuotaFailure violation ids, e.g. "...PerDay...".
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly ?string $providerStatus = null,
        public readonly ?string $reason = null,
        public readonly array $quotaIds = [],
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $providerMessage = null,
    ) {
        parent::__construct($message, $httpStatus);
    }
}
