<?php

namespace App\Domain\Signals\Opportunities;

use App\Domain\ProductMaster\ProductMaster;
use App\Support\ColumnExists;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * The Signal Review additions to the opportunity feed (slice 2): the date facets, the product facet, the "most recent" sort and the product names.
 *
 * Age of a signal = its EFFECTIVE DATE: event_date, else source_published_at, else none (the same order DateStatus::classify uses, so the badge
 * on a row and the facet that lists it cannot disagree). Everything is computed from those two columns, never from the stored `date_status`
 * column, so the facets work before the backfill has run and on a database that does not have that column at all.
 *
 *   freshness   last_30_days  effective date on or after today minus 30 days (a date in the future counts as fresh)
 *               last_90_days  effective date on or after today minus 90 days (includes the last 30 days)
 *               older         has an effective date and it is before today minus 90 days
 *               undated       no effective date
 *
 * Dates are compared as Y-m-d strings worked out here from the application's clock (the same one the expiry filter uses), never with NOW() or
 * DATE_SUB, so the SQL is the same on the sqlite tests, MariaDB 10.11 (dev) and MariaDB 10.1 (live). '0000-00-00' (a MariaDB "no date") counts as
 * absent, as DateStatus treats it.
 */
final class FeedFilters
{
    public const FRESHNESS = ['last_30_days', 'last_90_days', 'older', 'undated'];

    public const SORT_RECENT = 'recent';

    /** "older" is everything before the start of the widest window. */
    private const WINDOW_DAYS = ['last_30_days' => 30, 'last_90_days' => 90];

    private const NO_DATE = '0000-00-00';

    private const DATE_STATUS_LABELS = [
        DateStatus::EVENT_DATE_STATED => 'Event date stated',
        DateStatus::SOURCE_DATE_ONLY => 'Published date only',
        DateStatus::UNDATED => 'Date unknown',
    ];

    /**
     * Narrows the feed by the new optional query parameters. A parameter that is absent or empty changes nothing, so a request that sends none of
     * them builds exactly the query it always did. Call problem() first: the product facet needs a column an older database may not have yet.
     *
     * @param  array<string, mixed>  $query  the request's query string
     */
    public static function apply(Builder $q, array $query, ?CarbonInterface $today = null): Builder
    {
        $today ??= Carbon::today();

        $status = (string) ($query['date_status'] ?? '');
        if ($status !== '') {
            self::whereDateStatus($q, $status);
        }
        $freshness = (string) ($query['freshness'] ?? '');
        if ($freshness !== '') {
            self::whereFreshness($q, $freshness, $today);
        }
        $product = (int) ($query['product_master_id'] ?? 0);
        if ($product > 0) {
            $q->where('o.product_master_id', $product);
        }

        return $q;
    }

    /**
     * Why the request cannot be answered, or null. The only case: a product facet on a database that has not run the column migration. Ignoring
     * it would answer with every product's signals under a product name, which is worse than saying so.
     *
     * @param  array<string, mixed>  $query
     */
    public static function problem(array $query): ?string
    {
        if ((int) ($query['product_master_id'] ?? 0) > 0 && ! ColumnExists::has('g2g_company_opportunities', 'product_master_id')) {
            return 'Filtering by product is not available yet: the signals table has not been updated on this server.';
        }

        return null;
    }

    /**
     * Newest first by effective date; signals with no date come after the dated ones, by when they were first found; ties by id (newest). This
     * replaces the whole usual order, so the caller clears it first (Builder::reorder()). "Undated last" is stated as its own key rather than left
     * to how an engine happens to order NULLs under DESC.
     */
    public static function orderRecent(Builder $q): Builder
    {
        $effective = self::effectiveDate();

        return $q->orderByRaw("({$effective} IS NULL) ASC")
            ->orderByRaw("{$effective} DESC")
            ->orderByRaw("CASE WHEN {$effective} IS NULL THEN o.first_discovered_at END DESC")
            ->orderByDesc('o.id');
    }

    /** The first date a window starts on (Y-m-d), or null for a freshness that has no start. */
    public static function windowStart(string $freshness, CarbonInterface $today): ?string
    {
        $days = self::WINDOW_DAYS[$freshness] ?? null;

        return $days === null ? null : $today->copy()->subDays($days)->toDateString();
    }

    /** event_date, else source_published_at, else NULL; a zero date is no date. */
    public static function effectiveDate(): string
    {
        return "COALESCE(NULLIF(o.event_date, '" . self::NO_DATE . "'), NULLIF(o.source_published_at, '" . self::NO_DATE . "'))";
    }

    /**
     * The names of the products a page of signals points at, id => name, in one query. Only the organisation's own products and the shared ones
     * (sub_institute_id 0) are looked up, so a signal that points at another organisation's product shows no name. Archived products keep their
     * name: the signal was filed under it.
     *
     * @param  iterable<object>  $rows
     * @return array<int, string>
     */
    public static function productNames(int $tenantId, iterable $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row->product_master_id ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === [] || ! Schema::hasTable('g2g_product_master')) {
            return [];
        }

        return ProductMaster::query()->visibleTo($tenantId)->whereIn('id', array_values($ids))->pluck('name', 'id')
            ->map(fn ($name) => (string) $name)->all();
    }

    /** @return list<array{value: string, label: string}> */
    public static function reviewReasons(): array
    {
        return collect((array) config('signals.review.reasons', []))->map(fn ($label, $code) => ['value' => (string) $code, 'label' => (string) $label])->values()->all();
    }

    /** @return list<array{value: string, label: string}> */
    public static function dateStatusOptions(): array
    {
        return collect(DateStatus::ALL)->map(fn ($status) => ['value' => $status, 'label' => self::DATE_STATUS_LABELS[$status]])->values()->all();
    }

    private static function whereDateStatus(Builder $q, string $status): void
    {
        $event = "NULLIF(o.event_date, '" . self::NO_DATE . "')";
        $published = "NULLIF(o.source_published_at, '" . self::NO_DATE . "')";

        match ($status) {
            DateStatus::EVENT_DATE_STATED => $q->whereRaw("{$event} IS NOT NULL"),
            DateStatus::SOURCE_DATE_ONLY => $q->whereRaw("{$event} IS NULL AND {$published} IS NOT NULL"),
            DateStatus::UNDATED => $q->whereRaw(self::effectiveDate() . ' IS NULL'),
        };
    }

    private static function whereFreshness(Builder $q, string $freshness, CarbonInterface $today): void
    {
        $effective = self::effectiveDate();

        match ($freshness) {
            'last_30_days', 'last_90_days' => $q->whereRaw("{$effective} >= ?", [self::windowStart($freshness, $today)]),
            'older' => $q->whereRaw("{$effective} IS NOT NULL AND {$effective} < ?", [self::windowStart('last_90_days', $today)]),
            'undated' => $q->whereRaw("{$effective} IS NULL"),
        };
    }
}
