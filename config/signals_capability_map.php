<?php

/*
|--------------------------------------------------------------------------
| ScholarClone capability -> stored product offer mapping (NOT YET DEFINED)
|--------------------------------------------------------------------------
|
| ProductSignalRules names five capabilities (core, learn, ccb, insight, compass). The product gate works from the organisation's own stored product
| offers (g2g_product_offers). Nothing in the repository says which stored offer is which capability, and it must not be guessed.
|
| Fill `map` only from an authorised export of the product catalogue, as  capability => [offer_id, ...]  using the ids exactly as stored, and set
| `validated` to true once a person has checked it. Until then ProductSignalRules stays an annotation (it never qualifies or rejects a candidate) and
| App\Domain\Signals\Products\CapabilityMapping reports every capability as unmapped.
*/

return [
    'validated' => false,
    'map' => [
        // 'core' => [],
        // 'learn' => [],
        // 'ccb' => [],
        // 'insight' => [],
        // 'compass' => [],
    ],
];
