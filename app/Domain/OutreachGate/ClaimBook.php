<?php

namespace App\Domain\OutreachGate;

use App\Domain\ProductMaster\ProductMaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What an organisation has APPROVED to be said about its products (table g2g_product_claims), and which products a text is about. Read only: the claims API writes
 * (ProductClaims). Every read carries the organisation, so another organisation's claims are never consulted.
 *
 * A host that lacks the claims table has no approved claim: the safe default (no numeric or superlative claim passes).
 */
final class ClaimBook
{
    public const MIN_NAME = 3;

    public static function hash(string $claim): string
    {
        return sha1(Text::normalise($claim));
    }

    /**
     * The NORMALISED approved claims of these products, for one organisation. Proposed and retired claims are not included.
     *
     * @param  list<int>  $productIds
     * @return list<string>
     */
    public function approved(int $tenant, array $productIds): array
    {
        $productIds = array_values(array_filter(array_map('intval', $productIds), fn (int $id) => $id > 0));
        if ($tenant <= 0 || $productIds === [] || ! Schema::hasTable('g2g_product_claims')) {
            return [];
        }

        return DB::table('g2g_product_claims')->where('sub_institute_id', $tenant)->where('status', 'approved')->whereIn('product_master_id', $productIds)->orderBy('id')
            ->pluck('claim')->map(fn ($claim) => Text::normalise((string) $claim))->filter(fn (string $c) => $c !== '')->values()->all();
    }

    /**
     * Of these ids, the ones the organisation may point at: its own products and the shared ones (tenant 0). An archived product still counts.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function visibleProductIds(int $tenant, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0));
        if ($tenant <= 0 || $ids === [] || ! Schema::hasTable('g2g_product_master')) {
            return [];
        }

        return ProductMaster::query()->visibleTo($tenant)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The products (own and shared) whose NAME appears in the text as a whole word or phrase, ignoring case.
     *
     * @return list<int>
     */
    public function productsNamedIn(int $tenant, string $text): array
    {
        if ($tenant <= 0 || trim($text) === '' || ! Schema::hasTable('g2g_product_master')) {
            return [];
        }

        $hits = [];
        foreach (ProductMaster::query()->visibleTo($tenant)->orderBy('id')->limit(2000)->get(['id', 'name']) as $product) {
            $name = trim((string) $product->name);
            if (mb_strlen($name) < self::MIN_NAME) {
                continue;
            }
            $regex = '~(?<![\p{L}\p{N}])' . preg_replace('/\s+/u', '\s+', preg_quote($name, '~')) . '(?![\p{L}\p{N}])~iu';
            if (preg_match($regex, $text) === 1) {
                $hits[] = (int) $product->id;
            }
        }

        return $hits;
    }
}
