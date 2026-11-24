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
 * How well the discovered market signals are backed by evidence, and how much of the feed nobody has looked at.
 *
 * Rules (all PROVISIONAL, Thresholds P-7):
 *   - population: the tenant's signals first discovered in the last `signals_window_days`, DEMO/SAMPLE ROWS EXCLUDED with a NULL-SAFE filter
 *     (`COALESCE(import_source,'') NOT LIKE 'demo%' AND COALESCE(is_sample,0) = 0`: a NOT(NULL OR ...) form would silently drop every organic row whose
 *     import_source is NULL).
 *   - snippet-only: `fetch_level = 'search_snippet'` (the value the researcher writes when the page was not fetched; 'page_text' and 'full_document' are
 *     the page-read levels, verified in App\Domain\Signals\Opportunities\OpportunityResearcher and Market\SectionAssigner). Signals with NO fetch_level are
 *     reported separately as "not recorded", never counted as snippet-only and never as page-read.
 *   - undated: no event_date AND no source_published_at.
 *   - unreviewed: review_status 'New' (the column's default) or NULL.
 * Below `signals_min_signals` the detector says "not enough data" with its coverage instead of reporting a share.
 */
final class SignalEvidenceQualityDetector extends CommercialDetector
{
    /** PROVISIONAL (not calibrated against real data): share of the feed still unreviewed before it is worth saying so. */
    private const UNREVIEWED_PCT = 50;
    /** PROVISIONAL (not calibrated against real data): snippet-only share from which the finding is `high` rather than `attention`. */
    private const SNIPPET_HIGH_PCT = 75;

    private ?CommercialAccess $access;

    public function __construct(ConnectionInterface $db, Thresholds $thresholds, ?CommercialAccess $access = null)
    {
        parent::__construct($db, $thresholds);
        $this->access = $access;
    }

    public function id(): string
    {
        return 'commercial.signal_evidence_quality';
    }

    public function title(): string
    {
        return 'Signal evidence quality';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_SIGNALS, Taxonomy::SURFACE_SIGNAL_REVIEW];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return ($this->access ??= CommercialAccess::fromConfig())->canSignals($caller);
    }

    public function detect(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $window = $this->thresholds->int('signals_window_days');
        $since = $clock->dateTimeDaysAgo($window);
        $base = fn () => $this->db->table('g2g_company_opportunities')
            ->where('sub_institute_id', $caller->tenantId)
            ->whereRaw("COALESCE(import_source, '') NOT LIKE ?", ['demo%'])
            ->whereRaw('COALESCE(is_sample, 0) = 0')
            ->where('first_discovered_at', '>=', $since);

        $total = (int) $base()->count();
        $coverage = Coverage::of($total, null, "signals discovered in the last {$window} days (demo and sample rows excluded)", $this->thresholds->int('signals_min_signals'));
        if (! $coverage->sufficient) {
            return DetectorResult::insufficient($this, $coverage, $clock->asOf());
        }

        $snippet = (int) $base()->where('fetch_level', 'search_snippet')->count();
        $pageRead = (int) $base()->whereIn('fetch_level', ['page_text', 'full_document'])->count();
        $fetchKnown = (int) $base()->whereNotNull('fetch_level')->count();
        $undated = (int) $base()->whereNull('event_date')->whereNull('source_published_at')->count();
        $unreviewed = (int) $base()->where(fn ($q) => $q->where('review_status', 'New')->orWhereNull('review_status'))->count();

        $from = substr($since, 0, 10);
        $to = $clock->today();
        $insights = [];

        $snippetPct = self::pct($snippet, $fetchKnown);
        if ($fetchKnown > 0 && $snippetPct >= $this->thresholds->float('signals_snippet_only_pct')) {
            $insights[] = $this->build($caller, $clock, 'snippet_only', $snippetPct >= self::SNIPPET_HIGH_PCT ? 'high' : 'attention',
                sprintf('%s%% of signals rest on a search snippet only', $this->fmt($snippetPct)),
                sprintf('%d of the %d signals whose evidence level is recorded were never confirmed against the page itself (the researcher read only the search result snippet).', $snippet, $fetchKnown),
                'A snippet is only an excerpt from the search results, not the page: for these signals the claim has not been checked against the source page.',
                [
                    Evidence::metric('Signals in the window', $total, 'signals', 'signals feed'),
                    Evidence::metric('With evidence level recorded', $fetchKnown, 'signals'),
                    Evidence::metric('Snippet only', $snippet, 'signals'),
                    Evidence::metric('Page or document read', $pageRead, 'signals'),
                    Evidence::metric('Evidence level not recorded', $total - $fetchKnown, 'signals'),
                    Evidence::window('Discovered between', $from, $to),
                ],
                Coverage::of($fetchKnown, $total, 'signals with a recorded evidence level', 1),
                'Review the snippet-only signals and open the source page before pursuing them.', '/signal-review', 'rule_based');
        }

        $undatedPct = self::pct($undated, $total);
        if ($undatedPct >= $this->thresholds->float('signals_undated_pct')) {
            $insights[] = $this->build($caller, $clock, 'undated', 'attention',
                sprintf('%s%% of signals have no date', $this->fmt($undatedPct)),
                sprintf('%d of %d signals have neither an event date nor a source publication date, so their freshness cannot be judged.', $undated, $total),
                'Without a date a signal cannot be checked for freshness, ranked by urgency or expired.',
                [
                    Evidence::metric('Signals in the window', $total, 'signals', 'signals feed'),
                    Evidence::metric('No event date and no publication date', $undated, 'signals'),
                    Evidence::window('Discovered between', $from, $to),
                ],
                Coverage::of($total - $undated, $total, 'signals that carry a date', 1),
                'Confirm the date on the source page when reviewing; undated signals should not be treated as fresh.', '/signals', 'rule_based');
        }

        $unreviewedPct = self::pct($unreviewed, $total);
        if ($unreviewedPct >= self::UNREVIEWED_PCT) {
            $insights[] = $this->build($caller, $clock, 'unreviewed', 'info',
                sprintf('%s%% of signals have not been reviewed', $this->fmt($unreviewedPct)),
                sprintf('%d of %d signals are still in the "New" state; nobody has marked them reviewed, follow-up or dismissed.', $unreviewed, $total),
                'Review decisions (reviewed, follow-up, dismissed) are the record of which signals people acted on; signals still New have no decision.',
                [
                    Evidence::metric('Signals in the window', $total, 'signals', 'signals feed'),
                    Evidence::metric('Still New', $unreviewed, 'signals'),
                    Evidence::window('Discovered between', $from, $to),
                ],
                Coverage::of($total - $unreviewed, $total, 'signals that have a review decision', 0),
                'Work through the review queue, starting with the highest-priority signals.', '/signal-review', 'measured');
        }

        return DetectorResult::ok($this, $insights, $coverage, $clock->asOf());
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    /** @param list<Evidence> $evidence */
    private function build(IntelligenceCaller $caller, DetectionClock $clock, string $subject, string $severity, string $title, string $what, string $why, array $evidence, Coverage $coverage, string $action, string $link, string $confidence): Insight
    {
        return new Insight(
            $this->key($caller, $subject, $clock->today()), $this->id(), $this->domain(), 'signal_evidence', $severity, $title, $what, $why, $evidence, $coverage,
            $clock->asOf(), $action, $link, $confidence, $this->audience(), $this->surfaces(), null,
        );
    }
}
