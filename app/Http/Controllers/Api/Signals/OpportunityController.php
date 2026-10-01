<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\ProductProfileService;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Opportunities\SearchProviderFactory;
use App\Domain\Signals\SignalRunner;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Jobs\RunOpportunityResearchJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Product profile, daily research and the company-opportunity feed.
 *
 * Tenant identity comes from the token only. Another organisation's record is answered
 * exactly like a missing one (404). Nothing here contacts a company or performs a sales
 * action; the review actions only change the record's own status.
 */
class OpportunityController extends Controller
{
    use ResolvesApiIdentity;

    private const REVIEW_ACTIONS = ['review' => 'Reviewed', 'dismiss' => 'Dismissed', 'follow-up' => 'Follow-up'];

    public function __construct(private readonly ProductProfileService $profiles)
    {
    }

    // ── product profile ──────────────────────────────────────────────────────

    public function showProfile(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $profile = ProductProfile::where('sub_institute_id', $identity['sub_institute_id'])->first();

        return response()->json(['status' => 1, 'data' => [
            'profile' => $profile,
            'completeness' => $this->profiles->completeness($profile),
            'defaults' => [
                'schedule_time' => config('signals.opportunities.default_schedule_time'),
                'timezone' => config('signals.timezone'),
                'recency_days' => config('signals.opportunities.default_recency_days'),
            ],
        ]]);
    }

    public function saveProfile(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $list = ['nullable', 'array', 'max:30'];
        $v = Validator::make($request->all(), [
            'product_name' => 'nullable|string|max:191',
            'description' => 'nullable|string|max:4000',
            'problems_solved' => 'nullable|string|max:4000',
            'features' => 'nullable|string|max:4000',
            'target_industries' => $list, 'target_industries.*' => 'nullable|string|max:100',
            'target_company_types' => $list, 'target_company_types.*' => 'nullable|string|max:100',
            'target_markets' => $list, 'target_markets.*' => 'nullable|string|max:100',
            'keywords' => $list, 'keywords.*' => 'nullable|string|max:100',
            'excluded' => $list, 'excluded.*' => 'nullable|string|max:100',
            'competitors' => $list, 'competitors.*' => 'nullable|string|max:100',
            'target_company_size' => 'nullable|string|max:100',
            'ideal_customer_profile' => 'nullable|string|max:4000',
            'research_enabled' => 'nullable|boolean',
            'research_frequency' => 'nullable|in:daily,weekdays,weekly',
            'schedule_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'recency_days' => 'nullable|integer|min:1|max:365',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }

        $data = $v->validated();
        foreach (['target_industries', 'target_company_types', 'target_markets', 'keywords', 'excluded', 'competitors'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = array_values(array_unique(array_filter(array_map('trim', (array) $data[$key]), fn ($x) => $x !== '')));
            }
        }

        $profile = ProductProfile::firstOrNew(['sub_institute_id' => $identity['sub_institute_id']]);
        $profile->fill($data);
        $profile->updated_by = $identity['user_id'];

        // Research may only be switched on once the profile can support it. Refusing
        // here is what stops an empty profile from ever producing "personalised" results.
        $completeness = $this->profiles->completeness($profile);
        if ($profile->research_enabled && ! $completeness['complete']) {
            return response()->json([
                'status' => 0, 'message' => 'Complete the product profile before enabling daily research.',
                'missing' => $completeness['missing'],
            ], 422);
        }

        $profile->save();

        return response()->json(['status' => 1, 'data' => ['profile' => $profile, 'completeness' => $completeness]]);
    }

    // ── research runs ────────────────────────────────────────────────────────

    public function status(Request $request, SignalRunner $signals): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $profile = ProductProfile::where('sub_institute_id', $tenantId)->first();
        $completeness = $this->profiles->completeness($profile);
        $search = SearchProviderFactory::make();
        $latest = ResearchRun::where('sub_institute_id', $tenantId)->orderByDesc('id')->first();
        $success = ResearchRun::where('sub_institute_id', $tenantId)->whereIn('status', ['success', 'partial'])->orderByDesc('id')->first();
        $running = ResearchRun::where('sub_institute_id', $tenantId)->where('status', 'running')
            ->where('started_at', '>=', now()->subMinutes((int) config('signals.stale_run_minutes', 15)))->exists();
        $next = $profile ? $this->profiles->nextRun($profile) : null;

        return response()->json(['status' => 1, 'data' => [
            'requirements' => [
                'profile_complete' => $completeness['complete'],
                'profile_missing' => $completeness['missing'],
                'search_configured' => $search !== null && $search->isConfigured(),
                'search_driver' => $search?->name(),
                'ai_configured' => $signals->aiConfigured($tenantId),
                'research_enabled' => (bool) ($profile?->research_enabled),
            ],
            'running' => $running,
            'latest_run' => $latest ? $this->presentRun($latest) : null,
            'last_successful_run' => $success ? $this->presentRun($success) : null,
            'next_scheduled_at' => $next?->toIso8601String(),
            'timezone' => config('signals.timezone'),
        ]]);
    }

    public function runs(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $runs = ResearchRun::where('sub_institute_id', $identity['sub_institute_id'])->orderByDesc('id')->limit(60)->get();

        return response()->json(['status' => 1, 'data' => $runs->map(fn ($r) => $this->presentRun($r))->values()]);
    }

    public function run(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $customParams = array_filter([
            'topic' => $request->input('topic'),
            'company' => $request->input('company'),
            'geography' => $request->input('geography'),
            'industry' => $request->input('industry'),
            'signal_type' => $request->input('signal_type'),
            'recency_days' => $request->input('recency_days'),
        ]);

        $isCustom = ! empty($customParams['topic']) || ! empty($customParams['company']) || ! empty($customParams['industry']);

        $profile = ProductProfile::where('sub_institute_id', $tenantId)->first();
        $completeness = $this->profiles->completeness($profile);
        $search = SearchProviderFactory::make();

        // Refuse up front, with the reason, instead of starting a run that can only skip.
        if (! $isCustom && ! $completeness['complete']) {
            return response()->json(['status' => 0, 'code' => 'profile_incomplete', 'message' => 'Complete the product profile before running research.', 'missing' => $completeness['missing']], 422);
        }
        if ($search === null || ! $search->isConfigured()) {
            return response()->json(['status' => 0, 'code' => 'research_not_configured', 'message' => 'No web search provider is configured. An administrator must set SIGNALS_SEARCH_DRIVER and its API key in the backend environment.'], 422);
        }
        if (! app(SignalRunner::class)->aiConfigured($tenantId)) {
            return response()->json(['status' => 0, 'code' => 'ai_not_configured', 'message' => 'No AI provider is configured for this organisation.'], 422);
        }

        $stale = now()->subMinutes((int) config('signals.stale_run_minutes', 15));
        if (ResearchRun::where('sub_institute_id', $tenantId)->where('status', 'running')->where('started_at', '>=', $stale)->exists()
            || ! Cache::add("signals:research-pending:{$tenantId}", 1, 60)) {
            return response()->json(['status' => 0, 'code' => 'already_running', 'message' => 'Research is already running.'], 409);
        }

        $cooldown = (int) config('signals.opportunities.manual_cooldown_minutes', 10);
        $recent = ResearchRun::where('sub_institute_id', $tenantId)->where('trigger', 'manual')->where('status', '!=', 'skipped')
            ->where('started_at', '>=', now()->subMinutes($cooldown))->orderByDesc('started_at')->first();
        if ($recent) {
            Cache::forget("signals:research-pending:{$tenantId}");
            $wait = max(1, (int) ceil($recent->started_at->copy()->addMinutes($cooldown)->diffInSeconds(now(), true)));

            return response()->json(['status' => 0, 'code' => 'cooldown', 'message' => 'Research ran a moment ago. Please wait before running it again.', 'retry_after_seconds' => $wait], 429)->header('Retry-After', (string) $wait);
        }

        $run = ResearchRun::create([
            'sub_institute_id' => $tenantId,
            'report_date' => now(config('signals.timezone'))->toDateString(),
            'trigger' => 'manual',
            'status' => 'running',
            'stage' => 'preparing',
            'stage_message' => 'Validating profile and building search queries...',
            'triggered_by' => $identity['user_id'],
            'started_at' => now(),
            'search_provider' => $search->name(),
        ]);

        RunOpportunityResearchJob::dispatch($tenantId, $identity['user_id'], $customParams ?: null, $run->id);

        $this->ensureQueueWorkerRunning();

        return response()->json([
            'status' => 1,
            'message' => 'Research started.',
            'data' => [
                'run_id' => $run->id,
                'run' => $this->presentRun($run),
            ],
        ], 202);
    }

    public function showRun(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $run = ResearchRun::where('sub_institute_id', $tenantId)->find($id);
        if (! $run) {
            return response()->json(['status' => 0, 'message' => 'Run not found'], 404);
        }

        return response()->json(['status' => 1, 'data' => $this->presentRun($run)]);
    }

    private function ensureQueueWorkerRunning(): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }

        try {
            $queueName = (string) config('signals.queue', 'signals');
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                pclose(popen("start /B php artisan queue:work --queue={$queueName},default --stop-when-empty", "r"));
            } else {
                exec("php artisan queue:work --queue={$queueName},default --stop-when-empty > /dev/null 2>&1 &");
            }
        } catch (\Throwable) {
            // Ignore background spawn errors; regular worker or scheduler picks it up
        }
    }

    /** Every source a run retrieved (cited or not), for auditing a report. */
    public function runSources(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        if (! ResearchRun::where('sub_institute_id', $tenantId)->where('id', $id)->exists()) {
            return response()->json(['status' => 0, 'message' => 'Run not found'], 404);
        }

        $rows = \App\Domain\Signals\Opportunities\ResearchSource::where('sub_institute_id', $tenantId)->where('research_run_id', $id)
            ->orderByDesc('cited')->orderBy('id')->limit(100)->get();

        return response()->json(['status' => 1, 'data' => $rows->map(fn ($r) => [
            'url' => $r->url, 'title' => $r->title, 'domain' => $r->domain, 'snippet' => $r->snippet,
            'published_at' => $r->published_at?->toDateString(), 'retrieved_at' => $r->retrieved_at?->toIso8601String(),
            'page_fetched' => $r->page_fetched, 'cited' => $r->cited,
        ])->values()]);
    }

    // ── opportunity feed ─────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $v = Validator::make($request->query(), [
            'search' => 'nullable|string|max:100',
            'category' => 'nullable|string|in:' . implode(',', array_keys(config('signals.opportunities.categories'))),
            'kind' => 'nullable|string|in:' . implode(',', array_keys(config('signals.opportunities.signal_kinds'))),
            'priority' => 'nullable|in:High,Medium,Low',
            'qualification' => 'nullable|in:New Opportunity,Needs Verification,Relevant Requirement Found,Monitoring',
            'review_status' => 'nullable|in:New,Reviewed,Follow-up,Dismissed',
            'feed_section' => 'nullable|string|in:immediate_action,watchlist,market_intelligence,competitor_intelligence,top_actions',
            'run_id' => 'nullable|integer|min:1',
            'report_date' => 'nullable|date',
            'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }

        $base = fn () => $this->filtered($request, $tenantId);

        $list = $base()
            ->when($request->query('review_status'), fn ($q, $s) => $q->where('o.review_status', $s))
            ->when($request->query('priority'), fn ($q, $p) => $q->where('o.priority', $p))
            ->orderByRaw("CASE o.priority WHEN 'High' THEN 1 WHEN 'Medium' THEN 2 ELSE 3 END")
            ->orderByDesc('o.first_discovered_at')->orderByDesc('o.id')
            ->paginate((int) $request->query('per_page', 10));

        $counts = $base()->selectRaw(
            "COUNT(*) AS total,
             COALESCE(SUM(CASE WHEN o.review_status = 'New' THEN 1 ELSE 0 END), 0) AS new_count,
             COALESCE(SUM(CASE WHEN o.priority = 'High' AND o.review_status <> 'Dismissed' THEN 1 ELSE 0 END), 0) AS high_count,
             COALESCE(SUM(CASE WHEN o.review_status = 'Follow-up' THEN 1 ELSE 0 END), 0) AS follow_up_count,
             COALESCE(SUM(CASE WHEN o.feed_section = 'immediate_action' THEN 1 ELSE 0 END), 0) AS immediate_count,
             COALESCE(SUM(CASE WHEN o.feed_section = 'watchlist' THEN 1 ELSE 0 END), 0) AS watchlist_count,
             COALESCE(SUM(CASE WHEN o.feed_section = 'market_intelligence' THEN 1 ELSE 0 END), 0) AS market_intel_count,
             COALESCE(SUM(CASE WHEN o.feed_section = 'competitor_intelligence' THEN 1 ELSE 0 END), 0) AS competitor_intel_count"
        )->first();

        return response()->json([
            'status' => 1,
            'data' => collect($list->items())->map(fn ($row) => $this->presentOpportunity($row))->all(),
            'meta' => ['page' => $list->currentPage(), 'per_page' => $list->perPage(), 'total' => $list->total(), 'last_page' => $list->lastPage()],
            'summary' => [
                'total' => (int) ($counts->total ?? 0),
                'new' => (int) ($counts->new_count ?? 0),
                'high_priority' => (int) ($counts->high_count ?? 0),
                'follow_up' => (int) ($counts->follow_up_count ?? 0),
                'immediate' => (int) ($counts->immediate_count ?? 0),
                'watchlist' => (int) ($counts->watchlist_count ?? 0),
                'market_intelligence' => (int) ($counts->market_intel_count ?? 0),
                'competitor_intelligence' => (int) ($counts->competitor_intel_count ?? 0),
            ],
            'filters' => [
                'categories' => collect(config('signals.opportunities.categories'))->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values(),
                'kinds' => collect(config('signals.opportunities.signal_kinds'))->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values(),
                'report_dates' => ResearchRun::where('sub_institute_id', $tenantId)->whereIn('status', ['success', 'partial'])
                    ->orderByDesc('report_date')->limit(60)->pluck('report_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->values(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        $row = $this->query($identity['sub_institute_id'])->where('o.id', $id)->first();

        return $row
            ? response()->json(['status' => 1, 'data' => $this->presentOpportunity($row, true)])
            : response()->json(['status' => 0, 'message' => 'Opportunity not found'], 404);
    }

    public function act(Request $request, int $id, string $action): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        if (! isset(self::REVIEW_ACTIONS[$action])) {
            return response()->json(['status' => 0, 'message' => 'Unknown action'], 404);
        }

        $opportunity = CompanyOpportunity::where('sub_institute_id', $tenantId)->where('id', $id)->first();
        if (! $opportunity) {
            return response()->json(['status' => 0, 'message' => 'Opportunity not found'], 404);
        }

        $opportunity->forceFill(['review_status' => self::REVIEW_ACTIONS[$action], 'reviewed_by' => $identity['user_id'], 'reviewed_at' => now()])->save();
        $update = [
            'review_status' => self::REVIEW_ACTIONS[$action],
            'reviewed_by' => $identity['user_id'],
            'reviewed_at' => now(),
        ];
        if ($request->has('notes')) {
            $update['review_notes'] = $request->input('notes');
        }

        $opportunity->forceFill($update)->save();

        return response()->json(['status' => 1, 'data' => $this->presentOpportunity($this->query($tenantId)->where('o.id', $id)->first(), true)]);
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function query(int $tenantId)
    {
        return DB::table('g2g_company_opportunities as o')
            ->leftJoin('g2g_companies as c', function ($join) use ($tenantId) {
                $join->on('c.id', '=', 'o.company_id')->where('c.sub_institute_id', '=', $tenantId);
            })
            ->leftJoin('g2g_research_runs as r', function ($join) use ($tenantId) {
                $join->on('r.id', '=', 'o.research_run_id')->where('r.sub_institute_id', '=', $tenantId);
            })
            ->where('o.sub_institute_id', $tenantId)
            ->select('o.*', 'c.name as company_name', 'c.website as company_website', 'c.industry as company_industry', 'c.location as company_location', 'r.report_date');
    }

    private function filtered(Request $request, int $tenantId)
    {
        $q = $this->query($tenantId);

        foreach (['category' => 'o.category', 'kind' => 'o.signal_kind', 'qualification' => 'o.qualification', 'run_id' => 'o.research_run_id', 'feed_section' => 'o.feed_section'] as $param => $column) {
            if ($value = $request->query($param)) {
                $q->where($column, $value);
            }
        }
        if ($date = $request->query('report_date')) {
            $q->whereDate('r.report_date', Carbon::parse($date)->toDateString());
        }
        if ($from = $request->query('from')) {
            $q->where('o.first_discovered_at', '>=', Carbon::parse($from)->startOfDay());
        }
        if ($to = $request->query('to')) {
            $q->where('o.first_discovered_at', '<=', Carbon::parse($to)->endOfDay());
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $q->where(fn ($w) => $w->where('c.name', 'like', $like)->orWhere('o.title', 'like', $like)->orWhere('o.observed_event', 'like', $like));
        }

        return $q;
    }

    /** @return array<string, mixed> */
    public function presentOpportunity(object $row, bool $detail = false): array
    {
        $sources = json_decode($row->sources ?? '[]', true) ?: [];
        $confirmedFacts = json_decode($row->confirmed_facts ?? '[]', true) ?: [];
        $unverifiedClaims = json_decode($row->unverified_claims ?? '[]', true) ?: [];
        $categories = config('signals.opportunities.categories');

        $out = [
            'id' => (int) $row->id,
            'company_id' => (int) $row->company_id,
            'company_name' => $row->company_name,
            'website' => $row->company_website,
            'industry' => $row->company_industry,
            'location' => $row->company_location,
            'company_id' => $row->company_id ? (int) $row->company_id : null,
            'company_name' => $row->company_name ?? 'Identified Enterprise',
            'website' => $row->company_website ?? null,
            'industry' => $row->company_industry ?? null,
            'location' => $row->company_location ?? null,
            'title' => $row->title,
            'category' => $row->category,
            'category_label' => $categories[$row->category] ?? $row->category,
            'signal_kind' => $row->signal_kind ?? null,
            'signal_kind_label' => config('signals.opportunities.signal_kinds')[$row->signal_kind ?? ''] ?? null,
            'observed_event' => $row->observed_event,
            'why_indicates_need' => $row->why_indicates_need,
            'product_fit' => $row->product_fit,
            'product_name' => $row->product_name,
            'relevant_offer_id' => $row->relevant_offer_id ?? null,
            'relevant_offer_name' => $row->relevant_offer_name ?? null,
            'urgency' => $row->urgency ?? '30-60 days',
            'feed_section' => $row->feed_section ?? 'watchlist',
            'confirmed_facts' => $confirmedFacts,
            'unverified_claims' => $unverifiedClaims,
            'recommended_action' => $row->recommended_action,
            'priority' => $row->priority,
            'qualification' => $row->qualification,
            'confidence' => $row->confidence,
            'review_status' => $row->review_status,
            'review_notes' => $row->review_notes ?? null,
            'ingestion_source_id' => $row->ingestion_source_id ? (int) $row->ingestion_source_id : null,
            'primary_source' => $sources[0] ?? null,
            'source_count' => count($sources),
            'source_published_at' => $row->source_published_at,
            'event_date' => $row->event_date,
            'report_date' => Carbon::parse($row->report_date)->toDateString(),
            'report_date' => $row->report_date ? Carbon::parse($row->report_date)->toDateString() : null,
            'first_discovered_at' => Carbon::parse($row->first_discovered_at)->toIso8601String(),
            'last_verified_at' => Carbon::parse($row->last_verified_at)->toIso8601String(),
            'research_run_id' => (int) $row->research_run_id,
            'research_run_id' => $row->research_run_id ? (int) $row->research_run_id : null,
        ];
        if ($detail) {
            $out['sources'] = $sources;
            $out['reviewed_at'] = $row->reviewed_at ? Carbon::parse($row->reviewed_at)->toIso8601String() : null;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function presentRun(ResearchRun $r): array
    {
        return [
            'id' => $r->id,
            'report_date' => $r->report_date?->toDateString(),
            'trigger' => $r->trigger,
            'status' => $r->status,
            'stage' => $r->stage ?? ($r->status === 'running' ? 'preparing' : ($r->status === 'failed' ? 'failed' : 'completed')),
            'stage_message' => $r->stage_message ?? ($r->status === 'running' ? 'Research is in progress...' : $r->error_message),
            'started_at' => $r->started_at?->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
            'duration_ms' => $r->duration_ms,
            'queries_run' => $r->queries_run,
            'queries_failed' => $r->queries_failed,
            'sources_found' => $r->sources_found,
            'companies_researched' => $r->companies_researched,
            'opportunities_qualified' => $r->opportunities_qualified,
            'opportunities_new' => $r->opportunities_new,
            'opportunities_rejected' => $r->opportunities_rejected,
            'search_provider' => $r->search_provider,
            'ai_provider' => $r->ai_provider,
            'ai_model' => $r->ai_model,
            'error_code' => $r->error_code,
            'error_message' => $r->error_message,
        ];
    }
}
