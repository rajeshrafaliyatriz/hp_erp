<?php

namespace App\Domain\Crm\Leads;

use App\Domain\Crm\Records\RecordNote;
use App\Domain\ProductMaster\ProductMaster;
use Illuminate\Support\Facades\Schema;

/**
 * What a lead that came from a signal can say about where it came from (slice 6): the signal's own words, its source, its date and the product it was
 * judged to fit. ONE place builds it, for the structured note written when the signal is turned into a lead and for the `source_signal` block of the lead
 * API, so the two can never tell different stories.
 *
 * It states only what the signal row holds. The QUOTE is the first source's own excerpt (the text the source page gave, as the research saved it); a signal
 * without one is described by its `observed_event` instead and labelled that way, never passed off as a quote. A date is labelled for what it is: the date of
 * the event when one is stated, else the date the source was published, else "not stated". Nothing here guesses a date or a product.
 */
final class LeadSignalEvidence
{
    private const QUOTE_MAX = 700;

    /**
     * @param  object  $signal  a g2g_company_opportunities row or model
     * @return array{id: int, title: string, source_url: ?string, event_date: ?string, source_published_at: ?string, business_fit: ?string, quote: ?string, quote_kind: ?string}
     */
    public static function of(object $signal): array
    {
        $sources = self::sources($signal->sources ?? null);
        $first = $sources[0] ?? [];
        $excerpt = self::clean($first['excerpt'] ?? null);
        $observed = self::clean($signal->observed_event ?? null);

        return [
            'id' => (int) $signal->id,
            'title' => (string) ($signal->title ?? ''),
            'source_url' => self::url($signal->source_url ?? null) ?? self::url($first['url'] ?? null),
            'event_date' => self::date($signal->event_date ?? null),
            'source_published_at' => self::date($signal->source_published_at ?? null) ?? self::date($first['published_at'] ?? null),
            'business_fit' => self::clean($signal->business_fit ?? null),
            'quote' => $excerpt ?? $observed,
            'quote_kind' => $excerpt !== null ? 'excerpt' : ($observed !== null ? 'observed_event' : null),
        ];
    }

    /**
     * The text of the one note a promotion writes on the lead.
     *
     * @param  array<string, mixed>  $evidence  of()
     */
    public static function noteText(array $evidence, ?string $productName): string
    {
        $head = 'From a signal: ' . mb_substr((string) $evidence['title'], 0, 200) . ' (signal #' . $evidence['id'] . ')';
        $tail = [
            'Source: ' . ($evidence['source_url'] ?? 'no address saved'),
            $evidence['event_date'] !== null ? 'Event date: ' . $evidence['event_date']
                : ($evidence['source_published_at'] !== null ? 'Published: ' . $evidence['source_published_at'] . ' (the event date is not stated)' : 'Date: not stated'),
        ];
        $fit = array_values(array_filter([$productName, $evidence['business_fit'] !== null ? 'stored fit "' . $evidence['business_fit'] . '"' : null]));
        $tail[] = 'Fit: ' . ($fit === [] ? 'not matched to a product' : implode(', ', $fit));

        // The quote takes what is left of the note writer's room (RecordNote cuts at its maximum, and what it would cut is the LAST line: the fit).
        $room = RecordNote::MAX_LENGTH - mb_strlen(implode("\n", [$head, ...$tail])) - 60;
        if ($evidence['quote'] !== null) {
            $quote = mb_substr((string) $evidence['quote'], 0, max(0, min(self::QUOTE_MAX, $room)));
            $middle = $evidence['quote_kind'] === 'excerpt' ? 'Quote: "' . $quote . '"' : 'What the signal observed: ' . $quote;
        } else {
            $middle = 'Quote: none was saved with this signal.';
        }

        return implode("\n", [$head, $middle, ...$tail]);
    }

    /** The name of a Product Master row, or null (the table may not be on this host yet). */
    public static function productName(?int $productId): ?string
    {
        if ($productId === null || $productId <= 0 || ! Schema::hasTable('g2g_product_master')) {
            return null;
        }
        $name = ProductMaster::query()->whereKey($productId)->value('name');

        return $name === null ? null : (string) $name;
    }

    /** @return list<array<string, mixed>> */
    private static function sources(mixed $value): array
    {
        $list = is_string($value) ? json_decode($value, true) : $value;

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    private static function clean(mixed $value): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $text === '' ? null : $text;
    }

    private static function url(mixed $value): ?string
    {
        $url = self::clean($value);

        return $url !== null && preg_match('#^https?://#i', $url) === 1 ? mb_substr($url, 0, 1000) : null;
    }

    /** A date as Y-m-d, or null. A zero date and anything that is not a date is no date. */
    private static function date(mixed $value): ?string
    {
        $text = self::clean($value);
        if ($text === null || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
}
