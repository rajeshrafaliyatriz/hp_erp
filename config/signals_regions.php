<?php

/*
|--------------------------------------------------------------------------
| Where the built-in queries of a product line are tied to a place
|--------------------------------------------------------------------------
| A request limited to a region rewrites a product's search queries (App\Domain\Signals\Icp\IcpPack::withRegionScope). Without this map the
| rewrite could only take out the ICP's own saved regions, and the city lists and Gujarati-press queries inside the built-in queries stayed in,
| so an "India" or "Kerala" run still searched Gujarat. Keyed by the ICP's `product_line`. Nothing here changes a query when no region is asked
| for, and none of it is part of a saved ICP.
|
|   terms      place words the built-in queries use (states, districts, cities); taken out of a query when a region is requested
|   only_for   a query that is written FOR one place (for example the Gujarati-press searches): kept only when the request covers that
|              place (a state or city inside it), dropped for a country-only request or another state. Queries not listed stay in.
*/
return [
    'scholar' => [
        'terms' => [
            'Gujarat', 'Maharashtra', 'Dadra and Nagar Haveli and Daman and Diu', 'Dadra', 'Daman', 'Diu',
            'Ahmedabad', 'Surat', 'Vadodara', 'Rajkot', 'Gandhinagar', 'Bhavnagar', 'Jamnagar', 'Anand', 'Mehsana', 'Vapi', 'Bharuch',
            'Mumbai', 'Pune', 'Nashik',
        ],
        'only_for' => [
            'k12-gu-news-campus' => ['Gujarat'],
            'k12-gu-news-digital' => ['Gujarat'],
        ],
    ],
];
