<?php

namespace App\Domain\Gtm\Customers;

use Illuminate\Support\Carbon;

/**
 * Customer health, computed ONLY from records that exist, and always explainable.
 *
 * Five components, each scored 0-100 from real data or marked NOT AVAILABLE when the data is absent:
 *   recency        days since the last logged non-system activity
 *   responsiveness inbound messages from the customer in the last 90 days
 *   coverage       active contacts at the account, and whether a decision maker is among them
 *   contact_health share of the account's contacts that are still reachable (not bounced/unsubscribed)
 *   renewal        the next open renewal: its date and whether it is being worked
 *
 * The score is the weighted mean of the AVAILABLE components only, and only when at least two are
 * available; otherwise it is null with band `unknown`. A missing component is never treated as zero (that
 * would punish an account for data nobody entered) and never as full marks. What is missing is listed,
 * so the screen can tell an administrator exactly what to record.
 *
 * The bands and thresholds below are rules of THIS module, written down here and in config/gtm.php, not
 * claims about the customer. This class is pure: facts in, verdict out, no database.
 */
final class CustomerHealth
{
    /** weight of each component in the mean */
    public const WEIGHTS = ['recency' => 30, 'responsiveness' => 20, 'coverage' => 20, 'contact_health' => 10, 'renewal' => 20];

    /**
     * @param  array{
     *   days_since_activity: ?int, activities_total: int, inbound_90d: int, outbound_90d: int,
     *   contacts: int, active_contacts: int, unreachable_contacts: int, has_decision_maker: bool,
     *   renewal: ?array{date: string, status: string}
     * }  $f
     * @return array{score: ?int, band: string, components: array<string, array<string, mixed>>, risks: list<string>, missing: list<string>}
     */
    public static function assess(array $f, ?Carbon $now = null): array
    {
        $now ??= now();
        $healthyAt = (int) config('gtm.health.healthy_at', 70);
        $watchAt = (int) config('gtm.health.watch_at', 40);
        $quiet = (int) config('gtm.health.quiet_after_days', 60);
        $c = [];
        $risks = [];
        $missing = [];

        // recency
        if ($f['days_since_activity'] === null) {
            $c['recency'] = self::na('No activity has ever been logged for this account.');
            $missing[] = 'No activity is logged for this account, so recency cannot be judged.';
        } else {
            $d = $f['days_since_activity'];
            $s = $d <= 14 ? 100 : ($d <= 30 ? 80 : ($d <= 60 ? 50 : ($d <= 90 ? 25 : 0)));
            $c['recency'] = ['score' => $s, 'value' => $d, 'detail' => "Last activity {$d} day(s) ago."];
            if ($d >= $quiet) {
                $risks[] = "No activity logged for {$d} days.";
            }
        }

        // responsiveness
        if ($f['inbound_90d'] > 0) {
            $s = $f['inbound_90d'] >= 3 ? 100 : ($f['inbound_90d'] === 2 ? 75 : 50);
            $c['responsiveness'] = ['score' => $s, 'value' => $f['inbound_90d'], 'detail' => "{$f['inbound_90d']} inbound message(s) in the last 90 days."];
        } elseif ($f['outbound_90d'] > 0) {
            $c['responsiveness'] = ['score' => 0, 'value' => 0, 'detail' => "We sent {$f['outbound_90d']} message(s) in the last 90 days and recorded no reply."];
            $risks[] = 'We reached out in the last 90 days and no reply is recorded.';
        } else {
            $c['responsiveness'] = self::na('No messages in either direction in the last 90 days.');
            $missing[] = 'No inbound or outbound messages are recorded in the last 90 days.';
        }

        // coverage
        if ($f['contacts'] === 0) {
            $c['coverage'] = ['score' => 0, 'value' => 0, 'detail' => 'No contacts are recorded at this account.'];
            $risks[] = 'No contacts are recorded at this account.';
        } else {
            $s = $f['has_decision_maker'] ? ($f['active_contacts'] >= 2 ? 100 : 75) : ($f['active_contacts'] >= 1 ? 50 : 20);
            $c['coverage'] = ['score' => $s, 'value' => $f['active_contacts'], 'detail' => "{$f['active_contacts']} active contact(s); ".($f['has_decision_maker'] ? 'a decision maker is recorded.' : 'no decision maker is recorded.')];
            if (! $f['has_decision_maker']) {
                $risks[] = 'No economic buyer or decision maker is recorded.';
            }
        }

        // contact health
        if ($f['contacts'] === 0) {
            $c['contact_health'] = self::na('No contacts to assess.');
        } else {
            $reachable = $f['contacts'] - $f['unreachable_contacts'];
            $c['contact_health'] = ['score' => (int) round($reachable / $f['contacts'] * 100), 'value' => $reachable, 'detail' => "{$reachable} of {$f['contacts']} contact(s) can be reached."];
            if ($f['unreachable_contacts'] > 0) {
                $risks[] = "{$f['unreachable_contacts']} contact(s) are bounced, unsubscribed or do-not-contact.";
            }
        }

        // renewal
        if ($f['renewal'] === null) {
            $c['renewal'] = self::na('No open renewal is recorded.');
            $missing[] = 'No renewal is recorded for this customer, so renewal risk cannot be judged.';
        } else {
            $days = (int) $now->copy()->startOfDay()->diffInDays(Carbon::parse($f['renewal']['date'])->startOfDay(), false);
            $inProgress = $f['renewal']['status'] === 'in_progress';
            if ($days < 0) {
                $s = 0;
                $detail = 'The renewal date passed '.abs($days).' day(s) ago and it is still open.';
                $risks[] = $detail;
            } elseif ($inProgress) {
                $s = 80;
                $detail = "Renewal in {$days} day(s), being worked.";
            } elseif ($days <= 30) {
                $s = 30;
                $detail = "Renewal in {$days} day(s) and not yet in progress.";
                $risks[] = $detail;
            } elseif ($days <= 90) {
                $s = 70;
                $detail = "Renewal in {$days} day(s).";
            } else {
                $s = 100;
                $detail = "Renewal in {$days} day(s).";
            }
            $c['renewal'] = ['score' => $s, 'value' => $days, 'detail' => $detail];
        }

        $num = 0;
        $den = 0;
        $available = 0;
        foreach ($c as $key => $comp) {
            if ($comp['score'] !== null) {
                $num += $comp['score'] * self::WEIGHTS[$key];
                $den += self::WEIGHTS[$key];
                $available++;
            }
        }
        $score = $available >= 2 && $den > 0 ? (int) round($num / $den) : null;
        $band = $score === null ? 'unknown' : ($score >= $healthyAt ? 'healthy' : ($score >= $watchAt ? 'watch' : 'at_risk'));
        if ($score === null) {
            $missing[] = 'Fewer than two health components have data, so no score is given.';
        }

        foreach ($c as $key => &$comp) {
            $comp['weight'] = self::WEIGHTS[$key];
        }

        return ['score' => $score, 'band' => $band, 'components' => $c, 'risks' => $risks, 'missing' => array_values(array_unique($missing))];
    }

    /** @return array{score: null, value: null, detail: string} */
    private static function na(string $detail): array
    {
        return ['score' => null, 'value' => null, 'detail' => $detail];
    }
}
