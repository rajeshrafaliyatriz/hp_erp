<?php

namespace App\Domain\Signals\Products;

use App\Domain\Signals\Icp\IcpPack;
use App\Domain\Signals\Opportunities\ProductProfile;

/**
 * Everything a research run knows about the ONE product it is for: its ICP, its profile as the prompts read it, and the parts
 * (capabilities) a signal can be built on. Nothing here is shared with another product.
 */
final class ProductContext
{
    /**
     * @param  array<string, array{offer_id: string, name: string, parent_product: string, what_it_does: string}>  $parts
     * @param  string  $icpSource  'database' (the organisation's active ICP) or 'template' (the built-in definition, until it has its own)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $businessFit,
        public readonly IcpPack $pack,
        public readonly ProductProfile $profile,
        public readonly array $parts,
        public readonly string $icpSource,
    ) {
    }

    /** The school-specific stages (sales pull, school screening) apply only to the product whose ICP is for schools. */
    public function isScholar(): bool
    {
        return $this->businessFit === 'scholar';
    }

    /** @return list<string> */
    public function buyerTypes(): array
    {
        return array_values(array_map('strval', (array) ($this->pack->config()['buyer_types'] ?? [])));
    }
}
