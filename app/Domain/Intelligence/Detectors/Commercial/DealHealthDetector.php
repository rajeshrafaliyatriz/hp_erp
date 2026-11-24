<?php

namespace App\Domain\Intelligence\Detectors\Commercial;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\Coverage;
use App\Domain\Intelligence\DetectorResult;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\Support\DetectionClock;
use App\Domain\Intelligence\Taxonomy;
use Carbon\CarbonImmutable;

/**
 * Open deals that need attention, from the two deal stores a person may be allowed to see.
 *
 * GTM DEALS (needs `view` on /gtm/deals or /gtm/command-center). The flags are the product's own rules (App\Domain\Gtm\DealHealth, "pure rules over
 * recorded facts"), RE-IMPLEMENTED here on the injected connection with the same definitions rather than called, because DealHealth reads through the DB
 * facade, takes Eloquent GtmDeal models and reads its thresholds from config(): none of which can be bound to this detector's connection, tenant guard and
 * Thresholds. The rules are the same:
 *   no_next_step        next_step empty
 *   next_step_overdue   next_step_due before today (only when a next step is recorded)
 *   no_activity_ever / no_recent_activity
 *                       last activity = MAX(deal-level, account-level) activity EXCLUDING type 'system'; quiet at >= deals_quiet_days (default 14, P-9)
 *   no_close_date / close_date_passed
 *   stuck_in_stage      days since stage_changed_at (else created_at) >= deals_stuck_days (default 30, P-9)
 *   no_decision_maker   no live gtm_contacts row on the account with role economic_buyer or decision_maker
 * Open stages: discovery, qualification, proposal, negotiation (GtmDeal::OPEN_STAGES).
 * If at least 80% of the open deals have never had any activity logged, the two ACTIVITY flags are withheld: that means activity is not being recorded, and
 * flagging every deal would be noise (the number is reported instead).
 *
 * CRM OPPORTUNITIES (needs `view` on /module/crm/sales/opportunities): open = sales_stage not Closed Won / Closed Lost. "Untouched" uses updated_at as a
 * PROXY for inactivity (the CRM opportunity has no activity table here) and is labelled so; "past closing date" uses closing_date.
 *
 * Each source must have at least `deals_min_open` open records, otherwise it is "not enough data" (P-8).
 */
final class DealHealthDetector extends CommercialDetector
{
    private const GTM_OPEN = ['discovery', 'qualification', 'proposal', 'negotiation'];
    private const CRM_CLOSED = ['Closed Won', 'Closed Lost'];
    private const FETCH_CAP = 2000;
    /** PROVISIONAL (not calibrated against real data): when at least this share of open deals has never had an activity, the activity flags are withheld. */
    private const ACTIVITY_NOT_LOGGED_PCT = 80;
    /** PROVISIONAL (not calibrated against real data): share of assessed GTM deals with a flag from which the finding is `high` rather than `attention`. */
    private const GTM_HIGH_PCT = 50;
    /** PROVISIONAL (not calibrated against real data): share of open CRM opportunities flagged from which the finding is `high` rather than `attention`. */
    private const CRM_HIGH_PCT = 50;

    private const GTM_LINKS = [CommercialAccess::LINK_GTM_DEALS, CommercialAccess::LINK_GTM_COMMAND_CENTER];
    private const CRM_LINKS = [CommercialAccess::LINK_CRM_OPPORTUNITIES];

    private const FLAG_TEXT = [
        'no_next_step' => 'No next step recorded',
        'next_step_overdue' => 'Next step overdue',
        'no_activity_ever' => 'No activity ever logged',
        'no_recent_activity' => 'No recent activity',
        'no_close_date' => 'No expected close date',
        'close_date_passed' => 'Expected close date passed',
        'stuck_in_stage' => 'Stuck in the same stage',
        'no_decision_maker' => 'No decision maker on the account',
    ];

    public function id(): string
    {
        return 'commercial.deal_health';
    }

    public function title(): string
    {
        return 'Deal health';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_GTM_DEALS, Taxonomy::SURFACE_GTM_COMMAND_CENTER, Taxonomy::SURFACE_CRM_OPPORTUNITIES];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return $caller->canAny(self::GTM_LINKS) || $caller->canAny(self::CRM_LINKS);
    }

    public function detect(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $parts = [];
        if ($caller->canAny(self::GTM_LINKS)) {
            $parts[] = ['label' => 'GTM deals'] + $this->gtm($caller, $clock);
        }
        if ($caller->canAny(self::CRM_LINKS)) {
            $parts[] = ['label' => 'CRM opportunities'] + $this->crm($caller, $clock);
        }
        if ($parts === []) {
            return DetectorResult::notApplicable($this, $clock->asOf(), 'No deal screen is available to this profile.');
        }

        $observed = array_sum(array_map(fn ($p) => $p['coverage']->observed, $parts));
        $expected = array_sum(array_map(fn ($p) => $p['coverage']->expected ?? $p['coverage']->observed, $parts));
        $sufficient = (bool) array_filter($parts, fn ($p) => $p['coverage']->sufficient);
        $insights = [];
        foreach ($parts as $p) {
            array_push($insights, ...$p['insights']);
        }
        // Each side's reason is kept, prefixed with the side it belongs to, even when the OTHER side is sufficient: an insufficient side must not vanish.
        $reasons = [];
        foreach ($parts as $p) {
            if ($p['coverage']->reason !== null) {
                $reasons[] = $p['label'] . ': ' . $p['coverage']->reason;
            }
        }
        $coverage = new Coverage($observed, $expected, 'open deals and opportunities on the screens this profile can open', $sufficient, $reasons === [] ? null : implode(' ', $reasons));

        return $sufficient ? DetectorResult::ok($this, $insights, $coverage, $clock->asOf()) : DetectorResult::insufficient($this, $coverage, $clock->asOf());
    }

    /** @return array{coverage: Coverage, insights: list<Insight>} */
    private function gtm(IntelligenceCaller $caller, DetectionClock $clock): array
    {
        $min = $this->thresholds->int('deals_min_open');
        $openCount = (int) $this->db->table('gtm_deals')->where('sub_institute_id', $caller->tenantId)->whereNull('deleted_at')->whereIn('stage', self::GTM_OPEN)->count();
        $coverage = Coverage::of($openCount, null, 'open GTM deals', $min);
        if (! $coverage->sufficient) {
            return ['coverage' => $coverage, 'insights' => []];
        }

        $deals = $this->db->table('gtm_deals')->where('sub_institute_id', $caller->tenantId)->whereNull('deleted_at')->whereIn('stage', self::GTM_OPEN)
            ->orderBy('id')->limit(self::FETCH_CAP)
            // `next_step` is free text: only whether it is empty is needed, so that is computed in SQL and the text itself is never fetched.
            ->select(['id', 'account_id', 'next_step_due', 'stage_changed_at', 'created_at', 'expected_close_date'])
            ->selectRaw("CASE WHEN next_step IS NULL OR TRIM(next_step) = '' THEN 1 ELSE 0 END AS no_next_step")
            ->get();
        $dealIds = $deals->pluck('id')->map(fn ($v) => (int) $v)->all();
        $accountIds = $deals->pluck('account_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();

        $lastByDeal = $this->lastActivity('deal_id', $dealIds, $caller->tenantId);
        $lastByAccount = $this->lastActivity('account_id', $accountIds, $caller->tenantId);
        $deciders = [];
        foreach (array_chunk($accountIds, 500) as $chunk) {
            foreach ($this->db->table('gtm_contacts')->where('sub_institute_id', $caller->tenantId)->whereNull('deleted_at')->whereIn('account_id', $chunk)
                ->whereIn('role_in_deal', ['economic_buyer', 'decision_maker'])->distinct()->pluck('account_id') as $accountId) {
                $deciders[(int) $accountId] = true;
            }
        }

        $now = $clock->instant();
        $today = $clock->today();
        $quiet = $this->thresholds->int('deals_quiet_days');
        $stuck = $this->thresholds->int('deals_stuck_days');

        $rows = [];
        $neverActive = 0;
        foreach ($deals as $d) {
            $flags = [];
            if ((int) $d->no_next_step === 1) {
                $flags[] = 'no_next_step';
            } elseif ($d->next_step_due !== null && substr((string) $d->next_step_due, 0, 10) < $today) {
                $flags[] = 'next_step_overdue';
            }
            $last = $this->maxDate($lastByDeal[(int) $d->id] ?? null, $lastByAccount[(int) $d->account_id] ?? null);
            if ($last === null) {
                $neverActive++;
                $flags[] = 'no_activity_ever';
            } elseif ($this->daysBetween($last, $now) >= $quiet) {
                $flags[] = 'no_recent_activity';
            }
            if ($d->expected_close_date === null) {
                $flags[] = 'no_close_date';
            } elseif (substr((string) $d->expected_close_date, 0, 10) < $today) {
                $flags[] = 'close_date_passed';
            }
            $since = $d->stage_changed_at ?? $d->created_at;
            if ($since !== null && $this->daysBetween((string) $since, $now) >= $stuck) {
                $flags[] = 'stuck_in_stage';
            }
            if (! isset($deciders[(int) $d->account_id])) {
                $flags[] = 'no_decision_maker';
            }
            $rows[(int) $d->id] = $flags;
        }

        $assessed = count($rows);
        $withheld = $assessed > 0 && self::pct($neverActive, $assessed) >= self::ACTIVITY_NOT_LOGGED_PCT;
        if ($withheld) {
            foreach ($rows as $id => $flags) {
                $rows[$id] = array_values(array_diff($flags, ['no_activity_ever', 'no_recent_activity']));
            }
        }

        $flagged = array_filter($rows, fn ($f) => $f !== []);
        if ($flagged === []) {
            return ['coverage' => $coverage, 'insights' => []];
        }

        $perFlag = [];
        foreach ($flagged as $flags) {
            foreach ($flags as $f) {
                $perFlag[$f] = ($perFlag[$f] ?? 0) + 1;
            }
        }
        arsort($perFlag);
        $evidence = [
            Evidence::metric('Open GTM deals', $openCount, 'deals', 'GTM deals'),
            Evidence::metric('Deals with at least one flag', count($flagged), 'deals'),
        ];
        foreach ($perFlag as $flag => $n) {
            $evidence[] = Evidence::metric(self::FLAG_TEXT[$flag], $n, 'deals');
        }
        if ($withheld) {
            $evidence[] = Evidence::metric('Open deals with no activity ever logged (activity flags withheld: activity is not being recorded)', $neverActive, 'deals');
        }
        if ($openCount > $assessed) {
            $evidence[] = Evidence::metric('Deals assessed (the oldest first)', $assessed, 'deals');
        }
        uasort($flagged, fn ($a, $b) => count($b) <=> count($a));
        array_push($evidence, ...$this->entities($caller, 'Deal needing attention', 'gtm_deal', array_keys($flagged)));

        $pct = self::pct(count($flagged), $assessed);

        return ['coverage' => $coverage, 'insights' => [new Insight(
            $this->key($caller, 'gtm', $clock->today()), $this->id(), $this->domain(), 'deal_health', $pct >= self::GTM_HIGH_PCT ? 'high' : 'attention',
            sprintf('%d of %d open deals need attention', count($flagged), $assessed),
            sprintf('%d open GTM deals carry at least one health flag. The most common: %s.', count($flagged), self::FLAG_TEXT[array_key_first($perFlag)]),
            'Each flag is a gap in the recorded facts (no next step, no recent activity, a missing or past close date, a long time in one stage, no decision maker); the deals screen shows the flags deal by deal.',
            $evidence, $coverage, $clock->asOf(),
            'Open the flagged deals, set a next step with a date and log the latest contact.', '/gtm/deals', 'rule_based',
            $this->audience(), [Taxonomy::SURFACE_GTM_DEALS, Taxonomy::SURFACE_GTM_COMMAND_CENTER], null,
        )]];
    }

    /** @return array{coverage: Coverage, insights: list<Insight>} */
    private function crm(IntelligenceCaller $caller, DetectionClock $clock): array
    {
        $min = $this->thresholds->int('deals_min_open');
        $open = fn () => $this->db->table('crm_opportunities')->where('sub_institute_id', $caller->tenantId)->whereNull('deleted_at')
            ->whereRaw("COALESCE(sales_stage, '') NOT IN (?, ?)", self::CRM_CLOSED);
        $openCount = (int) $open()->count();
        $coverage = Coverage::of($openCount, null, 'open CRM opportunities', $min);
        if (! $coverage->sufficient) {
            return ['coverage' => $coverage, 'insights' => []];
        }

        $inactiveDays = $this->thresholds->int('crm_inactive_days');
        $cutoff = $clock->dateTimeDaysAgo($inactiveDays);
        $today = $clock->today();
        $untouchedRule = fn ($q) => $q->whereNotNull('updated_at')->where('updated_at', '<', $cutoff);
        $pastCloseRule = fn ($q) => $q->whereNotNull('closing_date')->where('closing_date', '<', $today);

        // A NULL updated_at is UNKNOWN: it is not "untouched" (nothing says it was) and it is not dropped silently either: it is counted and the coverage says so.
        $unknown = (int) $open()->whereNull('updated_at')->count();
        $untouched = (int) $open()->where($untouchedRule)->count();
        $pastClose = (int) $open()->where($pastCloseRule)->count();
        // DISTINCT opportunities that are untouched OR past their closing date: one row each, so an opportunity that is both is counted once.
        $flagged = (int) $open()->where(fn ($q) => $q->where($untouchedRule)->orWhere($pastCloseRule))->count();

        if ($unknown > 0) {
            $coverage = new Coverage(
                $openCount - $unknown, $openCount, 'open CRM opportunities that have a last-updated date',
                true, sprintf('%d open opportunit%s no last-updated date and could not be assessed for inactivity.', $unknown, $unknown === 1 ? 'y has' : 'ies have'),
            );
        }
        if ($flagged === 0) {
            return ['coverage' => $coverage, 'insights' => []];
        }

        $evidence = [
            Evidence::metric('Open opportunities', $openCount, 'opportunities', 'CRM opportunities'),
            Evidence::metric(sprintf('Not updated for %d days or more (the last-updated date stands in for activity: a PROXY)', $inactiveDays), $untouched, 'opportunities'),
            Evidence::metric('Closing date already passed', $pastClose, 'opportunities'),
            Evidence::metric('Untouched or past the closing date (each counted once)', $flagged, 'opportunities'),
            Evidence::metric('Open opportunities with no last-updated date (cannot be assessed for inactivity)', $unknown, 'opportunities'),
        ];
        $ids = $open()->where(fn ($q) => $q->where($untouchedRule)->orWhere($pastCloseRule))->orderBy('id')->limit(self::MAX_ENTITY_EVIDENCE)->pluck('id')->all();
        array_push($evidence, ...$this->entities($caller, 'Opportunity needing attention', 'crm_opportunity', $ids));

        return ['coverage' => $coverage, 'insights' => [new Insight(
            $this->key($caller, 'crm', $clock->today()), $this->id(), $this->domain(), 'deal_health', self::pct($flagged, $openCount) >= self::CRM_HIGH_PCT ? 'high' : 'attention',
            sprintf('%d CRM opportunities are stale or past their closing date', $flagged),
            sprintf('%d of %d open opportunities have not been updated for %d days or more (%d) or have a closing date in the past (%d); each is counted once.', $flagged, $openCount, $inactiveDays, $untouched, $pastClose),
            'The pipeline and the forecast include these opportunities at the stage and closing date recorded on them; the opportunities screen shows each one.',
            $evidence, $coverage, $clock->asOf(),
            'Update the stage and closing date of each, or close the opportunity as lost.', '/module/crm/sales/opportunities', 'rule_based',
            $this->audience(), [Taxonomy::SURFACE_CRM_OPPORTUNITIES], null,
        )]];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string> id => latest non-system activity time
     */
    private function lastActivity(string $column, array $ids, int $tenant): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = $this->db->table('gtm_activities')->where('sub_institute_id', $tenant)->where('type', '!=', 'system')->whereIn($column, $chunk)
                ->selectRaw("{$column} as k, MAX(occurred_at) as m")->groupBy($column)->get();
            foreach ($rows as $r) {
                if ($r->m !== null && $r->k !== null) {
                    $out[(int) $r->k] = (string) $r->m;
                }
            }
        }

        return $out;
    }

    private function maxDate(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return strcmp($a, $b) >= 0 ? $a : $b;
    }

    private function daysBetween(string $earlier, CarbonImmutable $now): int
    {
        return (int) floor(($now->getTimestamp() - CarbonImmutable::parse($earlier, 'UTC')->getTimestamp()) / 86400);
    }
}
