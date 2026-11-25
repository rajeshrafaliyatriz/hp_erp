<?php

namespace App\Domain\Intelligence\Detectors\Commercial;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\InsightDetector;
use App\Domain\Intelligence\Support\Thresholds;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Database\ConnectionInterface;

/**
 * Shared plumbing of the commercial detectors. Commercial data is ORGANISATION data (deals, signals, invoices), not people data, so `population` is always
 * null and the small-group rule does not apply; what limits who sees it is the menu right on the linked screen (CommercialAccess) and, for executives and
 * auditors, the absence of any entity reference.
 */
abstract class CommercialDetector implements InsightDetector
{
    /** At most this many record references per insight. */
    protected const MAX_ENTITY_EVIDENCE = 10;

    public function __construct(
        protected readonly ConnectionInterface $db,
        protected readonly Thresholds $thresholds,
    ) {
    }

    public function domain(): string
    {
        return Taxonomy::DOMAIN_COMMERCIAL;
    }

    protected function audience(): string
    {
        return 'organization';
    }

    /**
     * Entity references (kind + id) for the screen to deep-link to; none for a caller who may not reference individuals (executive, auditor).
     *
     * @param  list<int|string>  $ids
     * @return list<Evidence>
     */
    protected function entities(IntelligenceCaller $caller, string $label, string $type, array $ids): array
    {
        if (! $caller->mayReferenceIndividuals()) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values(array_unique(array_map('intval', $ids))), 0, self::MAX_ENTITY_EVIDENCE) as $id) {
            if ($id > 0) {
                $out[] = Evidence::entity($label, $type, $id);
            }
        }

        return $out;
    }

    protected static function pct(int $part, int $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
    }

    /** Stable insight key: detector + tenant + the day, so one open insight per (detector, subject, day). */
    protected function key(IntelligenceCaller $caller, string $subject, string $today): string
    {
        return sprintf('%s:%s:t%d:%s', $this->id(), $subject, $caller->tenantId, $today);
    }
}
