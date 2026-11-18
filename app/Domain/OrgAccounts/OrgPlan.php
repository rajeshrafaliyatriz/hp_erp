<?php

namespace App\Domain\OrgAccounts;

/**
 * What the matching engine decided for one organisation, before anything is written (OrgResolver::plan()).
 *
 * `groups` is every live source row, grouped: each group becomes ONE identity. A group is either new (`identity` null) or an identity that
 * already exists and may gain rows. `attach` lists the rows of the group that are not yet in an identity, with the basis that joined each (null for
 * a row that stands alone).
 */
final class OrgPlan
{
    /**
     * @param  list<array{identity: ?int, keys: list<string>, attach: array<string, ?string>}>  $groups
     * @param  array<string, int>  $groupOf  row key => index into $groups
     * @param  array<string, int>  $counts  the figures the backfill prints
     */
    public function __construct(
        public readonly array $groups,
        public readonly array $groupOf,
        public readonly array $counts,
    ) {}

    public function together(string $a, string $b): bool
    {
        return isset($this->groupOf[$a], $this->groupOf[$b]) && $this->groupOf[$a] === $this->groupOf[$b];
    }
}
