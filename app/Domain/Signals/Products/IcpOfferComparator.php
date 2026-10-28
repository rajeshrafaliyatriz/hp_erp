<?php

namespace App\Domain\Signals\Products;

use App\Domain\Portfolio\TaxonomyService;

/**
 * Compares one portfolio product (an "offer") with an ICP's actual requirements. Deterministic and explainable: no AI, no randomness, no outside
 * data. Every number comes from words that are present in the two stored records, and every conclusion lists the words and the field it used.
 *
 * Three dimensions, each scored 0-100 only when BOTH sides hold the data it needs (otherwise it is "insufficient data" and takes no part):
 *
 *   segment_fit       (weight 30)  the share of the ICP's buyer types that the product says it serves (its primary segments)
 *   need_coverage     (weight 40)  the share of the ICP's requirements (each part it sells, each buying event's pain) that the product's own
 *                                  description, modules, needs solved and proof points speak to
 *   trigger_alignment (weight 20)  the share of the ICP's buying events that match a signal the product says it responds to
 *
 * The overall score is the weighted mean of the dimensions that have data, and is only given when need_coverage and at least one other dimension
 * have data. Otherwise the result is "insufficient data" and no score is shown. Target-market geography is reported, but a product record holds no
 * region, so it is never scored. Readiness and pricing are shown as facts and are not part of the score.
 */
final class IcpOfferComparator
{
    public const METHOD = 'Weighted word-overlap between the ICP and the product record. Segment fit 30%, need coverage 40%, trigger alignment 20%; only dimensions with data on both sides count, and a score needs need coverage plus one more dimension. No AI is used.';

    private const WEIGHTS = ['segment_fit' => 30, 'need_coverage' => 40, 'trigger_alignment' => 20];

    /** Words too common to show that two texts are about the same thing. */
    private const GENERIC = ['school', 'schools', 'system', 'systems', 'platform', 'management', 'manager', 'data', 'new', 'need', 'needs', 'from', 'with', 'that', 'this', 'their', 'have', 'has', 'for', 'and', 'the', 'are', 'its', 'one', 'day', 'into', 'across', 'which', 'when', 'where', 'while', 'using', 'used', 'use', 'run', 'running', 'all', 'can', 'will', 'also', 'more', 'than', 'our', 'your', 'who', 'what', 'about', 'over', 'such', 'each', 'any', 'may', 'been', 'being', 'were', 'was'];

    /**
     * @param  array<string, mixed>  $icp    the ICP's settings (industry_label, buyer_types, regions, parts, triggers)
     * @param  array<string, mixed>  $offer  a stored product record
     * @return array<string, mixed>
     */
    public function compare(array $icp, array $offer): array
    {
        $dims = [
            $this->segmentFit($icp, $offer),
            $this->needCoverage($icp, $offer),
            $this->triggerAlignment($icp, $offer),
        ];

        $have = array_values(array_filter($dims, fn ($d) => $d['score'] !== null));
        $needs = collect($dims)->firstWhere('key', 'need_coverage');
        $scored = $needs['score'] !== null && count($have) >= 2;
        $score = null;
        if ($scored) {
            $w = array_sum(array_column($have, 'weight'));
            $score = (int) round(array_sum(array_map(fn ($d) => $d['score'] * $d['weight'], $have)) / $w);
        }

        $gaps = [];
        foreach ($dims as $d) {
            foreach ($d['missing'] as $m) {
                $gaps[] = ['dimension' => $d['key'], 'requirement' => $m];
            }
        }

        return [
            'offer' => ['id' => $offer['id'] ?? null, 'offer_id' => $offer['offer_id'] ?? null, 'name' => $offer['name'] ?? null, 'parent_product' => $offer['parent_product'] ?? null],
            'status' => $scored ? 'scored' : 'insufficient_data',
            'score' => $score,
            'summary' => $scored ? $this->summary($score, $dims) : 'Insufficient data to score this product against the ICP: ' . $this->whatIsMissing($dims),
            'method' => self::METHOD,
            'ai_used' => false,
            'dimensions' => $dims,
            'gaps' => $gaps,
            'market' => $this->market($icp, $offer),
            'facts' => [
                'readiness_status' => $offer['readiness_status'] ?? null,
                'readiness_confirmed' => (bool) ($offer['readiness_confirmed'] ?? false),
                'pricing_model' => ($offer['pricing_model'] ?? null) ?: null,
                'typical_deal_band' => ($offer['typical_deal_band'] ?? null) ?: null,
            ],
            'recommendations' => $this->recommendations($icp, $offer, $dims),
        ];
    }

    // ── segment fit ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function segmentFit(array $icp, array $offer): array
    {
        $buyers = array_values(array_filter((array) ($icp['buyer_types'] ?? []), 'is_string'));
        $codes = array_values(array_filter((array) ($offer['primary_segments'] ?? []), 'is_string'));
        $base = $this->dim('segment_fit', 'Customer segment fit', 'The ICP\'s buyer types against the segments the product says it serves.');
        if ($buyers === [] || $codes === []) {
            return $base + ['score' => null, 'status' => 'insufficient_data', 'matched' => [], 'missing' => [], 'evidence' => [],
                'note' => $buyers === [] ? 'The ICP lists no buyer types.' : 'The product record lists no primary segments.'];
        }

        $segments = [];
        foreach ($codes as $c) {
            $label = TaxonomyService::getSegmentLabel($c);
            $segments[] = ['code' => $c, 'label' => $label, 'tokens' => $this->tokens($c . ' ' . $label, false)];
        }

        $matched = [];
        $missing = [];
        $evidence = [];
        foreach ($buyers as $b) {
            $bt = $this->tokens($b, false);
            $hit = null;
            foreach ($segments as $s) {
                $common = array_values(array_intersect($bt, $s['tokens']));
                if ($common !== []) {
                    $hit = ['segment' => $s['label'], 'words' => $common];
                    break;
                }
            }
            if ($hit) {
                $matched[] = $b;
                $evidence[] = "ICP buyer type '{$b}' matches the product's segment '{$hit['segment']}' (shared: " . implode(', ', $hit['words']) . ').';
            } else {
                $missing[] = "Buyer type '{$b}' is not among the segments the product serves.";
            }
        }

        return $base + ['score' => (int) round(100 * count($matched) / count($buyers)), 'status' => 'scored', 'matched' => $matched, 'missing' => $missing, 'evidence' => $evidence,
            'note' => count($matched) . ' of ' . count($buyers) . ' buyer types are served.'];
    }

    // ── need / capability coverage ───────────────────────────────────────────

    /** @return array<string, mixed> */
    private function needCoverage(array $icp, array $offer): array
    {
        $base = $this->dim('need_coverage', 'Customer needs and product capabilities', 'What the ICP says it needs, against what the product record says it does.');
        $requirements = $this->requirements($icp);

        $corpus = [];
        foreach (['name' => 'name', 'what_it_does' => 'description', 'modules_components' => 'modules', 'proof_points' => 'proof points', 'notes' => 'notes'] as $field => $label) {
            if (! empty($offer[$field])) {
                $corpus[$label] = (string) $offer[$field];
            }
        }
        $needLabels = array_map(fn ($c) => TaxonomyService::getNeedLabel((string) $c), array_values(array_filter((array) ($offer['needs_solved'] ?? []), 'is_string')));
        if ($needLabels !== []) {
            $corpus['needs solved'] = implode('; ', $needLabels);
        }
        $capabilityText = trim(implode(' ', array_diff_key($corpus, ['name' => 1, 'notes' => 1])));

        if ($requirements === [] || mb_strlen($capabilityText) < 20) {
            return $base + ['score' => null, 'status' => 'insufficient_data', 'matched' => [], 'missing' => [], 'evidence' => [],
                'note' => $requirements === [] ? 'The ICP lists no parts or buying events to compare.' : 'The product record has too little description (what it does, modules, needs solved) to compare.'];
        }

        $fieldTokens = array_map(fn ($t) => $this->tokens($t, true), $corpus);
        $matched = [];
        $missing = [];
        $evidence = [];
        foreach ($requirements as $r) {
            $rt = $this->tokens($r['text'], true);
            $best = null;
            foreach ($fieldTokens as $label => $ft) {
                $common = array_values(array_intersect($rt, $ft));
                if (count($common) >= min(2, max(1, count($rt))) && ($best === null || count($common) > count($best['words']))) {
                    $best = ['field' => $label, 'words' => $common];
                }
            }
            if ($rt === []) {
                continue;   // nothing specific to compare in this requirement
            }
            if ($best) {
                $matched[] = $r['text'];
                $evidence[] = "{$r['kind']} '{$r['text']}' is covered by the product's {$best['field']} (shared: " . implode(', ', $best['words']) . ').';
            } else {
                $missing[] = "{$r['kind']} '{$r['text']}' is not covered by anything in the product record.";
            }
        }
        $total = count($matched) + count($missing);
        if ($total === 0) {
            return $base + ['score' => null, 'status' => 'insufficient_data', 'matched' => [], 'missing' => [], 'evidence' => [], 'note' => 'The ICP requirements hold no specific words to compare.'];
        }

        return $base + ['score' => (int) round(100 * count($matched) / $total), 'status' => 'scored', 'matched' => $matched, 'missing' => $missing, 'evidence' => $evidence,
            'note' => count($matched) . ' of ' . $total . ' ICP requirements are covered.'];
    }

    /** @return list<array{kind: string, text: string}> what the ICP says it needs */
    private function requirements(array $icp): array
    {
        $out = [];
        foreach ((array) ($icp['parts'] ?? []) as $k => $what) {
            $text = trim(is_string($what) ? $what : (string) $k);
            if ($text !== '') {
                $out[] = ['kind' => 'Part the ICP sells', 'text' => $text];
            }
        }
        foreach ((array) ($icp['triggers'] ?? []) as $k => $t) {
            $pain = is_array($t) ? trim((string) ($t['pain'] ?? '')) : '';
            if ($pain !== '') {
                $out[] = ['kind' => 'Need behind buying event ' . str_replace('_', ' ', (string) $k), 'text' => $pain];
            }
        }

        return $out;
    }

    // ── trigger alignment ────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function triggerAlignment(array $icp, array $offer): array
    {
        $base = $this->dim('trigger_alignment', 'Buying events', 'The ICP\'s buying events against the signals the product says it responds to.');
        $triggers = (array) ($icp['triggers'] ?? []);
        $signals = array_values(array_filter((array) ($offer['trigger_signals'] ?? []), 'is_string'));
        if ($triggers === [] || $signals === []) {
            return $base + ['score' => null, 'status' => 'insufficient_data', 'matched' => [], 'missing' => [], 'evidence' => [],
                'note' => $triggers === [] ? 'The ICP lists no buying events.' : 'The product record lists no trigger signals.'];
        }

        $sig = [];
        foreach ($signals as $s) {
            $label = TaxonomyService::getSignalLabel($s);
            $sig[] = ['label' => $label, 'tokens' => $this->tokens($s . ' ' . $label, true)];
        }
        $matched = [];
        $missing = [];
        $evidence = [];
        foreach ($triggers as $k => $t) {
            $text = str_replace('_', ' ', (string) $k) . ' ' . (is_array($t) ? ((string) ($t['pain'] ?? '')) . ' ' . implode(' ', array_map('strval', (array) ($t['phrases'] ?? []))) : '');
            $tt = $this->tokens($text, true);
            $hit = null;
            foreach ($sig as $s) {
                $common = array_values(array_intersect($tt, $s['tokens']));
                if ($common !== []) {
                    $hit = ['label' => $s['label'], 'words' => $common];
                    break;
                }
            }
            $name = str_replace('_', ' ', (string) $k);
            if ($hit) {
                $matched[] = $name;
                $evidence[] = "Buying event '{$name}' matches the product signal '{$hit['label']}' (shared: " . implode(', ', $hit['words']) . ').';
            } else {
                $missing[] = "Buying event '{$name}' matches none of the product's trigger signals.";
            }
        }
        $total = count($matched) + count($missing);

        return $base + ['score' => (int) round(100 * count($matched) / $total), 'status' => 'scored', 'matched' => $matched, 'missing' => $missing, 'evidence' => $evidence,
            'note' => count($matched) . ' of ' . $total . ' buying events line up with a product signal.'];
    }

    // ── target market ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function market(array $icp, array $offer): array
    {
        $regions = array_values(array_filter((array) ($icp['regions'] ?? []), 'is_string'));
        $codes = array_values(array_filter((array) ($offer['primary_segments'] ?? []), 'is_string'));

        return [
            'icp_industry' => ($icp['industry_label'] ?? null) ?: null,
            'icp_regions' => $regions,
            'product_segments' => array_map(fn ($c) => TaxonomyService::getSegmentLabel($c), $codes),
            'deployment_model' => ($offer['deployment_model'] ?? null) ?: null,
            'geography_status' => 'insufficient_data',
            'note' => 'A product record holds no region, so geographic suitability cannot be scored. Segment fit above covers who the product serves.',
        ];
    }

    // ── explanation ──────────────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $dims */
    private function summary(int $score, array $dims): string
    {
        $parts = [];
        foreach ($dims as $d) {
            $parts[] = $d['score'] === null ? "{$d['label']}: insufficient data" : "{$d['label']}: {$d['score']}% ({$d['note']})";
        }

        return "Overall {$score}%. " . implode(' ', $parts);
    }

    /** @param list<array<string, mixed>> $dims */
    private function whatIsMissing(array $dims): string
    {
        $notes = [];
        foreach ($dims as $d) {
            if ($d['score'] === null) {
                $notes[] = $d['label'] . ': ' . $d['note'];
            }
        }

        return implode(' ', $notes);
    }

    /**
     * @param  list<array<string, mixed>>  $dims
     * @return list<string>
     */
    private function recommendations(array $icp, array $offer, array $dims): array
    {
        $out = [];
        foreach ($dims as $d) {
            if ($d['score'] === null) {
                $out[] = match ($d['key']) {
                    'segment_fit' => 'Fill in the product\'s primary segments and the ICP\'s buyer types so the segment fit can be checked.',
                    'need_coverage' => 'Describe what the product does (description, modules, needs solved) and list the ICP\'s parts and buying events so needs can be compared.',
                    default => 'List the signals the product responds to and the ICP\'s buying events so they can be compared.',
                };
                continue;
            }
            foreach ($d['missing'] as $m) {
                $out[] = match ($d['key']) {
                    'segment_fit' => $m . ' If the product does serve them, add the segment to the product; if not, remove that buyer type from the ICP.',
                    'need_coverage' => $m . ' If the product covers it, say so in the product\'s description or modules; if it does not, treat it as a product gap or narrow the ICP.',
                    default => $m . ' If the product does respond to it, add the signal to the product; otherwise this event will not lead to this product.',
                };
            }
        }
        if (($offer['readiness_status'] ?? null) && ! (bool) ($offer['readiness_confirmed'] ?? false)) {
            $out[] = "Readiness '{$offer['readiness_status']}' has not been confirmed by an administrator: confirm it before selling this product on the strength of the match.";
        }

        return array_slice(array_values(array_unique($out)), 0, 12);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array{key: string, label: string, weight: int, description: string} */
    private function dim(string $key, string $label, string $description): array
    {
        return ['key' => $key, 'label' => $label, 'weight' => self::WEIGHTS[$key], 'description' => $description];
    }

    /**
     * Distinct, lightly stemmed words (letters and digits). `$dropGeneric` removes words too common to mean anything.
     *
     * @return list<string>
     */
    private function tokens(string $text, bool $dropGeneric): array
    {
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($words as $w) {
            if (strlen($w) < 3 && ! ctype_digit($w) && $dropGeneric) {
                continue;
            }
            $w = preg_replace('/(ies)$/', 'y', $w) ?? $w;
            $w = preg_replace('/(es|s)$/', '', $w) ?? $w;
            if ($dropGeneric && (strlen($w) < 4 || in_array($w, self::GENERIC, true) || in_array($w . 's', self::GENERIC, true))) {
                continue;
            }
            $out[$w] = true;
        }

        return array_keys($out);
    }
}
