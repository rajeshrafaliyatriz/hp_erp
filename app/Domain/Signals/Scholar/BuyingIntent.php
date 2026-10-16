<?php

namespace App\Domain\Signals\Scholar;

use App\Domain\Signals\Support\StructuredAi;

/**
 * BUYING INTENT: is there evidence the school is moving toward acquiring or implementing a solution? Decided in code.
 *
 * The model reports indicators, each with a verbatim quote. Only an indicator whose quote is found in a cited source counts, and the
 * level follows from those alone (config/sales_pull.php `buying_intent`):
 *
 *   explicit     at least one PURCHASE indicator (open tender, vendor selection, demo/proposal request, procurement notice)
 *   strong       two or more distinct indicators, at least one a PROJECT indicator
 *   moderate     one PROJECT indicator (a documented project, hiring for a system role, a stated problem)
 *   weak         only CONTEXT indicators (expansion, new leader, academic hiring)
 *   unconfirmed  no verified indicator
 *
 * Sales pull (motivation) never changes the level. Expansion, hiring in general or "digital transformation" never reach `explicit`.
 */
final class BuyingIntent
{
    public const LEVELS = ['unconfirmed' => 0, 'weak' => 1, 'moderate' => 2, 'strong' => 3, 'explicit' => 4];

    private const TENDERS = ['tender', 'rfp', 'eoi', 'rfq'];

    /**
     * @param  mixed  $raw  the model's "intent_indicators": [{indicator, quote}]
     * @param  list<string>  $citedNormalised  normalised text of the cited sources
     * @return array{level: string, indicators: list<array{indicator: string, tier: string, quote: string}>}
     */
    public static function assess(mixed $raw, array $citedNormalised, ?string $triggerType = null, ?string $mainQuote = null): array
    {
        $known = (array) config('sales_pull.buying_intent.indicators', []);
        $found = [];

        foreach ((array) (is_array($raw) ? $raw : []) as $item) {
            $key = is_array($item) && is_string($item['indicator'] ?? null) ? strtolower(trim($item['indicator'])) : '';
            $quote = is_array($item) && is_string($item['quote'] ?? null) ? trim($item['quote']) : '';
            if (isset($known[$key]) && ! isset($found[$key]) && self::verified($quote, $citedNormalised)) {
                $found[$key] = ['indicator' => $key, 'tier' => (string) $known[$key]['tier'], 'quote' => mb_substr($quote, 0, 300)];
            }
        }

        // The signal's own verified quote is the tender notice when the trigger IS a tender: no second quote is needed.
        if (in_array($triggerType, self::TENDERS, true) && ! isset($found['open_tender']) && $mainQuote !== null && self::verified($mainQuote, $citedNormalised)) {
            $found['open_tender'] = ['indicator' => 'open_tender', 'tier' => 'purchase', 'quote' => mb_substr($mainQuote, 0, 300)];
        }

        $tiers = array_count_values(array_column($found, 'tier'));
        $level = match (true) {
            ($tiers['purchase'] ?? 0) >= 1 => 'explicit',
            count($found) >= 2 && ($tiers['project'] ?? 0) >= 1 => 'strong',
            ($tiers['project'] ?? 0) >= 1 => 'moderate',
            $found !== [] => 'weak',
            default => 'unconfirmed',
        };

        return ['level' => $level, 'indicators' => array_values($found)];
    }

    /** @param list<string> $citedNormalised */
    private static function verified(string $quote, array $citedNormalised): bool
    {
        if (mb_strlen($quote) < 15) {
            return false;
        }
        $needle = StructuredAi::normalise($quote);
        foreach ($citedNormalised as $text) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function atLeast(string $level, string $minimum): bool
    {
        return (self::LEVELS[$level] ?? 0) >= (self::LEVELS[$minimum] ?? 0);
    }

    /** The coarse intent SignalStrength reads: direct (the school is moving), likely (an event that often leads to a purchase), or background. */
    public static function toIntent(string $level): string
    {
        $hot = (string) config('sales_pull.qualification.min_intent_for_hot', 'strong');

        return match (true) {
            self::atLeast($level, $hot) => 'direct_need',
            self::atLeast($level, 'weak') => 'likely_need',
            default => 'context',
        };
    }
}
