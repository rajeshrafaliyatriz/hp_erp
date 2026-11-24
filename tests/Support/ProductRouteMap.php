<?php

namespace Tests\Support;

/**
 * The route map of every Product door (slice 15, the audit's guard H2: Docs/agentic-crm/_evidence/s15/products-parity-audit-1-fields-endpoints-2026-11-13.md, lines 391 to 401;
 * built by track WPA2-H2 on 23 Nov 2026). Test-only data: the plan's status is not application behaviour, so nothing here is read by production code.
 *
 * ONE constant, keyed by "METHOD uri" (the router's own spelling, no leading slash), for every route under
 *     api/crm/products            the Sell catalogue (16)
 *     api/product-master          the unified record's API (17; tests/Feature/ProductMasterRouteMapTest.php holds THEIR rights, this map does not repeat that table)
 *                                 including the old pair endpoints, `matches` and `matches/decide`
 *     api/portfolio/offers        the Portfolio's offers, a Sub-product's About (7)
 *     api/portfolio/import        the workbook import of the Portfolio (1)
 *     api/signals/icp/products    an organisation's own Products (4)
 * tests/Feature/ProductRouteMapScanTest.php reads the router and this map and fails when they disagree.
 *
 * Each entry:
 *   home       kept       the route stays as it is and nothing replaces it
 *              delegated  the route keeps its address; its rules, field map or mirror come from the shared code (CatalogueFields, ProductLines, ProductMasterSync), so it cannot
 *                         drift from the one-record writer
 *              replaced   the unified API replaces it for the screens; the old route stays reachable until the old screens go (WPD). Needs `replacedBy` and `status`
 *              redirected a client-side redirect carries the old address (no server route uses it today)
 *              removed    the route is to be deleted
 *   handler    ControllerShortName@method, as the router reports it
 *   step       the plan step that decided or built this (STEPS below; section 5 of Docs/agentic-crm/slices/S15-product-portfolio-merge.md)
 *   why        30 characters or more, a sentence a reviewer can disagree with
 *   tests      files under tests/ that name the route's path or its handler (the scan reads them, so "tested by" cannot rot). This map and its scan never count
 *   replacedBy "METHOD uri" of the route that replaces it (home = replaced only)
 *   status     live (the replacing route exists) or planned (it does not exist yet); a `planned` route that already exists is a stale plan and fails (home = replaced only)
 *   debt       optional: what is still not proved. The scan prints it; the handover test (PARITY_FINAL=1) fails while any entry has one or is `planned`
 */
final class ProductRouteMap
{
    public const HOMES = ['kept', 'delegated', 'replaced', 'redirected', 'removed'];

    public const STATUSES = ['live', 'planned'];

    /** The plan's steps (section 5 of the slice file): the backend (P2.x), the unified screens (P3.x, with 4.1), the later clean-ups (P4.x) and the hand-over (P5). */
    public const STEPS = [
        'P2.1', 'P2.2', 'P2.3', 'P2.4', 'P2.5', 'P2.6', 'P2.7',
        'P3.1', 'P3.2', 'P3.3', 'P3.4', 'P3.5', 'P3.6', 'P3.7', 'P3.8',
        'P4.1', 'P4.2', 'P4.3', 'P4.4', 'P5',
        // the follow-up of 24 Nov 2026 (delete, move and the all-products picker: Docs/agentic-crm/slices/S15-ISOLATION-DELETE-MOVE-PICKER-CONTRACT.md)
        'FU',
    ];

    /** A route is in the map's scope when its URI is one of these or starts with one of these followed by a slash. */
    public const PREFIXES = ['api/crm/products', 'api/product-master', 'api/portfolio/offers', 'api/portfolio/import', 'api/signals/icp/products'];

    /** The file that holds the rights of the Product Master's routes; the entries of that family name it as one of their tests. */
    private const MASTER_RIGHTS = 'Feature/ProductMasterRouteMapTest.php';

    /** The one smoke file of the nine routes that no test called before (audit C). */
    public const SMOKE = 'Feature/CrmProductRouteSmokeTest.php';

    /** @return array<string, array{home: string, handler: string, step: string, why: string, tests: list<string>, replacedBy?: string, status?: string, debt?: string}> */
    public static function map(): array
    {
        $rm = self::MASTER_RIGHTS;
        $smoke = self::SMOKE;

        return [
            // ---- api/crm/products: the Sell catalogue ----
            'GET api/crm/products' => [
                'home' => 'replaced', 'handler' => 'CrmProductController@index', 'step' => 'P3.3', 'replacedBy' => 'GET api/product-master/list', 'status' => 'live',
                'why' => 'The unified Product Portfolio list pages /api/product-master/list; this flat Sell list stays only for the old Products and Services screens and the old pickers until WPD removes them.',
                'tests' => ['Feature/CrmFullLifecycleGoldenPathTest.php', 'Feature/ProductMasterSyncTest.php'],
            ],
            'POST api/crm/products' => [
                'home' => 'delegated', 'handler' => 'CrmProductController@store', 'step' => 'P2.4',
                'why' => 'Keeps its address for the old form and the golden path; its rules, field map and product number come from CatalogueFields and every create is mirrored by ProductMasterSync, so it cannot drift from POST /api/product-master.',
                'tests' => ['Feature/CrmProductFormLimitsTest.php', 'Feature/CrmFullLifecycleGoldenPathTest.php'],
            ],
            'POST api/crm/products/bulk/assign' => [
                'home' => 'kept', 'handler' => 'CrmProductController@bulkAssign', 'step' => 'P3.3',
                'why' => 'The unified list calls it for its Assign owner action (services/product-master/portfolio-list-service.ts, read in source); the Sell store has no other door for a bulk owner change.',
                'tests' => [$smoke],
            ],
            'POST api/crm/products/bulk/delete' => [
                'home' => 'kept', 'handler' => 'CrmProductController@bulkDelete', 'step' => 'P3.3',
                'why' => 'The unified list calls it to move items to the Recycle Bin (a soft delete); the master rows follow through ProductMasterSync, and the permanent delete is guarded in the Recycle Bin, not here.',
                'tests' => ['Feature/ProductMasterSyncTest.php'],
            ],
            'GET api/crm/products/duplicates' => [
                'home' => 'replaced', 'handler' => 'CrmProductController@duplicates', 'step' => 'P3.6', 'replacedBy' => 'GET api/product-master/matches', 'status' => 'live',
                'why' => 'The pair review finds the same product across catalogue items AND Sub-products; this finds a same name or same SKU inside the Sell catalogue only and serves the old Find duplicates dialog until WPD.',
                'tests' => [$smoke],
            ],
            'GET api/crm/products/export' => [
                'home' => 'kept', 'handler' => 'CrmProductController@export', 'step' => 'P3.3',
                'why' => 'The CSV file of the Sell catalogue (one item type, rows the caller may view) has no twin in the Product Master, which has no export route; kept as it is.',
                'tests' => [$smoke],
            ],
            'POST api/crm/products/import' => [
                'home' => 'delegated', 'handler' => 'CrmProductController@import', 'step' => 'P2.3',
                'why' => 'The unified list reuses this endpoint for its CSV import (lib/portfolio-list/import-template.ts, read in source); each row is judged by CatalogueFields and the created items are mirrored by ProductMasterSync.',
                'tests' => ['Feature/CrmProductFormLimitsTest.php', 'Feature/ProductWritePathDefectsTest.php'],
            ],
            'POST api/crm/products/merge' => [
                'home' => 'replaced', 'handler' => 'CrmProductController@merge', 'step' => 'P3.6', 'replacedBy' => 'POST api/product-master/matches/decide', 'status' => 'live',
                'why' => 'The pair review makes two records one through ProductMerger (decision "same"); this catalogue-only merge stays for the old Find duplicates dialog until WPD and repoints through ProductRepointer too.',
                'tests' => ['Feature/ProductMergeRepointTest.php', 'Feature/ProductMasterSyncTest.php'],
            ],
            'GET api/crm/products/{id}' => [
                'home' => 'replaced', 'handler' => 'CrmProductController@show', 'step' => 'P3.7', 'replacedBy' => 'GET api/product-master/by-crm/{crmId}', 'status' => 'live',
                'why' => 'A catalogue id opens the unified record through /api/product-master/by-crm/{crmId}; this single-item read stays for the old edit form and goes with it when WPD removes the old screens.',
                'tests' => ['Feature/CrmProductProductMasterLinkTest.php', 'Feature/ProductWritePathDefectsTest.php'],
            ],
            'PUT api/crm/products/{id}' => [
                'home' => 'delegated', 'handler' => 'CrmProductController@update', 'step' => 'P2.4',
                'why' => 'Keeps its address for the old edit form; the field rules come from CatalogueFields and the change is mirrored into the master row by ProductMasterSync, so it cannot drift from PUT /api/product-master/{id}.',
                'tests' => ['Feature/CrmProductFormLimitsTest.php', 'Feature/ProductMirrorTest.php'],
            ],
            'DELETE api/crm/products/{id}' => [
                'home' => 'delegated', 'handler' => 'CrmProductController@destroy', 'step' => 'P2.3',
                'why' => 'A soft delete into the Recycle Bin; the master row follows through ProductMasterSync, and the permanent delete that could orphan a pointer is refused in the Recycle Bin (CrmRecycleBinProductGuardTest).',
                'tests' => ['Feature/ProductMirrorTest.php', 'Feature/CrmRecycleBinProductGuardTest.php'],
            ],
            'GET api/crm/products/{id}/bundle-items' => [
                'home' => 'kept', 'handler' => 'CrmProductController@bundleItems', 'step' => 'P2.4',
                'why' => 'A side call of the Sell section: the components of a bundle (one level, Products only) live in crm_product_bundle_items, which the one-record writer does not write (plan 2.4).',
                'tests' => [$smoke],
            ],
            'POST api/crm/products/{id}/bundle-items' => [
                'home' => 'kept', 'handler' => 'CrmProductController@addBundleItem', 'step' => 'P2.4',
                'why' => 'A side call of the Sell section: adding a component to a bundle (a Product, never itself, never a bundle inside a bundle) is judged here, not by the one-record writer (plan 2.4).',
                'tests' => [$smoke],
            ],
            'DELETE api/crm/products/{id}/bundle-items/{rowId}' => [
                'home' => 'kept', 'handler' => 'CrmProductController@removeBundleItem', 'step' => 'P2.4',
                'why' => 'A side call of the Sell section: removing a bundle component by its row id, only from a parent of this organisation and only for a caller who may edit its type (plan 2.4).',
                'tests' => [$smoke],
            ],
            'GET api/crm/products/{id}/history' => [
                'home' => 'replaced', 'handler' => 'CrmProductController@history', 'step' => 'P2.5', 'replacedBy' => 'GET api/product-master/{id}/history', 'status' => 'live',
                'why' => 'The unified record\'s History tab (/api/product-master/{id}/history) carries the About and Product lines as well; this catalogue-only history stays for the old edit screen until WPD.',
                'tests' => ['Feature/ProductWritePathDefectsTest.php'],
            ],
            'POST api/crm/products/{id}/image' => [
                'home' => 'kept', 'handler' => 'CrmProductController@uploadImage', 'step' => 'P2.4',
                'why' => 'A side call of the Sell section: the picture goes to storage and its file name to crm_products.image, which the one-record writer takes as a name only (plan 2.4).',
                'tests' => [$smoke],
            ],

            // ---- api/product-master: the unified record's API (their rights are pinned in ProductMasterRouteMapTest) ----
            'GET api/product-master' => [
                'home' => 'kept', 'handler' => 'ProductMasterController@index', 'step' => 'P2.5',
                'why' => 'The destination of the merge: the organisation\'s Products with their Sub-products nested, then the catalogue-only items; the rights and the held-back Sell ids are pinned in ProductMasterRouteMapTest.',
                'tests' => [$rm, 'Feature/ProductMasterApiTest.php'],
            ],
            'GET api/product-master/list' => [
                'home' => 'kept', 'handler' => 'ProductMasterController@list', 'step' => 'P2.5',
                'why' => 'The unified paged list (the one screen that replaces the old Products, Services and Portfolio lists); Sell columns only for a caller who may view the row\'s type (ProductListApiTest).',
                'tests' => [$rm, 'Feature/ProductListApiTest.php'],
            ],
            'GET api/product-master/lines' => [
                'home' => 'kept', 'handler' => 'ProductPickerController@lines', 'step' => 'P2.5',
                'why' => 'The active Products (level 1) a Sub-product can be put under, for pickers, and with ?withSubProducts=1 each Product followed by its Sub-products; nothing priced is in the answer, so a signed-in user of the organisation may read it.',
                'tests' => [$rm, 'Feature/ProductReadApiTest.php', 'Feature/ProductPickerScopeTest.php'],
            ],
            'GET api/product-master/options' => [
                'home' => 'kept', 'handler' => 'ProductMasterController@options', 'step' => 'P2.5',
                'why' => 'A flat list for pickers: every active Product, Sub-product and item as id, key, name, kind, parent and label; nothing priced, so it asks only for a token.',
                'tests' => [$rm, 'Feature/ProductMasterApiTest.php'],
            ],
            'GET api/product-master/meta' => [
                'home' => 'kept', 'handler' => 'ProductMasterController@meta', 'step' => 'P2.5',
                'why' => 'What the caller may do (the can flags) and the tax rates for someone who can write the catalogue; the flags carry no data.',
                'tests' => [$rm, 'Feature/ProductMasterApiTest.php'],
            ],
            'GET api/product-master/picker' => [
                'home' => 'kept', 'handler' => 'ProductPickerController@picker', 'step' => 'P3.2',
                'why' => 'The one product picker the CRM forms move to (plan 3.2: quotes, orders, invoices and the rest); 403 unless the caller may view the Sell section of every type asked for, because it lists prices; ?scope=all offers every active Sub-product, sold or not, with the Sell fields held back from a caller who may not view them.',
                'tests' => [$rm, 'Feature/ProductReadApiTest.php', 'Feature/ProductPickerScopeTest.php'],
            ],
            'GET api/product-master/by-crm/{crmId}' => [
                'home' => 'kept', 'handler' => 'ProductMasterController@byCrm', 'step' => 'P3.7',
                'why' => 'Opens the unified record from an old catalogue id, so old addresses and CRM links keep working; names the record a merged item went into (mergedInto).',
                'tests' => [$rm, 'Feature/ProductReadApiTest.php'],
            ],
            'GET api/product-master/{id}' => [
                'home' => 'kept', 'handler' => 'ProductMasterController@show', 'step' => 'P2.5',
                'why' => 'One unified record: the Sell side and the drawer\'s crmProduct are filled only for a caller who may view the record\'s type, null otherwise.',
                'tests' => [$rm, 'Feature/ProductReadApiTest.php'],
            ],
            'GET api/product-master/{id}/sell' => [
                'home' => 'kept', 'handler' => 'ProductMasterController@sell', 'step' => 'P2.5',
                'why' => 'The Sell section of one record (prices, stock, commission); 403 unless the caller may view the Sell section of the record\'s type.',
                'tests' => [$rm, 'Feature/ProductReadApiTest.php'],
            ],
            'GET api/product-master/{id}/history' => [
                'home' => 'kept', 'handler' => 'ProductMasterController@history', 'step' => 'P2.5',
                'why' => 'The record\'s History tab across About, Product and Sell; no line of the Sell store, and none that touches a price field, for a caller who may not view Sell.',
                'tests' => [$rm, 'Feature/ProductHistoryApiTest.php'],
            ],
            'GET api/product-master/{id}/usage' => [
                'home' => 'kept', 'handler' => 'ProductMasterController@usage', 'step' => 'P2.5',
                'why' => 'Where a record is used (quotes, orders, tickets and the other pointers); 403 unless the caller may view the Sell section of the record\'s type.',
                'tests' => [$rm, 'Feature/ProductUsageApiTest.php'],
            ],
            'POST api/product-master' => [
                'home' => 'kept', 'handler' => 'ProductMasterController@store', 'step' => 'P2.4',
                'why' => 'The one door that writes About, ICP and Sell together (ProductWriter); each section the caller has no right on is refused with a 403 that names it, and nothing is written.',
                'tests' => [$rm, 'Feature/ProductMasterWriteApiTest.php'],
            ],
            'PUT api/product-master/{id}' => [
                'home' => 'kept', 'handler' => 'ProductMasterController@update', 'step' => 'P2.4',
                'why' => 'The one door for changing a record (ProductWriter): each section is judged by its own right, the mirrors follow in one transaction, and every change is audited.',
                'tests' => [$rm, 'Feature/ProductMasterWriteApiTest.php'],
            ],
            'POST api/product-master/{id}/restore' => [
                'home' => 'kept', 'handler' => 'ProductMasterController@restore', 'step' => 'P2.4',
                'why' => 'Asks for NO platformright middleware at the route (WPA1, D-E1): ProductWriter::setStatus judges by section, an administrator for a Product, the Portfolio right for a record with an About, the Sell right of its type for a Sell-only record.',
                'tests' => [$rm, 'Feature/ProductArchiveBySectionTest.php'],
            ],
            'POST api/product-master/{id}/archive' => [
                'home' => 'kept', 'handler' => 'ProductMasterController@archive', 'step' => 'P2.4',
                'why' => 'Asks for NO platformright middleware at the route (WPA1, D-E1): ProductWriter::setStatus judges by section, so HR who owns a Sub-product\'s About can archive it, and a Sell-only record needs the delete right of its type.',
                'tests' => [$rm, 'Feature/ProductArchiveBySectionTest.php'],
            ],
            // delete and move (the follow-up of 24 Nov 2026): the same middleware as archive, the rights judged per record inside ProductRemoval and ProductMover
            'GET api/product-master/{id}/removal' => [
                'home' => 'kept', 'handler' => 'ProductRemovalController@removal', 'step' => 'FU',
                'why' => 'The preview of a delete: what blocks it in plain sentences, the Sub-products under a Product and the Products they may go to; judged per record by the same right as the delete itself, and it writes nothing.',
                'tests' => [$rm, 'Feature/ProductRemovalApiTest.php'],
            ],
            'POST api/product-master/move' => [
                'home' => 'kept', 'handler' => 'ProductRemovalController@move', 'step' => 'FU',
                'why' => 'Moves one or several Sub-products under another Product through ProductMasterSync::mirror (the writer\'s own path), all or nothing; the edit right is judged per record and never read from the body.',
                'tests' => [$rm, 'Feature/ProductMoveApiTest.php'],
            ],
            'POST api/product-master/{id}/remove' => [
                'home' => 'kept', 'handler' => 'ProductRemovalController@remove', 'step' => 'FU',
                'why' => 'Deletes a Product, a Sub-product or an item, all or nothing in one transaction; a Product says what becomes of its Sub-products; anything in use is a 409 and the delete right is judged per record.',
                'tests' => [$rm, 'Feature/ProductRemovalApiTest.php'],
            ],
            // the old pair endpoints: the Products row's rights at the route, administrators only inside the controller
            'GET api/product-master/matches' => [
                'home' => 'kept', 'handler' => 'ProductMatchController@index', 'step' => 'P3.6',
                'why' => 'The pair review\'s suggestions (which catalogue items and Sub-products look like one product); the Products row\'s view right at the route and administrators only inside the controller.',
                'tests' => [$rm, 'Feature/ProductMatchApiTest.php'],
            ],
            'POST api/product-master/matches/decide' => [
                'home' => 'kept', 'handler' => 'ProductMatchController@decide', 'step' => 'P3.6',
                'why' => 'A person\'s answer for one pair: "same" makes two records one (ProductMerger), "different" writes only the decision row; the edit right at the route, administrators only inside.',
                'tests' => [$rm, 'Feature/ProductMatchApiTest.php'],
            ],

            // ---- api/portfolio/offers and api/portfolio/import: Portfolio-only routes (a Sub-product's About; nothing of the Sell catalogue is involved) ----
            'GET api/portfolio/offers' => [
                'home' => 'kept', 'handler' => 'PortfolioController@index', 'step' => 'P3.3',
                'why' => 'Portfolio-only: the organisation\'s offers for the Portfolio screens, the ICP offer lists and Signals; the unified list reads /api/product-master/list instead, this read stays as the offers-only view.',
                'tests' => ['Feature/ProductPortfolioIcpTest.php', 'Feature/PortfolioBundlesWithTest.php'],
            ],
            'POST api/portfolio/offers' => [
                'home' => 'delegated', 'handler' => 'PortfolioController@store', 'step' => 'P2.2',
                'why' => 'Portfolio-only create of a Sub-product\'s About; the offer is mirrored into the Product Master by ProductMasterSync::throughOffer in the same request, and POST /api/product-master is the door that writes About and Sell together.',
                'tests' => ['Feature/ProductMasterSyncTest.php', 'Feature/ProductPortfolioIcpTest.php'],
            ],
            'GET api/portfolio/offers/{id}' => [
                'home' => 'kept', 'handler' => 'PortfolioController@show', 'step' => 'P3.5',
                'why' => 'Portfolio-only read of one offer for the old Portfolio screens and the ICP editor; the Sub-product page reads the unified record (/api/product-master/{id}) instead.',
                'tests' => ['Feature/ProductPortfolioIcpTest.php', 'Feature/PortfolioBundlesWithTest.php'],
            ],
            'PUT api/portfolio/offers/{id}' => [
                'home' => 'delegated', 'handler' => 'PortfolioController@update', 'step' => 'P2.2',
                'why' => 'Portfolio-only edit of a Sub-product\'s About and its Individual ICP; the change is mirrored into the master row by ProductMasterSync::throughOffer, so the About cannot drift from the record.',
                'tests' => ['Feature/ProductMirrorTest.php', 'Feature/ProductPortfolioIcpTest.php'],
            ],
            'DELETE api/portfolio/offers/{id}' => [
                'home' => 'kept', 'handler' => 'PortfolioController@destroy', 'step' => 'P2.3',
                'why' => 'Portfolio-only delete, refused with a 409 while an ICP lists the offer, while its master row holds a crm_product_id (offer_has_crm_item) or while records point at it (D-8, ProductDeleteGuardsTest).',
                'tests' => ['Feature/ProductDeleteGuardsTest.php'],
            ],
            'POST api/portfolio/offers/{id}/readiness' => [
                'home' => 'kept', 'handler' => 'PortfolioController@confirmReadiness', 'step' => 'P2.4',
                'why' => 'Portfolio-only and administrators only, audited and never automatic; the one-record writer never writes readiness_confirmed (a system column), so this stays the only way it changes.',
                'tests' => [$smoke],
            ],
            'GET api/portfolio/offers/{id}/readiness-log' => [
                'home' => 'kept', 'handler' => 'PortfolioController@readinessLog', 'step' => 'P2.4',
                'why' => 'Portfolio-only read of who confirmed or removed a Sub-product\'s readiness and why, behind the Portfolio view right; written only by the readiness route, so it is kept as it is.',
                'tests' => [$smoke],
            ],
            'POST api/portfolio/import' => [
                'home' => 'delegated', 'handler' => 'PortfolioController@import', 'step' => 'P2.2',
                'why' => 'Portfolio-only workbook import of Sub-products; afterwards ProductMasterSync::throughOffersOfTenant mirrors every offer into the Product Master, so the import cannot leave a record without its master row.',
                'tests' => ['Feature/ProductMasterSyncTest.php'],
            ],

            // ---- api/signals/icp/products: an organisation's own Products (level 1) ----
            'POST api/signals/icp/products' => [
                'home' => 'delegated', 'handler' => 'ProductIcpController@create', 'step' => 'P2.4',
                'why' => 'Creating a Product goes through ProductLines::create, the one door that writes the custom row and the master row in one transaction (WPA1, D-5); the answers are unchanged.',
                'tests' => ['Feature/ProductOneDoorTest.php', 'Feature/CustomProductApiTest.php'],
            ],
            'PUT api/signals/icp/products/{product}' => [
                'home' => 'kept', 'handler' => 'ProductIcpController@update', 'step' => 'P2.3',
                'why' => 'Edits an organisation\'s own Product through ProductCatalog::updateProduct (administrators only); the master row follows by the write-through of ProductMasterSync.',
                'tests' => ['Feature/ProductMasterSyncTest.php', 'Feature/CustomProductApiTest.php'],
            ],
            'DELETE api/signals/icp/products/{product}' => [
                'home' => 'kept', 'handler' => 'ProductIcpController@destroy', 'step' => 'P2.3',
                'why' => 'Deletes an organisation\'s own Product; a built-in one is refused (422); the 409 product_has_sub_products of 23 Nov is gone (D-9, 24 Nov: the dialog asks what becomes of the Sub-products and calls POST /api/product-master/{id}/remove).',
                'tests' => ['Feature/ProductDeleteGuardsTest.php'],
            ],
            'GET api/signals/icp/products/{product}/dependencies' => [
                'home' => 'kept', 'handler' => 'ProductIcpController@dependencies', 'step' => 'P2.3',
                'why' => 'Read-only list of what depends on a Product, which the delete dialog shows before it asks; administrators only, nothing is written.',
                'tests' => ['Unit/MainProductManagementTest.php', self::SMOKE],
            ],
        ];
    }
}
