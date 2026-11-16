<?php

namespace App\Domain\Signals\Products;

/**
 * The Region could not be saved to the sub-product's ICP, so nothing was changed (see SubProductIcpRegionSync). The message is safe to show.
 */
final class RegionSyncRejected extends \RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly string $codeName, string $message, public readonly array $problems = [])
    {
        parent::__construct($message);
    }
}
