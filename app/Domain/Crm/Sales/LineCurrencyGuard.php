<?php

namespace App\Domain\Crm\Sales;

use App\Domain\Money\Currencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * One rule for the four document line tables (quote, sales order, invoice, purchase order; S16, 25 Nov 2026, contract section 4 (a)):
 * a line whose catalogue item is priced in one currency cannot be put on a document that is in another.
 *
 *   a line with `productId`, the item's `currency` is not empty, the document's `currency` is not empty, and they differ (case does not matter)
 *   -> 422 {status: 0, code: "currency_mismatch", message: "<item> is priced in <CUR>, this document is in <CUR>."} and the controller saves NOTHING.
 *
 * Nothing else changes: a line with no `productId`, an item with no currency, a document with no currency, an item of another organisation (it is not looked up) and every other
 * rule of saveLineItems stay as they were. ONE query for all the lines of a save, whatever their number.
 */
final class LineCurrencyGuard
{
    /**
     * @param  ?string  $documentCurrency  the `currency` of the document's own row
     * @param  mixed  $lineItems  the request's `lineItems`
     */
    public static function refusal(int $tenant, ?string $documentCurrency, mixed $lineItems): ?JsonResponse
    {
        $document = Currencies::normalise($documentCurrency);
        if ($document === null || ! is_array($lineItems)) {
            return null;
        }
        $ids = [];
        foreach ($lineItems as $line) {
            if (is_array($line) && isset($line['productId']) && is_numeric($line['productId']) && (int) $line['productId'] > 0) {
                $ids[(int) $line['productId']] = true;
            }
        }
        if ($ids === []) {
            return null;
        }

        $items = DB::table('crm_products')->where('sub_institute_id', $tenant)->whereIn('id', array_keys($ids))->get(['id', 'name', 'currency'])->keyBy('id');
        foreach ($lineItems as $line) {
            $id = is_array($line) && isset($line['productId']) && is_numeric($line['productId']) ? (int) $line['productId'] : 0;
            $item = $items->get($id);
            $priced = $item === null ? null : Currencies::normalise($item->currency);
            if ($priced !== null && $priced !== $document) {
                return response()->json([
                    'status' => 0, 'code' => 'currency_mismatch',
                    'message' => sprintf('%s is priced in %s, this document is in %s.', (string) $item->name, $priced, $document),
                ], 422);
            }
        }

        return null;
    }
}
