<?php

namespace App\Http\Controllers\Api\Gtm;

use App\Domain\Gtm\GtmAccount;
use App\Domain\Gtm\GtmActivity;
use App\Domain\Gtm\GtmAudit;
use App\Domain\Gtm\GtmContact;
use App\Domain\Gtm\IcpScorer;
use App\Domain\Signals\Opportunities\Company;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * GTM target accounts.
 *
 * Tenant identity comes from the token only. Another organisation's account is answered
 * exactly like a missing one (404). An account may point at a g2g_company (research
 * discovery); its signals are read live from g2g_company_opportunities, never copied.
 */
class AccountController extends Controller
{
    use ResolvesApiIdentity;

    public const STAGES = ['target', 'engaged', 'opportunity', 'customer', 'churned', 'disqualified'];

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenant = $identity['sub_institute_id'];

        $perPage = min(100, max(5, (int) $request->input('per_page', 25)));
        $q = trim((string) $request->input('q', ''));
        $stage = (string) $request->input('stage', '');

        $query = GtmAccount::query()->where('gtm_accounts.sub_institute_id', $tenant);
        if ($stage !== '' && in_array($stage, self::STAGES, true)) {
            $query->where('stage', $stage);
        }
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('domain', 'like', $like)->orWhere('industry', 'like', $like));
        }

        // Counts are correlated sub-selects on the SAME tenant, so a row can never be
        // counted against an account from another organisation.
        $query->select('gtm_accounts.*')
            ->selectSub(
                DB::table('gtm_contacts')->selectRaw('COUNT(*)')->whereColumn('gtm_contacts.account_id', 'gtm_accounts.id')
                    ->where('gtm_contacts.sub_institute_id', $tenant)->whereNull('gtm_contacts.deleted_at'),
                'contacts_count'
            )
            ->selectSub(
                DB::table('g2g_company_opportunities')->selectRaw('COUNT(*)')->whereColumn('g2g_company_opportunities.company_id', 'gtm_accounts.company_id')
                    ->where('g2g_company_opportunities.sub_institute_id', $tenant)->where('g2g_company_opportunities.review_status', '!=', 'Dismissed'),
                'signals_count'
            )
            ->selectSub(
                DB::table('gtm_activities')->selectRaw('MAX(occurred_at)')->whereColumn('gtm_activities.account_id', 'gtm_accounts.id')
                    ->where('gtm_activities.sub_institute_id', $tenant),
                'last_activity_at'
            );

        $sort = (string) $request->input('sort', 'recent');
        match ($sort) {
            'fit' => $query->orderByRaw('icp_fit_score IS NULL')->orderByDesc('icp_fit_score'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('gtm_accounts.id'),
        };

        $page = $query->paginate($perPage);

        return response()->json(['status' => 1, 'data' => [
            'items' => $page->items(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
        ]]);
    }

    /**
     * Companies research has found that nobody has promoted to an account yet, each with
     * its strongest live signal. This is the "who should we look at" list - every row is a
     * g2g_company the tenant's own research produced.
     */
    public function candidates(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        $rows = DB::table('g2g_companies as c')
            ->where('c.sub_institute_id', $t)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('gtm_accounts as a')
                ->whereColumn('a.company_id', 'c.id')->where('a.sub_institute_id', $t)->whereNull('a.deleted_at'))
            ->select('c.id', 'c.name', 'c.website', 'c.industry', 'c.location')
            ->selectSub(
                DB::table('g2g_company_opportunities as o')->selectRaw('COUNT(*)')->whereColumn('o.company_id', 'c.id')
                    ->where('o.sub_institute_id', $t)->where('o.review_status', '!=', 'Dismissed'),
                'signals_count'
            )
            ->orderByDesc('c.id')->limit(50)->get();

        foreach ($rows as $row) {
            $top = DB::table('g2g_company_opportunities')->where('sub_institute_id', $t)->where('company_id', $row->id)
                ->where('review_status', '!=', 'Dismissed')
                ->orderByRaw("FIELD(priority,'High','Medium','Low')")->orderByDesc('first_discovered_at')
                ->first(['title', 'priority', 'signal_kind', 'sources']);
            $row->top_signal = $top ? [
                'title' => $top->title, 'priority' => $top->priority, 'kind' => $top->signal_kind,
                'source_url' => (json_decode($top->sources ?? '[]', true)[0]['url'] ?? null),
            ] : null;
        }

        return response()->json(['status' => 1, 'data' => ['items' => $rows]]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenant = $identity['sub_institute_id'];

        $account = GtmAccount::where('sub_institute_id', $tenant)->find($id);
        if (! $account) {
            return response()->json(['status' => 0, 'message' => 'Account not found'], 404);
        }

        $contacts = GtmContact::where('sub_institute_id', $tenant)->where('account_id', $id)->orderBy('full_name')->get();

        $signals = [];
        if ($account->company_id) {
            $signals = DB::table('g2g_company_opportunities')
                ->where('sub_institute_id', $tenant)->where('company_id', $account->company_id)
                ->where('review_status', '!=', 'Dismissed')
                ->orderByDesc('first_discovered_at')->limit(25)
                ->get(['id', 'title', 'signal_kind', 'observed_event', 'why_indicates_need', 'recommended_action', 'priority', 'confidence', 'review_status', 'sources', 'event_date', 'first_discovered_at'])
                ->map(function ($s) {
                    $s->sources = json_decode($s->sources ?? '[]', true) ?: [];

                    return $s;
                })->all();
        }

        $activities = GtmActivity::where('sub_institute_id', $tenant)->where('account_id', $id)
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(50)->get();

        $analyses = DB::table('gtm_analyses')->where('sub_institute_id', $tenant)
            ->where('subject_type', 'account')->where('subject_id', $id)
            ->orderByDesc('id')->limit(10)
            ->get(['id', 'kind', 'status', 'is_estimate', 'provider', 'model', 'error_code', 'created_at']);

        return response()->json(['status' => 1, 'data' => [
            'account' => $account,
            'contacts' => $contacts,
            'signals' => $signals,
            'activities' => $activities,
            'analyses' => $analyses,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenant = $identity['sub_institute_id'];

        $v = Validator::make($request->all(), $this->rules(true));
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $data = $v->validated();
        $data['domain'] = $this->normaliseDomain($data['domain'] ?? ($data['website'] ?? null));

        if ($data['domain'] && GtmAccount::withTrashed()->where('sub_institute_id', $tenant)->where('domain', $data['domain'])->exists()) {
            return response()->json(['status' => 0, 'message' => 'An account with this domain already exists.'], 422);
        }

        $account = GtmAccount::create($data + [
            'sub_institute_id' => $tenant,
            'source' => 'manual',
            'created_by' => $identity['user_id'],
        ]);

        GtmAudit::record('gtm.account.created', $tenant, 'gtm_accounts', $account->id, $identity['user_id'], ['name' => $account->name, 'source' => 'manual']);

        return response()->json(['status' => 1, 'data' => ['account' => $account]], 201);
    }

    /**
     * Promote a research-discovered company into the working list.
     * Idempotent: promoting the same company twice returns the existing account.
     */
    public function fromCompany(Request $request, int $companyId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenant = $identity['sub_institute_id'];

        $company = Company::where('sub_institute_id', $tenant)->find($companyId);
        if (! $company) {
            return response()->json(['status' => 0, 'message' => 'Company not found'], 404);
        }

        $existing = GtmAccount::where('sub_institute_id', $tenant)->where('company_id', $company->id)->first();
        if ($existing) {
            return response()->json(['status' => 1, 'data' => ['account' => $existing, 'created' => false]]);
        }

        $domain = $this->normaliseDomain($company->domain ?: $company->website);
        if ($domain && GtmAccount::withTrashed()->where('sub_institute_id', $tenant)->where('domain', $domain)->exists()) {
            $domain = null; // a manual account already owns this domain; keep both rather than fail the promote
        }

        $account = GtmAccount::create([
            'sub_institute_id' => $tenant,
            'company_id' => $company->id,
            'name' => $company->name,
            'domain' => $domain,
            'website' => $company->website,
            'industry' => $company->industry,
            'location' => $company->location,
            'source' => 'research',
            'created_by' => $identity['user_id'],
        ]);

        GtmActivity::create([
            'sub_institute_id' => $tenant, 'account_id' => $account->id, 'type' => 'system',
            'subject' => 'Added from research signals', 'occurred_at' => now(), 'user_id' => $identity['user_id'],
            'metadata' => ['company_id' => $company->id],
        ]);
        GtmAudit::record('gtm.account.created', $tenant, 'gtm_accounts', $account->id, $identity['user_id'], ['name' => $account->name, 'source' => 'research', 'company_id' => $company->id]);

        return response()->json(['status' => 1, 'data' => ['account' => $account, 'created' => true]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenant = $identity['sub_institute_id'];

        $account = GtmAccount::where('sub_institute_id', $tenant)->find($id);
        if (! $account) {
            return response()->json(['status' => 0, 'message' => 'Account not found'], 404);
        }

        $v = Validator::make($request->all(), $this->rules(false));
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $data = $v->validated();

        if (array_key_exists('domain', $data) || array_key_exists('website', $data)) {
            $data['domain'] = $this->normaliseDomain($data['domain'] ?? ($data['website'] ?? $account->domain));
            if ($data['domain'] && GtmAccount::withTrashed()->where('sub_institute_id', $tenant)->where('domain', $data['domain'])->where('id', '!=', $id)->exists()) {
                return response()->json(['status' => 0, 'message' => 'Another account already uses this domain.'], 422);
            }
        }
        if (isset($data['owner_user_id']) && ! DB::table('tbluser')->where('id', $data['owner_user_id'])->where('sub_institute_id', $tenant)->exists()) {
            return response()->json(['status' => 0, 'message' => 'Owner must be a user in this organisation.'], 422);
        }

        $before = $account->only(['stage', 'owner_user_id']);
        $account->fill($data)->save();

        if (($before['stage'] ?? null) !== $account->stage) {
            GtmActivity::create([
                'sub_institute_id' => $tenant, 'account_id' => $account->id, 'type' => 'system',
                'subject' => "Stage changed: {$before['stage']} → {$account->stage}", 'occurred_at' => now(), 'user_id' => $identity['user_id'],
            ]);
        }
        GtmAudit::record('gtm.account.updated', $tenant, 'gtm_accounts', $account->id, $identity['user_id'], ['changed' => array_keys($data)]);

        return response()->json(['status' => 1, 'data' => ['account' => $account->fresh()]]);
    }

    /** Score this account against the organisation's own ICP. The result is an AI ESTIMATE. */
    public function scoreIcp(Request $request, int $id, IcpScorer $scorer): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenant = $identity['sub_institute_id'];

        $account = GtmAccount::where('sub_institute_id', $tenant)->find($id);
        if (! $account) {
            return response()->json(['status' => 0, 'message' => 'Account not found'], 404);
        }

        try {
            $result = $scorer->score($account, $identity['user_id']);
        } catch (\DomainException $e) {
            return response()->json(['status' => 0, 'code' => 'icp_not_defined', 'message' => $e->getMessage()], 422);
        } catch (\App\Domain\AI\Support\AiNotConfiguredException $e) {
            return response()->json(['status' => 0, 'code' => 'ai_not_configured', 'message' => $e->getMessage()], 503);
        } catch (\App\Domain\AI\Support\AiQuotaExceededException|\App\Domain\AI\Support\AiCredentialsExhaustedException $e) {
            return response()->json(['status' => 0, 'code' => 'ai_unavailable', 'message' => $e->getMessage()], 503);
        } catch (\App\Domain\AI\Support\AiProviderHttpException $e) {
            report($e);
            if ($e->httpStatus === 429) {
                return response()->json(['status' => 0, 'code' => 'ai_rate_limited', 'message' => 'The AI provider\'s quota or rate limit has been reached. Nothing was changed - try again later.'], 503);
            }

            return $e->httpStatus >= 500
                ? response()->json(['status' => 0, 'code' => 'ai_provider_busy', 'message' => 'The AI provider is temporarily overloaded. Nothing was changed - please try again in a minute.'], 503)
                : response()->json(['status' => 0, 'code' => 'ai_error', 'message' => 'The AI provider rejected this request. Nothing was changed.'], 502);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 0, 'code' => 'ai_error', 'message' => 'The AI provider could not score this account. Nothing was changed.'], 502);
        }

        GtmActivity::create([
            'sub_institute_id' => $tenant, 'account_id' => $account->id, 'type' => 'system',
            'subject' => 'ICP fit scored: '.($result['score'] ?? 'not enough data'), 'occurred_at' => now(), 'user_id' => $identity['user_id'],
            'metadata' => ['analysis_id' => $result['analysis_id']],
        ]);
        GtmAudit::record('gtm.account.icp_scored', $tenant, 'gtm_accounts', $account->id, $identity['user_id'], ['score' => $result['score'], 'analysis_id' => $result['analysis_id']]);

        return response()->json(['status' => 1, 'data' => ['account' => $account->fresh(), 'score' => $result['score'], 'basis' => $result['basis']]]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenant = $identity['sub_institute_id'];

        $account = GtmAccount::where('sub_institute_id', $tenant)->find($id);
        if (! $account) {
            return response()->json(['status' => 0, 'message' => 'Account not found'], 404);
        }
        $account->delete();
        GtmAudit::record('gtm.account.deleted', $tenant, 'gtm_accounts', $id, $identity['user_id'], ['name' => $account->name]);

        return response()->json(['status' => 1, 'message' => 'Account removed']);
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => "$req|string|max:191",
            'domain' => 'nullable|string|max:191',
            'website' => 'nullable|string|max:255',
            'industry' => 'nullable|string|max:150',
            'employee_range' => 'nullable|string|max:50',
            'location' => 'nullable|string|max:191',
            'stage' => 'sometimes|in:'.implode(',', self::STAGES),
            'owner_user_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:4000',
        ];
    }

    private function normaliseDomain(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }
        $host = parse_url(str_contains($value, '://') ? $value : 'https://'.$value, PHP_URL_HOST) ?: $value;

        return preg_replace('/^www\./', '', $host) ?: null;
    }
}
