<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Domain\Crm\Contacts\CompanyPeople;
use App\Domain\Crm\Contacts\ContactPrimary;
use App\Http\Controllers\Api\Crm\Concerns\HasCompanyPeopleInclude;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmAuditTrail;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmBulkActions;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmComments;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmDocuments;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmDuplicateDetection;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmEmailLinks;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmExport;
use App\Http\Controllers\Api\Crm\Concerns\HasCrmMerge;
use App\Domain\OrgAccounts\OrgEnsure;
use App\Domain\Signals\Support\StructuredAi;
use App\Support\ColumnExists;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Organizations - the legacy CRM's "Accounts" module, relabeled. Internal
 * name stays Organization/crm_organizations everywhere; "Organizations" is
 * a presentation-layer label only.
 */
class CrmOrganizationController extends Controller
{
    use ResolvesApiIdentity;
    use HasCrmBulkActions;
    use HasCrmDuplicateDetection;
    use HasCrmMerge;
    use HasCrmExport;
    use HasCrmComments;
    use HasCrmDocuments;
    use HasCrmEmailLinks;
    use HasCrmAuditTrail;
    use HasCompanyPeopleInclude;

    /** @var array<string, string> db column => CSV header, in export column order. */
    private const EXPORT_COLUMNS = [
        'name' => 'Name', 'account_type' => 'Type', 'industry' => 'Industry', 'rating' => 'Rating',
        'ownership' => 'Ownership', 'annual_revenue' => 'Annual Revenue', 'employees' => 'Employees',
        'phone' => 'Phone', 'email' => 'Email', 'website' => 'Website',
        'billing_street' => 'Billing Street', 'billing_city' => 'Billing City',
        'billing_state' => 'Billing State', 'billing_code' => 'Billing Postal Code',
        'billing_country' => 'Billing Country', 'description' => 'Description',
        'assigned_to' => 'Assigned To (user id)',
    ];

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $page = max(1, (int) $request->input('page', 1));

        $query = DB::table('crm_organizations')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            // A company also matches when the text matches the name, e-mail, phone or mobile of one of its live people (S18): one EXISTS, tenant scoped.
            $personMatch = fn ($contact) => $this->personMatches($contact, $search);
            $query->where(function ($q) use ($search, $identity, $personMatch) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereExists(function ($exists) use ($identity, $personMatch) {
                        $exists->select(DB::raw(1))->from('crm_contacts as pc')
                            ->whereColumn('pc.organization_id', 'crm_organizations.id')
                            ->where('pc.sub_institute_id', $identity['sub_institute_id'])
                            ->whereNull('pc.deleted_at')
                            ->where($personMatch);
                    });
            });
            // Which rows of THIS page got in only through a person is told apart in the same statement: no per-row query.
            $query->select('crm_organizations.*')->selectRaw('CASE WHEN name LIKE ? OR email LIKE ? OR phone LIKE ? THEN 1 ELSE 0 END as own_match', ["%{$search}%", "%{$search}%", "%{$search}%"]);
        }

        if ($accountType = $request->input('account_type')) {
            $query->where('account_type', $accountType);
        }

        $sortableColumns = ['name', 'account_type', 'industry', 'rating', 'billing_city', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $sortableColumns, true) ? $request->input('sort_by') : 'name';
        $sortDir = strtolower((string) $request->input('sort_dir')) === 'desc' ? 'desc' : 'asc';

        $total = (clone $query)->count();
        $rows = $query->orderBy($sortBy, $sortDir)->forPage($page, $perPage)->get();
        $people = $this->peopleOfPage($identity['sub_institute_id'], $rows->pluck('id')->map(fn ($id) => (int) $id)->all(), $search, $rows);

        return response()->json([
            'status' => 1,
            'message' => 'Organizations.',
            'data' => [
                'items' => $rows->map(fn ($row) => $this->resource($row) + $people[(int) $row->id])->all(),
                'pagination' => [
                    'current_page' => $page,
                    'last_page' => (int) max(1, ceil($total / $perPage)),
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $row = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $row) {
            return response()->json(['status' => 0, 'message' => 'Organization not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Organization.', 'data' => $this->resource($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:191',
            'email' => 'nullable|email|max:191',
            'assignedTo' => 'required|integer',
            'parentId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $nameClash = DB::table('crm_organizations')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->where('name', $request->input('name'))
            ->whereNull('deleted_at')
            ->exists();

        if ($nameClash) {
            return response()->json(['status' => 0, 'message' => 'An organization with that name already exists.'], 422);
        }

        $data = $this->payload($request);
        $data['account_no'] = 'ORG-' . Str::upper(Str::random(8));
        $data['sub_institute_id'] = $identity['sub_institute_id'];
        $data['created_by'] = $identity['user_id'];
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('crm_organizations')->insertGetId($data);
        $this->ensureOrgIdentity((int) $identity['sub_institute_id'], (int) $id);
        $row = DB::table('crm_organizations')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Organization created.', 'data' => $this->resource($row)], 201);
    }

    /**
     * Organisation 360 (agentic CRM slice 3): a new CRM organization gets its identity at once, so it is never without one. It is the same exact-name /
     * exact-domain decision the backfill makes (OrgEnsure), and it writes only g2g_org_accounts. Guarded: whatever goes wrong here (the table missing on
     * a host that has not run its migration, a lock, a race) is logged and swallowed, and the organization is created all the same. Opening the
     * organization's Account 360 tab ensures the identity again, so a miss here costs nothing.
     */
    private function ensureOrgIdentity(int $tenantId, int $organizationId): void
    {
        try {
            app(OrgEnsure::class)->forCrmOrganization($tenantId, $organizationId);
        } catch (\Throwable $e) {
            Log::warning('Organisation 360: the identity of a new CRM organization could not be made.', [
                'sub_institute_id' => $tenantId, 'crm_organization_id' => $organizationId, 'error' => $e->getMessage(),
            ]);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Organization not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:191',
            'email' => 'nullable|email|max:191',
            'assignedTo' => 'sometimes|required|integer',
            'parentId' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        if ($request->filled('parentId') && (int) $request->input('parentId') === $id) {
            return response()->json(['status' => 0, 'message' => 'An organization cannot be its own parent.'], 422);
        }

        if ($request->has('name')) {
            $nameClash = DB::table('crm_organizations')
                ->where('sub_institute_id', $identity['sub_institute_id'])
                ->where('name', $request->input('name'))
                ->where('id', '!=', $id)
                ->whereNull('deleted_at')
                ->exists();

            if ($nameClash) {
                return response()->json(['status' => 0, 'message' => 'An organization with that name already exists.'], 422);
            }
        }

        $data = $this->payload($request, forUpdate: true);
        $data['updated_by'] = $identity['user_id'];
        $data['updated_at'] = now();

        DB::table('crm_organizations')->where('id', $id)->update($data);

        $this->recordCrmAudit('crm.organization.updated', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'], (array) $existing, array_merge((array) $existing, $data));

        $row = DB::table('crm_organizations')->where('id', $id)->first();

        return response()->json(['status' => 1, 'message' => 'Organization updated.', 'data' => $this->resource($row)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $existing = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $existing) {
            return response()->json(['status' => 0, 'message' => 'Organization not found.'], 404);
        }

        $hasChildren = DB::table('crm_organizations')->where('parent_id', $id)->whereNull('deleted_at')->exists();

        if ($hasChildren) {
            return response()->json(['status' => 0, 'message' => 'Re-parent or remove this organization’s child organizations first.'], 422);
        }

        DB::table('crm_organizations')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $identity['user_id'],
        ]);

        $this->recordCrmAudit(
            'crm.organization.deleted', $identity['sub_institute_id'], $this->auditEntityType(), $id, $identity['user_id'],
            (array) $existing,
            array_merge((array) $existing, ['deleted_at' => now()->toDateTimeString(), 'deleted_by' => $identity['user_id']]),
        );

        return response()->json(['status' => 1, 'message' => 'Organization moved to Recycle Bin.']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        // Same guard as the single-record destroy() above: a parent with
        // live children must be re-parented first, bulk or not.
        return $this->bulkDeleteRows(
            $request,
            'crm_organizations',
            $identity['sub_institute_id'],
            $identity['user_id'],
            'Organization',
            guard: fn (int $id) => DB::table('crm_organizations')->where('parent_id', $id)->whereNull('deleted_at')->exists()
                ? 'Re-parent or remove this organization\'s child organizations first.'
                : null,
        );
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $cascade = $request->boolean('cascadeToContacts');

        return $this->bulkAssignRows(
            $request, 'crm_organizations', $identity['sub_institute_id'], $identity['user_id'], 'Organization',
            afterEach: $cascade
                ? function (int $organizationId, int $assignedTo) use ($identity) {
                    return DB::table('crm_contacts')
                        ->where('organization_id', $organizationId)
                        ->where('sub_institute_id', $identity['sub_institute_id'])
                        ->whereNull('deleted_at')
                        ->update(['assigned_to' => $assignedTo, 'updated_by' => $identity['user_id'], 'updated_at' => now()]);
                }
                : null,
        );
    }

    /**
     * Possible duplicates: a same (normalized) website, or a same phone
     * number. `name` is deliberately not a signal here - store() already
     * rejects a second organization with the same name per tenant, so an
     * exact name collision cannot exist going forward.
     */
    public function duplicates(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $tenantId = $identity['sub_institute_id'];
        $resource = fn ($row) => $this->resource($row);

        $groups = array_merge(
            $this->duplicateGroups('crm_organizations', $tenantId, 'LOWER(TRIM(website))', ['website'], 'Same website', $resource),
            $this->duplicateGroups('crm_organizations', $tenantId, 'LOWER(TRIM(phone))', ['phone'], 'Same phone number', $resource),
        );

        return response()->json(['status' => 1, 'message' => 'Possible duplicate organizations.', 'data' => $groups]);
    }

    public function merge(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        return $this->mergeRows(
            $request, 'crm_organizations', $identity['sub_institute_id'], $identity['user_id'], 'Organization',
            // Every table that stores an organization id (contacts, leads, campaign targets, the parent tree, quotes, orders, invoices, tickets, links, ...)
            // is listed in OrganisationReferences and moved by OrganisationMerger, scoped to this tenant, in mergeRows' transaction.
            repoint: function (int $fromId, int $toId) use ($identity) {
                // S18: each company may have its primary contact; once the people of both are under one company, only one of them may keep the star
                // (the survivor company's own primary stays, else the oldest). Read first, enforced after the move, in the same transaction.
                // A host that has not run the S18 migration yet has no star to keep.
                $hasStar = ColumnExists::has('crm_contacts', 'is_primary');
                $survivorPrimary = $hasStar
                    ? DB::table('crm_contacts')->where('sub_institute_id', $identity['sub_institute_id'])->where('organization_id', $toId)
                        ->whereNull('deleted_at')->where('is_primary', 1)->orderBy('id')->value('id')
                    : null;

                \App\Domain\OrgAccounts\OrganisationMerger::repoint($identity['sub_institute_id'], $fromId, $toId);

                if ($hasStar) {
                    ContactPrimary::enforceSingle($identity['sub_institute_id'], $toId, $survivorPrimary !== null ? (int) $survivorPrimary : null);
                }
            },
        );
    }

    /** Exports the same rows index() would list (search applied). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $query = DB::table('crm_organizations')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return $this->exportCsv($query, self::EXPORT_COLUMNS, 'organizations.csv');
    }

    /**
     * CSV import. A missing/invalid owner rejects the row (no fallback),
     * and a name that collides with an existing organization in this
     * tenant is also rejected - the same rule store() already applies to a
     * manual single create, so a CSV never bypasses it.
     */
    public function import(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'rows' => 'required|array|min:1|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $created = 0;
        $results = [];

        foreach ($request->input('rows') as $index => $row) {
            $rowRequest = Request::create('/', 'POST', is_array($row) ? $row : []);

            $rowValidator = Validator::make($rowRequest->all(), [
                'name' => 'required|string|max:191',
                'email' => 'nullable|email|max:191',
                'assignedTo' => 'required|integer',
                'annualRevenue' => 'nullable|numeric',
                'employees' => 'nullable|integer',
            ]);

            if ($rowValidator->fails()) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => $rowValidator->errors()->first()];
                continue;
            }

            $nameClash = DB::table('crm_organizations')
                ->where('sub_institute_id', $identity['sub_institute_id'])
                ->where('name', $rowRequest->input('name'))
                ->whereNull('deleted_at')
                ->exists();

            if ($nameClash) {
                $results[] = ['row' => $index + 1, 'ok' => false, 'reason' => 'An organization with that name already exists.'];
                continue;
            }

            $data = $this->payload($rowRequest);
            $data['account_no'] = 'ORG-' . Str::upper(Str::random(8));
            $data['sub_institute_id'] = $identity['sub_institute_id'];
            $data['created_by'] = $identity['user_id'];
            $data['created_at'] = now();
            $data['updated_at'] = now();

            DB::table('crm_organizations')->insert($data);
            $created++;
            $results[] = ['row' => $index + 1, 'ok' => true];
        }

        return response()->json([
            'status' => 1,
            'message' => $created === count($results) ? "{$created} organization(s) imported." : "{$created} of " . count($results) . ' row(s) imported.',
            'data' => ['created' => $created, 'results' => $results],
        ]);
    }

    /**
     * The parent/child tree, cycle-guarded - mirrors the legacy CRM's
     * `Accounts::getAccountHierarchy()` algorithm (upward to the topmost
     * ancestor, downward to every descendant), confirmed from research.
     */
    public function hierarchy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $root = $this->ownRow($identity['sub_institute_id'], $id);

        if (! $root) {
            return response()->json(['status' => 0, 'message' => 'Organization not found.'], 404);
        }

        $all = DB::table('crm_organizations')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'parent_id'])
            ->keyBy('id');

        $ancestors = [];
        $seen = [(int) $id => true];
        $cursor = $root;

        while ($cursor && $cursor->parent_id && ! isset($seen[(int) $cursor->parent_id])) {
            $parent = $all->get((int) $cursor->parent_id);
            if (! $parent) {
                break;
            }
            $seen[(int) $parent->id] = true;
            array_unshift($ancestors, ['id' => (string) $parent->id, 'name' => $parent->name]);
            $cursor = $parent;
        }

        $descendants = [];
        $this->collectDescendants((int) $id, $all, $descendants, $seen);

        return response()->json([
            'status' => 1,
            'message' => 'Organization hierarchy.',
            'data' => [
                'ancestors' => $ancestors,
                'current' => ['id' => (string) $root->id, 'name' => $root->name],
                'descendants' => $descendants,
            ],
        ]);
    }

    /** @param array<int, array{id: string, name: string, depth: int}> $out */
    private function collectDescendants(int $parentId, $all, array &$out, array &$seen, int $depth = 1): void
    {
        foreach ($all as $row) {
            if ((int) $row->parent_id !== $parentId || isset($seen[(int) $row->id])) {
                continue;
            }

            $seen[(int) $row->id] = true;
            $out[] = ['id' => (string) $row->id, 'name' => $row->name, 'depth' => $depth];
            $this->collectDescendants((int) $row->id, $all, $out, $seen, $depth + 1);
        }
    }

    /**
     * Single-record ownership transfer, with an opt-in cascade to this
     * organization's own Contacts - the one direct related-module this app
     * actually has (the legacy system's Potentials/Quotes/etc. cascade
     * targets don't exist here). Mirrors the legacy's "only the owner field
     * changes, cascade is opt-in" behavior, confirmed from research.
     */
    public function transferOwnership(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Organization not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'assignedTo' => 'required|integer',
            'cascadeToContacts' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $assignedTo = (int) $request->input('assignedTo');

        DB::table('crm_organizations')->where('id', $id)->update([
            'assigned_to' => $assignedTo,
            'updated_by' => $identity['user_id'],
            'updated_at' => now(),
        ]);

        $contactsTransferred = 0;

        if ($request->boolean('cascadeToContacts')) {
            $contactsTransferred = DB::table('crm_contacts')
                ->where('organization_id', $id)
                ->where('sub_institute_id', $identity['sub_institute_id'])
                ->whereNull('deleted_at')
                ->update(['assigned_to' => $assignedTo, 'updated_by' => $identity['user_id'], 'updated_at' => now()]);
        }

        return response()->json([
            'status' => 1,
            'message' => $contactsTransferred > 0
                ? "Ownership transferred, including {$contactsTransferred} contact(s)."
                : 'Ownership transferred.',
        ]);
    }

    /** The "one of its live people matches" test of the list search: name (first, last or both), e-mail, phone or mobile. @param \Illuminate\Database\Query\Builder $contact the crm_contacts query aliased pc */
    private function personMatches($contact, string $search): void
    {
        $like = "%{$search}%";
        $contact->where(function ($m) use ($like) {
            $m->where('pc.first_name', 'like', $like)->orWhere('pc.last_name', 'like', $like)
                ->orWhere('pc.email', 'like', $like)->orWhere('pc.phone', 'like', $like)->orWhere('pc.mobile', 'like', $like)
                ->orWhereRaw(CompanyPeople::fullNameSql('pc') . ' like ?', [$like]);
        });
    }

    /**
     * What the list adds to each company of the page (S18): how many live people it has, who its primary is, and (while searching) which person made it match. ONE grouped query for the
     * counts and the primary's id, one lookup of the primaries' names, and, only when a search is on and some company of the page matched through a person, one query for those people:
     * the number of queries is the same for 5 rows and for 25.
     *
     * @param  list<int>  $ids
     * @param  \Illuminate\Support\Collection<int, object>  $rows the page, each with own_match when a search is on
     * @return array<int, array{peopleCount: int, primaryContactName: ?string, matchedPerson: ?array{id: string, name: string}}>
     */
    private function peopleOfPage(int $tenantId, array $ids, string $search, $rows): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['peopleCount' => 0, 'primaryContactName' => null, 'matchedPerson' => null];
        }
        if ($ids === []) {
            return $out;
        }

        $primaryOf = [];
        $count = fn (string $primary) => DB::table('crm_contacts')->where('sub_institute_id', $tenantId)->whereIn('organization_id', $ids)->whereNull('deleted_at')
            ->groupBy('organization_id')->selectRaw("organization_id, COUNT(*) as people_count, {$primary} as primary_id")->get();
        try {
            $grouped = $count('MAX(CASE WHEN is_primary = 1 THEN id END)');
        } catch (\Illuminate\Database\QueryException) {
            // The S18 migration has not run on this host yet (the star column is missing): the count is still right, nobody is starred.
            $grouped = $count('NULL');
        }
        foreach ($grouped as $g) {
            $out[(int) $g->organization_id]['peopleCount'] = (int) $g->people_count;
            if ($g->primary_id !== null) {
                $primaryOf[(int) $g->organization_id] = (int) $g->primary_id;
            }
        }
        if ($primaryOf !== []) {
            $names = DB::table('crm_contacts')->where('sub_institute_id', $tenantId)->whereIn('id', array_values($primaryOf))->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(fn ($c) => [(int) $c->id => CompanyPeople::name($c->first_name, $c->last_name)]);
            foreach ($primaryOf as $organizationId => $contactId) {
                $out[$organizationId]['primaryContactName'] = ($names[$contactId] ?? '') !== '' ? $names[$contactId] : null;
            }
        }

        // Only a company that is on the page because of a person (its own name, e-mail and phone did not match) says which person.
        $viaPerson = $search === '' ? [] : $rows->filter(fn ($r) => (int) ($r->own_match ?? 1) === 0)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($viaPerson !== []) {
            $matches = DB::table('crm_contacts as pc')->where('pc.sub_institute_id', $tenantId)->whereIn('pc.organization_id', $viaPerson)->whereNull('pc.deleted_at')
                ->where(fn ($q) => $this->personMatches($q, $search))->orderBy('pc.id')->get(['pc.id', 'pc.organization_id', 'pc.first_name', 'pc.last_name']);
            foreach ($matches as $m) {
                $organizationId = (int) $m->organization_id;
                $out[$organizationId]['matchedPerson'] ??= ['id' => (string) $m->id, 'name' => CompanyPeople::name($m->first_name, $m->last_name)];
            }
        }

        return $out;
    }

    private function ownRow(int $tenantId, int $id): ?object
    {
        return DB::table('crm_organizations')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Projects in the Task Management module linked to this Organization, via
     * task_management_projects.crm_organization_id - the reverse side of the
     * FK ProjectController now accepts. A simple read, not worth a trait for
     * the 2 call sites (here and the Opportunity equivalent).
     */
    public function projects(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->ownRow($identity['sub_institute_id'], $id)) {
            return response()->json(['status' => 0, 'message' => 'Organization not found.'], 404);
        }

        $rows = DB::table('task_management_projects')
            ->where('crm_organization_id', $id)
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->orderByDesc('updated_at')
            ->get(['id', 'code', 'name', 'status', 'priority']);

        return response()->json([
            'status' => 1,
            'message' => 'Linked projects.',
            'data' => $rows->map(fn ($row) => [
                'id' => (string) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'status' => $row->status,
                'priority' => $row->priority,
            ])->all(),
        ]);
    }

    /**
     * Sales & Marketing unification, Phase 7: this Organization's own record in
     * GTM (reverse of the Phase 3 bridge, `gtm_accounts.crm_organization_id`)
     * and in Signals (matched by name against `g2g_companies`, since nothing
     * links a CRM Organization to a Signals Company directly - there is no
     * FK either direction). Read-only; never creates or changes a link
     * itself. See Docs/sales-marketing-merge/DECISIONS.md.
     */
    public function crossModuleLinks(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $t = $identity['sub_institute_id'];
        $org = $this->ownRow($t, $id);

        if (! $org) {
            return response()->json(['status' => 0, 'message' => 'Organization not found.'], 404);
        }

        $gtmAccount = DB::table('gtm_accounts')->where('sub_institute_id', $t)->where('crm_organization_id', $id)->whereNull('deleted_at')->first(['id', 'name', 'stage']);
        $gtmLink = null;

        if ($gtmAccount) {
            $gtmLink = [
                'id' => (string) $gtmAccount->id,
                'name' => $gtmAccount->name,
                'stage' => $gtmAccount->stage,
                'deals_count' => DB::table('gtm_deals')->where('sub_institute_id', $t)->where('account_id', $gtmAccount->id)->whereNull('deleted_at')->count(),
            ];
        }

        // g2g_companies.normalized_name is written by two different normalizers depending on
        // how the company was discovered (BuyerResolver's quote/dash-folding StructuredAi::normalise()
        // for research-sourced rows, vs IngestionAnalyzer's alphanumeric-strip for ingestion-sourced
        // rows) - match either form rather than assuming only one.
        $spacedNorm = StructuredAi::normalise((string) $org->name);
        $strippedNorm = preg_replace('/[^a-z0-9]+/', '', $spacedNorm) ?: 'enterprise';
        $company = DB::table('g2g_companies')->where('sub_institute_id', $t)
            ->whereIn('normalized_name', array_unique([$spacedNorm, $strippedNorm]))
            ->first(['id', 'name', 'industry']);
        $signalsLink = null;

        if ($company) {
            $signalsLink = [
                'id' => (string) $company->id,
                'name' => $company->name,
                'industry' => $company->industry,
                'opportunities_count' => DB::table('g2g_company_opportunities')->where('sub_institute_id', $t)->where('company_id', $company->id)->where('is_sample', false)->count(),
            ];
        }

        return response()->json([
            'status' => 1,
            'message' => 'Cross-module links.',
            'data' => ['gtm_account' => $gtmLink, 'signals_company' => $signalsLink],
        ]);
    }

    protected function commentsTable(): string
    {
        return 'crm_organization_comments';
    }

    protected function commentsForeignKey(): string
    {
        return 'organization_id';
    }

    protected function documentsLinkedType(): string
    {
        return 'ORGANIZATION';
    }

    protected function emailsLinkedType(): string
    {
        return 'ORGANIZATION';
    }

    protected function auditEntityType(): string
    {
        return 'crm_organizations';
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, bool $forUpdate = false): array
    {
        $map = [
            'name' => 'name', 'parentId' => 'parent_id', 'accountType' => 'account_type',
            'industry' => 'industry', 'rating' => 'rating', 'ownership' => 'ownership',
            'annualRevenue' => 'annual_revenue', 'employees' => 'employees', 'sicCode' => 'sic_code',
            'tickerSymbol' => 'ticker_symbol', 'phone' => 'phone', 'secondaryPhone' => 'secondary_phone',
            'email' => 'email', 'secondaryEmail' => 'secondary_email', 'website' => 'website', 'fax' => 'fax',
            'emailOptOut' => 'email_opt_out', 'billingStreet' => 'billing_street', 'billingCity' => 'billing_city',
            'billingState' => 'billing_state', 'billingCode' => 'billing_code', 'billingCountry' => 'billing_country',
            'billingPoBox' => 'billing_po_box', 'shippingStreet' => 'shipping_street', 'shippingCity' => 'shipping_city',
            'shippingState' => 'shipping_state', 'shippingCode' => 'shipping_code', 'shippingCountry' => 'shipping_country',
            'shippingPoBox' => 'shipping_po_box', 'description' => 'description', 'assignedTo' => 'assigned_to',
        ];

        $data = [];

        foreach ($map as $requestKey => $column) {
            if ($forUpdate && ! $request->has($requestKey)) {
                continue;
            }

            $data[$column] = $request->input($requestKey);
        }

        if (array_key_exists('email_opt_out', $data)) {
            $data['email_opt_out'] = (bool) $data['email_opt_out'];
        }

        if (array_key_exists('parent_id', $data) && $data['parent_id'] === '') {
            $data['parent_id'] = null;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function resource(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'accountNo' => $row->account_no,
            'name' => $row->name,
            'parentId' => $row->parent_id ? (string) $row->parent_id : null,
            'accountType' => $row->account_type,
            'industry' => $row->industry,
            'rating' => $row->rating,
            'ownership' => $row->ownership,
            'annualRevenue' => $row->annual_revenue !== null ? (float) $row->annual_revenue : null,
            'employees' => $row->employees !== null ? (int) $row->employees : null,
            'sicCode' => $row->sic_code,
            'tickerSymbol' => $row->ticker_symbol,
            'phone' => $row->phone,
            'secondaryPhone' => $row->secondary_phone,
            'email' => $row->email,
            'secondaryEmail' => $row->secondary_email,
            'website' => $row->website,
            'fax' => $row->fax,
            'emailOptOut' => (bool) $row->email_opt_out,
            'billingStreet' => $row->billing_street,
            'billingCity' => $row->billing_city,
            'billingState' => $row->billing_state,
            'billingCode' => $row->billing_code,
            'billingCountry' => $row->billing_country,
            'billingPoBox' => $row->billing_po_box,
            'shippingStreet' => $row->shipping_street,
            'shippingCity' => $row->shipping_city,
            'shippingState' => $row->shipping_state,
            'shippingCode' => $row->shipping_code,
            'shippingCountry' => $row->shipping_country,
            'shippingPoBox' => $row->shipping_po_box,
            'description' => $row->description,
            'assignedTo' => $row->assigned_to ? (string) $row->assigned_to : null,
            'createdBy' => $row->created_by ? (string) $row->created_by : null,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
        ];
    }
}
