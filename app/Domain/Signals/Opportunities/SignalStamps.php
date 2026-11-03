<?php

namespace App\Domain\Signals\Opportunities;

use App\Domain\ProductMaster\ProductResolver;
use App\Support\ColumnExists;
use Illuminate\Support\Facades\Log;

/**
 * The two facts slice 2 stamps on a signal when MarketImporter saves it, so a new signal is findable by the Product and Freshness facets without
 * waiting for `signals:backfill-product` / `signals:backfill-date-status`:
 *
 *   date_status        from the signal's own date columns (DateStatus), as the row will be stored. On a re-import it is recomputed from the dates the row
 *                      ENDS UP with: an update only overwrites a date with a newer non-empty one, so the incoming record alone cannot say what the stored
 *                      row is.
 *   product_master_id  the Product Master row the signal supports (ProductResolver::forSignal). Set only while empty: a person's or a backfill's value
 *                      is never replaced, and an unresolved signal stays NULL (never guessed).
 *
 * Both are guarded by ColumnExists, so on a database that has not run the S2 column migration this adds nothing and the import works as before. A
 * resolver failure never fails the import: the signal is saved without a product and the backfill picks it up.
 */
final class SignalStamps
{
    private const TABLE = 'g2g_company_opportunities';

    /**
     * Attributes to add to a NEW signal's columns.
     *
     * @param  array<string, mixed>  $record  the validated record (business_fit, event_date, source_published_at)
     * @return array<string, mixed>
     */
    public static function forNew(int $tenantId, array $record, ?string $offerId): array
    {
        $stamps = [];
        if (ColumnExists::has(self::TABLE, 'date_status')) {
            $stamps['date_status'] = DateStatus::status($record['event_date'] ?? null, $record['source_published_at'] ?? $record['event_date'] ?? null);
        }
        $product = self::productId($tenantId, $record['business_fit'] ?? null, $offerId);
        if ($product !== null) {
            $stamps['product_master_id'] = $product;
        }

        return $stamps;
    }

    /**
     * Attributes to add to an EXISTING signal that has just been refreshed from a re-import (call it after the refreshed dates are on the model).
     *
     * @return array<string, mixed>
     */
    public static function forExisting(CompanyOpportunity $signal): array
    {
        $stamps = [];
        if (ColumnExists::has(self::TABLE, 'date_status')) {
            $stamps['date_status'] = DateStatus::status($signal->event_date, $signal->source_published_at);
        }
        if ($signal->getAttribute('product_master_id') === null) {
            $product = self::productId((int) $signal->sub_institute_id, $signal->business_fit, $signal->relevant_offer_id ?? null);
            if ($product !== null) {
                $stamps['product_master_id'] = $product;
            }
        }

        return $stamps;
    }

    private static function productId(int $tenantId, ?string $businessFit, ?string $offerId): ?int
    {
        if (! ColumnExists::has(self::TABLE, 'product_master_id')) {
            return null;
        }
        try {
            $id = (new ProductResolver())->forSignal($tenantId, $businessFit, $offerId)?->id;

            return $id === null ? null : (int) $id;
        } catch (\Throwable $e) {
            Log::warning('signals.product_not_resolved', ['tenant' => $tenantId, 'exception' => get_class($e)]);

            return null;
        }
    }
}
