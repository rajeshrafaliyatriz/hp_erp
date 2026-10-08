<?php

namespace App\Domain\Portfolio;

class MatchingEngine
{
    /**
     * Matches a signal to existing product offers and eligible partners.
     * 
     * @param mixed $signal
     * @param string $signalType 'signal' | 'opportunity' | 'ingestion_finding'
     * @param int|null $tenantId
     * @return array
     */
    public function matchForSignal($signal, string $signalType, ?int $tenantId): array
    {
        $text = $this->extractSignalText($signal);
        
        $evidence = [
            'identified_needs' => $this->extractNeeds($text),
            // From a structured import: the need codes (N01..N20) and buyer segment the record
            // carried. Absent on researched signals, which then score exactly as before.
            'need_codes' => $this->importedNeedCodes($signal),
            'buyer_segment' => $this->importedSegment($signal),
            'target_segments' => [],
            'trigger_signals' => $this->extractTriggers($text),
        ];

        $offers = ProductOffer::forTenant($tenantId)->get();
        $partners = Partner::forTenant($tenantId)->active()->get();

        $offerMatches = [];

        foreach ($offers as $offer) {
            $matchResult = $this->evaluateOffer($offer, $text, $evidence, $partners);
            
            // If the signal explicitly named this offer ID or Name in the generation step (relevant_offer_id)
            if (isset($signal->relevant_offer_id) && $signal->relevant_offer_id === $offer->offer_id) {
                $matchResult['match_score'] = max($matchResult['match_score'], 95);
                $matchResult['match_reasons'][] = "Signal generation explicitly matched this offer.";
            }

            // Also match if the text simply matches any keyword, or if it matched explicitly
            if ($matchResult['match_score'] >= 10 || count($matchResult['match_reasons']) > 0) {
                // Ensure a base score
                $matchResult['match_score'] = max($matchResult['match_score'], 15);
                $offerMatches[] = $matchResult;
            }
        }

        // Sort by match score descending
        usort($offerMatches, fn($a, $b) => $b['match_score'] <=> $a['match_score']);

        return [
            'evidence' => $evidence,
            'offer_matches' => $offerMatches,
        ];
    }

    private function extractSignalText($signal): string
    {
        $parts = [];
        if (isset($signal->title)) $parts[] = $signal->title;
        if (isset($signal->description)) $parts[] = $signal->description;
        if (isset($signal->observed_event)) $parts[] = $signal->observed_event;
        if (isset($signal->why_indicates_need)) $parts[] = $signal->why_indicates_need;
        if (isset($signal->product_fit)) $parts[] = $signal->product_fit;
        if (isset($signal->finding)) $parts[] = $signal->finding;
        if (isset($signal->business_impact)) $parts[] = $signal->business_impact;

        return implode(' ', $parts);
    }

    /** @return list<string> upper-case N-codes carried by a structured import, else [] */
    private function importedNeedCodes($signal): array
    {
        $codes = $signal->candidate_need_codes ?? [];
        if (is_string($codes)) {
            $codes = json_decode($codes, true) ?: [];
        }

        return array_values(array_unique(array_map(fn ($c) => strtoupper(trim((string) $c)), (array) $codes)));
    }

    private function importedSegment($signal): ?string
    {
        $segment = isset($signal->buyer_segment) ? strtoupper(trim((string) $signal->buyer_segment)) : '';

        return $segment === '' ? null : $segment;
    }

    private function extractNeeds(string $text): array
    {
        // Simple extraction for demonstration of evidence
        $needs = [];
        $keywords = ['efficiency', 'automation', 'modernization', 'compliance', 'security', 'digital transformation', 'AI'];
        foreach ($keywords as $kw) {
            if (stripos($text, $kw) !== false) {
                $needs[] = $kw;
            }
        }
        return $needs;
    }

    private function extractTriggers(string $text): array
    {
        $triggers = [];
        $keywords = ['expansion', 'rfp', 'tender', 'partnership', 'merger', 'acquisition', 'festival', 'conference'];
        foreach ($keywords as $kw) {
            if (stripos($text, $kw) !== false) {
                $triggers[] = $kw;
            }
        }
        return $triggers;
    }

    private function evaluateOffer(ProductOffer $offer, string $text, array $evidence, $partners): array
    {
        $score = 0;
        $matchedNeeds = [];
        $matchReasons = [];
        
        $offerNeeds = $offer->needs_solved ?? [];
        foreach ($offerNeeds as $need) {
            if (stripos($text, $need) !== false || in_array($need, $evidence['identified_needs'])) {
                if (!in_array($need, $matchedNeeds)) {
                    $matchedNeeds[] = $need;
                    $score += 30;
                    $matchReasons[] = "Signal text indicates need for: {$need}";
                }
            }
        }
        
        $offerTriggers = $offer->trigger_signals ?? [];
        foreach ($offerTriggers as $trigger) {
            if (stripos($text, $trigger) !== false || in_array($trigger, $evidence['trigger_signals'])) {
                $score += 20;
                $matchReasons[] = "Signal mentions trigger event: {$trigger}";
            }
        }

        if (stripos($text, (string)$offer->name) !== false) {
            $score += 40;
            $matchReasons[] = "Direct mention of product name: {$offer->name}";
        }
        if (stripos($text, (string)$offer->offer_id) !== false) {
            $score += 50;
            $matchReasons[] = "Direct mention of product ID: {$offer->offer_id}";
        }

        // Structured import: a need code the record carries that this offer solves is a precise
        // match, so it adds a boost on top of the keyword score (config signals.matching.*).
        $matchedCodes = [];
        foreach ($offerNeeds as $need) {
            if (in_array(strtoupper((string) $need), $evidence['need_codes'], true) && ! in_array($need, $matchedCodes, true)) {
                $matchedCodes[] = $need;
                $score += (int) config('signals.matching.code_boost', 25);
                $matchReasons[] = "Import carries need code {$need}, which this offer solves";
            }
        }

        $matchedSegments = [];
        if ($evidence['buyer_segment'] !== null) {
            foreach ((array) ($offer->primary_segments ?? []) as $segment) {
                if (strtoupper((string) $segment) === $evidence['buyer_segment']) {
                    $matchedSegments[] = $segment;
                    $score += (int) config('signals.matching.segment_bonus', 15); // once, however many segments match
                    $matchReasons[] = "Buyer segment {$segment} is one of this offer's primary segments";
                    break;
                }
            }
        }

        // Add some score if no direct match but generic tech
        if (empty($matchReasons) && stripos($text, 'technology') !== false) {
            $score += 5; // very weak match
        }

        // Cap score at 100
        $score = min(100, $score);

        $partnerEvals = [];
        if ($offer->partner_sellable) {
            foreach ($partners as $partner) {
                $partnerEvals[] = $this->evaluatePartner($partner, $offer, $text);
            }
        }

        usort($partnerEvals, function ($a, $b) {
            $rankA = $a['eligibility_status'] === 'eligible' ? 2 : ($a['eligibility_status'] === 'needs_review' ? 1 : 0);
            $rankB = $b['eligibility_status'] === 'eligible' ? 2 : ($b['eligibility_status'] === 'needs_review' ? 1 : 0);
            return $rankB <=> $rankA;
        });

        return [
            'offer' => $offer,
            // Readiness is reported, never scored: a strong match to an unverified offer must stay
            // visible and clearly labelled rather than silently ranking lower.
            'match_score' => $score,
            'matched_needs' => $matchedNeeds,
            'matched_need_codes' => $matchedCodes,
            'matched_segments' => $matchedSegments,
            'readiness_status' => $offer->readiness_status,
            'readiness_confirmed' => (bool) $offer->readiness_confirmed,
            'is_deliverable' => (bool) $offer->readiness_confirmed,
            'match_reasons' => array_unique($matchReasons),
            'satisfied_criteria' => $matchedNeeds,
            'missing_criteria' => [],
            'partner_evaluations' => $partnerEvals,
        ];
    }

    private function evaluatePartner(Partner $partner, ProductOffer $offer, string $text): array
    {
        $rules = [];
        $isEligible = true;
        
        // 1. Authorization
        $auth = is_array($partner->offers_authorized) && in_array($offer->offer_id, $partner->offers_authorized);
        $rules['authorization'] = [
            'passed' => $auth,
            'detail' => $auth ? 'Authorized to sell this offer' : 'Not authorized for this specific offer'
        ];
        if (!$auth) $isEligible = false;

        // 2. Capacity
        $hasCapacity = $partner->capacity_available > 0;
        $rules['capacity'] = [
            'passed' => $hasCapacity,
            'detail' => $hasCapacity ? "Has available capacity ({$partner->capacity_available} slots)" : 'No active deal capacity available'
        ];
        if (!$hasCapacity) $isEligible = false;

        // 3. Segment
        $hasSegment = !empty($partner->segments_covered);
        $rules['segment'] = [
            'passed' => $hasSegment,
            'detail' => $hasSegment ? implode(', ', $partner->segments_covered) : 'No segment defined'
        ];

        // 4. Geography
        $hasGeo = !empty($partner->states_covered);
        $rules['geography'] = [
            'passed' => $hasGeo,
            'detail' => $hasGeo ? implode(', ', $partner->states_covered) : 'No states covered defined'
        ];

        // 5. Empanelment
        $rules['empanelment'] = [
            'passed' => true,
            'detail' => !empty($partner->empanelments) ? implode(', ', $partner->empanelments) : 'No empanelment restrictions'
        ];

        // 6. Conflict
        $rules['conflict'] = [
            'passed' => $partner->deal_registration_agreed,
            'detail' => $partner->deal_registration_agreed ? 'Deal registration agreed' : 'Missing deal registration agreement'
        ];

        // 7. Status
        $rules['status'] = [
            'passed' => $partner->partner_status === 'Active',
            'detail' => "Partner status is {$partner->partner_status}"
        ];

        return [
            'partner' => $partner,
            'eligibility_status' => $isEligible ? 'eligible' : 'ineligible',
            'rule_evaluations' => $rules
        ];
    }
}
