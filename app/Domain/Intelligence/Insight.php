<?php

namespace App\Domain\Intelligence;

use InvalidArgumentException;

/**
 * One decision-support item. Immutable, validated on construction, and serialised in exactly one shape (the API contract the frontend renders).
 *
 * An insight answers: what happened, why it matters, what supports it, how much data stands behind it, what to do next, and where in G2G to do it.
 * `population` is the number of distinct PEOPLE the figure is drawn from (null when not about people, e.g. deals): the small-group rule suppresses an
 * insight about fewer than the configured minimum people for any caller who is not entitled to see individuals (see SmallGroupGuard).
 *
 * `provisional` is always true in Phase 1: the thresholds behind every rule are proposals to be calibrated, and the screen says so.
 */
final class Insight
{
    /**
     * @param list<Evidence>  $evidence
     * @param list<string>    $surfaces
     */
    public function __construct(
        public readonly string $key,
        public readonly string $detector,
        public readonly string $domain,
        public readonly string $category,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $whatHappened,
        public readonly string $whyItMatters,
        public readonly array $evidence,
        public readonly Coverage $coverage,
        public readonly string $asOf,
        public readonly ?string $suggestedAction,
        public readonly ?string $deepLink,
        public readonly string $confidence,
        public readonly string $audience,
        public readonly array $surfaces = [],
        public readonly ?int $population = null,
        public readonly string $status = Taxonomy::STATUS_OPEN,
        public readonly bool $provisional = true,
    ) {
        $this->require(trim($key) !== '', 'key');
        $this->require(in_array($domain, Taxonomy::DOMAINS, true), 'domain');
        $this->require(in_array($severity, Taxonomy::SEVERITIES, true), 'severity');
        $this->require(in_array($confidence, Taxonomy::CONFIDENCES, true), 'confidence');
        $this->require(in_array($audience, Taxonomy::AUDIENCES, true), 'audience');
        $this->require(in_array($status, Taxonomy::STATUSES, true), 'status');
        $this->require(trim($title) !== '' && trim($whatHappened) !== '' && trim($whyItMatters) !== '', 'title/what/why');
        $this->require($evidence !== [], 'evidence (an insight without evidence is an opinion)');
        foreach ($evidence as $line) {
            $this->require($line instanceof Evidence, 'evidence item');
        }
        foreach ($surfaces as $surface) {
            $this->require(in_array($surface, Taxonomy::SURFACES, true), 'surface ' . $surface);
        }
        // A deep link is an in-app path, never an absolute URL: an insight must not be able to send a person somewhere else.
        $this->require($deepLink === null || (str_starts_with($deepLink, '/') && ! str_starts_with($deepLink, '//')), 'deep link');
        $this->require($population === null || $population >= 0, 'population');
    }

    private function require(bool $ok, string $what): void
    {
        if (! $ok) {
            throw new InvalidArgumentException("Invalid insight: {$what}.");
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'detector' => $this->detector,
            'domain' => $this->domain,
            'category' => $this->category,
            'severity' => $this->severity,
            'title' => $this->title,
            'what_happened' => $this->whatHappened,
            'why_it_matters' => $this->whyItMatters,
            'evidence' => array_map(static fn (Evidence $e) => $e->toArray(), $this->evidence),
            'coverage' => $this->coverage->toArray(),
            'as_of' => $this->asOf,
            'suggested_action' => $this->suggestedAction,
            'deep_link' => $this->deepLink,
            'confidence' => $this->confidence,
            'audience' => $this->audience,
            'surfaces' => $this->surfaces,
            'status' => $this->status,
            'provisional' => $this->provisional,
        ];
        // `population` is deliberately NOT serialised: it drives suppression server-side and would itself disclose a small group's size.
    }
}
