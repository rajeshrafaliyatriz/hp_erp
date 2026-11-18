<?php

namespace App\Domain\Signals\Connectors;

final readonly class SocialConnectorContent
{
    public function __construct(
        public string $externalId,
        public string $url,
        public string $title,
        public string $text,
        public ?string $publishedAt,
    ) {
    }
}
