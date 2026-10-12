<?php

namespace App\Domain\Gtm\Customers;

use App\Domain\Gtm\GtmAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gathers the real records behind each customer account and hands them to CustomerHealth. A "customer" is
 * an account whose stage is `customer` - set by a confirmed won deal or by an administrator, never inferred.
 */
final class CustomerService
{
    public const OPEN_RENEWAL = ['upcoming', 'in_progress'];

    /**
     * Facts for a set of customer accounts, in a handful of queries (not one per account).
     *
     * @param  list<int>  $accountIds
     * @return array<int, array<string, mixed>> keyed by account id
     */
    public function facts(int $tenant, array $accountIds, ?Carbon $now = null): array
    {
        $now ??= now();
        if ($accountIds === []) {
            return [];
        }
        $since = $now->copy()->subDays(90)->toDateTimeString();

        $acts = DB::table('gtm_activities')->where('sub_institute_id', $tenant)->whereIn('account_id', $accountIds)->where('type', '!=', 'system')
            ->selectRaw("account_id, MAX(occurred_at) last_at, COUNT(*) total, SUM(CASE WHEN occurred_at >= ? AND direction = 'inbound' THEN 1 ELSE 0 END) inb, SUM(CASE WHEN occurred_at >= ? AND direction = 'outbound' THEN 1 ELSE 0 END) outb", [$since, $since])
            ->groupBy('account_id')->get()->keyBy('account_id');
        $contacts = DB::table('gtm_contacts')->where('sub_institute_id', $tenant)->whereIn('account_id', $accountIds)->whereNull('deleted_at')->get(['account_id', 'status', 'role_in_deal'])->groupBy('account_id');
        $renewals = DB::table('gtm_renewals')->where('sub_institute_id', $tenant)->whereIn('account_id', $accountIds)->whereNull('deleted_at')->whereIn('status', self::OPEN_RENEWAL)
            ->orderBy('renewal_date')->get(['id', 'account_id', 'renewal_date', 'status', 'amount', 'currency', 'name'])->groupBy('account_id');

        $out = [];
        foreach ($accountIds as $id) {
            $a = $acts[$id] ?? null;
            $cs = $contacts[$id] ?? collect();
            $unreachable = $cs->whereIn('status', ['bounced', 'unsubscribed', 'do_not_contact'])->count();
            $next = ($renewals[$id] ?? collect())->first();
            $out[$id] = [
                'days_since_activity' => $a && $a->last_at ? (int) Carbon::parse($a->last_at)->startOfDay()->diffInDays($now->copy()->startOfDay()) : null,
                'activities_total' => (int) ($a->total ?? 0), 'inbound_90d' => (int) ($a->inb ?? 0), 'outbound_90d' => (int) ($a->outb ?? 0),
                'contacts' => $cs->count(), 'active_contacts' => $cs->where('status', 'active')->count(), 'unreachable_contacts' => $unreachable,
                'has_decision_maker' => $cs->where('status', 'active')->whereIn('role_in_deal', ['economic_buyer', 'decision_maker'])->isNotEmpty(),
                'renewal' => $next ? ['id' => (int) $next->id, 'date' => (string) $next->renewal_date, 'status' => $next->status, 'amount' => $next->amount, 'currency' => $next->currency, 'name' => $next->name] : null,
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> every customer account with its health, worst first */
    public function customers(int $tenant, ?Carbon $now = null): array
    {
        $accounts = DB::table('gtm_accounts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->where('stage', 'customer')->orderBy('name')->get(['id', 'name', 'industry', 'owner_user_id']);
        $facts = $this->facts($tenant, $accounts->pluck('id')->all(), $now);
        $trend = $this->trend($tenant, $accounts->pluck('id')->all());
        $rows = [];
        foreach ($accounts as $a) {
            $h = CustomerHealth::assess($facts[$a->id], $now);
            $rows[] = ['account_id' => (int) $a->id, 'name' => $a->name, 'industry' => $a->industry, 'owner_user_id' => $a->owner_user_id, 'health' => $h, 'renewal' => $facts[$a->id]['renewal'], 'trend' => $trend[$a->id] ?? null];
        }
        // at-risk first, unknown next, healthy last; ties by name
        $order = ['at_risk' => 0, 'watch' => 1, 'unknown' => 2, 'healthy' => 3];
        usort($rows, fn ($x, $y) => [$order[$x['health']['band']], $x['name']] <=> [$order[$y['health']['band']], $y['name']]);

        return $rows;
    }

    /** One account's health, or null when it is not one of this tenant's customers. @return array<string, mixed>|null */
    public function one(int $tenant, int $accountId, ?Carbon $now = null): ?array
    {
        $a = DB::table('gtm_accounts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->where('stage', 'customer')->find($accountId, ['id', 'name', 'industry']);
        if (! $a) {
            return null;
        }
        $f = $this->facts($tenant, [$accountId], $now)[$accountId];

        return ['account_id' => (int) $a->id, 'name' => $a->name, 'facts' => $f, 'health' => CustomerHealth::assess($f, $now), 'history' => $this->history($tenant, $accountId)];
    }

    /** Daily snapshots (written by `gtm:health-snapshot`); returns how many were stored for the tenant today. */
    public function snapshot(int $tenant): int
    {
        $n = 0;
        foreach ($this->customers($tenant) as $c) {
            DB::table('gtm_health_snapshots')->updateOrInsert(
                ['sub_institute_id' => $tenant, 'account_id' => $c['account_id'], 'snapshot_date' => now()->toDateString()],
                ['score' => $c['health']['score'], 'band' => $c['health']['band'], 'components' => json_encode($c['health']['components']), 'created_at' => now()]
            );
            $n++;
        }

        return $n;
    }

    /** @return list<array{date: string, score: ?int, band: string}> */
    public function history(int $tenant, int $accountId, int $limit = 30): array
    {
        return DB::table('gtm_health_snapshots')->where('sub_institute_id', $tenant)->where('account_id', $accountId)->orderByDesc('snapshot_date')->limit($limit)->get(['snapshot_date', 'score', 'band'])
            ->map(fn ($r) => ['date' => (string) $r->snapshot_date, 'score' => $r->score === null ? null : (int) $r->score, 'band' => $r->band])->reverse()->values()->all();
    }

    /**
     * A trend only where there is history: the change between the two latest snapshots. Null otherwise - never extrapolated.
     *
     * @param  list<int>  $accountIds
     * @return array<int, array{from: ?int, to: ?int, change: ?int}>
     */
    private function trend(int $tenant, array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('gtm_health_snapshots')->where('sub_institute_id', $tenant)->whereIn('account_id', $accountIds)->orderByDesc('snapshot_date')->get(['account_id', 'score'])->groupBy('account_id') as $id => $rows) {
            if ($rows->count() >= 2) {
                [$to, $from] = [$rows[0]->score, $rows[1]->score];
                $out[$id] = ['from' => $from === null ? null : (int) $from, 'to' => $to === null ? null : (int) $to, 'change' => ($from !== null && $to !== null) ? (int) $to - (int) $from : null];
            }
        }

        return $out;
    }

    /**
     * What needs attention now, computed from real records: overdue renewals, renewals due soon with no work
     * started, and customers whose health band is at_risk. Used by the screen and by the daily sweep.
     *
     * @return array{renewals: list<array<string, mixed>>, at_risk: list<array<string, mixed>>}
     */
    public function alerts(int $tenant, ?Carbon $now = null): array
    {
        $now ??= now();
        $windowDays = (int) config('gtm.health.renewal_alert_days', 60);
        $renewals = DB::table('gtm_renewals as r')->join('gtm_accounts as a', fn ($j) => $j->on('a.id', '=', 'r.account_id')->where('a.sub_institute_id', '=', $tenant))
            ->where('r.sub_institute_id', $tenant)->whereNull('r.deleted_at')->whereIn('r.status', self::OPEN_RENEWAL)->where('r.renewal_date', '<=', $now->copy()->addDays($windowDays)->toDateString())
            ->orderBy('r.renewal_date')->get(['r.id', 'r.name', 'r.account_id', 'a.name as account_name', 'r.renewal_date', 'r.status', 'r.amount', 'r.currency']);
        $renewalAlerts = [];
        foreach ($renewals as $r) {
            $days = (int) $now->copy()->startOfDay()->diffInDays(Carbon::parse($r->renewal_date)->startOfDay(), false);
            $kind = $days < 0 ? 'overdue' : ($r->status === 'upcoming' ? 'due_soon_not_started' : null);
            if ($kind) {
                $renewalAlerts[] = ['renewal_id' => (int) $r->id, 'name' => $r->name, 'account_id' => (int) $r->account_id, 'account_name' => $r->account_name, 'renewal_date' => (string) $r->renewal_date, 'days' => $days, 'status' => $r->status, 'kind' => $kind, 'amount' => $r->amount, 'currency' => $r->currency];
            }
        }
        $atRisk = array_values(array_map(fn ($c) => ['account_id' => $c['account_id'], 'name' => $c['name'], 'score' => $c['health']['score'], 'risks' => $c['health']['risks']], array_filter($this->customers($tenant, $now), fn ($c) => $c['health']['band'] === 'at_risk')));

        return ['renewals' => $renewalAlerts, 'at_risk' => $atRisk];
    }

    /**
     * The daily sweep: emit an event for each NEWLY alerting renewal/customer so a workflow can react. The
     * idempotency key bakes in the day (renewals) or ISO week (customers), so a repeat run cannot re-announce.
     *
     * @return array{renewal_events: int, customer_events: int}
     */
    public function sweep(int $tenant, ?Carbon $now = null): array
    {
        $now ??= now();
        $alerts = $this->alerts($tenant, $now);
        $r = 0;
        $c = 0;
        foreach ($alerts['renewals'] as $a) {
            $key = "renewal-due:{$a['renewal_id']}:{$a['kind']}:".$now->format('o-W');
            $before = DB::table('g2g_event')->where('sub_institute_id', $tenant)->where('idempotency_key', $key)->exists();
            GtmAudit::record('gtm.renewal.due_soon', $tenant, 'gtm_renewals', $a['renewal_id'], null, ['kind' => $a['kind'], 'days' => $a['days'], 'renewal_date' => $a['renewal_date']], ['source' => 'sweep'], $key);
            $before ?: $r++;
        }
        foreach ($alerts['at_risk'] as $a) {
            $key = "customer-at-risk:{$a['account_id']}:".$now->format('o-W');
            $before = DB::table('g2g_event')->where('sub_institute_id', $tenant)->where('idempotency_key', $key)->exists();
            GtmAudit::record('gtm.customer.at_risk', $tenant, 'gtm_accounts', $a['account_id'], null, ['score' => $a['score'], 'risks' => $a['risks']], ['source' => 'sweep'], $key);
            $before ?: $c++;
        }

        return ['renewal_events' => $r, 'customer_events' => $c];
    }
}
