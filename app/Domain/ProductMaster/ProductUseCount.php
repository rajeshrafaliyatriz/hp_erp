<?php

namespace App\Domain\ProductMaster;

use App\Support\ColumnExists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many rows still point at one record (slice 15, P2-E2a; audit findings G-1, G-E2 and D-8, Docs/agentic-crm/_evidence/s15/).
 *
 * The answer to "may this record be deleted for good?": a hard delete of a catalogue item (the Recycle Bin's "Delete permanently") or of a Portfolio offer (the old
 * DELETE /portfolio/offers/{id}, the import commands' --replace) would leave every row that points at it behind or, through a foreign key, cascade or null it. The list
 * of what points where is ProductPointers (toCrmProduct() for the catalogue item, toOffer() for the About row), so a pointer added to the registry is counted here
 * without anybody remembering it; LABELS gives each one the words a person reads, and a test fails until a new pointer has one.
 *
 * It counts the ROWS THAT POINT, not the documents that are alive and not one organisation's: the delete would reach a line of a document in the Recycle Bin and a row of
 * another organisation that holds the same number just as much. It never counts the Product Master row's own pointers (crm_product_id, offer_row_id): ProductMasterSync is
 * what follows a delete, and archiving a catalogue-only row or letting a Sub-product go of a deleted item is its job, not a use. Read only. A table or a column the host
 * does not have yet is skipped. MariaDB 10.1 safe: a plain COUNT(*).
 */
final class ProductUseCount
{
    /** Registry key ("table.column") => what a person reads, in the plural ("This product is used by 3 records (quote lines, tickets)"). */
    public const LABELS = [
        'crm_quote_line_items.product_id' => 'quote lines',
        'crm_sales_order_line_items.product_id' => 'sales order lines',
        'crm_invoice_line_items.product_id' => 'invoice lines',
        'crm_opportunity_products.product_id' => 'opportunity products',
        'crm_product_bundle_items.parent_product_id' => 'bundle parts it holds',
        'crm_product_bundle_items.component_product_id' => 'bundles it is a part of',
        'crm_tickets.product_id' => 'tickets',
        'crm_assets.product_id' => 'assets',
        'crm_faqs.product_id' => 'FAQs',
        'crm_campaigns.product_id' => 'campaigns',
        'g2g_offer_readiness_log.offer_row_id' => 'readiness history lines',
    ];

    /**
     * What points at a catalogue item (crm_products.id), registry order, only the pointers that hold it.
     *
     * @return list<array{table: string, column: string, label: string, count: int}>
     */
    public static function atCatalogueItem(int $crmProductId): array
    {
        return self::count(ProductPointers::toCrmProductInUse(), $crmProductId);
    }

    /**
     * What points at a Portfolio offer's row (g2g_product_offers.id) by its number: the registry's toOffer() family.
     *
     * @return list<array{table: string, column: string, label: string, count: int}>
     */
    public static function atOffer(int $offerRowId): array
    {
        return self::count(ProductPointers::toOffer(), $offerRowId);
    }

    /** @param list<array{table: string, column: string, label: string, count: int}> $usedBy */
    public static function total(array $usedBy): int
    {
        return (int) array_sum(array_column($usedBy, 'count'));
    }

    /** The labels of the pointers that hold something, in the order given: "quote lines, tickets". @param list<array{table: string, column: string, label: string, count: int}> $usedBy */
    public static function words(array $usedBy): string
    {
        return implode(', ', array_column($usedBy, 'label'));
    }

    /**
     * @param  list<array<string, mixed>>  $entries registry entries
     * @return list<array{table: string, column: string, label: string, count: int}>
     */
    private static function count(array $entries, int $id): array
    {
        $found = [];
        if ($id <= 0) {
            return $found;
        }
        foreach ($entries as $e) {
            $table = (string) $e['table'];
            $column = (string) $e['column'];
            if (! Schema::hasTable($table) || ! ColumnExists::has($table, $column)) {
                continue;
            }
            $count = (int) DB::table($table)->where($column, $id)->count();
            if ($count > 0) {
                $found[] = ['table' => $table, 'column' => $column, 'label' => self::LABELS["{$table}.{$column}"] ?? "{$table}.{$column}", 'count' => $count];
            }
        }

        return $found;
    }
}
