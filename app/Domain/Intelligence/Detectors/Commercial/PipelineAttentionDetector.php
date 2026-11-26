<?php

namespace App\Domain\Intelligence\Detectors\Commercial;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Taxonomy;

/**
 * Whether the GTM pipeline data is complete enough to trust the pipeline value and close-date figures built on it. Needs `view` on /gtm/deals or /gtm/command-center.
 *
 * Open deals (discovery, qualification, proposal, negotiation) are checked for a price (amount AND currency) and an expected close date. Value and close-month
 * figures cannot include a deal missing either, so incomplete deals silently shrink them. COUNTS ONLY: no amount is read or shown. Priced deals are counted
 * PER CURRENCY and currencies are never combined. Needs `deals_min_open` open deals (P-8); the publication gate "at least 50% priced and dated" (P-8) decides
 * the severity: under it the finding is `attention`, over it `info`.
 */
final class PipelineAttentionDetector extends CommercialDetector
{
    private const OPEN = ['discovery', 'qualification', 'proposal', 'negotiation'];
    private const LINKS = [CommercialAccess::LINK_GTM_DEALS, CommercialAccess::LINK_GTM_COMMAND_CENTER];
    /** PROVISIONAL (not calibrated against real data; Thresholds P-8): share of open deals that should be both priced and dated; below it the finding is `attention`, otherwise `info`. */
    private const COMPLETE_PCT = 50;

    public function id(): string
    {
        return 'commercial.pipeline_attention';
    }

    public function title(): string
    {
        return 'Pipeline completeness';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_GTM_DEALS, Taxonomy::SURFACE_GTM_COMMAND_CENTER];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return $caller->canAny(self::LINKS);
    }

    public function detect(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $open = fn () => $this->db->table('gtm_deals')->where('sub_institute_id', $caller->tenantId)->whereNull('deleted_at')->whereIn('stage', self::OPEN);
        $total = (int) $open()->count();
        $coverage = Coverage::of($total, null, 'open GTM deals', $this->thresholds->int('deals_min_open'));
        if (! $coverage->sufficient) {
            return DetectorResult::insufficient($this, $coverage, $clock->asOf());
        }

        $unpriced = (int) $open()->where(fn ($q) => $q->whereNull('amount')->orWhereNull('currency'))->count();
        $undated = (int) $open()->whereNull('expected_close_date')->count();
        $complete = (int) $open()->whereNotNull('amount')->whereNotNull('currency')->whereNotNull('expected_close_date')->count();
        $incomplete = $total - $complete;
        if ($incomplete === 0) {
            return DetectorResult::ok($this, [], $coverage, $clock->asOf());
        }

        $evidence = [
            Evidence::metric('Open deals', $total, 'deals', 'GTM deals'),
            Evidence::metric('Missing a price (amount or currency)', $unpriced, 'deals'),
            Evidence::metric('Missing an expected close date', $undated, 'deals'),
            Evidence::metric('Priced and dated', $complete, 'deals'),
        ];
        // Priced open deals per currency; a currency is its own line, never added to another.
        $byCurrency = $open()->whereNotNull('amount')->whereNotNull('currency')->selectRaw('currency as c, COUNT(*) as n')->groupBy('currency')->orderBy('currency')->get();
        foreach ($byCurrency as $row) {
            $evidence[] = Evidence::metric('Priced open deals in ' . strtoupper(trim((string) $row->c)), (int) $row->n, 'deals');
        }
        $ids = $open()->where(fn ($q) => $q->whereNull('amount')->orWhereNull('currency')->orWhereNull('expected_close_date'))->orderBy('id')->limit(self::MAX_ENTITY_EVIDENCE)->pluck('id')->all();
        array_push($evidence, ...$this->entities($caller, 'Incomplete deal', 'gtm_deal', $ids));

        $completePct = self::pct($complete, $total);
        $under = $completePct < self::COMPLETE_PCT;

        return DetectorResult::ok($this, [new Insight(
            $this->key($caller, 'completeness', $clock->today()), $this->id(), $this->domain(), 'pipeline_completeness', $under ? 'attention' : 'info',
            sprintf('%d of %d open deals are missing a price or a close date', $incomplete, $total),
            sprintf('Only %s%% of open deals have both a price and an expected close date; the pipeline value and close-month figures leave the rest out.', rtrim(rtrim(number_format($completePct, 1, '.', ''), '0'), '.')),
            'Value and close-month figures leave out open deals that have no price or no expected close date, so they do not include them.',
            $evidence, new Coverage($complete, $total, 'open deals that are priced and dated', ! $under, $under ? 'Fewer than half of the open deals are priced and dated, so value and close-month figures are unreliable.' : null),
            $clock->asOf(), 'Add an amount, currency and expected close date to each open deal.', '/gtm/deals', 'rule_based',
            $this->audience(), $this->surfaces(), null,
        )], $coverage, $clock->asOf());
    }
}
