<?php

namespace App\Domain\Signals\Support;

/** A region selection that cannot be used, with every reason. */
final class RegionInvalid extends \RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('The selected region is not valid: ' . implode('; ', $problems));
    }
}
