<?php

namespace App\Domain\ProductMaster;

/**
 * Every column that points at a product, in ONE reviewed list (S15 plan section 2.1, Docs/agentic-crm/slices/S15-product-portfolio-merge.md).
 *
 * Why it exists: a product is written by four code paths today and read by 21 columns in 19 tables. Before anyone changes how a product is written (the
 * one writer, the pair review that merges two records of the same product), there has to be a single list of what points at it, so a merge can move every
 * pointer and a read-only checker (ProductIntegrity, `php artisan product-master:check`) can prove that none was lost. The same idea as
 * App\Domain\OrgAccounts\OrganisationReferences, and guarded the same way: tests/Unit/ProductPointersScanTest.php reads every migration and fails when a
 * column that looks like a product pointer is neither listed here nor in notPointers() with a reason. A new table that points at a product is therefore a
 * one-line change here, and forgetting it is a red test rather than an orphan on production.
 *
 * Two families, by what the column holds:
 *
 *   toCrmProduct()  11 columns in 10 tables holding crm_products.id (the CRM catalogue row): the quote, sales order and invoice lines, the
 *                   price-book items, the opportunity products, tickets, assets, FAQs, bundle items (both ends) and campaigns.
 *   toMaster()      10 columns in 10 tables holding g2g_product_master.id: leads, opportunities, campaigns (CRM and GTM), the GTM playbooks and outreach
 *                   messages, signals, research runs and approved claims. crm_products.product_master_id is in this family and means the PRODUCT (level 1,
 *                   kind `product`), never a sub-product: `target_kind` says so and the checker enforces it.
 *
 * and, apart, the master row's own three pointers (masterOwn()): crm_product_id, offer_row_id and parent_id.
 *
 * An entry: `table`, `column`, `target` (the table the value points into), `scope` (how the checker finds the organisation a row belongs to: the name of a
 * tenant column on the table itself, or a `via` description of the parent row that carries it, as OrganisationReferences does for campaign targets),
 * `measures` (the numeric columns the census sums, to prove line counts and totals unchanged), `lines` (the census also counts the rows per product id).
 * The tenant value 0 on a target means "shared by every organisation" (the built-in products), so a pointer into a shared row never counts as crossing.
 *
 * Tables are registered lazily by the checker: a table or column that a host does not have yet (the older live schema) is skipped and reported, never an error.
 * Verified against the dev database on 13 Nov 2026 (information_schema: 23 columns match the three name patterns, 22 listed then plus the master's own crm_product_id; 21 now that the purchase order lines are gone).
 *
 * @phpstan-type Scope string|array{via: string, parent: string, parent_key: string, parent_tenant: string}
 */
final class ProductPointers
{
    public const CRM_PRODUCTS = 'crm_products';

    public const MASTER = 'g2g_product_master';

    public const OFFERS = 'g2g_product_offers';

    /**
     * A column whose name matches this looks like a pointer at a product: it ends in product_id, is product_master_id, or contains crm_product, or (slice 15, WPA1, G-E2) ends in
     * offer_row_id, the NUMBER of a Portfolio About row. The text offer id (`offer_id`: P1, SCH-01) is deliberately not matched: it is not a row number, so no merge or delete can
     * repoint it, and it appears in dozens of columns that are not pointers at all.
     */
    public const NAME_PATTERN = '/(?:product_id$|^product_master_id$|crm_product|offer_row_id$)/';

    private const TENANT = 'sub_institute_id';

    /** The columns of a document line the census sums: they prove that quantities and totals did not move. */
    private const LINE_MEASURES = ['quantity', 'line_total'];

    /**
     * Pointers kept in the registry ONLY so that a merge repoints their rows and the checker finds orphans, while no screen, no document and no person reads them any more (S16, 25 Nov 2026:
     * the Price Book is gone; its tables stay, DB-RULES item 1). They are not a USE of a catalogue item: nothing counts them, nothing is blocked by them, and a Sub-product that is deleted
     * takes its rows with it (ProductRemoval). The registry key is "table.column".
     */
    public const DORMANT = ['crm_price_book_items.product_id'];

    /** toCrmProduct() without the dormant pointers: the pointers that mean "this catalogue item is USED" (counts, the delete guards, the usage tab, the match report). */
    public static function toCrmProductInUse(): array
    {
        return array_values(array_filter(self::toCrmProduct(), fn (array $e): bool => ! in_array($e['table'] . '.' . $e['column'], self::DORMANT, true)));
    }

    /**
     * The 11 columns at crm_products.id.
     *
     * @return list<array{table: string, column: string, target: string, scope: string|array{via: string, parent: string, parent_key: string, parent_tenant: string}, measures: list<string>, lines: bool, target_kind?: string}>
     */
    public static function toCrmProduct(): array
    {
        $own = self::TENANT;
        $via = fn (string $column, string $parent): array => ['via' => $column, 'parent' => $parent, 'parent_key' => 'id', 'parent_tenant' => self::TENANT];
        $row = fn (string $table, string $column, string|array $scope, array $measures = [], bool $lines = false): array => [
            'table' => $table, 'column' => $column, 'target' => self::CRM_PRODUCTS, 'scope' => $scope, 'measures' => $measures, 'lines' => $lines,
        ];

        return [
            // Documents. The lines carry no organisation of their own; it lives on the document.
            $row('crm_quote_line_items', 'product_id', $via('quote_id', 'crm_quotes'), self::LINE_MEASURES, true),
            $row('crm_sales_order_line_items', 'product_id', $via('sales_order_id', 'crm_sales_orders'), self::LINE_MEASURES, true),
            $row('crm_invoice_line_items', 'product_id', $via('invoice_id', 'crm_invoices'), self::LINE_MEASURES, true),
            $row('crm_opportunity_products', 'product_id', $via('opportunity_id', 'crm_opportunities'), ['quantity'], true),
            $row('crm_price_book_items', 'product_id', $via('price_book_id', 'crm_price_books'), ['list_price'], true),
            // A bundle is a composition of products: the row belongs to the organisation of its parent product, and the component may not cross to another.
            $row('crm_product_bundle_items', 'parent_product_id', $via('parent_product_id', self::CRM_PRODUCTS), ['quantity'], true),
            $row('crm_product_bundle_items', 'component_product_id', $via('parent_product_id', self::CRM_PRODUCTS), ['quantity'], true),
            // Records with their own organisation column.
            $row('crm_tickets', 'product_id', $own),
            $row('crm_assets', 'product_id', $own),
            $row('crm_faqs', 'product_id', $own),
            // Loose: the column has no foreign key, so a deleted product leaves its value behind.
            $row('crm_campaigns', 'product_id', $own),
        ];
    }

    /**
     * The 10 columns at g2g_product_master.id.
     *
     * @return list<array{table: string, column: string, target: string, scope: string, measures: list<string>, lines: bool, target_kind?: string}>
     */
    public static function toMaster(): array
    {
        $row = fn (string $table, ?string $kind = null, array $measures = []): array => [
            'table' => $table, 'column' => 'product_master_id', 'target' => self::MASTER, 'scope' => self::TENANT, 'measures' => $measures, 'lines' => false,
        ] + ($kind !== null ? ['target_kind' => $kind] : []);

        return [
            $row('crm_leads'),
            // The opportunity's value is the one amount among these tables, so the census sums it: a repointed product must not move a deal's value.
            $row('crm_opportunities', null, ['amount']),
            $row('crm_campaigns'),
            // The catalogue item's product line (level 1). It must stay a `product` row: the sub-product page and the pair review read it as the parent.
            $row('crm_products', ProductMaster::KIND_PRODUCT),
            $row('g2g_company_opportunities'),
            $row('g2g_product_claims'),
            $row('g2g_research_runs'),
            $row('gtm_campaigns'),
            $row('gtm_outreach_messages'),
            $row('gtm_playbooks'),
        ];
    }

    /**
     * The pointers at the ABOUT row (g2g_product_offers.id), the third family (slice 15, P2-E2a, audit finding G-E2). Numeric ones only: a column that holds the
     * offer's row number, so it can be counted, repointed and checked like the others. Today that is the readiness history of an offer. It is NOT part of all(),
     * columns of the census or the checker's I1: nothing merges two About rows, and nothing breaks when one is deleted; it exists so that a delete of an About
     * (the old DELETE /portfolio/offers/{id}, `portfolio:import-offers --replace`) can say what it would leave behind (ProductUseCount::atOffer), and so that
     * the migration scan knows these columns (columns() includes them, NAME_PATTERN matches `offer_row_id`, so a new numeric pointer at the About row that is not listed here
     * is a red test). The offer ids that are TEXT (`offer_id` in the signals, runs, matches and ICP lists, `bundles_with`, `offers_authorized`) are not registered: they are not
     * row numbers, so nothing can repoint or count them.
     *
     * @return list<array{table: string, column: string, target: string, scope: string, measures: list<string>, lines: bool}>
     */
    public static function toOffer(): array
    {
        return [
            ['table' => 'g2g_offer_readiness_log', 'column' => 'offer_row_id', 'target' => self::OFFERS, 'scope' => self::TENANT, 'measures' => [], 'lines' => false],
        ];
    }

    /**
     * The 21 external pointers.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [...self::toCrmProduct(), ...self::toMaster()];
    }

    /**
     * The master row's own pointers, all on g2g_product_master. `crm_product_id` and `offer_row_id` are optional and independent (a row may hold one, both
     * or neither); `parent_id` points at a master row of kind `product`.
     *
     * @return list<array{column: string, target: string, target_kind?: string}>
     */
    public static function masterOwn(): array
    {
        return [
            ['column' => 'crm_product_id', 'target' => self::CRM_PRODUCTS],
            ['column' => 'offer_row_id', 'target' => self::OFFERS],
            ['column' => 'parent_id', 'target' => self::MASTER, 'target_kind' => ProductMaster::KIND_PRODUCT],
        ];
    }

    /**
     * Columns that match NAME_PATTERN in a migration but are not product pointers, each with the reason. The 21 + 3 columns of the registry are all pointers; the one entry is a table that
     * no longer exists. An entry here is a decision that a merge may leave the column alone, so it needs a sentence a reviewer can disagree with.
     *
     * @return array<string, string> "table.column" => reason
     */
    public static function notPointers(): array
    {
        return [
            'crm_purchase_order_line_items.product_id' => 'the table was dropped with the Purchase Orders module (2026_12_27_090300); its create migration stays as history and the drop migration recreates it empty in down(), so nothing is left to repoint',
            'g2g_product_kit_items.product_master_id' => 'a Sales Kit item (27 Nov 2026) belongs to a Product (kind product) of one organisation; the pair review merges only a catalogue item into a Sub-product and never one Product into another, so no merge repoints it. A Product that is deleted leaves its kit rows unreachable (ProductKit::product answers 404); whether its removal should also soft-delete them is a decision for the Product delete (ProductRemoval), not taken here',
        ];
    }

    /**
     * Every (table, column) this registry accounts for: the 21 external pointers, the master's own three and the numeric pointers at the About row (toOffer(), G-E2). The
     * migration scan compares the schema with it.
     *
     * @return list<array{table: string, column: string}>
     */
    public static function columns(): array
    {
        $columns = [];
        foreach (self::all() as $e) {
            $columns[] = ['table' => $e['table'], 'column' => $e['column']];
        }
        foreach (self::masterOwn() as $e) {
            $columns[] = ['table' => self::MASTER, 'column' => $e['column']];
        }
        foreach (self::toOffer() as $e) {
            $columns[] = ['table' => $e['table'], 'column' => $e['column']];
        }

        return $columns;
    }

    /**
     * The tables the 21 external pointers live in, in registry order, each once (19 tables: crm_campaigns holds a pointer of each family).
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        return array_values(array_unique(array_map(fn (array $e): string => $e['table'], self::all())));
    }

    /**
     * The registry entries of one table.
     *
     * @return list<array<string, mixed>>
     */
    public static function forTable(string $table): array
    {
        return array_values(array_filter(self::all(), fn (array $e): bool => $e['table'] === $table));
    }

    /** Whether a column name looks like a pointer at a product. */
    public static function looksLikeProductPointer(string $column): bool
    {
        return preg_match(self::NAME_PATTERN, $column) === 1;
    }
}
