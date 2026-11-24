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
use Illuminate\Database\Query\Builder;

/**
 * Renewals that need action and customers whose recorded health band is at risk. Needs `view` on /gtm/customers.
 *
 *   RENEWALS  : gtm_renewals not deleted, status upcoming or in_progress. `overdue` = renewal_date before today; `due soon` = today .. today +
 *               renewal_alert_days (default 60, P-9: a code default, env override unread). An overdue renewal makes the finding `high`.
 *   HEALTH    : gtm_health_snapshots of LIVE accounts only (joined to gtm_accounts of the same tenant with deleted_at NULL, so a deleted account or an
 *               account id that belongs to another tenant is never counted), on the LATEST snapshot day. Band `at_risk` is reported. A snapshot with a NULL score
 *               or band `unknown` means "not enough data" and is NEVER read as healthy or as at risk; it is counted separately.
 *   STALENESS : the latest snapshot day must be no older than SNAPSHOT_STALE_DAYS. An older one is not current health, so no at-risk finding is made from it
 *               (an insight stamped "as of now" from old data would mislead). With nothing else to assess the detector answers "not enough data" and says how
 *               old the snapshot is; with renewals to assess, only the health part is withheld and the coverage note says why.
 *   TREND     : only when the tenant has at least `customers_min_snapshots` distinct snapshot days (P-11). Then the at-risk count on the latest day is compared
 *               with the earliest snapshot day in the last TREND_LOOKBACK_DAYS; below that, only the latest-day band is reported and no trend is claimed.
 * Nothing about amounts is read. The prose states what is recorded and what a person can check; it makes no claim about what will happen.
 */
final class CustomerRenewalDetector extends CommercialDetector
{
    private const LINKS = [CommercialAccess::LINK_GTM_CUSTOMERS];
    public const OPEN_STATUSES = ['upcoming', 'in_progress'];

    /** PROVISIONAL (not calibrated against real data): oldest acceptable age, in days, of the latest health snapshot. Public: the cross-functional detector reuses it. */
    public const SNAPSHOT_STALE_DAYS = 3;
    /** PROVISIONAL (not calibrated against real data): how far back the trend comparison looks, in days. */
    private const TREND_LOOKBACK_DAYS = 30;
    /** PROVISIONAL (not calibrated against real data): at-risk share of the accounts on the latest day from which the finding is `high` rather than `attention`. */
    private const AT_RISK_HIGH_PCT = 25;

    public function id(): string
    {
        return 'commercial.customer_renewals';
    }

    public function title(): string
    {
        return 'Customer renewals and health';
    }

    public function surfaces(): array
    {
        return [Taxonomy::SURFACE_GTM_CUSTOMERS];
    }

    public function authorizes(IntelligenceCaller $caller): bool
    {
        return $caller->canAny(self::LINKS);
    }

    /**
     * THE "renewal needs action" rule, shared with the cross-functional detector: an open status (upcoming or in progress) and a renewal date on or before
     * `$dueUntil` (past dates included: those are the overdue ones). `$prefix` is a table alias including its dot.
     */
    public static function applyNeedsActionRule(Builder $query, string $dueUntil, string $prefix = ''): Builder
    {
        return $query->whereIn($prefix . 'status', self::OPEN_STATUSES)->where($prefix . 'renewal_date', '<=', $dueUntil);
    }

    /** Snapshots of live accounts of this tenant (columns are aliased `s.`). Public: the cross-functional detector reuses the same live-account join. */
    public function liveSnapshots(int $tenant): Builder
    {
        return $this->db->table('gtm_health_snapshots as s')
            ->join('gtm_accounts as a', function ($join) {
                $join->on('a.id', '=', 's.account_id')->on('a.sub_institute_id', '=', 's.sub_institute_id');
            })
            ->where('s.sub_institute_id', $tenant)
            ->whereNull('a.deleted_at');
    }

    public function detect(IntelligenceCaller $caller, DetectionClock $clock): DetectorResult
    {
        $tenant = $caller->tenantId;
        $today = $clock->today();
        $alertDays = $this->thresholds->int('renewal_alert_days');
        $dueUntil = $clock->daysAhead($alertDays);

        $renewals = fn () => $this->db->table('gtm_renewals')->where('sub_institute_id', $tenant)->whereNull('deleted_at');
        $openRenewals = fn () => $renewals()->whereIn('status', self::OPEN_STATUSES);
        $renewalCount = (int) $renewals()->count();

        $latestDay = $this->liveSnapshots($tenant)->max('s.snapshot_date');
        $staleNote = null;
        $healthUsable = false;
        if ($latestDay !== null) {
            if ((string) $latestDay >= $clock->daysAgo(self::SNAPSHOT_STALE_DAYS)) {
                $healthUsable = true;
            } else {
                $age = (int) CarbonImmutable::parse((string) $latestDay, 'UTC')->startOfDay()->diffInDays($clock->instant()->startOfDay(), true);
                $staleNote = sprintf('The latest health snapshot is %d days old', $age);
            }
        }
        $latestAccounts = $healthUsable ? (int) $this->liveSnapshots($tenant)->where('s.snapshot_date', $latestDay)->count() : 0;

        $basis = 'renewals recorded plus accounts on the latest health snapshot';
        if ($renewalCount + $latestAccounts < 1) {
            $reason = $staleNote !== null ? $staleNote . ' and no renewals are recorded, so there is nothing current to assess.' : 'No renewals and no customer health snapshots are recorded yet.';

            return DetectorResult::insufficient($this, new Coverage(0, null, $basis, false, $reason), $clock->asOf());
        }
        $coverage = new Coverage($renewalCount + $latestAccounts, null, $basis, true, $staleNote !== null ? $staleNote . '; the health band was not assessed.' : null);

        $insights = [];

        $overdue = (int) $openRenewals()->where('renewal_date', '<', $today)->count();
        $dueSoon = (int) $openRenewals()->where('renewal_date', '>=', $today)->where('renewal_date', '<=', $dueUntil)->count();
        if ($overdue + $dueSoon > 0) {
            $unowned = (int) $openRenewals()->where('renewal_date', '<=', $dueUntil)->whereNull('owner_user_id')->count();
            $ids = $openRenewals()->where('renewal_date', '<=', $dueUntil)->orderBy('renewal_date')->limit(self::MAX_ENTITY_EVIDENCE)->pluck('id')->all();
            $evidence = [
                Evidence::metric('Renewals past their date and still open', $overdue, 'renewals', 'GTM renewals'),
                Evidence::metric("Renewals due in the next {$alertDays} days", $dueSoon, 'renewals'),
                Evidence::metric('Of these, with no owner', $unowned, 'renewals'),
                Evidence::window('Renewal dates up to', $today, $dueUntil),
            ];
            array_push($evidence, ...$this->entities($caller, 'Renewal', 'gtm_renewal', $ids));
            $insights[] = new Insight(
                $this->key($caller, 'renewals', $today), $this->id(), $this->domain(), 'renewals', $overdue > 0 ? 'high' : 'attention',
                $overdue > 0 ? sprintf('%d renewal(s) are overdue and %d more are due soon', $overdue, $dueSoon) : sprintf('%d renewal(s) are due in the next %d days', $dueSoon, $alertDays),
                sprintf('%d open renewal(s) have a renewal date that has already passed and %d fall due within %d days; %d of them have no owner recorded.', $overdue, $dueSoon, $alertDays, $unowned),
                'These renewals are still open (status upcoming or in progress) and their dates are on or before the window shown; the customers screen lists each one with its owner.',
                $evidence, $coverage, $clock->asOf(), 'Assign an owner and record the renewal status, starting with the overdue ones.', '/gtm/customers', 'measured',
                $this->audience(), $this->surfaces(), null,
            );
        }

        if ($healthUsable) {
            $snapshots = fn () => $this->liveSnapshots($tenant)->where('s.snapshot_date', $latestDay);
            $atRisk = (int) $snapshots()->where('s.band', 'at_risk')->count();
            $unknown = (int) $snapshots()->where(fn ($q) => $q->whereNull('s.score')->orWhere('s.band', 'unknown'))->count();
            if ($atRisk > 0) {
                $days = (int) $this->liveSnapshots($tenant)->distinct()->count('s.snapshot_date');
                $evidence = [
                    Evidence::metric('Accounts with a health snapshot on the latest day', $latestAccounts, 'accounts', 'customer health snapshots'),
                    Evidence::metric('At risk', $atRisk, 'accounts'),
                    Evidence::metric('Not enough data to score (not counted as healthy or at risk)', $unknown, 'accounts'),
                    Evidence::metric('Latest snapshot day', (string) $latestDay),
                ];
                $trend = '';
                if ($days >= $this->thresholds->int('customers_min_snapshots')) {
                    $earlier = $this->liveSnapshots($tenant)->where('s.snapshot_date', '>=', $clock->daysAgo(self::TREND_LOOKBACK_DAYS))->where('s.snapshot_date', '<', $latestDay)->min('s.snapshot_date');
                    if ($earlier !== null) {
                        $before = (int) $this->liveSnapshots($tenant)->where('s.snapshot_date', $earlier)->where('s.band', 'at_risk')->count();
                        $evidence[] = Evidence::metric('At risk on the earliest snapshot day in the last ' . self::TREND_LOOKBACK_DAYS . " days ({$earlier})", $before, 'accounts');
                        $trend = sprintf(' On %s it was %d.', $earlier, $before);
                    }
                } else {
                    $evidence[] = Evidence::metric('Snapshot days recorded (a trend needs ' . $this->thresholds->int('customers_min_snapshots') . ')', $days, 'days');
                }
                $ids = $snapshots()->where('s.band', 'at_risk')->orderBy('s.account_id')->limit(self::MAX_ENTITY_EVIDENCE)->pluck('s.account_id')->all();
                array_push($evidence, ...$this->entities($caller, 'At-risk account', 'gtm_account', $ids));
                $insights[] = new Insight(
                    $this->key($caller, 'at_risk', $today), $this->id(), $this->domain(), 'customer_health', self::pct($atRisk, max(1, $latestAccounts)) >= self::AT_RISK_HIGH_PCT ? 'high' : 'attention',
                    sprintf('%d customer account(s) are in the at-risk band', $atRisk),
                    sprintf('On the latest health snapshot (%s), %d of %d accounts are in the at-risk band.%s', $latestDay, $atRisk, $latestAccounts, $trend),
                    'The band is computed from recorded activity, responsiveness, contact coverage and renewal timing; the customers screen shows the components behind each account\'s score.',
                    $evidence, new Coverage($latestAccounts, null, 'live accounts on the latest health snapshot', true), $clock->asOf(),
                    'Open the at-risk accounts, check the components behind each score and record the follow-up.', '/gtm/customers', 'rule_based',
                    $this->audience(), $this->surfaces(), null,
                );
            }
        }

        return DetectorResult::ok($this, $insights, $coverage, $clock->asOf());
    }
}
