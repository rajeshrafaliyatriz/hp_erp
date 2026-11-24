<?php

namespace App\Domain\Intelligence\Detectors\Commercial;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Support\Thresholds;
use App\Domain\Intelligence\Taxonomy;
use Illuminate\Database\ConnectionInterface;

/**
 * Whether the market-research runs are producing anything. Counts only: no cost, no money.
 *
 * Population: this tenant's FINISHED research runs (status success, partial or failed) with report_date in the last `signals_window_days`. Runs still
 * `running` and runs `skipped` are not finished and are not counted. Below `signals_min_runs` the detector says so (P-7).
 *
 * Two claims, over DIFFERENT populations so the same run is never reported twice:
 *   zero-yield : over runs that did NOT fail (status success or partial): opportunities_qualified is 0 or NULL. A run that completed and found nothing is still
 *                a successful run (the product's yield report does the same); a FAILED run says nothing about yield and is never in this claim.
 *   failure    : over all finished runs: status = 'failed'.
 */
final class SignalYieldDetector extends CommercialDetector
{
    /** PROVISIONAL (not calibrated against real data): share of finished runs that failed before it is worth saying so. */
    private const FAILURE_PCT = 25;
    /** PROVISIONAL (not calibrated against real data): zero-yield share from which the finding is `high` rather than `attention`. */
    private const ZERO_YIELD_HIGH_PCT = 80;
    /** PROVISIONAL (not calibrated against real data): failure share from which the finding is `high` rather than `attention`. */
    private const FAILURE_HIGH_PCT = 50;

    private ?CommercialAccess $access;

    public function __construct(ConnectionInterface $db, Thresholds $thresholds, ?CommercialAccess $access = null)
    {
        parent::__construct($db, $thresholds);
        $this->access = $access;
    }

    public function id(): string
    {
        return 'commercial.signal_yield';
    }

    public function title(): string
    {
        return 'Signal research yield';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_SIGNALS];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return ($this->access ??= CommercialAccess::fromConfig())->canSignals($caller);
    }

    public function detect(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $window = $this->thresholds->int('signals_window_days');
        $from = $clock->daysAgo($window);
        $finished = fn () => $this->db->table('g2g_research_runs')
            ->where('sub_institute_id', $caller->tenantId)
            ->whereIn('status', ['success', 'partial', 'failed'])
            ->where('report_date', '>=', $from);
        $notFailed = fn () => $finished()->whereIn('status', ['success', 'partial']);

        $runs = (int) $finished()->count();
        $coverage = Coverage::of($runs, null, "finished research runs in the last {$window} days", $this->thresholds->int('signals_min_runs'));
        if (! $coverage->sufficient) {
            return DetectorResult::insufficient($this, $coverage, $clock->asOf());
        }

        $okRuns = (int) $notFailed()->count();
        $zero = (int) $notFailed()->where(fn ($q) => $q->whereNull('opportunities_qualified')->orWhere('opportunities_qualified', 0))->count();
        $failed = $runs - $okRuns;
        $queriesFailed = (int) $finished()->sum('queries_failed');
        $qualified = (int) $finished()->sum('opportunities_qualified');
        $zeroPct = self::pct($zero, $okRuns);
        $failedPct = self::pct($failed, $runs);
        $windowEv = Evidence::window('Runs between', $from, $clock->today());
        $insights = [];

        if ($okRuns > 0 && $zeroPct >= $this->thresholds->float('signals_zero_yield_pct')) {
            $insights[] = new Insight(
                $this->key($caller, 'zero_yield', $clock->today()), $this->id(), $this->domain(), 'signal_yield', $zeroPct >= self::ZERO_YIELD_HIGH_PCT ? 'high' : 'attention',
                sprintf('%s%% of research runs found no qualified signal', rtrim(rtrim(number_format($zeroPct, 1, '.', ''), '0'), '.')),
                sprintf('%d of the %d research runs that finished without failing in the last %d days ended with no qualified signal. Failed runs are not part of this count.', $zero, $okRuns, $window),
                'The run history lists each run and what it found, so the runs that found nothing can be inspected.',
                [
                    Evidence::metric('Runs that finished (success or partial)', $okRuns, 'runs', 'research runs'),
                    Evidence::metric('Of these, with no qualified signal', $zero, 'runs'),
                    Evidence::metric('Qualified signals found in total', $qualified, 'signals'),
                    $windowEv,
                ],
                $coverage, $clock->asOf(),
                'Open the run history and look at the runs that found nothing, and at the product profile, ICP and regions they used.', '/signals', 'rule_based',
                $this->audience(), $this->surfaces(), null,
            );
        }

        if ($failedPct >= self::FAILURE_PCT) {
            $insights[] = new Insight(
                $this->key($caller, 'failures', $clock->today()), $this->id(), $this->domain(), 'signal_yield', $failedPct >= self::FAILURE_HIGH_PCT ? 'high' : 'attention',
                sprintf('%s%% of research runs failed', rtrim(rtrim(number_format($failedPct, 1, '.', ''), '0'), '.')),
                sprintf('%d of %d finished research runs in the last %d days ended in failure, and %d individual queries failed across the runs.', $failed, $runs, $window, $queriesFailed),
                'A failed run does not refresh the signals feed; the date of the last successful run shows how current the feed is.',
                [
                    Evidence::metric('Finished runs, including failed', $runs, 'runs', 'research runs'),
                    Evidence::metric('Failed runs', $failed, 'runs'),
                    Evidence::metric('Failed queries', $queriesFailed, 'queries'),
                    $windowEv,
                ],
                $coverage, $clock->asOf(),
                'Open the run history to see the failure reasons; check the search provider and AI configuration.', '/signals', 'rule_based',
                $this->audience(), $this->surfaces(), null,
            );
        }

        return DetectorResult::ok($this, $insights, $coverage, $clock->asOf());
    }
}
