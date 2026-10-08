<?php

namespace App\Domain\AI\Lifecycle;

/** One stage's outcome: a status, one plain sentence for a person, and optional detail for a trace view. */
final class StageResult
{
    /** @param array<string, mixed> $detail */
    private function __construct(
        public readonly StageStatus $status,
        public readonly string $summary,
        public readonly array $detail = [],
    ) {
    }

    /** @param array<string, mixed> $detail */
    public static function ran(string $summary, array $detail = []): self
    {
        return new self(StageStatus::Ran, $summary, $detail);
    }

    public static function skipped(string $summary): self
    {
        return new self(StageStatus::Skipped, $summary);
    }

    /** Stops the turn: later stages are reported as not reached. */
    public static function blocked(string $summary, array $detail = []): self
    {
        return new self(StageStatus::Blocked, $summary, $detail);
    }

    public static function failed(string $summary, array $detail = []): self
    {
        return new self(StageStatus::Failed, $summary, $detail);
    }

    /** @param array<string, mixed> $detail */
    public static function pending(string $summary, array $detail = []): self
    {
        return new self(StageStatus::Pending, $summary, $detail);
    }
}
