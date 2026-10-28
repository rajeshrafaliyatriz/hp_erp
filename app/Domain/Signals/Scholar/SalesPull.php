<?php

namespace App\Domain\Signals\Scholar;

use App\Domain\Signals\Support\StructuredAi;

/**
 * SALES PULL: why might this school VALUE ScholarClone? A layer inside the signal pipeline, never a source of signals.
 *
 * It is motivation, not demand. A motivation never makes a candidate a signal and never raises buying intent; it only explains,
 * in the buyer's terms, why the need matters. The model proposes; this class keeps only what is well formed and, where it claims
 * the source shows the motivation directly ("observed"), only what a verbatim quote in a cited source supports. Everything else is
 * kept as "inferred" and shown as such.
 *
 * The taxonomy is config/sales_pull.php (36 categories). Pure apart from reading config.
 */
final class SalesPull
{
    public const OBSERVED = 'observed';
    public const INFERRED = 'inferred';

    /** @return array<string, array<string, mixed>> */
    public static function categories(): array
    {
        return (array) config('sales_pull.categories', []);
    }

    /** The taxonomy as instructions for the model: the key, then what it means and how it shows up in a school. */
    public static function promptBlock(): string
    {
        $lines = [];
        foreach (self::categories() as $key => $c) {
            $lines[] = "  - {$key} ({$c['label']}): {$c['definition']} For example: {$c['examples']}." . (! empty($c['pressure_risk']) ? ' [Describe the need only; never use it as pressure.]' : '');
        }

        return implode("\n", $lines);
    }

    /**
     * @param  mixed  $raw  the model's "sales_pull" list
     * @param  list<string>  $citedNormalised  normalised text of the sources the signal cites
     * @return list<array{category: string, label: string, basis: string, rationale: string, quote: ?string}>
     */
    public static function validate(mixed $raw, array $citedNormalised): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $max = max(1, (int) config('sales_pull.max_per_signal', 3));
        $out = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = is_string($item['category'] ?? null) ? strtolower(trim($item['category'])) : '';
            $rationale = is_string($item['rationale'] ?? null) ? trim(preg_replace('/\s+/', ' ', $item['rationale']) ?? '') : '';
            if (! isset(self::categories()[$key]) || isset($out[$key]) || mb_strlen($rationale) < 15) {
                continue;   // unknown label, repeated, or no real reasoning
            }

            $quote = is_string($item['quote'] ?? null) ? trim($item['quote']) : '';
            $supported = false;
            if (mb_strlen($quote) >= 15) {
                $needle = StructuredAi::normalise($quote);
                foreach ($citedNormalised as $text) {
                    if (str_contains($text, $needle)) {
                        $supported = true;
                        break;
                    }
                }
            }
            // "Observed" only when the source's own words back it. Anything else is our reading, and says so.
            $basis = ($item['basis'] ?? null) === self::OBSERVED && $supported ? self::OBSERVED : self::INFERRED;

            $out[$key] = [
                'category' => $key, 'label' => (string) self::categories()[$key]['label'], 'basis' => $basis,
                'rationale' => mb_substr($rationale, 0, 300), 'quote' => $supported ? mb_substr($quote, 0, 300) : null,
            ];
            if (count($out) >= $max) {
                break;
            }
        }

        return array_values($out);
    }
}
