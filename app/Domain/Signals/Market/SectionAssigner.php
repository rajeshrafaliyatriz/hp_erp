<?php

namespace App\Domain\Signals\Market;

/**
 * Assigns a signal to a feed section. Thresholds live in config('signals.card.sections'); there
 * are no magic numbers here.
 *
 *   CONTEXT  = names a company but no buyer (a competitor move, a market-level event). Never scored
 *              into the other two sections.
 *   ACT NOW  = evidence CONFIRMED or INFERENCE, and score_total >= act_now_min_score.
 *   WATCHING = score_total >= watching_min_score, or evidence HYPOTHESIS.
 *   (none)   = a non-hypothesis item scoring below watching_min_score: below the bar, not surfaced.
 *
 * Stored in the existing `feed_section` column, so no new vocabulary reaches the table:
 *   ACT NOW -> immediate_action, WATCHING -> watchlist,
 *   CONTEXT -> market_intelligence | competitor_intelligence.
 *
 * Scoreless records (imports that supply no scores) keep the long-standing rule instead of being
 * guessed a score: confirmed evidence read from the page or document, not yet expired, is
 * immediate_action; everything else is watchlist.
 *
 * Pure. Expiry is passed in; the API recomputes it at read time because a signal expires with the clock.
 */
final class SectionAssigner
{
    public const ACT_NOW = 'immediate_action';
    public const WATCHING = 'watchlist';
    public const MARKET = 'market_intelligence';
    public const COMPETITOR = 'competitor_intelligence';

    /**
     * @param  array{claim_level: string, fetch_level?: ?string, score_total?: ?int, context?: ?string}  $s
     * @return string|null a feed_section value, or null when below the bar
     */
    public function assign(array $s, ?int $daysLeft = null): ?string
    {
        if (($s['context'] ?? null) === 'competitor') {
            return self::COMPETITOR;
        }
        if (($s['context'] ?? null) === 'market') {
            return self::MARKET;
        }

        $claim = $s['claim_level'];
        $score = $s['score_total'] ?? null;
        $expired = $daysLeft !== null && $daysLeft < 0;

        if ($score === null) {
            return $claim === 'confirmed' && in_array($s['fetch_level'] ?? null, ['full_document', 'page_text'], true) && ! $expired
                ? self::ACT_NOW : self::WATCHING;
        }

        $cfg = (array) config('signals.card.sections');
        if (! $expired && $score >= (int) $cfg['act_now_min_score'] && in_array($claim, (array) $cfg['act_now_evidence'], true)) {
            return self::ACT_NOW;
        }
        if ($claim === 'hypothesis' || $score >= (int) $cfg['watching_min_score']) {
            return self::WATCHING;
        }

        return null;
    }
}
