<?php

namespace App\Domain\Signals\Products;

use App\Domain\Portfolio\ProductOffer;
use App\Domain\Portfolio\TaxonomyService;
use App\Domain\Signals\Icp\IcpPack;
use Illuminate\Support\Carbon;

/**
 * Searches written from what is STORED about each child product (its name, the problems it solves, its features, who it is sold to and the
 * events it responds to), not from the ICP's fixed catalogue. Before this, a child product reached only the research prompt: adding one
 * changed no search, so nothing it was built for was ever looked for.
 *
 * Deterministic and free: no AI call, nothing external. It works for any product, because every word comes from the product's own record and
 * its ICP (buyer description, regions); nothing here is specific to schools.
 *
 * Each child gets one search per kind of evidence (tender / RFP, hiring, expansion, a system change, a stated problem, an industry
 * announcement). A run takes a rotating window of that list, so over a few runs every child is searched under every kind.
 */
final class ProductQueryBuilder
{
    /** kind => [source category, search topic, freshness days]. Source categories match the ICP catalogue's, so the run treats them the same way. */
    private const FACETS = [
        'tender' => ['tender', 'general', 90],
        'hiring' => ['job', 'general', 45],
        'expansion' => ['news', 'news', 30],
        'system_change' => ['concept', 'general', 60],
        'pain_point' => ['news', 'news', 45],
        'industry' => ['concept', 'news', 60],
    ];

    /**
     * The whole ordered list of searches for the child products (not yet windowed).
     *
     * The order is a diagonal over (child, kind of evidence): round q gives child c the kind (c + q) mod K. So a window of the list
     * that sits inside one round holds DIFFERENT children AND different kinds (not two tenders in a row), and over K rounds every child has been searched
     * under every kind it has, exactly once. A child that lacks a kind (no stored event, so no "industry" search) takes its next kind instead.
     *
     * @param  iterable<ProductOffer>  $offers
     * @return list<array<string, mixed>>
     */
    public function forOffers(iterable $offers, IcpPack $pack): array
    {
        $kinds = array_keys(self::FACETS);
        $perChild = [];
        foreach ($offers as $offer) {
            $specs = [];
            foreach ($this->forOffer($offer, $pack) as $spec) {
                $specs[$spec['facet']] = $spec;
            }
            if ($specs !== []) {
                $perChild[] = $specs;
            }
        }

        $k = count($kinds);
        $out = [];
        $used = [];
        for ($q = 0; $q < $k; $q++) {
            foreach ($perChild as $c => $specs) {
                for ($step = 0; $step < $k; $step++) {
                    $kind = $kinds[($c + $q + $step) % $k];
                    if (isset($specs[$kind]) && ! isset($used[$c][$kind])) {
                        $used[$c][$kind] = true;
                        $out[] = $specs[$kind];
                        break;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * A window of `$n` searches that moves with the date (and with repeat runs on one day), like the catalogue's.
     *
     * @param  list<array<string, mixed>>  $all
     * @return list<array<string, mixed>>
     */
    public function window(array $all, Carbon $now, int $n): array
    {
        $count = count($all);
        if ($count === 0 || $n <= 0) {
            return [];
        }
        $start = ($now->dayOfYear * $n) % $count;
        $out = [];
        for ($i = 0; $i < min($n, $count); $i++) {
            $out[] = $all[($start + $i) % $count];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function forOffer(ProductOffer $offer, IcpPack $pack): array
    {
        $terms = $this->termsFor($offer);
        if ($terms['need'] === null && $terms['capability'] === null) {
            return [];   // nothing stored to search with
        }
        $cfg = $pack->config();
        $buyer = $this->clean((string) ($cfg['industry_label'] ?? ''));
        // Short region names only (a long official name such as a union territory's would use up the query); at most two.
        $regions = $this->orList(array_slice(array_values(array_filter(array_map('strval', (array) ($cfg['regions'] ?? [])), fn ($r) => mb_strlen(trim($r)) <= 24)), 0, 2));
        $where = $regions !== '' ? ' ' . $regions : '';
        $need = $terms['need'] ?? $terms['capability'];
        $capability = $terms['capability'] ?? $terms['need'];
        $segment = $terms['segment'] ?? $buyer;

        $queries = [
            'tender' => 'tender OR RFP OR "request for proposal" OR EOI ' . $this->phrase($capability) . $where,
            'hiring' => $segment . ' hiring ' . $this->orList([$terms['capability'], $terms['need2'] ?? $terms['name']]) . $where,
            'expansion' => $segment . ' expansion OR "new location" OR "new facility" OR launches ' . $this->phrase($need) . $where,
            'system_change' => $segment . ' replacing OR migrating OR implementing OR upgrading ' . $this->phrase($capability) . $where,
            'pain_point' => $segment . ' ' . $this->phrase($need) . ' problem OR challenge OR delay OR complaint' . $where,
        ];
        if ($terms['event'] !== null) {
            $queries['industry'] = $segment . ' ' . $this->phrase($terms['event']) . ' ' . $this->phrase($need) . $where;
        }

        $out = [];
        foreach ($queries as $kind => $q) {
            $q = trim(preg_replace('/\s+/', ' ', $q) ?? $q);
            if (mb_strlen($q) < 12) {
                continue;
            }
            [$source, $topic, $days] = self::FACETS[$kind];
            $out[] = [
                'q' => mb_substr($q, 0, 220), 'topic' => $topic, 'days' => $days, 'source' => $source,
                'id' => 'child-' . strtolower((string) $offer->offer_id) . '-' . $kind, 'facet' => $kind,
                'child_offer_id' => (string) $offer->offer_id, 'child_name' => (string) $offer->name,
            ];
        }

        return $out;
    }

    /**
     * The few plain words that best say what this product does, read from its stored record.
     *
     * @return array{need: ?string, need2: ?string, name: ?string, capability: ?string, segment: ?string, event: ?string}
     */
    private function termsFor(ProductOffer $o): array
    {
        $needs = [];
        foreach (array_slice(array_values(array_filter((array) $o->needs_solved, 'is_string')), 0, 3) as $code) {
            $needs[] = $this->short(TaxonomyService::getNeedLabel($code));
        }
        $needs = array_values(array_filter($needs));

        // The problem a buyer would name comes first, then the product's name. A stored feature is the last resort: features are often the
        // product's internal module names ("285 menus across 25 module groups"), which no buyer's page contains.
        $capability = $needs[0] ?? ($this->short((string) $o->name) ?: null);
        if ($capability === null) {
            foreach (preg_split('/[;,\n]+/', (string) $o->modules_components) ?: [] as $f) {
                $f = $this->short($f);
                if ($f !== '') {
                    $capability = $f;
                    break;
                }
            }
        }
        $capability ??= $this->short((string) $o->what_it_does) ?: null;

        $segment = null;
        foreach (array_values(array_filter((array) $o->primary_segments, 'is_string')) as $code) {
            $label = $this->short(TaxonomyService::getSegmentLabel($code));
            // A person's own words are used as they are; only a bare code with no label ("S03") says nothing to search with.
            if ($label !== '' && preg_match('/^[A-Za-z]{1,3}[-_ ]?\d{1,3}$/', $label) !== 1) {
                $segment = $label;
                break;
            }
        }

        $event = null;
        foreach (array_values(array_filter((array) $o->trigger_signals, 'is_string')) as $code) {
            $e = $this->short(explode(':', TaxonomyService::getSignalLabel($code))[0]);
            if ($e !== '') {
                $event = $e;
                break;
            }
        }

        return ['need' => $needs[0] ?? null, 'need2' => $needs[1] ?? null, 'name' => $this->short((string) $o->name) ?: null, 'capability' => $capability ?: null, 'segment' => $segment, 'event' => $event];
    }

    /** A stored phrase reduced to at most 6 plain words, with nothing a search engine would read as an operator. */
    private function short(string $text): string
    {
        $text = $this->clean(explode('(', explode(':', $text)[0])[0]);

        return implode(' ', array_slice(preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6));
    }

    private function clean(string $text): string
    {
        $text = preg_replace('/["\x{201C}\x{201D}<>(){}\[\]\\\\|\/]|\b(?:site|inurl|intitle|filetype):/iu', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private function phrase(?string $text): string
    {
        $text = $this->clean((string) $text);

        return $text === '' ? '' : (str_contains($text, ' ') ? '"' . $text . '"' : $text);
    }

    /** @param list<?string> $items */
    private function orList(array $items): string
    {
        $items = array_values(array_unique(array_filter(array_map(fn ($i) => $this->phrase($i), $items), fn ($i) => $i !== '')));

        return implode(' OR ', $items);
    }
}
