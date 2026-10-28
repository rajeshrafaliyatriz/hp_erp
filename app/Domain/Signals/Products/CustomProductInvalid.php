<?php

namespace App\Domain\Signals\Products;

/** A new product that cannot be created, with every reason. */
final class CustomProductInvalid extends \RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems, string $message = 'This product cannot be created')
    {
        parent::__construct($message);
    }
}
