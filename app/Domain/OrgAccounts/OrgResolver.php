<?php

namespace App\Domain\OrgAccounts;

/**
 * The matching engine of Organisation 360 (slice 3, Docs/agentic-crm/slices/S03-organisation-360.md, decision D2).
 *
 * Costs are asymmetric: a missed link costs a click, a wrong link mixes two schools' histories and contacts. So a pair of rows is LINKED on exact
 * evidence only, and only when nothing argues against it:
 *   1. the links the system already holds (gtm_accounts.company_id and .crm_organization_id) come first, basis existing_link;
 *   2. then rows of different sources with the same normalised name or the same domain, basis exact_name / exact_domain, but only if
 *        - the match is one-to-one (the left row has exactly one candidate in the other source and that candidate has exactly one back),
 *        - the two places do not contradict (when both are known, they share a word),
 *        - nobody turned the pair down;
 *   3. a set of links is applied only if the rows it would join stay one-per-source and stay inside one identity; otherwise none of that set is
 *      applied and the conflict is counted.
 * Everything softer is a SUGGESTION (suggestions()) that a person confirms or rejects.
 *
 * The engine only reads. The backfill writes what plan() decided.
 */
final class OrgResolver
{
    /** Two names this alike are offered to a person (Jaccard overlap of their words). */
    public const OVERLAP_MIN = 0.8;

    public const SUGGESTION_BASIS = 'name_overlap';

    /** Which basis a row is recorded with when several links touch it: the lowest number wins. */
    private const BASIS_RANK = [OrgAccount::BASIS_EXISTING => 0, OrgAccount::BASIS_EXACT_NAME => 1, OrgAccount::BASIS_EXACT_DOMAIN => 2];

    // ---------------------------------------------------------------- the plan

    public function plan(int $tenantId): OrgPlan
    {
        return $this->planFrom(OrgSources::rows($tenantId), OrgSources::identities($tenantId), OrgSources::rejections($tenantId));
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows  OrgSources::rows()
     * @param  list<array<string, mixed>>  $identities  OrgSources::identities()
     * @param  array<string, true>  $rejected  OrgSources::rejections()
     */
    public function planFrom(array $rows, array $identities, array $rejected): OrgPlan
    {
        $groups = new OrgGroups(array_keys($rows));
        [$owner, $slots] = $this->owners($rows, $identities, $groups);

        $counts = ['ambiguous' => 0, 'place_conflict' => 0, 'same_source_conflict' => 0, 'identity_conflict' => 0];

        $existing = [];
        foreach ($rows as $key => $row) {
            foreach ($row['links'] as $target) {
                if (isset($rows[$target])) {
                    $existing[] = [$key, $target, OrgAccount::BASIS_EXISTING];
                }
            }
        }
        $committed = $this->commit($groups, $existing, $rows, $owner, $slots, $counts);
        $committed = array_merge($committed, $this->commit($groups, $this->exactEdges($rows, $groups, $rejected, $counts), $rows, $owner, $slots, $counts));

        // A row already in an identity keeps the basis it has; the others take the strongest link that touched them.
        $basis = [];
        foreach ($committed as [$a, $b, $by]) {
            foreach ([$a, $b] as $key) {
                if (! isset($owner[$key]) && (! isset($basis[$key]) || self::BASIS_RANK[$by] < self::BASIS_RANK[$basis[$key]])) {
                    $basis[$key] = $by;
                }
            }
        }

        $out = [];
        $groupOf = [];
        $counts += ['rows' => count($rows), 'left_alone' => count($owner), 'identities_to_create' => 0, 'identities_to_extend' => 0,
            OrgAccount::BASIS_EXISTING => 0, OrgAccount::BASIS_EXACT_NAME => 0, OrgAccount::BASIS_EXACT_DOMAIN => 0];
        foreach ($groups->members() as $keys) {
            $identity = null;
            $attach = [];
            foreach ($keys as $key) {
                if (isset($owner[$key])) {
                    $identity = $owner[$key];
                } else {
                    $attach[$key] = $basis[$key] ?? null;
                }
            }
            foreach ($attach as $by) {
                if ($by !== null) {
                    $counts[$by]++;
                }
            }
            if ($identity === null) {
                $counts['identities_to_create']++;
            } elseif ($attach !== []) {
                $counts['identities_to_extend']++;
            }
            foreach ($keys as $key) {
                $groupOf[$key] = count($out);
            }
            $out[] = ['identity' => $identity, 'keys' => $keys, 'attach' => $attach];
        }

        return new OrgPlan($out, $groupOf, $counts);
    }

    /**
     * Which identity each source row is already in, which pointer slots each identity occupies (a slot stays taken even when the row it points
     * at is gone), and the groups seeded with each identity's rows.
     *
     * @return array{0: array<string, int>, 1: array<int, array<string, true>>}
     */
    private function owners(array $rows, array $identities, OrgGroups $groups): array
    {
        $owner = [];
        $slots = [];
        foreach ($identities as $identity) {
            $first = null;
            foreach (OrgAccount::POINTERS as $type => $column) {
                if (($identity[$column] ?? null) === null) {
                    continue;
                }
                $slots[(int) $identity['id']][$type] = true;
                $key = OrgSources::key($type, (int) $identity[$column]);
                if (! isset($rows[$key])) {
                    continue;
                }
                $owner[$key] = (int) $identity['id'];
                if ($first === null) {
                    $first = $key;
                } else {
                    $groups->union($first, $key);
                }
            }
        }

        return [$owner, $slots];
    }

    /**
     * Applies a set of links to the groups, a connected piece at a time. A piece that would put two rows of one source, or two identities, in
     * one group is refused whole and counted; the others are applied.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $edges
     * @param  array<string, int>  $counts
     * @return list<array{0: string, 1: string, 2: string}> the links that were applied
     */
    private function commit(OrgGroups $groups, array $edges, array $rows, array $owner, array $slots, array &$counts): array
    {
        $pending = array_values(array_filter($edges, fn (array $e) => ! $groups->same($e[0], $e[1])));
        if ($pending === []) {
            return [];
        }

        $trial = clone $groups;
        foreach ($pending as $e) {
            $trial->union($e[0], $e[1]);
        }
        $members = $trial->members();
        $pieces = [];
        foreach ($pending as $e) {
            $pieces[$trial->find($e[0])][] = $e;
        }

        $applied = [];
        foreach ($pieces as $root => $pieceEdges) {
            $conflict = $this->conflict($members[$root], $rows, $owner, $slots);
            if ($conflict !== null) {
                $counts[$conflict]++;

                continue;
            }
            foreach ($pieceEdges as $e) {
                $groups->union($e[0], $e[1]);
                $applied[] = $e;
            }
        }

        return $applied;
    }

    /** @param list<string> $members @return 'identity_conflict'|'same_source_conflict'|null */
    private function conflict(array $members, array $rows, array $owner, array $slots): ?string
    {
        $identities = [];
        $perType = [];
        foreach ($members as $key) {
            $perType[$rows[$key]['type']] = ($perType[$rows[$key]['type']] ?? 0) + 1;
            if (isset($owner[$key])) {
                $identities[$owner[$key]][$rows[$key]['type']] = true;
            }
        }
        if (count($identities) > 1) {
            return 'identity_conflict';
        }
        foreach ($identities as $id => $heldLive) {
            // A pointer whose row is gone still fills its slot, so a row of that source cannot take it.
            foreach (array_keys($slots[$id] ?? []) as $type) {
                if (! isset($heldLive[$type])) {
                    $perType[$type] = ($perType[$type] ?? 0) + 1;
                }
            }
        }

        return max($perType) > 1 ? 'same_source_conflict' : null;
    }

    /**
     * The exact-evidence links between rows of different sources (see the class comment), in a stable order.
     *
     * @param  array<string, true>  $rejected
     * @param  array<string, int>  $counts
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function exactEdges(array $rows, OrgGroups $groups, array $rejected, array &$counts): array
    {
        $byName = [];
        $byDomain = [];
        foreach ($rows as $key => $row) {
            if ($row['norm'] !== '') {
                $byName[$row['norm']][$row['type']][] = $key;
            }
            if ($row['domain'] !== null) {
                $byDomain[$row['domain']][$row['type']][] = $key;
            }
        }

        // Every row's candidates in each OTHER source: same name or same domain, minus pairs a person turned down.
        $candidates = [];
        foreach ($rows as $key => $row) {
            foreach (OrgAccount::SOURCES as $type) {
                if ($type === $row['type']) {
                    continue;
                }
                $found = array_merge(
                    $row['norm'] !== '' ? ($byName[$row['norm']][$type] ?? []) : [],
                    $row['domain'] !== null ? ($byDomain[$row['domain']][$type] ?? []) : []
                );
                $found = array_values(array_filter(array_unique($found), fn (string $other) => ! isset($rejected[OrgSources::pairKey($key, $other)])));
                if ($found !== []) {
                    $candidates[$key][$type] = $found;
                }
            }
        }

        $order = array_flip(array_keys($rows));
        $edges = [];
        foreach ($candidates as $a => $byType) {
            foreach ($byType as $type => $others) {
                foreach ($others as $b) {
                    if ($order[$b] < $order[$a] || $groups->same($a, $b)) {
                        continue;   // each pair once; a pair already together is not in question
                    }
                    if (count($others) !== 1 || count($candidates[$b][$rows[$a]['type']] ?? []) !== 1) {
                        $counts['ambiguous']++;

                        continue;
                    }
                    if ($this->placesContradict($rows[$a]['place'], $rows[$b]['place'])) {
                        $counts['place_conflict']++;

                        continue;
                    }
                    $edges[] = [$a, $b, $rows[$a]['norm'] !== '' && $rows[$a]['norm'] === $rows[$b]['norm'] ? OrgAccount::BASIS_EXACT_NAME : OrgAccount::BASIS_EXACT_DOMAIN];
                }
            }
        }

        return $edges;
    }

    /** Both places known and not one word in common. A place that is missing on either side contradicts nothing. */
    private function placesContradict(array $a, array $b): bool
    {
        return $a !== [] && $b !== [] && array_intersect($a, $b) === [];
    }

    // ------------------------------------------------------------- suggestions

    /**
     * Pairs a person should look at: two live source rows whose names share at least 80% of their words, best first. An identical-name pair the
     * backfill refused to link (ambiguous, or in two different places) is offered too, with score 1.0. Left out: rows already in one identity,
     * pairs a person rejected (either order), and two rows of one source other than the Signals companies, where the buyer resolver creates
     * real duplicates.
     *
     * Each pair also says where each side is (`place`, null when unknown), whether the two places contradict (`place_conflict`) and whether a
     * person's "same organisation" could ever be accepted (`confirmable`: false for two rows of one source, which can only be turned down). A pair that
     * a confirm would be refused for, because an identity already holds a row of that source, is left out.
     *
     * @return list<array{key: string, left: array{type: string, id: int, name: string, place: ?string}, right: array{type: string, id: int, name: string, place: ?string}, basis: string, score: float, place_conflict: bool, confirmable: bool}>
     */
    public function suggestions(int $tenantId, int $limit = 100): array
    {
        return array_slice($this->pairs($tenantId, null), 0, max(0, $limit));
    }

    /** How many suggestions there are in all. With a plan, rows the plan would put in one identity are not counted: what is left after the backfill. */
    public function suggestionCount(int $tenantId, ?OrgPlan $plan = null): int
    {
        return count($this->pairs($tenantId, $plan));
    }

    /** @return list<array<string, mixed>> */
    private function pairs(int $tenantId, ?OrgPlan $plan): array
    {
        $rows = OrgSources::rows($tenantId);
        $rejected = OrgSources::rejections($tenantId);
        [$owner, $slots] = $this->owners($rows, OrgSources::identities($tenantId), new OrgGroups(array_keys($rows)));

        // Only rows that share one of their rarest words can be 80% alike, so those are the only pairs worth scoring: with the words of each row
        // ordered rare first, two rows with overlap >= t must share a word among the first n - ceil(t*n) + 1 of each ("prefix filtering").
        $frequency = [];
        foreach ($rows as $row) {
            foreach ($row['tokens'] as $token) {
                $frequency[$token] = ($frequency[$token] ?? 0) + 1;
            }
        }
        $index = [];
        foreach ($rows as $key => $row) {
            $tokens = $row['tokens'];
            if ($tokens === []) {
                continue;
            }
            usort($tokens, fn (string $x, string $y) => $frequency[$x] <=> $frequency[$y] ?: strcmp($x, $y));
            $prefix = count($tokens) - (int) ceil(self::OVERLAP_MIN * count($tokens) - 1e-9) + 1;
            foreach (array_slice($tokens, 0, $prefix) as $token) {
                $index[$token][] = $key;
            }
        }

        $found = [];
        $seen = [];
        foreach ($index as $keys) {
            for ($i = 0, $n = count($keys); $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    [$a, $b] = [$keys[$i], $keys[$j]];
                    $pair = OrgSources::pairKey($a, $b);
                    if (isset($seen[$pair])) {
                        continue;
                    }
                    $seen[$pair] = true;

                    if (($rows[$a]['type'] === $rows[$b]['type'] && $rows[$a]['type'] !== OrgAccount::SOURCE_COMPANY)
                        || (isset($owner[$a]) && ($owner[$a] ?? null) === ($owner[$b] ?? null))
                        || isset($rejected[$pair])
                        || ($plan !== null && $plan->together($a, $b))) {
                        continue;
                    }
                    $score = OrgNormalizer::overlap($rows[$a]['tokens'], $rows[$b]['tokens']);
                    if ($score < self::OVERLAP_MIN) {
                        continue;
                    }
                    // Two rows of one source (the Signals duplicates) can never be shown as one organisation: an identity holds one row per source.
                    // They are still offered, but only to be turned down (`confirmable` false). Any OTHER pair that a confirm would now refuse
                    // (an identity already holds a row of that source, because the person decided something else first) is no longer a question.
                    $confirmable = $rows[$a]['type'] !== $rows[$b]['type'];
                    if ($confirmable && $this->confirmWouldDouble($a, $b, $rows, $owner, $slots)) {
                        continue;
                    }
                    [$left, $right] = OrgSources::compare($a, $b) <= 0 ? [$rows[$a], $rows[$b]] : [$rows[$b], $rows[$a]];
                    $found[] = [
                        'key' => $pair,
                        'left' => ['type' => $left['type'], 'id' => $left['id'], 'name' => $left['name'], 'place' => $left['place_text'] ?? null],
                        'right' => ['type' => $right['type'], 'id' => $right['id'], 'name' => $right['name'], 'place' => $right['place_text'] ?? null],
                        'basis' => self::SUGGESTION_BASIS,
                        'score' => round($score, 4),
                        // Both places are known and share no word: the same name in two different places is more likely two organisations than one.
                        'place_conflict' => $this->placesContradict($rows[$a]['place'], $rows[$b]['place']),
                        'confirmable' => $confirmable,
                    ];
                }
            }
        }
        usort($found, fn (array $x, array $y) => $y['score'] <=> $x['score'] ?: strcmp($x['key'], $y['key']));

        return $found;
    }

    /**
     * Whether OrgLinker::confirm would refuse this pair of rows of DIFFERENT sources, the same rule as its merge() and attach(): an identity holds one
     * row per source. A pointer whose row is gone still fills its slot, so $slots (not the live rows) is what is asked.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<string, int>  $owner  row key => the identity it is in
     * @param  array<int, array<string, true>>  $slots  identity => the source types it has a pointer for
     */
    private function confirmWouldDouble(string $a, string $b, array $rows, array $owner, array $slots): bool
    {
        $ia = $owner[$a] ?? null;
        $ib = $owner[$b] ?? null;
        if ($ia !== null && $ib !== null) {
            return array_intersect_key($slots[$ia] ?? [], $slots[$ib] ?? []) !== [];   // two identities would be merged
        }
        if ($ia !== null) {
            return isset($slots[$ia][$rows[$b]['type']]);                              // b would join a's identity
        }
        if ($ib !== null) {
            return isset($slots[$ib][$rows[$a]['type']]);
        }

        return false;
    }
}
