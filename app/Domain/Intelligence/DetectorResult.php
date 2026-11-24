<?php

namespace App\Domain\Intelligence;

use InvalidArgumentException;

/**
 * What one detector returns for one caller: its insights AND whether it could assess at all.
 *
 * A detector that lacks the data answers `insufficient_data` with a reason and its coverage, not an empty list: "nothing to report" and "could not check"
 * are different statements and the screen must be able to tell them apart (the Evidence & coverage view lists every detector's state).
 *
 * The state vocabulary (ok, insufficient_data, not_applicable, data_unavailable, access_restricted, error) is documented on Taxonomy::STATE_OK.
 */
final class DetectorResult
{
    /** @param list<Insight> $insights */
    private function __construct(
        public readonly string $detector,
        public readonly string $domain,
        public readonly string $title,
        public readonly string $state,
        public readonly array $insights,
        public readonly ?Coverage $coverage,
        public readonly string $asOf,
        public readonly ?string $reason,
    ) {
        if (! in_array($state, Taxonomy::STATES, true)) {
            throw new InvalidArgumentException('Invalid detector state.');
        }
        foreach ($insights as $insight) {
            if (! $insight instanceof Insight) {
                throw new InvalidArgumentException('A detector result holds insights only.');
            }
        }
    }

    /** @param list<Insight> $insights  An empty list with state ok means: checked, nothing needs attention. */
    public static function ok(InsightDetector $d, array $insights, Coverage $coverage, string $asOf): self
    {
        return new self($d->id(), $d->domain(), $d->title(), Taxonomy::STATE_OK, $insights, $coverage, $asOf, null);
    }

    public static function insufficient(InsightDetector $d, Coverage $coverage, string $asOf): self
    {
        return new self($d->id(), $d->domain(), $d->title(), Taxonomy::STATE_INSUFFICIENT, [], $coverage, $asOf, $coverage->reason ?? 'Not enough data to assess.');
    }

    public static function notApplicable(InsightDetector $d, string $asOf, string $reason): self
    {
        return new self($d->id(), $d->domain(), $d->title(), Taxonomy::STATE_NOT_APPLICABLE, [], null, $asOf, $reason);
    }

    /** The source is not present or not readable on this installation (a table or column is absent). Nothing was assessed; this is neither an error nor zero. */
    public static function dataUnavailable(InsightDetector $d, string $asOf, string $reason): self
    {
        return new self($d->id(), $d->domain(), $d->title(), Taxonomy::STATE_DATA_UNAVAILABLE, [], null, $asOf, $reason);
    }

    /**
     * The caller is not authorised for this detector. Built from the detector's identity ONLY: the service never runs the detector, so nothing was read, and
     * the entry carries no coverage and no count.
     */
    public static function accessRestricted(InsightDetector $d, string $asOf): self
    {
        return new self($d->id(), $d->domain(), $d->title(), Taxonomy::STATE_ACCESS_RESTRICTED, [], null, $asOf, 'Your role does not have access to this check.');
    }

    /** A failure is reported generically: the message never carries SQL, table names or a stack trace. */
    public static function failed(InsightDetector $d, string $asOf): self
    {
        return new self($d->id(), $d->domain(), $d->title(), Taxonomy::STATE_ERROR, [], null, $asOf, 'This check could not be completed right now.');
    }

    /** A copy with a different insight list (the service uses it after suppression). @param list<Insight> $insights */
    public function withInsights(array $insights): self
    {
        return new self($this->detector, $this->domain, $this->title, $this->state, $insights, $this->coverage, $this->asOf, $this->reason);
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'detector' => $this->detector,
            'domain' => $this->domain,
            'title' => $this->title,
            'state' => $this->state,
            // null when nothing was counted (data_unavailable / access_restricted / error): an error or a restriction is never presented as zero records.
            'insight_count' => in_array($this->state, [Taxonomy::STATE_DATA_UNAVAILABLE, Taxonomy::STATE_ACCESS_RESTRICTED, Taxonomy::STATE_ERROR], true)
                ? null
                : count($this->insights),
            'coverage' => $this->coverage?->toArray(),
            'as_of' => $this->asOf,
            'reason' => $this->reason,
        ];
    }
}
