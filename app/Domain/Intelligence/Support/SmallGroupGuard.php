<?php

namespace App\Domain\Intelligence\Support;

use App\Domain\Intelligence\Access\IntelligenceCaller;

/**
 * THE SMALL-GROUP RULE (decision D-8 default: minimum 5, PROVISIONAL).
 *
 * An insight drawn from fewer than N people can identify them ("1 late arrival in a team of 3"). The rule applies to a caller who is NOT entitled to see
 * individuals through this channel: team and department scopes, and aggregate-only roles (executive, auditor). It does not apply to `self` (it is the person's
 * own data) nor to HR/administrators at organisation scope (they may open the individual records anyway; suppressing would only hide work from them).
 *
 * An insight with `population = null` (not about people, e.g. deals) is never suppressed by this rule.
 */
final class SmallGroupGuard
{
    public function __construct(private readonly int $minimum)
    {
    }

    public static function fromThresholds(Thresholds $thresholds): self
    {
        return new self(max(1, $thresholds->int('min_group_size')));
    }

    public function minimum(): int
    {
        return $this->minimum;
    }

    /** Whether the caller is subject to the small-group rule at all. */
    public function appliesTo(IntelligenceCaller $caller): bool
    {
        if ($caller->aggregateOnly()) {
            return true;
        }

        return in_array($caller->scope(), ['team', 'department'], true);
    }

    /** True when this insight must be withheld from this caller. */
    public function suppresses(IntelligenceCaller $caller, ?int $population): bool
    {
        return $population !== null && $this->appliesTo($caller) && $population < $this->minimum;
    }
}
