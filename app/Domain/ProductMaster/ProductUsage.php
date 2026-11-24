<?php

namespace App\Domain\ProductMaster;

use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `GET /api/product-master/{id}/usage`: what a record is used by (slice 15, P2-E1, brief section 5; audit finding G-3).
 *
 * The answer keeps what it always had (`signals`, `icp`, `priceBooks`, `quotes`, `basePrice`, `currency`) and adds, for a record with a catalogue row:
 *   counts    a number for EVERY table of ProductPointers::toCrmProduct() under a name a screen can use: quotes, sales orders, purchase orders, invoices, opportunities, price
 *             books, the bundle rows the item holds as a parent and the bundles it is a part of, tickets, assets, FAQs and campaigns. A document is counted once however
 *             many lines of it hold the item; a document in the Recycle Bin is not a use
 *   pointers  the same numbers under the registry's own key ("crm_invoice_line_items.product_id"), so a pointer added to the registry is counted without anybody remembering it
 *             (a test fails until it has a name in COUNT_NAMES); null for a table or column this host does not have
 *   latest    the newest five quotes, sales orders and invoices: id (the link), number, date and status
 * A record with no catalogue row (an About only, or a Product) answers its signals and ICP only: no Sell side exists to count.
 *
 * Every count and every list is this organisation's own (the document row's organisation, found the way the registry says). The CALLER's right is asked before this class
 * is: only a person who may view the Sell side of the record's type gets any of it (ProductReadRights::canViewSellOf).
 *
 * Read only. A table or a column an older host lacks is skipped (null), never an error. MariaDB 10.1 safe: joins, EXISTS, COUNT(DISTINCT), LIMIT.
 */
final class ProductUsage
{
    /** Registry key ("table.column") => the name in `counts`. */
    public const COUNT_NAMES = [
        'crm_quote_line_items.product_id' => 'quotes',
        'crm_sales_order_line_items.product_id' => 'salesOrders',
        'crm_purchase_order_line_items.product_id' => 'purchaseOrders',
        'crm_invoice_line_items.product_id' => 'invoices',
        'crm_opportunity_products.product_id' => 'opportunities',
        'crm_price_book_items.product_id' => 'priceBooks',
        'crm_product_bundle_items.parent_product_id' => 'bundleParts',
        'crm_product_bundle_items.component_product_id' => 'usedInBundles',
        'crm_tickets.product_id' => 'tickets',
        'crm_assets.product_id' => 'assets',
        'crm_faqs.product_id' => 'faqs',
        'crm_campaigns.product_id' => 'campaigns',
    ];

    /** How many documents the newest lists hold. */
    public const LATEST = 5;

    /** The lists: name => [registry key of the line table, number column, status column, date column] on the document table the registry names. */
    private const LATEST_LISTS = [
        'quotes' => ['crm_quote_line_items.product_id', 'quote_no', 'quote_stage', 'created_at'],
        'salesOrders' => ['crm_sales_order_line_items.product_id', 'so_no', 'so_stage', 'created_at'],
        'invoices' => ['crm_invoice_line_items.product_id', 'invoice_no', 'invoice_stage', 'invoice_date'],
    ];

    /** @var array<string, list<string>> */
    private array $columns = [];

    public function __construct(private readonly int $tenant)
    {
    }

    /** @return array<string, mixed> */
    public function forRecord(ProductMaster $row): array
    {
        $offerCode = $this->offerCode($row);
        $crm = $this->catalogueRow($row);

        $answer = [
            'signals' => $this->signals($row, $offerCode),
            'icp' => ProductReadSupport::icpState($row, $this->tenant, $offerCode),
            'priceBooks' => [],
            'quotes' => 0,
            'basePrice' => null,
            'currency' => null,
            'counts' => null,
            'pointers' => null,
            'latest' => null,
        ];
        if ($crm === null) {
            return $answer;
        }

        $pointers = [];
        foreach (ProductPointers::toCrmProduct() as $entry) {
            $pointers[$entry['table'] . '.' . $entry['column']] = $this->count($entry, (int) $crm->id);
        }
        $counts = [];
        foreach (self::COUNT_NAMES as $key => $name) {
            $counts[$name] = $pointers[$key] ?? null;
        }
        $latest = [];
        foreach (self::LATEST_LISTS as $name => [$key, $number, $status, $date]) {
            $latest[$name] = $this->latest($key, $number, $status, $date, (int) $crm->id);
        }

        return [
            'priceBooks' => $this->priceBooks((int) $crm->id),
            'quotes' => (int) ($counts['quotes'] ?? 0),
            'basePrice' => $crm->unit_price !== null ? (float) $crm->unit_price : null,
            'currency' => $crm->currency,
            'counts' => $counts,
            'pointers' => $pointers,
            'latest' => $latest,
        ] + $answer;
    }

    // ---------------------------------------------------------------- the record

    private function offerCode(ProductMaster $row): ?string
    {
        if ($row->offer_row_id === null || ! Schema::hasTable('g2g_product_offers')) {
            return null;
        }
        $code = DB::table('g2g_product_offers')->where('id', $row->offer_row_id)->where('sub_institute_id', $this->tenant)->value('offer_id');

        return $code === null ? null : (string) $code;
    }

    /** The catalogue row behind the record, in any state (documents still point at an item in the Recycle Bin, and that is exactly what a person wants to see). */
    private function catalogueRow(ProductMaster $row): ?object
    {
        if ($row->crm_product_id === null || ! Schema::hasTable('crm_products')) {
            return null;
        }

        return DB::table('crm_products')->where('id', $row->crm_product_id)->where('sub_institute_id', $this->tenant)->first(['id', 'unit_price', 'currency']);
    }

    private function signals(ProductMaster $row, ?string $offerCode): ?int
    {
        if (! Schema::hasTable('g2g_company_opportunities')) {
            return null;
        }
        $base = DB::table('g2g_company_opportunities')->where('sub_institute_id', $this->tenant)->where('is_sample', 0);
        if ($row->kind === ProductMaster::KIND_PRODUCT && $row->signal_key !== null) {
            return (int) (clone $base)->where('business_fit', $row->signal_key)->count();
        }
        if ($row->kind === ProductMaster::KIND_SUB_PRODUCT && $offerCode !== null) {
            return (int) (clone $base)->where('relevant_offer_id', $offerCode)->count();
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function priceBooks(int $crmId): array
    {
        if (! Schema::hasTable('crm_price_book_items') || ! Schema::hasTable('crm_price_books')) {
            return [];
        }

        return DB::table('crm_price_book_items as i')->join('crm_price_books as b', 'b.id', '=', 'i.price_book_id')
            ->where('i.product_id', $crmId)->where('b.sub_institute_id', $this->tenant)->whereNull('b.deleted_at')->orderBy('b.name')
            ->get(['b.id', 'b.price_book_no', 'b.name', 'b.currency', 'b.is_active', 'i.list_price'])
            ->map(fn ($p) => ['priceBookId' => (int) $p->id, 'priceBookNo' => $p->price_book_no, 'name' => $p->name, 'currency' => $p->currency, 'active' => (bool) $p->is_active, 'listPrice' => (float) $p->list_price])
            ->all();
    }

    // ---------------------------------------------------------------- the counts

    /** @param array<string, mixed> $entry a registry entry (ProductPointers::toCrmProduct) */
    private function count(array $entry, int $crmId): ?int
    {
        $table = $entry['table'];
        $column = $entry['column'];
        $scope = $entry['scope'];
        if (! Schema::hasTable($table) || ! $this->has($table, $column)) {
            return null;
        }

        // A record with an organisation column of its own (tickets, assets, FAQs, campaigns).
        if (is_string($scope)) {
            if (! $this->has($table, $scope)) {
                return null;
            }
            $q = DB::table($table)->where($column, $crmId)->where($scope, $this->tenant);
            if ($this->has($table, 'deleted_at')) {
                $q->whereNull('deleted_at');
            }

            return (int) $q->count();
        }

        // A line: its organisation lives on the document (or, for a bundle row, on the product that owns it).
        $parent = $scope['parent'];
        if (! Schema::hasTable($parent) || ! $this->has($table, $scope['via']) || ! $this->has($parent, $scope['parent_key']) || ! $this->has($parent, $scope['parent_tenant'])) {
            return null;
        }
        $q = DB::table($table . ' as l')->join($parent . ' as d', 'd.' . $scope['parent_key'], '=', 'l.' . $scope['via'])
            ->where('l.' . $column, $crmId)->where('d.' . $scope['parent_tenant'], $this->tenant);
        // A document in the Recycle Bin is not a use. (When the pointer column IS the owner column, the owner is the item itself, which may well be in the Bin.)
        if ($column !== $scope['via'] && $this->has($parent, 'deleted_at')) {
            $q->whereNull('d.deleted_at');
        }

        // A bundle row is one part, counted as it is; a document may hold several lines of the item and counts once.
        return $parent === ProductPointers::CRM_PRODUCTS ? (int) $q->count() : (int) $q->distinct()->count('d.' . $scope['parent_key']);
    }

    /**
     * The newest documents that hold the item on a line: id (the link), number, date, status. The document table and the way a line reaches it are the registry's.
     *
     * @return list<array{id: int, number: ?string, date: ?string, status: ?string}>|null null when this host has no such table
     */
    private function latest(string $registryKey, string $number, string $status, string $date, int $crmId): ?array
    {
        $entry = null;
        foreach (ProductPointers::toCrmProduct() as $e) {
            if ($e['table'] . '.' . $e['column'] === $registryKey) {
                $entry = $e;
            }
        }
        $scope = $entry['scope'] ?? null;
        if (! is_array($scope) || ! Schema::hasTable($entry['table']) || ! Schema::hasTable($scope['parent']) || ! $this->has($entry['table'], $scope['via']) || ! $this->has($scope['parent'], $scope['parent_tenant'])) {
            return null;
        }
        $parent = $scope['parent'];
        $pick = fn (string $column, string $as) => $this->has($parent, $column) ? "d.{$column} as {$as}" : DB::raw("NULL as {$as}");

        $q = DB::table($parent . ' as d')->where('d.' . $scope['parent_tenant'], $this->tenant)
            ->whereExists(fn ($s) => $s->select(DB::raw('1'))->from($entry['table'] . ' as l')
                ->whereColumn('l.' . $scope['via'], 'd.' . $scope['parent_key'])->where('l.' . $entry['column'], $crmId));
        if ($this->has($parent, 'deleted_at')) {
            $q->whereNull('d.deleted_at');
        }
        if ($this->has($parent, 'created_at')) {
            $q->orderByDesc('d.created_at');
        }

        return $q->orderByDesc('d.' . $scope['parent_key'])->limit(self::LATEST)
            ->get(['d.' . $scope['parent_key'] . ' as doc_id', $pick($number, 'doc_number'), $pick($date, 'doc_date'), $pick($status, 'doc_status')])
            ->map(fn ($r) => [
                'id' => (int) $r->doc_id, 'number' => $r->doc_number !== null ? (string) $r->doc_number : null,
                'date' => $r->doc_date !== null ? substr((string) $r->doc_date, 0, 10) : null, 'status' => $r->doc_status !== null ? (string) $r->doc_status : null,
            ])->values()->all();
    }

    /** Does the table have the column on this host? Asked once per table. */
    private function has(string $table, string $column): bool
    {
        $this->columns[$table] ??= Schema::hasTable($table) ? ColumnExists::all($table) : [];

        return in_array($column, $this->columns[$table], true);
    }
}
