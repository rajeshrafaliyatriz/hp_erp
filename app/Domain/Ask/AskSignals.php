<?php

namespace App\Domain\Ask;

use App\Domain\Signals\Products\ProductCatalog;
use App\Support\ColumnExists;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The read side of Signals for the Ask chat (slice 8): the market signals the Signals screen lists, never a write. Every query carries the caller's
 * organisation, demonstration rows (`is_sample`) are never returned (the Signals feed hides them unless asked), and the company a signal belongs to is
 * looked up in the same organisation only.
 *
 * WHAT THE MODEL IS SHOWN of a signal: its title, company, status, the dates it was found and last decided on, the product, what was observed, and the reason
 * a person gave when they dismissed it. NOT the research's evidence note (it is about the source, and is never the reason a person dismissed a signal: the
 * Organisation agent's first real run mixed the two up) and not the source address.
 */
final class AskSignals
{
    public const STATUSES = ['New', 'Reviewed', 'Follow-up', 'Dismissed'];

    private const TABLE = 'g2g_company_opportunities';

    public static function available(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    /**
     * Signals, newest first by "the date it was last decided on, or found when nobody has decided on it".
     *
     * @param  ?string  $product  a product key, already normalised
     * @return array{rows: list<AskRow>, total: int}
     */
    public static function list(AskCaller $caller, ?string $product, ?string $status, ?string $since, ?string $until, int $limit): array
    {
        if (! self::available()) {
            return ['rows' => [], 'total' => 0];
        }
        $q = self::base($caller);
        $have = array_map('strtolower', ColumnExists::all(self::TABLE));

        if ($product !== null) {
            $fit = ProductCatalog::businessFit($product, $caller->tenantId);
            $q->where(function ($w) use ($product, $fit, $have) {
                if (in_array('product_key', $have, true)) {
                    $w->orWhere('o.product_key', $product);
                }
                if ($fit !== null && in_array('business_fit', $have, true)) {
                    $w->orWhere('o.business_fit', $fit);
                }
                if (! in_array('product_key', $have, true) && ($fit === null || ! in_array('business_fit', $have, true))) {
                    $w->whereRaw('1 = 0');   // a host that stores no product on a signal can answer for no product
                }
            });
        }
        if ($status !== null) {
            $status === 'New'
                ? $q->where(fn ($w) => $w->whereNull('o.review_status')->orWhere('o.review_status', '')->orWhere('o.review_status', 'New'))
                : $q->where('o.review_status', $status);
        }
        $effective = in_array('reviewed_at', $have, true) ? 'COALESCE(o.reviewed_at, o.first_discovered_at)' : 'o.first_discovered_at';
        if ($since !== null) {
            $q->whereRaw("{$effective} >= ?", [$since . ' 00:00:00']);
        }
        if ($until !== null) {
            $q->whereRaw("{$effective} <= ?", [$until . ' 23:59:59']);
        }

        $total = (int) (clone $q)->count();
        $records = $q->orderByRaw("{$effective} DESC")->orderByDesc('o.id')->limit($limit)->get(self::columns($have));

        return ['rows' => self::rows($caller, $records), 'total' => $total];
    }

    /**
     * The signals of one CRM organisation, found through its Organisation 360 identity (the Signals company it is linked to).
     *
     * @return list<AskRow>
     */
    public static function forOrganization(AskCaller $caller, int $crmOrganizationId, int $limit): array
    {
        if (! self::available() || ! Schema::hasTable('g2g_org_accounts')) {
            return [];
        }
        $company = DB::table('g2g_org_accounts')->where('sub_institute_id', $caller->tenantId)->where('crm_organization_id', $crmOrganizationId)->whereNull('deleted_at')->value('g2g_company_id');
        if ($company === null) {
            return [];
        }
        $have = array_map('strtolower', ColumnExists::all(self::TABLE));
        $order = in_array('first_discovered_at', $have, true) ? 'o.first_discovered_at' : 'o.id';
        $records = self::base($caller)->where('o.company_id', (int) $company)->orderByDesc($order)->orderByDesc('o.id')->limit($limit)->get(self::columns($have));

        return self::rows($caller, $records);
    }

    /**
     * @param  list<int>  $ids
     * @return list<AskRow>
     */
    public static function byIds(AskCaller $caller, array $ids): array
    {
        if ($ids === [] || ! self::available()) {
            return [];
        }
        $have = array_map('strtolower', ColumnExists::all(self::TABLE));

        return self::rows($caller, self::base($caller)->whereIn('o.id', $ids)->get(self::columns($have)));
    }

    private static function base(AskCaller $caller): Builder
    {
        $have = array_map('strtolower', ColumnExists::all(self::TABLE));
        $q = DB::table(self::TABLE . ' as o')
            ->leftJoin('g2g_companies as c', fn ($j) => $j->on('c.id', '=', 'o.company_id')->where('c.sub_institute_id', '=', $caller->tenantId))
            ->where('o.sub_institute_id', $caller->tenantId);
        if (in_array('is_sample', $have, true)) {
            $q->where('o.is_sample', 0);
        }

        return $q;
    }

    /**
     * @param  list<string>  $have
     * @return list<string>
     */
    private static function columns(array $have): array
    {
        $out = ['o.id', 'c.name as company_name'];
        foreach (['title', 'review_status', 'reviewed_at', 'first_discovered_at', 'event_date', 'observed_event', 'product_key', 'business_fit', 'priority'] as $column) {
            if (in_array($column, $have, true)) {
                $out[] = "o.{$column}";
            }
        }

        return $out;
    }

    /**
     * @param  iterable<object>  $records
     * @return list<AskRow>
     */
    private static function rows(AskCaller $caller, iterable $records): array
    {
        $records = is_array($records) ? $records : iterator_to_array($records, false);
        if ($records === []) {
            return [];
        }
        $reasons = self::dismissalReasons($caller, array_map(fn ($r) => (int) $r->id, $records));
        $definitions = ProductCatalog::definitionsFor($caller->tenantId);
        $out = [];
        foreach ($records as $r) {
            $title = (string) (AskText::clip($r->title ?? null, 120) ?? "Signal #{$r->id}");
            $status = ($r->review_status ?? '') !== '' ? (string) $r->review_status : 'New';
            $key = ProductCatalog::normalise($r->product_key ?? null, $caller->tenantId) ?? ProductCatalog::normalise($r->business_fit ?? null, $caller->tenantId);
            $out[] = new AskRow('signal', (string) $r->id, $title, '/module/crm/signal-review?status=all&q=' . rawurlencode(mb_substr($title, 0, 80)), [
                'title' => AskText::clip($r->title ?? null, 200), 'company' => AskText::clip($r->company_name ?? null, 120), 'status' => $status,
                'product' => $key !== null ? ($definitions[$key]['label'] ?? $key) : null, 'priority' => $r->priority ?? null,
                'found_on' => self::day($r->first_discovered_at ?? null), 'last_decided_on' => self::day($r->reviewed_at ?? null), 'event_date' => self::day($r->event_date ?? null),
                'what_was_observed' => AskText::clip($r->observed_event ?? null, (int) config('ask.max_text_chars', 300)),
            ] + ($status === 'Dismissed' ? ['dismissal_reason' => $reasons[(int) $r->id] ?? 'none recorded'] : []));
        }

        return $out;
    }

    /**
     * The reason a person gave when they dismissed each signal ("not a buyer: <note>"), from the newest dismissal. Read per organisation. A signal with no
     * review row, or a dismissal with no reason and no note, has no entry (the caller says "none recorded").
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private static function dismissalReasons(AskCaller $caller, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('g2g_signal_reviews')) {
            return [];
        }
        $out = [];
        foreach (DB::table('g2g_signal_reviews')->where('sub_institute_id', $caller->tenantId)->whereIn('opportunity_id', $ids)->where('decision', 'dismissed')
            ->orderByDesc('decided_at')->orderByDesc('id')->get(['opportunity_id', 'reason_code', 'note']) as $review) {
            $id = (int) $review->opportunity_id;
            if (isset($out[$id])) {
                continue;   // the newest dismissal wins
            }
            $code = trim(str_replace('_', ' ', (string) ($review->reason_code ?? '')));
            $note = trim((string) ($review->note ?? ''));
            $text = $code !== '' && $note !== '' ? "{$code}: {$note}" : ($code !== '' ? $code : $note);
            if ($text !== '') {
                $out[$id] = AskText::clip($text, 200) ?? $text;
            }
        }

        return $out;
    }

    private static function day(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : ($value instanceof \DateTimeInterface ? $value->format('Y-m-d') : '');

        return $text === '' || str_starts_with($text, '0000') ? null : substr($text, 0, 10);
    }
}
