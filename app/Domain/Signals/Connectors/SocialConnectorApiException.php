<?php

namespace App\Domain\Signals\Connectors;

use RuntimeException;

final class SocialConnectorApiException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $connectionStatus,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct(match ($connectionStatus) {
            'token_expired' => 'The platform access token expired. Reconnect this account.',
            'permission_required' => 'The platform denied access. Check the connector permissions and account access.',
            'rate_limited' => 'The platform rate limit was reached. The sync will be retried.',
            default => 'The platform API returned an error. Please retry.',
        });
    }
}
