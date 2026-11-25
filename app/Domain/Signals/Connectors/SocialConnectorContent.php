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
        /** own_posts = written by the connected account itself; comments = other people on it; discovery = other accounts found by search. */
        public string $origin = 'own_posts',
    ) {
    }
}
