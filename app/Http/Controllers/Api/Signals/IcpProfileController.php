<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Signals\Icp\IcpPack;
use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Icp\IcpProfileValidator;
use App\Domain\Signals\Icp\IcpProfileVersion;
use App\Domain\Signals\Icp\IcpResearchPlanner;
use App\Domain\Signals\Icp\TargetAccount;
use App\Domain\Signals\Products\ProductCatalog;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * An organisation's own ICPs, as data. Administrators only (route middleware). Every query is scoped to the
 * caller's organisation, which comes from the token, never from the request; another organisation's ICP is
 * answered exactly like a missing one (404).
 *
 * An ICP is validated before it is saved (IcpProfileValidator) and every save keeps the previous version.
 * Only an ACTIVE ICP is researched. Nothing here calls a search or AI provider: preview only plans.
 */
class IcpProfileController extends Controller
{
    use ResolvesApiIdentity;

    public function __construct(private readonly IcpProfileValidator $validator)
    {
    }

    /** Templates an organisation can start from. */
    public function templates(Request $request): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }

        $out = [];
        foreach ((array) config('signals_icp') as $key => $cfg) {
            $out[] = ['key' => $key, 'label' => $cfg['label'] ?? $key, 'industry_label' => $cfg['industry_label'] ?? null, 'queries' => count($cfg['queries'] ?? []), 'triggers' => count($cfg['triggers'] ?? [])];
        }

        return response()->json(['status' => 1, 'data' => $out]);
    }

    public function index(Request $request): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        $tenant = $identity['sub_institute_id'];

        $rows = IcpProfile::where('sub_institute_id', $tenant)->orderByDesc('is_default')->orderBy('id')->get();
        $accounts = TargetAccount::where('sub_institute_id', $tenant)->selectRaw('pack, COUNT(*) c')->groupBy('pack')->pluck('c', 'pack');

        return response()->json(['status' => 1, 'data' => $rows->map(fn ($p) => $this->present($p, false) + ['accounts' => (int) ($accounts[$p->key] ?? 0)])->values()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        $p = $this->find($identity['sub_institute_id'], $id);

        return $p ? response()->json(['status' => 1, 'data' => $this->present($p, true) + ['versions' => $this->versions($p)]]) : $this->missing();
    }

    /** Create an ICP from a template or from a configuration the caller supplies. It starts as a draft. */
    public function store(Request $request): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        $tenant = $identity['sub_institute_id'];

        $v = Validator::make($request->all(), [
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'name' => 'required|string|max:120',
            'template' => 'nullable|string|max:60',
            'config' => 'nullable|array',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        if (IcpProfile::where('sub_institute_id', $tenant)->where('key', $request->input('key'))->exists()) {
            return response()->json(['status' => 0, 'message' => 'You already have an ICP with that key.'], 422);
        }

        $config = $request->input('config');
        if ($config === null) {
            $config = config('signals_icp.' . (string) $request->input('template'));
            if (! is_array($config)) {
                return response()->json(['status' => 0, 'message' => 'Give a "config", or the key of a template to copy.'], 422);
            }
        }
        if (($problems = $this->validator->validate($config)) !== []) {
            return response()->json(['status' => 0, 'message' => 'The ICP is not valid.', 'problems' => $problems], 422);
        }

        $profile = DB::transaction(function () use ($tenant, $identity, $request, $config) {
            $p = IcpProfile::create([
                'sub_institute_id' => $tenant, 'key' => $request->input('key'), 'name' => $request->input('name'),
                'product_line' => $config['product_line'] ?? null, 'status' => 'draft', 'is_default' => false, 'version' => 1,
                'config' => $config, 'created_by' => $identity['user_id'], 'updated_by' => $identity['user_id'],
            ]);
            $this->snapshot($p, $identity['user_id'], 'created');

            return $p;
        });

        return response()->json(['status' => 1, 'data' => $this->present($profile, true)], 201);
    }

    /** Change name and/or configuration. The previous version is kept; the version number goes up. */
    public function update(Request $request, int $id): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        $p = $this->find($identity['sub_institute_id'], $id);
        if (! $p) {
            return $this->missing();
        }

        $v = Validator::make($request->all(), ['name' => 'nullable|string|max:120', 'config' => 'nullable|array', 'note' => 'nullable|string|max:255']);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $config = $request->input('config');
        if ($config !== null && ($problems = $this->validator->validate($config)) !== []) {
            return response()->json(['status' => 0, 'message' => 'The ICP is not valid.', 'problems' => $problems], 422);
        }

        // An ICP belongs to one product for good: its product line cannot be changed to point it at another product.
        if ($config !== null && ($config['product_line'] ?? null) !== $p->product_line && ProductCatalog::isProductLine($p->product_line, (int) $p->sub_institute_id)) {
            return response()->json(['status' => 0, 'message' => 'This ICP belongs to its product and cannot be moved to another. Create a separate ICP for that product.'], 422);
        }

        DB::transaction(function () use ($p, $request, $config, $identity) {
            if ($config !== null && $config != $p->config) {
                $p->version = (int) $p->version + 1;
                $p->config = $config;
                $p->product_line = $config['product_line'] ?? null;
            }
            if ($request->filled('name')) {
                $p->name = $request->input('name');
            }
            $p->updated_by = $identity['user_id'];
            $dirtyConfig = $p->isDirty('config');
            $p->save();
            if ($dirtyConfig) {
                $this->snapshot($p, $identity['user_id'], (string) $request->input('note', 'edited'));
            }
        });

        return response()->json(['status' => 1, 'data' => $this->present($p->refresh(), true)]);
    }

    /** Check a configuration without saving anything. */
    public function validateConfig(Request $request): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        $config = $request->input('config');
        if (! is_array($config)) {
            return response()->json(['status' => 0, 'message' => 'Give a "config" object.'], 422);
        }
        $problems = $this->validator->validate($config);

        return response()->json(['status' => 1, 'data' => ['valid' => $problems === [], 'problems' => $problems]]);
    }

    /**
     * Show what today's run would search for, and the instructions the model would be given. Calls no provider.
     */
    public function preview(Request $request, int $id): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        $p = $this->find($identity['sub_institute_id'], $id);
        if (! $p) {
            return $this->missing();
        }
        $pack = new IcpPack((string) $p->key, (array) $p->config, (int) $p->id, (string) $p->name);
        $plan = (new IcpResearchPlanner())->plan($identity['sub_institute_id'], $pack);
        $counts = TargetAccount::where('sub_institute_id', $identity['sub_institute_id'])->where('pack', $p->key)->selectRaw('icp_status, COUNT(*) c')->groupBy('icp_status')->pluck('c', 'icp_status');

        return response()->json(['status' => 1, 'data' => [
            'problems' => $this->validator->validate((array) $p->config),
            'todays_searches' => array_map(fn ($s) => array_filter(['id' => $s['id'] ?? null, 'source' => $s['source'] ?? null, 'topic' => $s['topic'] ?? null, 'recency_days' => $s['days'] ?? null, 'query' => $s['q'], 'sites' => $s['domains'] ?? null]), $plan['specs']),
            'accounts_in_todays_run' => $plan['accounts'],
            'account_counts' => $counts,
            'instructions_to_the_model' => $pack->promptBlock(),
        ]]);
    }

    /** activate | archive | draft, and optionally make it the default for a manual run. */
    public function setStatus(Request $request, int $id): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        $p = $this->find($identity['sub_institute_id'], $id);
        if (! $p) {
            return $this->missing();
        }
        $v = Validator::make($request->all(), ['status' => 'required|in:draft,active,archived', 'is_default' => 'nullable|boolean']);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        if ($request->input('status') === 'active' && ($problems = $this->validator->validate((array) $p->config)) !== []) {
            return response()->json(['status' => 0, 'message' => 'Fix these before activating.', 'problems' => $problems], 422);
        }

        DB::transaction(function () use ($p, $request, $identity) {
            $p->status = $request->input('status');
            // At most one ICP is active per product line: activating this one archives the others (their history is kept).
            if ($p->status === 'active' && ProductCatalog::isProductLine($p->product_line, (int) $p->sub_institute_id)) {
                IcpProfile::where('sub_institute_id', $identity['sub_institute_id'])->where('product_line', $p->product_line)
                    ->where('id', '!=', $p->id)->where('status', 'active')->update(['status' => 'archived', 'is_default' => false]);
            }
            if ($request->has('is_default') && $request->boolean('is_default') && $p->status === 'active') {
                IcpProfile::where('sub_institute_id', $identity['sub_institute_id'])->where('id', '!=', $p->id)->update(['is_default' => false]);
                $p->is_default = true;
            }
            if ($p->status !== 'active') {
                $p->is_default = false;
            }
            $p->updated_by = $identity['user_id'];
            $p->save();
        });

        return response()->json(['status' => 1, 'data' => $this->present($p->refresh(), false)]);
    }

    /** The accounts (organisations watched) on this ICP, with their scores. */
    public function accounts(Request $request, int $id): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        $p = $this->find($identity['sub_institute_id'], $id);
        if (! $p) {
            return $this->missing();
        }
        $q = TargetAccount::where('sub_institute_id', $identity['sub_institute_id'])->where('pack', $p->key)
            ->when($request->query('status'), fn ($q, $s) => $q->where('icp_status', $s))
            ->when(trim((string) $request->query('search', '')) !== '', fn ($q) => $q->where('name', 'like', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $request->query('search'))) . '%'))
            ->orderBy('watch_priority')->orderBy('id');
        $page = $q->paginate(min(50, max(1, (int) $request->query('per_page', 25))));

        return response()->json(['status' => 1, 'data' => collect($page->items())->map(fn ($a) => [
            'id' => $a->id, 'name' => $a->name, 'city' => $a->city, 'state' => $a->state, 'board' => $a->board, 'crm_ref' => $a->crm_ref,
            'icp_status' => $a->icp_status, 'fit_pct' => $a->icp_fit_pct, 'watch_priority' => $a->watch_priority,
            'attributes' => $a->attributes, 'last_checked_at' => $a->last_checked_at?->toIso8601String(), 'last_signal_at' => $a->last_signal_at?->toIso8601String(),
        ])->values(), 'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function find(int $tenantId, int $id): ?IcpProfile
    {
        return IcpProfile::where('sub_institute_id', $tenantId)->where('id', $id)->first();
    }

    private function missing(): JsonResponse
    {
        return response()->json(['status' => 0, 'message' => 'ICP not found'], 404);
    }

    private function snapshot(IcpProfile $p, ?int $userId, string $note): void
    {
        IcpProfileVersion::create([
            'sub_institute_id' => $p->sub_institute_id, 'icp_profile_id' => $p->id, 'version' => $p->version,
            'config' => $p->config, 'changed_by' => $userId, 'note' => mb_substr($note, 0, 255),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function versions(IcpProfile $p): array
    {
        return IcpProfileVersion::where('icp_profile_id', $p->id)->orderByDesc('version')->limit(20)
            ->get(['version', 'changed_by', 'note', 'created_at'])->map(fn ($v) => ['version' => $v->version, 'changed_by' => $v->changed_by, 'note' => $v->note, 'at' => $v->created_at?->toIso8601String()])->all();
    }

    /** @return array<string, mixed> */
    private function present(IcpProfile $p, bool $withConfig): array
    {
        return array_filter([
            'id' => $p->id, 'key' => $p->key, 'name' => $p->name, 'product_line' => $p->product_line, 'status' => $p->status,
            'is_default' => (bool) $p->is_default, 'version' => (int) $p->version, 'updated_at' => $p->updated_at?->toIso8601String(),
            'config' => $withConfig ? $p->config : null,
        ], fn ($v) => $v !== null);
    }
}
