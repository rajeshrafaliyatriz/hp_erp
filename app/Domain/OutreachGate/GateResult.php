<?php

namespace App\Domain\OutreachGate;

use Carbon\CarbonInterface;

/**
 * The answer of one evaluation: every check, in the order they ran, grouped by `runId`. A message passes only when EVERY check passed, and a result with no
 * check at all does not pass (nothing was looked at, so nothing is vouched for).
 */
final class GateResult
{
    /** @param list<CheckResult> $checks */
    public function __construct(
        public readonly string $runId,
        public readonly string $table,
        public readonly int $messageId,
        public readonly int $tenant,
        public readonly array $checks,
        public readonly CarbonInterface $checkedAt,
    ) {
    }

    public function passed(): bool
    {
        return $this->checks !== [] && array_reduce($this->checks, fn (bool $all, CheckResult $c) => $all && $c->passed, true);
    }

    /** @return list<CheckResult> */
    public function failed(): array
    {
        return array_values(array_filter($this->checks, fn (CheckResult $c) => ! $c->passed));
    }

    /** @return array{passed: bool, checks: list<array{key: string, label: string, passed: bool, detail: string}>, checked_at: string} */
    public function toArray(): array
    {
        return ['passed' => $this->passed(), 'checks' => array_map(fn (CheckResult $c) => $c->toArray(), $this->checks), 'checked_at' => $this->checkedAt->toIso8601String()];
    }
}
