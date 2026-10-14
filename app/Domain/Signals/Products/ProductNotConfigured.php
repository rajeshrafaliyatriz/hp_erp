<?php

namespace App\Domain\Signals\Products;

/** A product cannot be researched yet: says exactly what is missing, so the screen can tell a person what to configure. */
final class ProductNotConfigured extends \RuntimeException
{
    /** @param list<string> $missing */
    public function __construct(public readonly string $productKey, public readonly array $missing, public readonly string $reason = 'product_not_configured')
    {
        parent::__construct($reason === 'unknown_product'
            ? "Unknown product \"{$productKey}\"."
            : "{$productKey} is not ready for discovery: " . implode('; ', $missing) . '.');
    }
}
