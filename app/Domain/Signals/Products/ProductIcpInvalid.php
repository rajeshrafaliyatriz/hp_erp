<?php

namespace App\Domain\Signals\Products;

/** An ICP cannot be saved or activated: carries the exact problems, one per line, for the screen to show. */
final class ProductIcpInvalid extends \RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems, string $headline = 'The ICP is not valid')
    {
        parent::__construct($headline . ': ' . implode('; ', $problems) . '.');
    }
}
