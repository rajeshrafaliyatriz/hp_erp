<?php

namespace App\Domain\Signals\Market;

/**
 * How well one opportunity fits the product it was found for, from facts the system verified, never from a score the AI gave itself.
 *
 * Six components, each judged by plain rules from what is stored on the candidate (the AI's own scores are not an input here):
 *   product    the offering named is a real stored part of the product (and, for a child product or a sub-product run, is backed by the evidence)
 *   icp        the buyer is of a type the selected ICP targets and the trigger is one the ICP lists
 *   geography  the source names a requested place (or, with no region asked for, where the ICP aims)
 *   evidence   the quote was found in a page that was read, not only a snippet; more than one source is better
 *   intent     the buying intent decided from verified indicators
 *   freshness  how recent the stated date is, or how long an open tender has left
 *
 * The total is 0 to 100. Pure: no database, no clock except the `now` it is given.
 */
final class OpportunityFit
{
    public const WEIGHTS = ['product' => 20, 'icp' => 15, 'geography' => 10, 'evidence' => 25, 'intent' => 20, 'freshness' => 10];

    private const INTENT = ['explicit' => 1.0, 'strong' => 0.75, 'moderate' => 0.45, 'weak' => 0.2, 'unconfirmed' => 0.0];

    /**
     * @param  array{
     *     part_known: bool, child_supported: ?bool, sub_product_run: bool,
     *     buyer_type: ?string, icp_buyer_types: list<string>, trigger_type: ?string, icp_triggers: list<string>,
     *     region_requested: bool, region_status: ?string, location_in_icp_regions: ?bool,
     *     page_read: bool, quote_verified: bool, source_domains: int,
     *     intent_level: string,
     *     age_days: ?int, tender_days_left: ?int,
     * }  $in
     * @return array{total: int, components: array<string, array{score: int, max: int, why: string}>, version: int}
     */
    public static function score(array $in): array
    {
        $c = [];

        // product
        $p = 0;
        $why = [];
        if ($in['part_known']) {
            $p += 12;
            $why[] = 'a stored part of the product';
        }
        if ($in['child_supported'] === true) {
            $p += 8;
            $why[] = 'the evidence supports the offering';
        } elseif ($in['child_supported'] === null && $in['part_known']) {
            $p += 4;   // a general part has no stored problem to check against: partial credit
        }
        if ($in['sub_product_run'] && $in['part_known']) {
            $p = min(self::WEIGHTS['product'], $p + 2);
        }
        $c['product'] = self::part($p, 'product', $why ?: ['no stored part of the product named']);

        // icp
        $i = 0;
        $why = [];
        if ($in['icp_buyer_types'] === [] ) {
            $i += 5;   // the ICP names no buyer types: nothing to contradict, nothing confirmed
        } elseif ($in['buyer_type'] !== null && in_array($in['buyer_type'], $in['icp_buyer_types'], true)) {
            $i += 10;
            $why[] = 'a buyer type the ICP targets';
        } else {
            $why[] = 'buyer type not confirmed against the ICP';
        }
        if ($in['trigger_type'] !== null && in_array($in['trigger_type'], $in['icp_triggers'], true)) {
            $i += 5;
            $why[] = 'a trigger the ICP lists';
        }
        $c['icp'] = self::part($i, 'icp', $why ?: ['not confirmed against the ICP']);

        // geography
        if ($in['region_requested']) {
            $g = match ($in['region_status']) {
                'confirmed' => 10,
                'unconfirmed' => 4,
                default => 0,
            };
            $why = [$g === 10 ? 'the source names the requested place' : ($g === 4 ? 'the requested place is not named in the source' : 'outside the requested place')];
        } else {
            $g = $in['location_in_icp_regions'] === true ? 8 : ($in['location_in_icp_regions'] === false ? 3 : 5);
            $why = [$in['location_in_icp_regions'] === true ? 'inside the places the ICP aims at' : ($in['location_in_icp_regions'] === false ? 'outside the places the ICP aims at' : 'no place stated')];
        }
        $c['geography'] = self::part($g, 'geography', $why);

        // evidence
        $e = $in['quote_verified'] ? 18 : ($in['page_read'] ? 12 : 6);
        $why = [$in['quote_verified'] ? 'the quote was found in the page that was read' : ($in['page_read'] ? 'the page was read' : 'only a search snippet was read')];
        if ($in['source_domains'] >= 2) {
            $e += 7;
            $why[] = 'more than one source reports it';
        } elseif ($in['source_domains'] === 1) {
            $e += 2;
        }
        $c['evidence'] = self::part($e, 'evidence', $why);

        // intent
        $factor = self::INTENT[$in['intent_level']] ?? 0.0;
        $c['intent'] = self::part((int) round($factor * self::WEIGHTS['intent']), 'intent', ['buying intent: ' . $in['intent_level']]);

        // freshness
        if ($in['tender_days_left'] !== null) {
            $f = $in['tender_days_left'] >= 14 ? 10 : ($in['tender_days_left'] >= 0 ? 7 : 0);
            $why = ['tender open for ' . max(0, $in['tender_days_left']) . ' more days'];
        } elseif ($in['age_days'] === null) {
            $f = 2;
            $why = ['no stated date: how recent this is is unknown'];
        } else {
            $f = $in['age_days'] <= 7 ? 10 : ($in['age_days'] <= 30 ? 8 : ($in['age_days'] <= 60 ? 5 : ($in['age_days'] <= 120 ? 2 : 0)));
            $why = ["{$in['age_days']} days old"];
        }
        $c['freshness'] = self::part($f, 'freshness', $why);

        return ['total' => (int) array_sum(array_column($c, 'score')), 'components' => $c, 'version' => 1];
    }

    /** The priority band the fit allows: a model's or a rule's higher band is capped to this. */
    public static function band(int $total): string
    {
        return $total >= 65 ? 'High' : ($total >= 40 ? 'Medium' : 'Low');
    }

    /** @param list<string> $why @return array{score: int, max: int, why: string} */
    private static function part(int $score, string $name, array $why): array
    {
        $max = self::WEIGHTS[$name];

        return ['score' => max(0, min($max, $score)), 'max' => $max, 'why' => implode('; ', $why)];
    }
}
