<?php

namespace App\Domain\Intelligence\Detectors\Cross;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Detectors\Hr\AbstractHrDetector;
use App\Domain\Intelligence\InsightDetector;
use App\Domain\Intelligence\Taxonomy;

/**
 * CROSS-FUNCTIONAL DETECTORS: a POSSIBLE RELATIONSHIP between the sources of two existing detectors, joined ONLY on a key verified in the schema and with the
 * organisation (`sub_institute_id`) enforced on BOTH sides of the join.
 *
 * Rules every cross detector follows (each one is asserted by tests):
 *   - AUTHORISATION IS THE INTERSECTION of the two source detectors' own `authorizes()`. A caller who may use only one side gets `access_restricted` from the
 *     service and the detector is never run, so nothing is read. Intelligence grants nothing new: it can only show what both source checks already show.
 *   - The output is COUNTS and shares of verified-linked records. No names, no amounts, no money added across currencies, no forecast, no cause.
 *   - Wording separates what was OBSERVED (what_happened) from what it MIGHT mean (why_it_matters), which is always a possible relationship that needs a
 *     person to verify it, and always says that no cause is established. The confidence is `rule_based`, never `measured`: a relationship is not a count.
 *   - Coverage is linked records over eligible records, with the basis in words. Below the provisional minimum the answer is `insufficient_data`; a missing
 *     table or column is `data_unavailable`; a throw is `error` (the HR base class handles it). None of them is ever zero or healthy.
 *
 * Failure isolation, the table probe and the key helper are inherited from the HR base class; only the domain differs.
 */
abstract class AbstractCrossDetector extends AbstractHrDetector
{
    /** Said in every cross insight: the relationship is a hypothesis for a person to check, never a finding. */
    protected const NO_CAUSE = 'No cause is established, and the records do not show whether either one affects the other.';

    public function domain(): string
    {
        return Taxonomy::DOMAIN_CROSS;
    }

    /** @return list<InsightDetector> The two source detectors whose authorisation this one requires, both of them. */
    abstract protected function sources(): array;

    final public function authorizes(IntelligenceCaller $caller): bool
    {
        foreach ($this->sources() as $source) {
            if (! $source->authorizes($caller)) {
                return false;
            }
        }

        return true;
    }
}
