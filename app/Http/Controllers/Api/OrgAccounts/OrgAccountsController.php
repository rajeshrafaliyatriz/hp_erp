<?php

namespace App\Http\Controllers\Api\OrgAccounts;

use App\Domain\OrgAccounts\OrgAccess;
use App\Domain\OrgAccounts\OrgAccount;
use App\Domain\OrgAccounts\OrgAccountPresenter;
use App\Domain\OrgAccounts\OrgEnsure;
use App\Domain\OrgAccounts\OrgLinkConflict;
use App\Domain\OrgAccounts\OrgLinker;
use App\Domain\OrgAccounts\OrgLinkNotFound;
use App\Domain\OrgAccounts\OrgNormalizer;
use App\Domain\OrgAccounts\OrgResolver;
use App\Domain\OrgAccounts\OrgSources;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The Organisation 360 API (slice 3, Docs/agentic-crm/slices/S03-organisation-360.md). Routes and rights are in routes/org_accounts.php.
 *
 * The organisation always comes from the caller's token, never from the request. Every read and write of an identity filters by it, and another
 * organisation's identity is answered with the same 404 as one that does not exist, so a probe learns nothing. The shapes are built by
 * OrgAccountPresenter; the matching and linking rules live in OrgResolver and OrgLinker.
 */
class OrgAccountsController extends Controller
{
    use ResolvesApiIdentity;

    /** How many possible duplicates the Possible duplicates tab shows. */
    private const SUGGESTION_LIMIT = 100;

    /** Where each source type lives: the table, and whether it keeps deleted rows. */
    private const SOURCE_TABLES = [
        OrgAccount::SOURCE_CRM => ['crm_organizations', true],
        OrgAccount::SOURCE_GTM => ['gtm_accounts', true],
        OrgAccount::SOURCE_COMPANY => ['g2g_companies', false],
    ];

    /** GET /: ?q, ?lifecycle, ?has_crm / ?has_gtm / ?has_signals (0|1), ?sort=name|updated_at, ?dir=asc|desc, ?page, ?per_page (at most 50). */
    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        if ($down = $this->notSetUp()) {
            return $down;
        }

        $validator = Validator::make($request->query(), [
            'q' => ['nullable', 'string', 'max:100'],
            'lifecycle' => ['nullable', Rule::in(OrgAccount::LIFECYCLES)],
            'has_crm' => ['nullable', 'in:0,1'], 'has_gtm' => ['nullable', 'in:0,1'], 'has_signals' => ['nullable', 'in:0,1'],
            'sort' => ['nullable', 'in:name,updated_at'], 'dir' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        $filters = $validator->validated();
        foreach (['has_crm', 'has_gtm', 'has_signals'] as $flag) {
            if (isset($filters[$flag])) {
                $filters[$flag] = $filters[$flag] === '1';
            }
        }

        $presenter = new OrgAccountPresenter($t);
        $page = $presenter->page($filters);

        return response()->json([
            'status' => 1, 'data' => $page['data'], 'meta' => $page['meta'],
            // The badge counts every suggestion; the Possible duplicates tab lists the first SUGGESTION_LIMIT of them.
            'summary' => $presenter->summary(count($this->suggestionList($t, PHP_INT_MAX))),
        ]);
    }

    /** GET /meta: what the screen's forms may offer and what the caller may do (the server still enforces it). */
    public function meta(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $screen = fn (string $action) => OrgAccess::canUseScreen($identity, $action);

        return response()->json(['status' => 1, 'data' => [
            'lifecycles' => OrgAccount::LIFECYCLES,
            'can' => [
                'create_crm_organization' => $screen('add'),
                'edit' => $screen('edit'),
                'confirm_links' => $screen('edit'),
            ],
        ]]);
    }

    /**
     * GET /suggestions: possible duplicates a person is asked about. With ?identity_id=N only the pairs that involve a row of that one organisation
     * (its Account 360 tab asks that way); another organisation's identity is a 404.
     */
    public function suggestions(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        if ($down = $this->notSetUp()) {
            return $down;
        }

        $validator = Validator::make($request->query(), ['identity_id' => ['nullable', 'integer', 'min:1']]);
        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }
        if ($request->query('identity_id') === null) {
            return response()->json(['status' => 1, 'data' => $this->suggestionList($t)]);
        }

        $account = $this->find($t, (int) $request->query('identity_id'));
        if (! $account) {
            return $this->notFound();
        }
        $mine = [];
        foreach (OrgAccount::POINTERS as $type => $column) {
            if ($account->{$column} !== null) {
                $mine[OrgSources::key($type, (int) $account->{$column})] = true;
            }
        }
        $pairs = array_filter(
            $this->suggestionList($t, PHP_INT_MAX),
            fn (array $s) => isset($mine[OrgSources::key($s['left']['type'], $s['left']['id'])]) || isset($mine[OrgSources::key($s['right']['type'], $s['right']['id'])]),
        );

        return response()->json(['status' => 1, 'data' => array_values($pairs)]);
    }

    /**
     * POST /for-crm/{id}: the 360 of the CRM organisation {id}, making its identity first if it has none (a CRM organisation made after the backfill).
     * Idempotent. It decides exactly what the backfill decides (an exact name or domain, never a fuzzy match) and writes only g2g_org_accounts, which is
     * why the screen's view right is enough. Another organisation's CRM organisation, a deleted one and one that never existed are the same 404.
     */
    public function forCrmOrganization(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        if ($down = $this->notSetUp()) {
            return $down;
        }
        try {
            $result = app(OrgEnsure::class)->forCrmOrganization($t, $id);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 0, 'code' => 'identity_conflict', 'message' => 'This organisation is being linked by someone else right now. Try again in a moment.'], 409);
        }
        if ($result === null) {
            return $this->notFound();
        }

        return response()->json(['status' => 1, 'created' => $result['created'], 'data' => (new OrgAccountPresenter($t))->threeSixty($result['account'])]);
    }

    /** GET /{id}: the 360. */
    public function show(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        if ($down = $this->notSetUp()) {
            return $down;
        }

        $account = $this->find($t, $id);
        if (! $account) {
            return $this->notFound();
        }

        return response()->json(['status' => 1, 'data' => (new OrgAccountPresenter($t))->threeSixty($account)]);
    }

    /** PATCH /{id} {lifecycle?, owner_user_id?, name?}: answers with the 360 as it now stands. */
    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        if ($down = $this->notSetUp()) {
            return $down;
        }

        $account = $this->find($t, $id);
        if (! $account) {
            return $this->notFound();
        }

        $body = $request->all();
        $fields = array_values(array_filter(['lifecycle', 'owner_user_id', 'name'], fn (string $f) => array_key_exists($f, $body)));
        if ($fields === []) {
            return $this->invalid(['body' => ['Send at least one of lifecycle, owner_user_id or name.']]);
        }

        $validator = Validator::make($body, [
            'lifecycle' => ['sometimes', 'required', 'string', Rule::in(OrgAccount::LIFECYCLES)],
            'owner_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'name' => ['sometimes', 'required', 'string', 'max:191'],
        ], [
            'lifecycle.in' => 'Lifecycle must be one of: ' . implode(', ', OrgAccount::LIFECYCLES) . '.',
            'name.required' => 'Give the organisation a name.', 'name.max' => 'A name can have at most 191 characters.',
        ]);
        $errors = $validator->errors()->toArray();
        $name = array_key_exists('name', $body) ? trim((string) ($body['name'] ?? '')) : null;
        if ($name === '' && ! isset($errors['name'])) {
            $errors['name'] = ['Give the organisation a name.'];
        }
        // The owner must be a live person of this organisation, never a user id of another one.
        if (! isset($errors['owner_user_id']) && ($body['owner_user_id'] ?? null) !== null
            && ! DB::table('tbluser')->where('id', (int) $body['owner_user_id'])->where('sub_institute_id', $t)->where('status', 1)->exists()) {
            $errors['owner_user_id'] = ['Pick an active person from your organisation.'];
        }
        if ($errors !== []) {
            return $this->invalid($errors);
        }

        if (in_array('lifecycle', $fields, true)) {
            $account->lifecycle = $body['lifecycle'];
        }
        if (in_array('owner_user_id', $fields, true)) {
            $account->owner_user_id = $body['owner_user_id'] !== null ? (int) $body['owner_user_id'] : null;
        }
        if ($name !== null) {
            $account->name = $name;
            $account->normalized_name = OrgNormalizer::name($name);
        }
        $account->updated_by = $identity['user_id'];
        $account->save();

        return response()->json(['status' => 1, 'data' => (new OrgAccountPresenter($t))->threeSixty($account)]);
    }

    /** POST /links {left_type, left_id, right_type, right_id, action: confirm|reject}: a person's answer to a possible duplicate. */
    public function links(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        if ($down = $this->notSetUp()) {
            return $down;
        }

        $validator = Validator::make($request->all(), [
            'left_type' => ['required', Rule::in(OrgAccount::SOURCES)], 'left_id' => ['required', 'integer', 'min:1'],
            'right_type' => ['required', Rule::in(OrgAccount::SOURCES)], 'right_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['confirm', 'reject'])],
        ]);
        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }
        $in = $validator->validated();
        $left = [$in['left_type'], (int) $in['left_id']];
        $right = [$in['right_type'], (int) $in['right_id']];
        if ($left === $right) {
            return $this->invalid(['right_id' => ['A row cannot be linked to itself.']]);
        }

        // Both ends must be rows of the caller's organisation before anything is handed on: another organisation's row is a 404, whatever the linker does.
        if (! $this->sourceExists($t, ...$left) || ! $this->sourceExists($t, ...$right)) {
            return $this->notFound();
        }

        try {
            $linker = app(OrgLinker::class);
            $in['action'] === 'confirm'
                ? $linker->confirm($t, $identity['user_id'], $left[0], $left[1], $right[0], $right[1])
                : $linker->reject($t, $identity['user_id'], $left[0], $left[1], $right[0], $right[1]);
        } catch (OrgLinkNotFound) {
            return $this->notFound();
        } catch (OrgLinkConflict $e) {
            return response()->json(['status' => 0, 'code' => $e->reason, 'message' => $e->getMessage()], 409);
        }

        if ($in['action'] === 'reject') {
            return response()->json(['status' => 1, 'data' => null]);
        }
        $merged = OrgAccount::query()->where('sub_institute_id', $t)->where(OrgAccount::POINTERS[$left[0]], $left[1])->first();

        return response()->json(['status' => 1, 'data' => $merged ? (new OrgAccountPresenter($t))->items(collect([$merged]))[0] : null]);
    }

    /**
     * POST /{id}/create-crm-organisation {restore_deleted?}: makes the CRM organisation of this identity and links it. Idempotent: an identity that
     * already has a live one answers 200 created:false. A DELETED CRM organisation with the same name is never overwritten and the name is never
     * reused behind its back: the answer is 409 deleted_name_exists, unless restore_deleted is true, which brings that organisation back and links it.
     */
    public function createCrmOrganisation(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];
        if ($down = $this->notSetUp()) {
            return $down;
        }

        // The add right of the Organizations screen (Marketing or Support row) is checked by the route: the same check the CRM's own create route makes.
        $validator = Validator::make($request->all(), ['restore_deleted' => ['sometimes', 'nullable', 'boolean']]);
        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }
        $restore = (bool) $request->boolean('restore_deleted');

        return DB::transaction(function () use ($t, $id, $identity, $restore) {
            // Locked, so two clicks on the same identity cannot both create an organisation.
            $account = OrgAccount::query()->where('sub_institute_id', $t)->lockForUpdate()->find($id);
            if (! $account) {
                return $this->notFound();
            }

            if ($account->crm_organization_id !== null && $this->crmRows($t)->where('id', $account->crm_organization_id)->whereNull('deleted_at')->exists()) {
                return $this->created($account->crm_organization_id, false, false, 200);
            }

            $clash = $this->crmRows($t)->where('name', $account->name)->first(['id', 'deleted_at']);
            $crmId = null;
            $restored = false;
            if ($clash && $clash->deleted_at === null) {
                return $this->invalid(['name' => ['An organization with that name already exists in the CRM. Link it instead of creating another.']]);
            }
            if ($clash) {
                if (! $restore) {
                    return response()->json(['status' => 0, 'code' => 'deleted_name_exists', 'message' => 'A deleted CRM organization already has this name. Restore it instead?',
                        'deleted_organization_id' => (int) $clash->id], 409);
                }
                // One CRM organization belongs to one identity, deleted identities included (the unique index counts them).
                if (OrgAccount::withTrashed()->where('sub_institute_id', $t)->where('crm_organization_id', $clash->id)->where('id', '!=', $account->id)->exists()) {
                    return $this->invalid(['name' => ['That CRM organization already belongs to another organisation here.']]);
                }
                $crmId = (int) $clash->id;
                $restored = true;
                // The columns CrmOrganizationController::destroy wrote when it deleted the row.
                $this->crmRows($t)->where('id', $crmId)->update(['deleted_at' => null, 'deleted_by' => null, 'updated_by' => $identity['user_id'], 'updated_at' => now()]);
            } else {
                try {
                    $crmId = (int) DB::table('crm_organizations')->insertGetId([
                        'account_no' => 'ORG-' . Str::upper(Str::random(8)),
                        'name' => $account->name,
                        'website' => $account->domain,
                        'email_opt_out' => false,
                        'assigned_to' => $account->owner_user_id ?? $identity['user_id'],
                        'created_by' => $identity['user_id'],
                        'sub_institute_id' => $t,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Someone made an organization with this name between the check and the insert.
                    return $this->invalid(['name' => ['An organization with that name already exists in the CRM. Link it instead of creating another.']]);
                }
            }

            $account->crm_organization_id = $crmId;
            $account->crm_link_basis = OrgAccount::BASIS_MANUAL;
            // Having a CRM organization moves an organisation up to engaged, but never down from customer or archived.
            if (in_array($account->lifecycle, ['discovered', 'target'], true)) {
                $account->lifecycle = 'engaged';
            }
            $account->updated_by = $identity['user_id'];
            $account->save();

            return $this->created($crmId, true, $restored, $restored ? 200 : 201);
        });
    }

    // ---- helpers ----

    /**
     * A host that has not run the schema migration has no identity tables. The Organizations screen opens this API on every visit, so such a host must
     * answer 503 ("not set up", which the screen shows and works around) and never a SQL error.
     */
    private function notSetUp(): ?JsonResponse
    {
        return OrgSources::hasIdentityTables() ? null : response()->json(['status' => 0, 'message' => 'Organisation 360 is not set up on this server yet.'], 503);
    }

    /** One identity of the caller's organisation (not deleted); null for a missing one and for another organisation's alike. */
    private function find(int $tenant, int $id): ?OrgAccount
    {
        return OrgAccount::query()->where('sub_institute_id', $tenant)->find($id);
    }

    private function crmRows(int $tenant): \Illuminate\Database\Query\Builder
    {
        return DB::table('crm_organizations')->where('sub_institute_id', $tenant);
    }

    private function sourceExists(int $tenant, string $type, int $id): bool
    {
        [$table, $softDeletes] = self::SOURCE_TABLES[$type];
        if (! Schema::hasTable($table)) {
            return false;
        }
        $q = DB::table($table)->where('sub_institute_id', $tenant)->where('id', $id);

        return ($softDeletes ? $q->whereNull('deleted_at') : $q)->exists();
    }

    /** @return list<array<string, mixed>> */
    private function suggestionList(int $tenant, int $limit = self::SUGGESTION_LIMIT): array
    {
        return array_values(app(OrgResolver::class)->suggestions($tenant, $limit));
    }

    private function created(int $crmId, bool $created, bool $restored, int $status): JsonResponse
    {
        return response()->json(['status' => 1, 'data' => ['created' => $created, 'crm_organization_id' => $crmId, 'restored' => $restored]], $status);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => 'Organisation not found.'], 404);
    }

    /** @param array<string, list<string>> $errors field => messages */
    private function invalid(array $errors): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => (string) (collect($errors)->flatten()->first() ?? 'The request is not valid.'), 'errors' => $errors], 422);
    }
}
