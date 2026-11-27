<?php

namespace App\Domain\Signals\Outreach;

use App\Domain\Signals\Opportunities\SignalContactStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The read side of the Outreach tab: the companies signals found (and people added by hand), the people at them, and what can be done with each (OutreachReach).
 * Always tenant-scoped; a deleted row is never read. Reach is worked out here, in one place, from the rows themselves, so a filter chip, a count and a row can never disagree.
 *
 * It reads a tenant's whole list into memory and filters, sorts and pages there: reach is a rule over the person's facts (and a company's reach a rule over its people),
 * and a tenant has hundreds of companies, not millions. If that ever changes, the rule moves into the query; the screens do not care.
 */
final class OutreachDirectory
{
    private const COMPANY_COLUMNS = ['id', 'name', 'website', 'domain', 'industry', 'location', 'type', 'state', 'phone', 'email', 'description', 'source', 'assigned_to', 'lead_id', 'first_seen_at', 'created_at'];

    /** True once the extra columns exist on this host (the migration ran); the screens answer 503 with a plain sentence until then. */
    public static function ready(): bool
    {
        try {
            return Schema::hasTable('g2g_companies') && Schema::hasTable('g2g_signal_contacts')
                && Schema::hasColumn('g2g_companies', 'source') && Schema::hasColumn('g2g_signal_contacts', 'consent_status');
        } catch (\Throwable) {
            return false;
        }
    }

    // ── companies ────────────────────────────────────────────────────────────────

    /**
     * Order: base rows (the search is part of them) -> the four facet filters -> the chips' counts -> the chip -> sort -> page. The facet options are counted over the base rows with the
     * other three facets applied (OutreachFacets), never over the chip.
     *
     * @param  array<string, mixed>  $f  search, reach, source, mine, product, sub_product, icp, region, sort, dir, page, per_page
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>, summary: array<string, int>, facets?: array<string, mixed>}
     */
    public function companies(int $tenant, int $userId, array $f): array
    {
        $rows = $this->companyRows($tenant, trim((string) ($f['search'] ?? '')));
        $built = $this->buildCompanies($tenant, $rows);
        [$built, $facets] = $this->faceted($tenant, $built, $f, fn (OutreachFacets $x, Collection $b) => $x->companySets($b), fn (array $c) => (int) $c['id']);
        $summary = $this->summary($built, $userId);

        $list = $built->filter(fn (array $c) => $this->passes($c, $f, $userId))->values();
        $list = $this->sorted($list, (string) ($f['sort'] ?? 'name'), (string) ($f['dir'] ?? 'asc'), [
            'name' => fn ($c) => mb_strtolower($c['name']), 'industry' => fn ($c) => mb_strtolower((string) $c['industry']), 'location' => fn ($c) => mb_strtolower((string) $c['location']),
            'signals' => fn ($c) => $c['signals_count'], 'people' => fn ($c) => $c['people_count'], 'created' => fn ($c) => (string) $c['first_seen_at'],
            'last_contacted' => fn ($c) => (string) $c['last_contacted_at'], 'reach' => fn ($c) => self::rank($c['reach']),
        ]);

        return $this->page($list, $f) + ['summary' => $summary] + ($facets !== null ? ['facets' => $facets] : []);
    }

    /** @return ?array<string, mixed> the company with its signals, its people and where the contact search stands */
    public function company(int $tenant, int $id): ?array
    {
        $row = DB::table('g2g_companies')->where('sub_institute_id', $tenant)->where('id', $id)->whereNull('deleted_at')->first(self::COMPANY_COLUMNS);
        if (! $row) {
            return null;
        }
        $company = $this->buildCompanies($tenant, collect([$row]))->first();
        $signals = DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenant)->where('company_id', $id)->orderByDesc('id')->limit(30)
            ->get(['id', 'title', 'product_name', 'review_status', 'pipeline_status', 'first_discovered_at'])
            ->map(fn ($s) => $this->signalRef($s))->all();
        $people = $this->people($tenant, 0, ['company_id' => $id, 'per_page' => 200, 'sort' => 'name', 'facets' => false]);

        $found = collect($people['data'])->where('source', 'found')->count();
        $last = SignalContactStore::lastSearch($tenant, $id);
        $searchOn = \App\Jobs\EnrichSignalContactsJob::isAvailable();

        return $company + [
            'signals' => $signals,
            'people' => $people['data'],
            'research' => [
                'state' => $found > 0 ? 'found' : ($last !== null ? 'none_found' : ($searchOn ? 'not_searched' : 'not_set_up')),
                'searched_at' => $last['at'] ?? null,
                'can_search' => $searchOn && $signals !== [],
            ],
        ];
    }

    // ── people ───────────────────────────────────────────────────────────────────

    /**
     * Same order as companies(). A company page asks for its own people with `facets => false` (it has no filters and needs no options).
     *
     * @param  array<string, mixed>  $f  search, reach, source, mine, company_id, product, sub_product, icp, region, sort, dir, page, per_page
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>, summary: array<string, int>, facets?: array<string, mixed>}
     */
    public function people(int $tenant, int $userId, array $f): array
    {
        $q = DB::table('g2g_signal_contacts as p')->join('g2g_companies as c', function ($j) {
            $j->on('c.id', '=', 'p.company_id')->on('c.sub_institute_id', '=', 'p.sub_institute_id');
        })->where('p.sub_institute_id', $tenant)->whereNull('p.deleted_at')->whereNull('c.deleted_at');
        if (! empty($f['company_id'])) {
            $q->where('p.company_id', (int) $f['company_id']);
        }
        $search = trim((string) ($f['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $this->like($search) . '%';
            $q->where(fn ($w) => $w->where('p.full_name', 'like', $like)->orWhere('p.email', 'like', $like)->orWhere('p.phone', 'like', $like)->orWhere('p.mobile', 'like', $like)
                ->orWhere('p.designation', 'like', $like)->orWhere('c.name', 'like', $like));
        }
        $rows = $q->get(['p.*', 'c.name as company_name']);

        $built = $this->buildPeople($tenant, $rows);
        [$built, $facets] = $this->faceted($tenant, $built, $f, fn (OutreachFacets $x) => $x->personSets($rows), fn (array $p) => (int) $p['id']);
        $summary = $this->summary($built, $userId);
        $list = $built->filter(fn (array $p) => $this->passes($p, $f, $userId))->values();
        $list = $this->sorted($list, (string) ($f['sort'] ?? 'name'), (string) ($f['dir'] ?? 'asc'), [
            'name' => fn ($p) => mb_strtolower((string) ($p['full_name'] ?? '~')), 'company' => fn ($p) => mb_strtolower($p['company_name']), 'created' => fn ($p) => (string) $p['created_at'],
            'last_contacted' => fn ($p) => (string) $p['last_contacted_at'], 'reach' => fn ($p) => self::rank($p['reach']),
        ]);

        return $this->page($list, $f) + ['summary' => $summary] + ($facets !== null ? ['facets' => $facets] : []);
    }

    /** @return ?array<string, mixed> */
    public function person(int $tenant, int $id): ?array
    {
        $row = DB::table('g2g_signal_contacts as p')->join('g2g_companies as c', 'c.id', '=', 'p.company_id')->where('p.sub_institute_id', $tenant)->where('p.id', $id)
            ->whereNull('p.deleted_at')->whereNull('c.deleted_at')->first(['p.*', 'c.name as company_name']);

        return $row ? $this->buildPeople($tenant, collect([$row]))->first() : null;
    }

    /** The drafts and sent mail to one person (through the GTM contact made when a message was first written to them). @return list<array<string, mixed>> */
    public function messages(int $tenant, int $personId): array
    {
        $gtm = (int) DB::table('g2g_signal_contacts')->where('sub_institute_id', $tenant)->where('id', $personId)->value('gtm_contact_id');
        if ($gtm <= 0) {
            return [];
        }

        return DB::table('gtm_outreach_messages')->where('sub_institute_id', $tenant)->where('contact_id', $gtm)->orderByDesc('id')->limit(50)
            ->get(['id', 'subject', 'status', 'created_at', 'sent_at'])
            ->map(fn ($m) => ['id' => (int) $m->id, 'subject' => (string) $m->subject, 'status' => (string) $m->status, 'created_at' => $m->created_at, 'sent_at' => $m->sent_at])->all();
    }

    // ── building rows ────────────────────────────────────────────────────────────

    /** Companies that signals found, were added by hand, or have a contact: never a company only some other feature knows. */
    private function companyRows(int $tenant, string $search): Collection
    {
        $q = DB::table('g2g_companies as c')->where('c.sub_institute_id', $tenant)->whereNull('c.deleted_at')->where(function ($w) use ($tenant) {
            $w->where('c.source', 'manual')
                ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('g2g_company_opportunities as o')->whereColumn('o.company_id', 'c.id')->where('o.sub_institute_id', $tenant))
                ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('g2g_signal_contacts as p')->whereColumn('p.company_id', 'c.id')->where('p.sub_institute_id', $tenant)->whereNull('p.deleted_at'));
        });
        if ($search !== '') {
            $like = '%' . $this->like($search) . '%';
            $q->where(function ($w) use ($like, $tenant) {
                $w->where('c.name', 'like', $like)->orWhere('c.domain', 'like', $like)->orWhere('c.industry', 'like', $like)->orWhere('c.location', 'like', $like)
                    ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('g2g_signal_contacts as p')->whereColumn('p.company_id', 'c.id')->where('p.sub_institute_id', $tenant)->whereNull('p.deleted_at')
                        ->where(fn ($x) => $x->where('p.full_name', 'like', $like)->orWhere('p.email', 'like', $like)->orWhere('p.phone', 'like', $like)->orWhere('p.designation', 'like', $like)));
            });
        }

        return $q->get(array_map(fn ($c) => "c.{$c}", self::COMPANY_COLUMNS));
    }

    /** @param Collection<int, object> $rows @return Collection<int, array<string, mixed>> */
    private function buildCompanies(int $tenant, Collection $rows): Collection
    {
        $ids = $rows->pluck('id')->map(fn ($i) => (int) $i)->all();
        if ($ids === []) {
            return collect();
        }
        $people = collect();
        $latest = [];
        $counts = [];
        foreach (array_chunk($ids, 800) as $chunk) {
            $people = $people->concat(DB::table('g2g_signal_contacts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->whereIn('company_id', $chunk)->get());
            foreach (DB::table('g2g_company_opportunities')->where('sub_institute_id', $tenant)->whereIn('company_id', $chunk)->orderByDesc('id')
                ->get(['id', 'company_id', 'title', 'product_name', 'review_status', 'pipeline_status', 'first_discovered_at']) as $o) {
                $counts[(int) $o->company_id] = ($counts[(int) $o->company_id] ?? 0) + 1;
                $latest[(int) $o->company_id] ??= $this->signalRef($o);
            }
        }
        $contacted = $this->lastContacted($tenant, $people->pluck('gtm_contact_id')->filter()->map(fn ($i) => (int) $i)->unique()->all());
        $names = $this->userNames($tenant, $rows->pluck('assigned_to')->merge($people->pluck('assigned_to'))->filter()->unique()->all());
        $byCompany = $people->groupBy(fn ($p) => (int) $p->company_id);

        return $rows->map(function ($c) use ($byCompany, $counts, $latest, $contacted, $names) {
            $id = (int) $c->id;
            $mine = ($byCompany[$id] ?? collect())->map(fn ($p) => ['row' => $p, 'reach' => OutreachReach::forPerson($p)]);
            $best = $mine->filter(fn ($p) => OutreachReach::isReachable($p['reach']))
                ->sortByDesc(fn ($p) => [(int) $p['row']->is_primary, self::rank($p['reach']), $this->score($p['row'])])->first();
            $lastContact = $mine->map(fn ($p) => $contacted[(int) $p['row']->gtm_contact_id] ?? null)->filter()->sortDesc()->first();

            return [
                'id' => $id, 'name' => (string) $c->name, 'website' => $c->website, 'domain' => $c->domain, 'industry' => $c->industry, 'location' => $c->location, 'type' => $c->type,
                'state' => $c->state, 'phone' => $c->phone, 'email' => $c->email, 'description' => $c->description, 'source' => (string) ($c->source ?: 'found'),
                'assigned_to' => $c->assigned_to ? (int) $c->assigned_to : null, 'assigned_to_name' => $names[(int) $c->assigned_to] ?? null,
                'signals_count' => $counts[$id] ?? 0, 'people_count' => $mine->count(), 'reachable_people_count' => $mine->filter(fn ($p) => OutreachReach::isReachable($p['reach']))->count(),
                'best_contact' => $best ? ['id' => (int) $best['row']->id, 'name' => $best['row']->full_name, 'designation' => $best['row']->designation,
                    'email' => OutreachReach::emailOf($best['row']), 'phone' => $best['row']->phone ?: $best['row']->mobile] : null,
                'reach' => OutreachReach::forCompany($mine->pluck('reach')->all()), 'last_contacted_at' => $lastContact, 'first_seen_at' => $c->first_seen_at ?? $c->created_at,
                'lead_id' => $c->lead_id ? (int) $c->lead_id : null, 'latest_signal' => $latest[$id] ?? null,
            ];
        })->values();
    }

    /** @param Collection<int, object> $rows @return Collection<int, array<string, mixed>> */
    private function buildPeople(int $tenant, Collection $rows): Collection
    {
        $contacted = $this->lastContacted($tenant, $rows->pluck('gtm_contact_id')->filter()->map(fn ($i) => (int) $i)->unique()->all());
        $names = $this->userNames($tenant, $rows->pluck('assigned_to')->filter()->unique()->all());

        return $rows->map(fn ($p) => [
            'id' => (int) $p->id, 'company_id' => (int) $p->company_id, 'company_name' => (string) $p->company_name, 'full_name' => $p->full_name, 'designation' => $p->designation,
            'department' => $p->department, 'contact_role' => $p->contact_role, 'is_primary' => (bool) $p->is_primary, 'email' => $p->email, 'secondary_email' => $p->secondary_email,
            'phone' => $p->phone, 'mobile' => $p->mobile, 'linkedin_url' => $p->linkedin_url, 'website' => $p->website, 'location' => $p->location, 'description' => $p->description,
            'source' => (string) ($p->source ?: 'found'), 'source_url' => $p->source_url, 'email_source_url' => $p->email_source_url, 'phone_source_url' => $p->phone_source_url,
            'verification_status' => (string) $p->verification_status, 'consent_status' => (string) ($p->consent_status ?: 'unknown'), 'email_opt_out' => (bool) $p->email_opt_out,
            'do_not_call' => (bool) $p->do_not_call, 'suppressed_reason' => $p->suppressed_reason, 'assigned_to' => $p->assigned_to ? (int) $p->assigned_to : null,
            'assigned_to_name' => $names[(int) $p->assigned_to] ?? null, 'reach' => OutreachReach::forPerson($p), 'last_contacted_at' => $contacted[(int) $p->gtm_contact_id] ?? null,
            'researched_at' => $p->researched_at, 'created_at' => $p->created_at, 'lead_id' => $p->lead_id ? (int) $p->lead_id : null,
        ])->values();
    }

    /** @return array<string, mixed> */
    private function signalRef(object $s): array
    {
        return ['id' => (int) $s->id, 'title' => (string) $s->title, 'product_name' => $s->product_name, 'review_status' => (string) $s->review_status,
            'pipeline_status' => $s->pipeline_status, 'first_discovered_at' => $s->first_discovered_at];
    }

    // ── filters, counts, paging ──────────────────────────────────────────────────

    /**
     * The Product, Sub-product, ICP and Region filters (OutreachFacets: one definition for every screen). Returns the rows that pass them and the options with their counts, which are
     * counted over `$built` with the other three facets applied, before the chip and before paging. A caller that wants neither (the company page asking for its own people) sends
     * `facets => false` and no filter, and gets its rows back untouched with no options.
     *
     * @param  Collection<int, array<string, mixed>>  $built
     * @param  array<string, mixed>  $f
     * @param  callable(OutreachFacets, Collection<int, array<string, mixed>>): array<int, array<string, mixed>>  $setsOf  the sets of every row, by id
     * @param  callable(array<string, mixed>): int  $idOf
     * @return array{0: Collection<int, array<string, mixed>>, 1: ?array<string, mixed>}
     */
    private function faceted(int $tenant, Collection $built, array $f, callable $setsOf, callable $idOf): array
    {
        $selection = OutreachFacets::selection($f);
        $active = OutreachFacets::isActive($selection);
        if (($f['facets'] ?? true) === false && ! $active) {
            return [$built, null];
        }
        $facets = new OutreachFacets($tenant);
        $sets = $setsOf($facets, $built);
        $options = $facets->answer($sets, $selection);
        if ($active) {
            $built = $built->filter(fn (array $row) => OutreachFacets::passes($sets[$idOf($row)] ?? OutreachFacets::emptySets(), $selection))->values();
        }

        return [$built, $options];
    }

    /** @param array<string, mixed> $row a company or a person  @param array<string, mixed> $f */
    private function passes(array $row, array $f, int $userId): bool
    {
        $reach = (string) ($f['reach'] ?? 'all');
        if ($reach === 'reachable' && ! OutreachReach::isReachable($row['reach'])) {
            return false;
        }
        if (! in_array($reach, ['all', 'reachable', ''], true) && $reach !== $row['reach']) {
            return false;
        }
        $source = (string) ($f['source'] ?? 'all');
        if (in_array($source, ['found', 'manual'], true) && $row['source'] !== $source) {
            return false;
        }
        if (! empty($f['mine']) && ($userId <= 0 || (int) $row['assigned_to'] !== $userId)) {
            return false;
        }

        return true;
    }

    /** The number on each chip: how many rows each chip would show, within the current search. @param Collection<int, array<string, mixed>> $rows @return array<string, int> */
    private function summary(Collection $rows, int $userId): array
    {
        return [
            'all' => $rows->count(),
            'reachable' => $rows->filter(fn ($r) => OutreachReach::isReachable($r['reach']))->count(),
            'needs_details' => $rows->where('reach', OutreachReach::NEEDS_DETAILS)->count(),
            'blocked' => $rows->where('reach', OutreachReach::BLOCKED)->count(),
            'found' => $rows->where('source', 'found')->count(),
            'manual' => $rows->where('source', 'manual')->count(),
            'mine' => $userId > 0 ? $rows->filter(fn ($r) => (int) $r['assigned_to'] === $userId)->count() : 0,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $list @param array<string, callable> $keys @return Collection<int, array<string, mixed>> */
    private function sorted(Collection $list, string $sort, string $dir, array $keys): Collection
    {
        $key = $keys[$sort] ?? $keys['name'];
        $sign = $dir === 'desc' ? -1 : 1;
        $rows = $list->all();
        // The id breaks ties so a page never shows the same row twice or skips one when two rows compare equal.
        usort($rows, fn (array $a, array $b) => (($key($a) <=> $key($b)) * $sign) ?: ($a['id'] <=> $b['id']));

        return collect($rows);
    }

    /** @param Collection<int, array<string, mixed>> $list @param array<string, mixed> $f @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    private function page(Collection $list, array $f): array
    {
        $per = max(1, min(200, (int) ($f['per_page'] ?? 20)));
        $total = $list->count();
        $last = max(1, (int) ceil($total / $per));
        $page = max(1, min($last, (int) ($f['page'] ?? 1)));

        return ['data' => $list->slice(($page - 1) * $per, $per)->values()->all(), 'meta' => ['page' => $page, 'per_page' => $per, 'total' => $total, 'last_page' => $last]];
    }

    private static function rank(string $reach): int
    {
        return match ($reach) {
            OutreachReach::READY => 5, OutreachReach::EMAIL_ONLY => 4, OutreachReach::PHONE_ONLY => 3, OutreachReach::NEEDS_DETAILS => 2, default => 1,
        };
    }

    private function score(object $p): int
    {
        return ($p->email ? 4 : 0) + ($p->phone ? 4 : 0) + ($p->full_name ? 2 : 0) + ($p->designation ? 1 : 0) + ($p->linkedin_url ? 1 : 0);
    }

    private function like(string $text): string
    {
        return addcslashes($text, '%_\\');
    }

    // ── small lookups ────────────────────────────────────────────────────────────

    /** @param list<int> $gtmContactIds @return array<int, string> the latest time a message to each GTM contact was SENT */
    private function lastContacted(int $tenant, array $gtmContactIds): array
    {
        $out = [];
        foreach (array_chunk($gtmContactIds, 800) as $chunk) {
            foreach (DB::table('gtm_outreach_messages')->where('sub_institute_id', $tenant)->whereIn('contact_id', $chunk)->where('status', 'sent')->whereNotNull('sent_at')
                ->groupBy('contact_id')->selectRaw('contact_id, MAX(sent_at) as at')->get() as $r) {
                $out[(int) $r->contact_id] = (string) $r->at;
            }
        }

        return $out;
    }

    /** @param list<int|string> $ids @return array<int, string> */
    private function userNames(int $tenant, array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('tbluser')) {
            return [];
        }
        $out = [];
        foreach (DB::table('tbluser')->where('sub_institute_id', $tenant)->whereIn('id', array_map('intval', $ids))->get(['id', 'first_name', 'last_name']) as $u) {
            $out[(int) $u->id] = trim("{$u->first_name} {$u->last_name}");
        }

        return $out;
    }
}
