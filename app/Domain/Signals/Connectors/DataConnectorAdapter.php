<?php

namespace App\Domain\Signals\Connectors;

use App\Domain\Signals\Ingestion\IngestionSource;
use App\Domain\Signals\Ingestion\DataConnector;

interface DataConnectorAdapter
{
    public function key(): string;

    public function label(): string;

    public function sync(DataConnector $connector, ?int $userId): IngestionSource;
}
