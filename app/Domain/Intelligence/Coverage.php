<?php

namespace App\Domain\Intelligence;

/**
 * HOW MUCH OF THE QUESTION THE DATA ANSWERS.
 *
 * Every insight and every detector result carries one. It is the house rule of the product made explicit: never present a number whose basis is thin as if it
 * were solid, and never turn "not recorded" into zero.
 *
 *   observed   - how many records/people/days the figure rests on
 *   expected   - how many there should be if the data were complete (null when that is not knowable: say so in `basis`)
 *   basis      - one plain sentence: "active employees with at least one attendance row in the last 30 days"
 *   sufficient - whether the detector's PROVISIONAL minimum was met
 *   reason     - why it is not sufficient, in words a manager can read (null when sufficient)
 */
final class Coverage
{
    public function __construct(
        public readonly int $observed,
        public readonly ?int $expected,
        public readonly string $basis,
        public readonly bool $sufficient,
        public readonly ?string $reason = null,
    ) {
    }

    public static function of(int $observed, ?int $expected, string $basis, int $minimumObserved): self
    {
        $ok = $observed >= $minimumObserved;

        return new self(
            $observed,
            $expected,
            $basis,
            $ok,
            $ok ? null : sprintf('Only %d record(s) support this; at least %d are needed before it is assessed.', $observed, $minimumObserved),
        );
    }

    /** Percentage of expected, one decimal; null when the expectation is unknown or zero. */
    public function percent(): ?float
    {
        if ($this->expected === null || $this->expected <= 0) {
            return null;
        }

        return round(min(100, ($this->observed / $this->expected) * 100), 1);
    }

    /** @return array{observed: int, expected: ?int, percent: ?float, basis: string, sufficient: bool, reason: ?string} */
    public function toArray(): array
    {
        return [
            'observed' => $this->observed,
            'expected' => $this->expected,
            'percent' => $this->percent(),
            'basis' => $this->basis,
            'sufficient' => $this->sufficient,
            'reason' => $this->reason,
        ];
    }
}
