<?php

namespace App\Domain\OrgAccounts;

/**
 * Disjoint groups of source-row keys (union-find). Cloning gives an independent copy, which the resolver uses to try a set of links before it
 * commits them.
 */
final class OrgGroups
{
    /** @var array<string, string> */
    private array $parent = [];

    /** @param list<string> $keys */
    public function __construct(array $keys)
    {
        foreach ($keys as $key) {
            $this->parent[$key] = $key;
        }
    }

    public function find(string $key): string
    {
        $root = $key;
        while ($this->parent[$root] !== $root) {
            $root = $this->parent[$root];
        }
        // Point the walked path straight at the root so later lookups are short.
        while ($this->parent[$key] !== $root) {
            [$key, $this->parent[$key]] = [$this->parent[$key], $root];
        }

        return $root;
    }

    public function same(string $a, string $b): bool
    {
        return $this->find($a) === $this->find($b);
    }

    public function union(string $a, string $b): void
    {
        $ra = $this->find($a);
        $rb = $this->find($b);
        if ($ra !== $rb) {
            $this->parent[$rb] = $ra;
        }
    }

    /**
     * Every group's members, in the order the keys were given.
     *
     * @return array<string, list<string>> root => members
     */
    public function members(): array
    {
        $groups = [];
        foreach (array_keys($this->parent) as $key) {
            $groups[$this->find($key)][] = $key;
        }

        return $groups;
    }
}
