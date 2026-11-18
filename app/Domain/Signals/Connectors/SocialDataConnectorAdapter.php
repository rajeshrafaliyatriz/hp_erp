<?php

namespace App\Domain\Signals\Connectors;

use App\Domain\Signals\Ingestion\DataConnector;

interface SocialDataConnectorAdapter
{
    public function key(): string;

    public function label(): string;

    /** @return list<SocialConnectorContent> */
    public function fetch(DataConnector $connector): array;
}
