<?php

/*
 * Which organisations the built-in Products belong to (S15 follow-up, 24 Nov 2026; contract Docs/agentic-crm/slices/S15-ISOLATION-DELETE-MOVE-PICKER-CONTRACT.md, D-1 and D-2).
 *
 * A Product, a Sub-product, an item, an About and a custom Product belong to ONE organisation and are visible to that organisation only: no organisation-0 or NULL-organisation
 * product row is visible to anybody. The four Products defined in code (config/signals_products.php: ScholarClone, Enterprise Brain, G2G, Fiber Valley) belong to the organisations
 * listed here and to nobody else. Read it only through App\Domain\ProductMaster\ProductIsolation.
 *
 *   G2G_BUILTIN_PRODUCTS_TENANTS   a comma list of organisation ids (sub_institute_id), for example "6" or "6,7". Default: 6.
 *                                  An empty value means no organisation owns them (nobody sees the four built-in Products).
 */
return [
    'builtin_owner_tenants' => array_values(array_unique(array_filter(
        array_map(static fn (string $id): int => (int) trim($id), explode(',', (string) env('G2G_BUILTIN_PRODUCTS_TENANTS', '6'))),
        static fn (int $id): bool => $id > 0
    ))),
];
