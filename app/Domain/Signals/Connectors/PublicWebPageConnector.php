<?php

namespace App\Domain\Signals\Connectors;

use App\Domain\Signals\Ingestion\DataConnector;
use App\Domain\Signals\Ingestion\IngestionService;
use App\Domain\Signals\Ingestion\IngestionSource;

final class PublicWebPageConnector implements DataConnectorAdapter
{
    public function __construct(private readonly IngestionService $ingestion)
    {
    }

    public function key(): string
    {
        return 'public_web_page';
    }

    public function label(): string
    {
        return 'Public website or article';
    }

    public function sync(DataConnector $connector, ?int $userId): IngestionSource
    {
        return $this->ingestion->ingestUrl(
            (int) $connector->sub_institute_id,
            $userId,
            (string) $connector->url,
        );
    }
}
