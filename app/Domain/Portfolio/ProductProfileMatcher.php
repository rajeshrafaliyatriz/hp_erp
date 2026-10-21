<?php

namespace App\Domain\Portfolio;

use App\Domain\Signals\Support\TextTokens;

/**
 * Reads one product's WHOLE stored profile against one signal, and says exactly what lines up. It works for any kind of product: nothing in it is
 * specific to schools or to any product line. What it reads (every field is optional; a missing one simply adds nothing):
 *
 *   product:  name, description (what_it_does), features (modules_components), proof, the customer problems it solves (codes, or a person's own
 *             free text), who it is for (segments, codes or free text) and the buying events it responds to (signal families, older codes, or free text)
 *   signal:   its title, what happened, why it indicates a need, the likely problem, what could be sold, the trigger summary and type, and the buyer
 *             type or segment
 *
 * Deterministic: shared distinctive words and equal families, never a guess. Every point comes with the words behind it. This ADDS to the engine's
 * existing scoring (need and trigger keywords, product name, imported codes); it never lowers it.
 */
final class ProductProfileMatcher
{
    public const MAX_POINTS = 75;

    /**
     * @param  object  $signal  a company opportunity (or any record with the same fields)
     * @return array{points: int, reasons: list<string>, needs: list<string>, segments: list<string>, events: list<string>}
     */
    public function evaluate(ProductOffer $offer, object $signal): array
    {
        $reasons = [];
        $points = 0;

        $signalText = $this->signalText($signal);
        $signalWords = TextTokens::of($signalText);

        // 1. Problems and capabilities: the product's own words against the signal's.
        $fields = $this->capabilityFields($offer);
        $needWords = [];
        $where = [];
        foreach ($fields as $label => $text) {
            $shared = TextTokens::shared($signalWords, TextTokens::of($text));
            if ($shared !== []) {
                $needWords = array_merge($needWords, $shared);
                $where[$label] = $shared;
            }
        }
        $needWords = array_values(array_unique($needWords));
        if (count($needWords) >= 2) {
            // Two shared distinctive words is the least that counts; three is a real overlap (enough to be stored as a match); more adds up to the cap.
            $points += min(40, 10 * (count($needWords) - 1) + 10);
            $reasons[] = 'The signal and this product both speak about: ' . implode(', ', array_slice($needWords, 0, 8)) . ' (in the product\'s ' . implode(', ', array_keys($where)) . ').';
        } else {
            $needWords = [];
        }

        // 2. Who it is for: the buyer's type or segment against the product's target customers.
        $segmentWords = [];
        $buyer = TextTokens::of(trim(((string) ($signal->buyer_segment ?? '')) . ' ' . ((string) ($signal->company_type ?? '')) . ' ' . ((string) ($signal->buyer_type ?? ''))));
        foreach ($this->segmentLabels($offer) as $label) {
            $shared = TextTokens::shared($buyer, TextTokens::of($label));
            if ($shared !== []) {
                $segmentWords = array_merge($segmentWords, $shared);
            }
        }
        $segmentWords = array_values(array_unique($segmentWords));
        if ($segmentWords !== []) {
            $points += 12;
            $reasons[] = 'The buyer fits a customer this product is for (' . implode(', ', array_slice($segmentWords, 0, 5)) . ').';
        }

        // 3. The buying event: the signal's trigger against the families (or events) the product responds to.
        $events = [];
        $type = strtolower(trim((string) ($signal->trigger_type ?? '')));
        $family = TaxonomyService::familyForTrigger($type);
        foreach ($this->eventEntries($offer) as $e) {
            $same = $type !== '' && ($type === $e['code'] || $type === strtolower($e['label']) || ($family !== null && $family === $e['code']));
            $shared = TextTokens::shared(TextTokens::of($signalText . ' ' . $type), $e['words']);
            if ($same || count($shared) >= 2) {
                $events[] = $e['label'];
            }
        }
        $events = array_values(array_unique($events));
        if ($events !== []) {
            $points += 20;
            $reasons[] = 'The event matches what this product responds to: ' . implode(', ', array_slice($events, 0, 3)) . '.';
        }

        return ['points' => min(self::MAX_POINTS, $points), 'reasons' => $reasons, 'needs' => $needWords, 'segments' => $segmentWords, 'events' => $events];
    }

    private function signalText(object $s): string
    {
        $parts = [];
        foreach (['title', 'observed_event', 'why_indicates_need', 'likely_problem', 'what_we_could_sell', 'trigger_summary', 'product_fit', 'recommended_action', 'finding', 'description', 'business_impact'] as $f) {
            if (isset($s->{$f}) && is_string($s->{$f})) {
                $parts[] = $s->{$f};
            }
        }

        return implode(' ', $parts);
    }

    /** @return array<string, string> */
    private function capabilityFields(ProductOffer $o): array
    {
        $out = [];
        foreach (['name' => 'name', 'what_it_does' => 'description', 'modules_components' => 'features', 'proof_points' => 'proof'] as $f => $label) {
            if (! empty($o->{$f})) {
                $out[$label] = (string) $o->{$f};
            }
        }
        $needs = array_map(fn ($n) => TaxonomyService::getNeedLabel((string) $n), array_values(array_filter((array) ($o->needs_solved ?? []), 'is_string')));
        if ($needs !== []) {
            $out['customer problems'] = implode('. ', $needs);
        }

        return $out;
    }

    /** @return list<string> */
    private function segmentLabels(ProductOffer $o): array
    {
        return array_map(fn ($c) => TaxonomyService::getSegmentLabel((string) $c), array_values(array_filter((array) ($o->primary_segments ?? []), 'is_string')));
    }

    /** @return list<array{code: string, label: string, words: list<string>}> */
    private function eventEntries(ProductOffer $o): array
    {
        $out = [];
        foreach (array_values(array_filter((array) ($o->trigger_signals ?? []), 'is_string')) as $code) {
            $family = TaxonomyService::signalFamilies()[$code] ?? null;
            $label = $family['label'] ?? TaxonomyService::getSignalLabel($code);
            $text = $family ? ($family['label'] . ' ' . $family['examples']) : $label;
            $out[] = ['code' => strtolower($code), 'label' => $label, 'words' => TextTokens::of($text)];
        }

        return $out;
    }
}
