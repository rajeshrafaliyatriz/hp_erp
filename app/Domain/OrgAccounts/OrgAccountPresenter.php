<?php

namespace App\Domain\OrgAccounts;

use App\Domain\ProductMaster\ProductMaster;
use App\Domain\Signals\Opportunities\DateStatus;
use App\Support\ColumnExists;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the Organisation 360 API shows (slice 3, Docs/agentic-crm/slices/S03-organisation-360.md; JSON shapes in g2gv0 services/org-accounts/types.ts):
 * the list item and the 360 aggregate of one identity. Read only.
 *
 * ONE ORGANISATION, ONE TENANT. Every query here carries `sub_institute_id`, including the ones that follow a pointer from the identity into a source
 * table, so a pointer that was ever mis-set into another organisation's row still shows nothing of it.
 *
 * BOUNDED. Every list is capped at CAP rows (`totals` says how many exist) and the number of queries does not depend on the number of rows: one query
 * per section, one batch lookup of the people's names, one grouped query for the list's signal counts.
 *
 * HOSTS DIFFER. The live database is MariaDB 10.1 and lacks tables and columns the dev one has, so every optional table is checked with
 * Schema::hasTable and every optional column with ColumnExists (both answered once per request, then remembered). Only plain query-builder SQL is used:
 * it runs on sqlite (the tests), MariaDB 10.11 and 10.1.
 */
final class OrgAccountPresenter
{
    /** Rows per section of the 360. */
    public const CAP = 20;

    public const MAX_PER_PAGE = 50;

    /** What a signal decision is called on the screens (follow_up is the one the screens call "Contacted"). */
    private const DECISIONS = ['reviewed' => 'Reviewed', 'dismissed' => 'Dismissed', 'follow_up' => 'Contacted', 'reopened' => 'Reopened', 'promoted' => 'Made into a lead'];

    /** @var array<string, bool> */
    private array $tables = [];

    /** @var array<string, list<string>> */
    private array $columns = [];

    public function __construct(private readonly int $tenant) {}

    // ---- the list ----

    /**
     * One page of the list. $filters: q, lifecycle, has_crm / has_gtm / has_signals (true = has a row there, false = has none), sort, page, per_page.
     *
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function page(array $filters): array
    {
        $query = OrgAccount::query()->where('sub_institute_id', $this->tenant);

        $needle = trim((string) ($filters['q'] ?? ''));
        if ($needle !== '') {
            // "!" is the escape character (a backslash would need doubling in a PHP string and differs between sqlite and MariaDB's string rules).
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle) . '%';
            $query->where(fn ($w) => $w->whereRaw("name LIKE ? ESCAPE '!'", [$like])->orWhereRaw("domain LIKE ? ESCAPE '!'", [$like]));
        }
        if (! empty($filters['lifecycle'])) {
            $query->where('lifecycle', $filters['lifecycle']);
        }
        foreach (['has_crm' => 'crm_organization_id', 'has_gtm' => 'gtm_account_id', 'has_signals' => 'g2g_company_id'] as $filter => $pointer) {
            if (array_key_exists($filter, $filters) && $filters[$filter] !== null) {
                $filters[$filter] ? $query->whereNotNull($pointer) : $query->whereNull($pointer);
            }
        }

        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $total = (int) (clone $query)->count();

        // Sorted by name (A to Z) or by last change (newest first) unless `dir` says otherwise; the id keeps pages stable when two rows tie.
        $column = ($filters['sort'] ?? 'name') === 'updated_at' ? 'updated_at' : 'name';
        $direction = ($filters['dir'] ?? null) === 'asc' || (($filters['dir'] ?? null) === null && $column === 'name') ? 'asc' : 'desc';
        $rows = $query->orderBy($column, $direction)->orderBy('id', $direction)->forPage($page, $perPage)
            ->get(['id', 'name', 'domain', 'lifecycle', 'owner_user_id', 'crm_organization_id', 'gtm_account_id', 'g2g_company_id', 'updated_at']);

        return [
            'data' => $this->items($rows),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ];
    }

    /** Counts over every non-deleted identity of the organisation (the list's filters do not narrow them). @return array<string, int> */
    public function summary(int $suggestions): array
    {
        $lifecycle = fn (string $l) => "SUM(CASE WHEN lifecycle = '{$l}' THEN 1 ELSE 0 END) as {$l}";
        $row = OrgAccount::query()->where('sub_institute_id', $this->tenant)->toBase()->selectRaw(implode(', ', [
            'COUNT(*) as total', ...array_map($lifecycle, OrgAccount::LIFECYCLES),
            'SUM(CASE WHEN crm_organization_id IS NOT NULL THEN 1 ELSE 0 END) as with_crm',
            'SUM(CASE WHEN gtm_account_id IS NOT NULL THEN 1 ELSE 0 END) as with_gtm',
            'SUM(CASE WHEN g2g_company_id IS NOT NULL THEN 1 ELSE 0 END) as with_signals',
        ]))->first();

        $out = ['total' => (int) ($row->total ?? 0)];
        foreach ([...OrgAccount::LIFECYCLES, 'with_crm', 'with_gtm', 'with_signals'] as $key) {
            $out[$key] = (int) ($row->{$key} ?? 0);
        }

        return $out + ['suggestions' => $suggestions];
    }

    /**
     * List items for identities of this organisation, with their source details looked up in batches.
     *
     * @param  Collection<int, OrgAccount>  $accounts
     * @return list<array<string, mixed>>
     */
    public function items(Collection $accounts): array
    {
        $names = $this->userNames($accounts->pluck('owner_user_id')->all());
        $crm = $this->pluckBySourceId('crm_organizations', 'account_no', $accounts->pluck('crm_organization_id')->all(), softDeletes: true);
        $gtm = $this->pluckBySourceId('gtm_accounts', 'stage', $accounts->pluck('gtm_account_id')->all(), softDeletes: true);
        $signals = $this->signalCounts($accounts->pluck('g2g_company_id')->all());

        return $accounts->map(fn (OrgAccount $a) => [
            'id' => (int) $a->id,
            'name' => (string) $a->name,
            'domain' => $a->domain,
            'lifecycle' => $a->lifecycle,
            'owner_user_id' => $a->owner_user_id,
            'owner_name' => $a->owner_user_id !== null ? ($names[$a->owner_user_id] ?? null) : null,
            'sources' => [
                'crm' => $a->crm_organization_id !== null ? ['id' => $a->crm_organization_id, 'account_no' => $crm[$a->crm_organization_id] ?? null] : null,
                'gtm' => $a->gtm_account_id !== null ? ['id' => $a->gtm_account_id, 'stage' => $gtm[$a->gtm_account_id] ?? null] : null,
                'signals' => $a->g2g_company_id !== null ? [
                    'company_id' => $a->g2g_company_id,
                    'open_signals' => $signals[$a->g2g_company_id]['open'] ?? 0,
                    'last_signal_at' => $signals[$a->g2g_company_id]['last'] ?? null,
                ] : null,
            ],
            'updated_at' => $a->updated_at?->toIso8601String(),
        ])->values()->all();
    }

    // ---- the 360 ----

    /** @return array<string, mixed> */
    public function threeSixty(OrgAccount $a): array
    {
        $crmId = $a->crm_organization_id;
        $gtmId = $a->gtm_account_id;
        $companyId = $a->g2g_company_id;

        $crm = $crmId !== null ? $this->sourceRow('crm_organizations', $crmId, ['id', 'account_no', 'name', 'industry', 'phone', 'email', 'website', 'billing_city', 'billing_state', 'assigned_to'], true) : null;
        $gtm = $gtmId !== null ? $this->sourceRow('gtm_accounts', $gtmId, ['id', 'name', 'stage', 'domain', 'icp_fit_score', 'owner_user_id'], true) : null;
        $company = $companyId !== null ? $this->sourceRow('g2g_companies', $companyId, ['id', 'name', 'website', 'industry', 'location', 'first_seen_at', 'type', 'state'], false) : null;

        $signals = $companyId !== null ? $this->signals($companyId) : ['total' => 0, 'needing' => 0, 'rows' => collect()];
        [$contacts, $contactsTotal] = $this->contacts($crmId, $gtmId);
        [$deals, $dealsTotal] = $this->deals($crmId, $gtmId);
        [$activity, $activityTotal] = $this->activity($crmId, $companyId);

        $names = $this->userNames([$a->owner_user_id, $crm->assigned_to ?? null, $gtm->owner_user_id ?? null, ...array_column($activity, 'by')]);
        $nameOf = fn (?int $id) => $id !== null ? ($names[$id] ?? null) : null;
        $productNames = $this->productNames($signals['rows']->pluck('product_master_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all());

        return [
            'identity' => [
                'id' => (int) $a->id,
                'name' => (string) $a->name,
                'domain' => $a->domain,
                'lifecycle' => $a->lifecycle,
                'owner_user_id' => $a->owner_user_id,
                'owner_name' => $nameOf($a->owner_user_id),
                'links' => [
                    'crm' => $crmId !== null ? ['id' => $crmId, 'basis' => $a->crm_link_basis] : null,
                    'gtm' => $gtmId !== null ? ['id' => $gtmId, 'basis' => $a->gtm_link_basis] : null,
                    'company' => $companyId !== null ? ['id' => $companyId, 'basis' => $a->company_link_basis] : null,
                ],
                'created_at' => $a->created_at?->toIso8601String(),
                'updated_at' => $a->updated_at?->toIso8601String(),
            ],
            'crm' => $crm ? [
                'id' => (int) $crm->id, 'account_no' => $crm->account_no, 'name' => (string) $crm->name, 'industry' => $crm->industry, 'phone' => $crm->phone,
                'email' => $crm->email, 'website' => $crm->website, 'billing_city' => $crm->billing_city, 'billing_state' => $crm->billing_state,
                'assigned_to_name' => $nameOf($crm->assigned_to !== null ? (int) $crm->assigned_to : null),
            ] : null,
            'gtm' => $gtm ? [
                'id' => (int) $gtm->id, 'name' => (string) $gtm->name, 'stage' => $gtm->stage, 'domain' => $gtm->domain,
                'icp_fit_score' => $gtm->icp_fit_score !== null ? (int) $gtm->icp_fit_score : null,
                'owner_name' => $nameOf($gtm->owner_user_id !== null ? (int) $gtm->owner_user_id : null),
            ] : null,
            'company' => $company ? [
                'id' => (int) $company->id, 'name' => (string) $company->name, 'website' => $company->website, 'industry' => $company->industry, 'location' => $company->location,
                'state' => $company->state ?? null, 'type' => $company->type ?? null, 'first_seen_at' => $this->iso($company->first_seen_at),
            ] : null,
            'signals' => [
                'total' => $signals['total'],
                'needing_decision' => $signals['needing'],
                'decided' => $signals['total'] - $signals['needing'],
                'latest' => $signals['rows']->map(fn ($r) => [
                    'id' => (int) $r->id,
                    'title' => (string) $r->title,
                    'review_status' => ($r->review_status ?? '') !== '' ? $r->review_status : 'New',
                    'date_info' => DateStatus::classify($r->event_date ?? null, $r->source_published_at ?? null),
                    'business_fit' => $r->business_fit ?? null,
                    'product_master_name' => ($r->product_master_id ?? null) ? ($productNames[(int) $r->product_master_id] ?? null) : null,
                ])->values()->all(),
            ],
            'contacts' => $contacts,
            'deals' => $deals,
            'activity' => array_map(fn (array $l) => ['kind' => $l['kind'], 'at' => $l['at'], 'by_name' => $nameOf($l['by']), 'text' => $l['text'], 'source' => $l['source']], $activity),
            'totals' => ['contacts' => $contactsTotal, 'deals' => $dealsTotal, 'activity' => $activityTotal],
        ];
    }

    // ---- 360 sections ----

    /** @return array{total: int, needing: int, rows: Collection<int, object>} */
    private function signals(int $companyId): array
    {
        $base = $this->signalBase($companyId);
        if ($base === null) {
            return ['total' => 0, 'needing' => 0, 'rows' => collect()];
        }

        // An empty status counts as New, the way SignalReviewService reads it.
        $counts = (clone $base)->selectRaw("COUNT(*) as total, SUM(CASE WHEN review_status IS NULL OR review_status = '' OR review_status = 'New' THEN 1 ELSE 0 END) as needing")->first();
        $table = 'g2g_company_opportunities';
        $columns = ['id', 'title', 'review_status', ...$this->existing($table, ['event_date', 'source_published_at', 'business_fit', 'product_master_id'])];
        $rows = (clone $base)->orderByDesc('first_discovered_at')->orderByDesc('id')->limit(self::CAP)->get($columns);

        return ['total' => (int) ($counts->total ?? 0), 'needing' => (int) ($counts->needing ?? 0), 'rows' => $rows];
    }

    /** The signals of one Signals company: this organisation's, and never the demo rows. */
    private function signalBase(int $companyId): ?Builder
    {
        if (! $this->hasTable('g2g_company_opportunities')) {
            return null;
        }
        $q = DB::table('g2g_company_opportunities')->where('sub_institute_id', $this->tenant)->where('company_id', $companyId);

        return $this->hasColumn('g2g_company_opportunities', 'is_sample') ? $q->where('is_sample', 0) : $q;
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} the lines (CRM and GTM together, by name) and how many exist */
    private function contacts(?int $crmId, ?int $gtmId): array
    {
        $lines = [];
        $total = 0;
        if ($crmId !== null && $this->hasTable('crm_contacts')) {
            $base = DB::table('crm_contacts')->where('sub_institute_id', $this->tenant)->where('organization_id', $crmId)->whereNull('deleted_at');
            [$rows, $n] = $this->capped($base, ['id', 'first_name', 'last_name', 'email', 'phone', 'mobile', 'title'], fn (Builder $q) => $q->orderBy('last_name')->orderBy('first_name')->orderBy('id'));
            $total += $n;
            foreach ($rows as $r) {
                $lines[] = [
                    'source' => 'crm', 'id' => (int) $r->id, 'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')), 'email' => $r->email,
                    'phone' => ($r->phone ?? '') !== '' ? $r->phone : ($r->mobile ?: null), 'role' => $r->title,
                ];
            }
        }
        if ($gtmId !== null && $this->hasTable('gtm_contacts')) {
            $base = DB::table('gtm_contacts')->where('sub_institute_id', $this->tenant)->where('account_id', $gtmId)->whereNull('deleted_at');
            [$rows, $n] = $this->capped($base, ['id', 'full_name', 'email', 'phone', 'title', 'role_in_deal'], fn (Builder $q) => $q->orderBy('full_name')->orderBy('id'));
            $total += $n;
            foreach ($rows as $r) {
                $lines[] = [
                    'source' => 'gtm', 'id' => (int) $r->id, 'name' => (string) $r->full_name, 'email' => $r->email, 'phone' => $r->phone,
                    'role' => ($r->title ?? '') !== '' ? $r->title : ($r->role_in_deal ?: null),
                ];
            }
        }
        usort($lines, fn (array $x, array $y) => [mb_strtolower($x['name']), $x['source'], $x['id']] <=> [mb_strtolower($y['name']), $y['source'], $y['id']]);

        return [array_slice($lines, 0, self::CAP), $total];
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} the lines (CRM opportunities and GTM deals together, latest closing date first) and how many exist */
    private function deals(?int $crmId, ?int $gtmId): array
    {
        $order = fn (string $column) => fn (Builder $q) => $q->orderByRaw("{$column} IS NULL")->orderByDesc($column)->orderByDesc('id');
        $lines = [];
        $total = 0;
        if ($crmId !== null && $this->hasTable('crm_opportunities')) {
            $base = DB::table('crm_opportunities')->where('sub_institute_id', $this->tenant)->where('organization_id', $crmId)->whereNull('deleted_at');
            [$rows, $n] = $this->capped($base, ['id', 'name', 'sales_stage', 'amount', 'currency', 'closing_date'], $order('closing_date'));
            $total += $n;
            foreach ($rows as $r) {
                $lines[] = ['source' => 'crm', 'id' => (int) $r->id, 'name' => (string) $r->name, 'stage' => $r->sales_stage, 'amount' => $r->amount !== null ? (float) $r->amount : null,
                    'currency' => $r->currency, 'closing_date' => $this->day($r->closing_date)];
            }
        }
        if ($gtmId !== null && $this->hasTable('gtm_deals')) {
            $base = DB::table('gtm_deals')->where('sub_institute_id', $this->tenant)->where('account_id', $gtmId)->whereNull('deleted_at');
            [$rows, $n] = $this->capped($base, ['id', 'name', 'stage', 'amount', 'currency', 'expected_close_date'], $order('expected_close_date'));
            $total += $n;
            foreach ($rows as $r) {
                $lines[] = ['source' => 'gtm', 'id' => (int) $r->id, 'name' => (string) $r->name, 'stage' => $r->stage, 'amount' => $r->amount !== null ? (float) $r->amount : null,
                    'currency' => $r->currency, 'closing_date' => $this->day($r->expected_close_date)];
            }
        }
        // The latest closing date first; no closing date last.
        usort($lines, function (array $x, array $y) {
            if ($x['closing_date'] !== $y['closing_date']) {
                return $x['closing_date'] === null ? 1 : ($y['closing_date'] === null ? -1 : strcmp($y['closing_date'], $x['closing_date']));
            }

            return [$x['source'], $x['id']] <=> [$y['source'], $y['id']];
        });

        return [array_slice($lines, 0, self::CAP), $total];
    }

    /**
     * Notes, tasks, signal decisions and leads made from the signals, newest first. `by` is a user id the caller turns into a name.
     *
     * @return array{0: list<array{kind: string, at: string, by: ?int, text: string, source: string}>, 1: int}
     */
    private function activity(?int $crmId, ?int $companyId): array
    {
        $lines = [];
        $total = 0;
        // A line without a time cannot be placed on a timeline, so it is left out of the list and of its count alike.
        $add = function (Builder $base, array $columns, string $time, string $tie, \Closure $line) use (&$lines, &$total) {
            [$rows, $n] = $this->capped($base->whereNotNull($time), $columns, fn (Builder $q) => $q->orderByDesc($time)->orderByDesc($tie));
            $total += $n;
            foreach ($rows as $r) {
                $lines[] = $line($r);
            }
        };

        if ($crmId !== null && $this->hasTable('crm_organization_comments')) {
            $add(DB::table('crm_organization_comments')->where('sub_institute_id', $this->tenant)->where('organization_id', $crmId),
                ['comment', 'created_at', 'created_by'], 'created_at', 'id',
                fn ($r) => ['kind' => 'note', 'at' => $this->iso($r->created_at), 'by' => $this->id($r->created_by), 'text' => (string) $r->comment, 'source' => 'crm_organization_comments']);
        }
        if ($crmId !== null && $this->hasTable('task_management_calendar_events')) {
            $add(DB::table('task_management_calendar_events')->where('sub_institute_id', $this->tenant)->where('linked_type', 'ORGANIZATION')->where('linked_id', $crmId)->whereNull('deleted_at'),
                ['title', 'start_at', 'owner_id'], 'start_at', 'id',
                fn ($r) => ['kind' => 'task', 'at' => $this->iso($r->start_at), 'by' => $this->id($r->owner_id), 'text' => (string) $r->title, 'source' => 'task_management_calendar_events']);
        }
        if ($companyId !== null && $this->hasTable('g2g_signal_reviews') && $this->hasTable('g2g_company_opportunities')) {
            $add($this->throughSignals(DB::table('g2g_signal_reviews as r')->where('r.sub_institute_id', $this->tenant)->join('g2g_company_opportunities as o', 'o.id', '=', 'r.opportunity_id'), $companyId),
                ['r.decision', 'r.note', 'r.decided_at', 'r.decided_by', 'o.title as signal_title'], 'r.decided_at', 'r.id',
                fn ($r) => ['kind' => 'decision', 'at' => $this->iso($r->decided_at), 'by' => $this->id($r->decided_by), 'source' => 'g2g_signal_reviews',
                    'text' => (self::DECISIONS[$r->decision] ?? ucfirst(str_replace('_', ' ', (string) $r->decision))) . ': ' . $r->signal_title . (($r->note ?? '') !== '' ? ' - ' . $r->note : '')]);
        }
        if ($companyId !== null && $this->hasTable('crm_leads') && $this->hasColumn('crm_leads', 'source_company_opportunity_id') && $this->hasTable('g2g_company_opportunities')) {
            $add($this->throughSignals(DB::table('crm_leads as l')->where('l.sub_institute_id', $this->tenant)->whereNull('l.deleted_at')->join('g2g_company_opportunities as o', 'o.id', '=', 'l.source_company_opportunity_id'), $companyId),
                ['l.lead_no', 'l.lead_status', 'l.created_at', 'l.created_by', 'o.title as signal_title'], 'l.created_at', 'l.id',
                fn ($r) => ['kind' => 'lead', 'at' => $this->iso($r->created_at), 'by' => $this->id($r->created_by), 'source' => 'crm_leads',
                    'text' => 'Lead ' . ($r->lead_no ?: '') . ($r->lead_status ? ' (' . $r->lead_status . ')' : '') . ' made from the signal: ' . $r->signal_title]);
        }

        usort($lines, fn (array $x, array $y) => strtotime($y['at']) <=> strtotime($x['at']));

        return [array_slice($lines, 0, self::CAP), $total];
    }

    /** Narrows a query that joins the signals table as `o` to this organisation's real signals of one company. */
    private function throughSignals(Builder $q, int $companyId): Builder
    {
        $q->where('o.sub_institute_id', $this->tenant)->where('o.company_id', $companyId);

        return $this->hasColumn('g2g_company_opportunities', 'is_sample') ? $q->where('o.is_sample', 0) : $q;
    }

    // ---- lookups ----

    /** One row of a source table, for this organisation only; null when it is missing, deleted or belongs to another organisation. */
    private function sourceRow(string $table, int $id, array $wanted, bool $softDeletes): ?object
    {
        if (! $this->hasTable($table)) {
            return null;
        }
        $q = DB::table($table)->where('sub_institute_id', $this->tenant)->where('id', $id);

        return ($softDeletes ? $q->whereNull('deleted_at') : $q)->first($this->existing($table, $wanted));
    }

    /** @param list<mixed> $ids @return array<int, mixed> id => value of one column, for the live rows of this organisation */
    private function pluckBySourceId(string $table, string $column, array $ids, bool $softDeletes): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === [] || ! $this->hasTable($table)) {
            return [];
        }
        $q = DB::table($table)->where('sub_institute_id', $this->tenant)->whereIn('id', $ids);

        return ($softDeletes ? $q->whereNull('deleted_at') : $q)->pluck($column, 'id')->all();
    }

    /** @param list<mixed> $companyIds @return array<int, array{open: int, last: ?string}> open = not dismissed */
    private function signalCounts(array $companyIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $companyIds))));
        if ($ids === [] || ! $this->hasTable('g2g_company_opportunities')) {
            return [];
        }
        $q = DB::table('g2g_company_opportunities')->where('sub_institute_id', $this->tenant)->whereIn('company_id', $ids);
        if ($this->hasColumn('g2g_company_opportunities', 'is_sample')) {
            $q->where('is_sample', 0);
        }
        $rows = $q->groupBy('company_id')->selectRaw("company_id, COUNT(*) as total, SUM(CASE WHEN review_status = 'Dismissed' THEN 1 ELSE 0 END) as dismissed, MAX(first_discovered_at) as last_at")->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->company_id] = ['open' => (int) $r->total - (int) $r->dismissed, 'last' => $this->iso($r->last_at)];
        }

        return $out;
    }

    /** @param list<mixed> $ids @return array<int, string> user id => name, for people of this organisation only */
    private function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($ids, fn ($v) => $v !== null)))));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('tbluser')->where('sub_institute_id', $this->tenant)->whereIn('id', $ids)->get(['id', 'first_name', 'last_name']) as $u) {
            $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
            $out[(int) $u->id] = $name !== '' ? $name : 'User #' . $u->id;
        }

        return $out;
    }

    /** Product Master names, only when the table exists on this host. An organisation sees its own products and the shared ones. @param list<int> $ids @return array<int, string> */
    private function productNames(array $ids): array
    {
        if ($ids === [] || ! $this->hasTable('g2g_product_master')) {
            return [];
        }

        return DB::table('g2g_product_master')->whereIn('id', $ids)->whereIn('sub_institute_id', [$this->tenant, ProductMaster::SHARED])->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    /**
     * Up to CAP rows of a query and how many it has in all. The count is only run when there are more rows than the cap, so a small
     * organisation costs one query per section.
     *
     * @param  list<string>  $columns
     * @param  \Closure(Builder): Builder  $order
     * @return array{0: Collection<int, object>, 1: int}
     */
    private function capped(Builder $base, array $columns, \Closure $order): array
    {
        $rows = $order(clone $base)->limit(self::CAP + 1)->get($columns);
        if ($rows->count() <= self::CAP) {
            return [$rows, $rows->count()];
        }

        return [$rows->take(self::CAP)->values(), (int) (clone $base)->count()];
    }

    // ---- schema questions, answered once per request ----

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= Schema::hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array(strtolower($column), $this->columnsOf($table), true);
    }

    /** @param list<string> $wanted @return list<string> the columns of $wanted this host's table has, in order */
    private function existing(string $table, array $wanted): array
    {
        return array_values(array_filter($wanted, fn (string $c) => $this->hasColumn($table, $c)));
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        return $this->columns[$table] ??= array_map('strtolower', ColumnExists::all($table));
    }

    // ---- small conversions ----

    private function id(mixed $value): ?int
    {
        return $value !== null && (int) $value > 0 ? (int) $value : null;
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000')) {
            return null;
        }
        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /** A date column as Y-m-d, or null for a missing or zero date. */
    private function day(mixed $value): ?string
    {
        $day = substr((string) $value, 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && ! str_starts_with($day, '0000') ? $day : null;
    }
}
