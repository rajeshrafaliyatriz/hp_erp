<?php

namespace App\Domain\ProductMaster;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The small pieces every unified read shares (slice 15, P2-E1): paging, the search pattern, the Signals counts and a record's ICP state. Read only; MariaDB 10.1 safe
 * (plain LIMIT / OFFSET, LIKE with an explicit escape character, no JSON, no window functions, no CTEs).
 */
final class ProductReadSupport
{
    /** The page sizes a client may ask for: a smaller number is raised to the least, a larger one cut to the most, anything else gets the default. */
    public const PER_MIN = 5;

    public const PER_MAX = 100;

    public const PER_DEFAULT = 20;

    /** A page number beyond this is not a page anybody has: it only keeps OFFSET far from the integer limit. */
    private const PAGE_MAX = 100000;

    /** The longest search text that is used; the rest is dropped. */
    public const SEARCH_MAX = 100;

    /** The character that escapes `%`, `_` and itself in a LIKE pattern built here. */
    public const LIKE_ESCAPE = '!';

    /** `?per=`: 5 to 100, default 20. */
    public static function perPage(mixed $value): int
    {
        if (! is_numeric($value)) {
            return self::PER_DEFAULT;
        }

        return max(self::PER_MIN, min(self::PER_MAX, (int) $value));
    }

    /** `?page=`: 1 or more. A page past the end is simply empty. */
    public static function page(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 1;
        }

        return max(1, min(self::PAGE_MAX, (int) $value));
    }

    /**
     * @return array{total: int, page: int, perPage: int, lastPage: int}
     */
    public static function paging(int $total, int $page, int $perPage): array
    {
        return ['total' => $total, 'page' => $page, 'perPage' => $perPage, 'lastPage' => max(1, (int) ceil($total / $perPage))];
    }

    /** The text a person typed, trimmed and cut; '' when there is none. */
    public static function searchText(mixed $value): string
    {
        return mb_substr(trim((string) $value), 0, self::SEARCH_MAX);
    }

    /** A `%text%` LIKE pattern in which what the person typed matches literally (a `%` or `_` in it is a letter, not a wildcard). Use with `ESCAPE '!'`. */
    public static function likePattern(string $text): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text) . '%';
    }

    /** @return array<string, int> business_fit value => signals of this organisation (real ones only, not the sample rows) */
    public static function signalsByProductKey(int $tenant): array
    {
        if (! Schema::hasTable('g2g_company_opportunities')) {
            return [];
        }

        return DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenant)->where('is_sample', 0)->whereNotNull('business_fit')
            ->selectRaw('business_fit as b, COUNT(*) as n')->groupBy('business_fit')->pluck('n', 'b')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * The state of the ideal-customer profile of one record, the same word the list shows: a Product's own, a Sub-product's through its offer, null for anything an
     * ICP does not apply to (a catalogue-only item). Never the profile's content.
     */
    public static function icpState(ProductMaster $row, int $tenant, ?string $offerCode): ?string
    {
        return match ($row->kind) {
            ProductMaster::KIND_PRODUCT => ProductLinks::icpByProduct($tenant, collect([$row]))[(int) $row->id] ?? null,
            ProductMaster::KIND_SUB_PRODUCT => $offerCode !== null ? (ProductLinks::icpByOffer($tenant, [$offerCode])[$offerCode] ?? ProductLinks::ICP_NONE) : null,
            default => null,
        };
    }
}
