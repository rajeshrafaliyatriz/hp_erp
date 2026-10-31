<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Crm\Leads\LeadFactory;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Products\ProductCatalog;
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
 * ScholarClone daily research and the buying-signal feed.
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
        \App\Domain\Signals\Opportunities\ResearchRunReaper::sweep($tenantId);   // a dead run is closed with a reason, never left "running"

        $profile = ProductProfile::where('sub_institute_id', $tenantId)->first();
        $completeness = $this->profiles->completeness($profile);
        $search = SearchProviderFactory::make();

        // Readiness of the product the screen has selected: what is configured and what is still missing for discovery.
        $productStatus = null;
        $scopeKey = null;
        if ($request->query('product') !== null && $request->query('product') !== '') {
            $key = ProductCatalog::normalise((string) $request->query('product'), $tenantId);
            if ($key === null) {
                return response()->json(['status' => 0, 'code' => 'unknown_product', 'message' => 'Unknown product.'], 422);
            }
            $catalog = new ProductCatalog();
            $d = $catalog->describe($tenantId, $key);
            $model = strtolower(trim((string) $request->query('customer_model', 'b2b')));
            if (! in_array($model, ['b2b', 'b2c', 'b2g'], true)) {
                return response()->json(['status' => 0, 'code' => 'invalid_customer_model', 'message' => 'Choose B2B, B2C, or B2G.'], 422);
            }
            try {
                $statusRegion = \App\Domain\Signals\Support\RegionScope::fromInput($request->query('regions'));
            } catch (\App\Domain\Signals\Support\RegionInvalid $e) {
                return response()->json(['status' => 0, 'code' => 'invalid_region', 'message' => $e->getMessage()], 422);
            }
            $selectedOffer = trim((string) $request->query('offer_id', '')) ?: null;
            $hasRunScope = $request->query('customer_model') !== null || $request->query('offer_id') !== null || $request->query('regions') !== null;
            $scopeKey = $hasRunScope ? \App\Domain\Signals\Opportunities\ResearchRunScope::key($tenantId, $key, $selectedOffer, $model, $statusRegion?->selections()) : null;
            $modelStatus = $d['icp_models'][$model] ?? ['ready' => false, 'missing' => $d['missing']];
            if ($selectedOffer !== null) {
                $child = $catalog->subProduct($tenantId, $key, $selectedOffer, $model);
                if ($child['offer'] === null) {
                    return response()->json(['status' => 0, 'code' => 'unknown_sub_product', 'message' => 'That sub-product is not one of this product\'s products.'], 422);
                }
                $modelStatus = ['ready' => $child['problems'] === [], 'missing' => $child['problems']];
            }
            $productStatus = ['key' => $d['key'], 'label' => $d['label'], 'ready' => (bool) $modelStatus['ready'], 'missing' => $modelStatus['missing'], 'icp_models' => $d['icp_models']];
        }
        // With a product selected, the run shown is THAT product's (runs made before products were recorded are ScholarClone's). `busy_elsewhere`
        // tells the screen that another product's run holds the organisation's single run slot.
        $forProduct = fn ($q) => isset($key)
            ? $q->where(fn ($w) => $key === 'scholarclone' ? $w->whereNull('product_key')->orWhere('product_key', $key) : $w->where('product_key', $key))
                ->when($scopeKey !== null && \App\Support\ColumnExists::has('g2g_research_runs', 'scope_key'), fn ($w) => $w->where('scope_key', $scopeKey))
            : $q;
        $latest = ResearchRun::where('sub_institute_id', $tenantId)->where($forProduct)->orderByDesc('id')->first();
        $success = ResearchRun::where('sub_institute_id', $tenantId)->where($forProduct)->whereIn('status', ['success', 'partial'])->orderByDesc('id')->first();
        $active = ResearchRun::where('sub_institute_id', $tenantId)->where('status', 'running')->orderByDesc('id')->first();
        $running = $active !== null && (! isset($key) || ($active->product_key ?? 'scholarclone') === $key)
            && ($scopeKey === null || ! \App\Support\ColumnExists::has('g2g_research_runs', 'scope_key') || $active->scope_key === $scopeKey);
        $busyElsewhere = $active !== null && ! $running;
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
            'product' => $productStatus,
            'running' => $running,
            'busy_elsewhere' => $busyElsewhere,
            'latest_run' => $latest ? $this->presentRun($latest) : null,
            'last_successful_run' => $success ? $this->presentRun($success) : null,
            'next_scheduled_at' => $next?->toIso8601String(),
            'daily_time' => config('signals.opportunities.default_schedule_time'),
            'timezone' => config('signals.timezone'),
        ]]);
    }

    public function runs(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        \App\Domain\Signals\Opportunities\ResearchRunReaper::sweep($identity['sub_institute_id']);
        $runs = ResearchRun::where('sub_institute_id', $identity['sub_institute_id'])->orderByDesc('id')->limit(60)->get();

        return response()->json(['status' => 1, 'data' => $runs->map(fn ($r) => $this->presentRun($r))->values()]);
    }

    /**
     * The dates a product has research to look back on: one entry per day (the application's timezone, `report_date`) on which at least one of
     * the product's runs finished successfully. "Successful" is the backend's existing rule (`last_successful_run`, `report_dates`): status
     * `success`, or `partial` (finished, with some searches or sources unavailable; its results are real and stored). Failed, skipped, timed-out
     * and still-running runs never make a date. A run with zero qualified opportunities still counts: running well and finding something are
     * separate. Runs made before products were recorded (no product) belong to no product and are not offered. Reads only; starts nothing.
     */
    public function runDates(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $key = ProductCatalog::normalise((string) $request->query('product', ''), $identity['sub_institute_id']);
        if ($key === null) {
            return response()->json(['status' => 0, 'code' => 'unknown_product', 'message' => 'Choose a product.'], 422);
        }

        $model = strtolower(trim((string) $request->query('customer_model', 'b2b')));
        if (! in_array($model, ['b2b', 'b2c', 'b2g'], true)) {
            return response()->json(['status' => 0, 'code' => 'invalid_customer_model', 'message' => 'Choose B2B, B2C, or B2G.'], 422);
        }
        $offerId = trim((string) $request->query('offer_id', '')) ?: null;
        try {
            $regionScope = \App\Domain\Signals\Support\RegionScope::fromInput($request->query('regions'));
        } catch (\App\Domain\Signals\Support\RegionInvalid $e) {
            return response()->json(['status' => 0, 'code' => 'invalid_region', 'message' => $e->getMessage()], 422);
        }
        $hasRunScope = $request->query('customer_model') !== null || $request->query('offer_id') !== null || $request->query('regions') !== null;
        $scopeKey = $hasRunScope ? \App\Domain\Signals\Opportunities\ResearchRunScope::key($identity['sub_institute_id'], $key, $offerId, $model, $regionScope?->selections()) : null;
        $runs = ResearchRun::where('sub_institute_id', $identity['sub_institute_id'])->where('product_key', $key)
            ->when($scopeKey !== null && \App\Support\ColumnExists::has('g2g_research_runs', 'scope_key'), fn ($q) => $q->where('scope_key', $scopeKey))
            ->whereIn('status', self::SUCCESS_STATUSES)->whereNotNull('report_date')
            ->orderByDesc('report_date')->orderByDesc('id')->limit(500)->get(['id', 'report_date', 'status', 'completed_at', 'opportunities_qualified', 'queries_run', 'queries_failed', 'sources_found', 'companies_researched', 'opportunities_rejected', 'stage_message']);

        $dates = $runs->groupBy(fn ($r) => Carbon::parse($r->report_date)->toDateString())->map(fn ($day, $date) => [
            'date' => $date,
            'runs' => $day->count(),
            // The day's headline status: success only if every counted run was a clean success.
            'status' => $day->every(fn ($r) => $r->status === 'success') ? 'success' : 'partial',
            'last_completed_at' => $day->max('completed_at')?->toIso8601String(),
            'qualified' => (int) $day->sum('opportunities_qualified'),
            // What the day's runs did, so a day with no signals can say why (searches made, pages found, organisations looked at, checks failed).
            'searches' => (int) $day->sum('queries_run'),
            'sources' => (int) $day->sum('sources_found'),
            'organisations' => (int) $day->sum('companies_researched'),
            'rejected' => (int) $day->sum('opportunities_rejected'),
            'note' => $day->first()->stage_message,
        ])->sortByDesc('date')->values();

        return response()->json(['status' => 1, 'data' => ['product' => $key, 'timezone' => config('signals.timezone'), 'dates' => $dates]]);
    }

    public function run(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        \App\Domain\Signals\Opportunities\ResearchRunReaper::sweep($tenantId);

        // Optional: scan ONE named school instead of the day's rotation, or (as before) a custom topic / industry / geography scan.
        $company = trim((string) $request->input('company', '')) ?: null;
        $custom = array_filter([
            'topic' => $request->input('topic'),
            'geography' => $request->input('geography'),
            'industry' => $request->input('industry'),
            'signal_type' => $request->input('signal_type'),
            'recency_days' => $request->input('recency_days'),
        ]);
        $custom = ($custom === [] || ($company !== null)) ? null : $custom;

        // The product this run is for. Not given = ScholarClone (what every earlier caller meant). It must be configured: a run never
        // borrows another product's ICP or profile.
        $productKey = ProductCatalog::normalise((string) $request->input('product', 'scholarclone'), $tenantId);
        if ($productKey === null) {
            return response()->json(['status' => 0, 'code' => 'unknown_product', 'message' => 'Unknown product.'], 422);
        }
        // The geography of THIS request (null = All Regions, the existing behaviour). It is validated here and never saved to the product or its ICP.
        try {
            $region = \App\Domain\Signals\Support\RegionScope::fromInput($request->input('regions'));
        } catch (\App\Domain\Signals\Support\RegionInvalid $e) {
            return response()->json(['status' => 0, 'code' => 'invalid_region', 'message' => $e->getMessage(), 'errors' => ['regions' => $e->problems]], 422);
        }
        $customerModel = strtolower(trim((string) ($request->input('customer_model') ?? $request->input('icp_type', 'b2b'))));
        if (! in_array($customerModel, ['b2b', 'b2c', 'b2g'], true)) {
            return response()->json(['status' => 0, 'code' => 'invalid_customer_model', 'message' => 'Choose B2B, B2C, or B2G.'], 422);
        }

        // Optional: ONE sub-product of the product (one of its offers). The run is then about that sub-product alone, with its own ICP.
        $offerId = trim((string) $request->input('offer_id', '')) ?: null;
        $subName = null;
        if ($offerId !== null) {
            $found = (new ProductCatalog())->subProduct($tenantId, $productKey, $offerId, $customerModel);
            if ($found['offer'] === null) {
                return response()->json(['status' => 0, 'code' => 'unknown_sub_product', 'message' => 'That sub-product is not one of this product\'s products.'], 422);
            }
            if ($found['problems'] !== []) {
                return response()->json([
                    'status' => 0, 'code' => 'sub_product_not_ready', 'product' => $productKey, 'offer_id' => $found['offer']->offer_id,
                    'message' => $found['offer']->name . ' (' . strtoupper($customerModel) . ' ICP) is not ready for discovery: ' . implode('; ', $found['problems']) . '. Open its Individual ICP and complete it.',
                    'missing' => $found['problems'],
                ], 422);
            }
            $offerId = (string) $found['offer']->offer_id;
            $subName = (string) $found['offer']->name;
        }
        $product = (new ProductCatalog())->describe($tenantId, $productKey);
        $productReady = $product['ready'];
        if (isset($product['icp_models'][$customerModel])) {
            $productReady = $product['icp_models'][$customerModel]['ready'];
        }
        if (! $productReady && $offerId === null) {
            return response()->json([
                'status' => 0, 'code' => 'product_not_configured', 'product' => $productKey,
                'message' => $product['label'] . ' (' . strtoupper($customerModel) . ' ICP) is not ready for discovery.',
                'missing' => $product['icp_models'][$customerModel]['missing'] ?? $product['missing'],
            ], 422);
        }

        $search = SearchProviderFactory::make();

        // Refuse up front, with the reason, instead of starting a run that can only skip.
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
        // The cool-down is per product: running one product does not hold back another. (Runs made before products were recorded count as ScholarClone's.)
        // The cool-down is about work that was done. A run that failed or was skipped (no worker, a timeout, nothing configured) can be retried at once,
        // by a person pressing the button again: nothing here ever retries by itself.
        $recent = ResearchRun::where('sub_institute_id', $tenantId)->where('trigger', 'manual')->whereIn('status', ['success', 'partial', 'running'])
            ->where(fn ($q) => $productKey === 'scholarclone' ? $q->whereNull('product_key')->orWhere('product_key', $productKey) : $q->where('product_key', $productKey))
            ->where('started_at', '>=', now()->subMinutes($cooldown))->orderByDesc('started_at')->first();
        if ($recent) {
            Cache::forget("signals:research-pending:{$tenantId}");
            $wait = max(1, (int) ceil($recent->started_at->copy()->addMinutes($cooldown)->diffInSeconds(now(), true)));

            return response()->json(['status' => 0, 'code' => 'cooldown', 'message' => 'Research ran a moment ago. Please wait before running it again.', 'retry_after_seconds' => $wait], 429)->header('Retry-After', (string) $wait);
        }

        $runAttributes = [
            'sub_institute_id' => $tenantId,
            'report_date' => now(config('signals.timezone'))->toDateString(),
            'trigger' => 'manual',
            'status' => 'running',
            'stage' => 'queued',
            'stage_message' => 'Queued. Waiting for a worker to start the run…' . ($subName !== null ? ' Sub-product: ' . $subName . ' (' . $offerId . '), using its own ICP.' : '') . $this->regionNote($region, $product),
            'triggered_by' => $identity['user_id'],
            'started_at' => now(),
            'search_provider' => $search->name(),
            'product_key' => $productKey,
            'customer_model' => $customerModel,
            'offer_id' => $offerId,
            'region_scope' => $region?->selections() ? json_encode($region->selections(), JSON_UNESCAPED_UNICODE) : null,
            'scope_key' => \App\Domain\Signals\Opportunities\ResearchRunScope::key($tenantId, $productKey, $offerId, $customerModel, $region?->selections()),
        ];
        // Keep requests compatible with databases that have not yet received the additive scope migration.
        foreach (['customer_model', 'offer_id', 'region_scope', 'scope_key'] as $column) {
            if (! \App\Support\ColumnExists::has('g2g_research_runs', $column)) {
                unset($runAttributes[$column]);
            }
        }
        $run = ResearchRun::create($runAttributes);

        // Sanitized: ids, the product key and the normalised place names the person chose. No credentials, no page text.
        \Illuminate\Support\Facades\Log::info('signals.run_region', [
            'run' => $run->id, 'tenant' => $tenantId, 'product' => $productKey, 'sub_product' => $offerId,
            'region_source' => $region !== null ? 'request' : (! empty($product['icp']['regions']) ? 'saved ICP' : 'All Regions'),
            'region' => $region?->label(),
        ]);
        RunOpportunityResearchJob::dispatch($tenantId, $identity['user_id'], $company, $run->id, $custom, $productKey, $region?->selections(), $offerId, $customerModel);

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

    /**
     * What the first run message says about geography, and where it came from: the request, the product's saved ICP regions (the default when no
     * region is asked for), or no region at all. Only what is known at this moment: what the run actually used is in its stage statistics.
     *
     * @param  array<string, mixed>  $product  ProductCatalog::describe()
     */
    private function regionNote(?\App\Domain\Signals\Support\RegionScope $region, array $product): string
    {
        if ($region !== null) {
            return ' Region: ' . $region->label() . ' (selected for this run).';
        }
        $saved = array_values(array_map('strval', (array) ($product['icp']['regions'] ?? [])));

        return $saved !== []
            ? ' Region: All Regions requested; the saved ICP regions (' . implode(', ', array_slice($saved, 0, 4)) . ') apply as the default.'
            : ' Region: All Regions (no region set).';
    }

    /** Stable identity for the exact selector values of a research run (explicit All Regions is distinct from named places). */
    public function showRun(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        \App\Domain\Signals\Opportunities\ResearchRunReaper::sweep($tenantId);

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

        // Absolute paths and the CLI PHP binary: a bare `php artisan` depends on the web server's working directory and PATH (see QueueWorkerLauncher).
        // When the host does not allow starting a process, a standing `queue:work --queue=signals,default` worker drains the queue instead.
        \App\Support\QueueWorkerLauncher::start((string) config('signals.queue', 'signals'));
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
            'expired' => 'nullable|in:hide,only,include',
            'include_samples' => 'nullable|boolean',
            'claim_level' => 'nullable|in:confirmed,inference,hypothesis',
            'fetch_level' => 'nullable|in:full_document,page_text,search_snippet,blocked',
            'business_fit' => 'nullable|in:eb,scholar,g2g,multiple',
            'product' => ['nullable', 'string', 'in:all,unassigned,' . implode(',', array_keys(ProductCatalog::definitionsFor($tenantId)))],
            'pipeline_status' => 'nullable|in:NEW,REVIEWING,ENGAGED,WON,LOST,ARCHIVED',
            'government_track' => 'nullable|boolean',
            'need_code' => ['nullable', 'regex:/^N(0[1-9]|1\d|20)$/'],
            'trigger_type' => 'nullable|string|max:30',
            'report_date' => 'nullable|date',
            'run_date' => 'nullable|date_format:Y-m-d',
            'customer_model' => 'nullable|in:b2b,b2c,b2g',
            'offer_id' => 'nullable|string|max:80',
            'regions' => 'nullable|array|max:30',
            'latest_saved' => 'nullable|boolean',
            'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }

        // `latest_saved`: the screen's fallback when the chosen day saved nothing. It reads the newest research day of this product that really
        // has saved signals (stored rows only: nothing is generated or copied) and answers with that day's own signals, naming the day.
        $resolvedDate = null;
        if ($request->boolean('latest_saved')) {
            // Always for ONE named product: never across products, never "all" or "unassigned".
            if (! array_key_exists((string) $request->query('product', ''), ProductCatalog::definitionsFor($tenantId))) {
                return response()->json(['status' => 0, 'code' => 'product_required', 'message' => 'Choose one product to look up its latest saved signals.'], 422);
            }
        }
        if ($request->boolean('latest_saved') && ! $request->query('run_date')) {
            $resolvedDate = $this->filtered($request, $tenantId)->whereIn('r.status', self::SUCCESS_STATUSES)->whereNotNull('r.report_date')->max(DB::raw('date(r.report_date)'));
            $resolvedDate = $resolvedDate ? Carbon::parse($resolvedDate)->toDateString() : null;
            $request->query->set('run_date', $resolvedDate ?? '1900-01-01');   // no saved signals at all: an empty list, not an unfiltered one
        }

        $base = fn () => $this->filtered($request, $tenantId);

        $list = $base()
            ->when($request->query('review_status'), fn ($q, $s) => $q->where('o.review_status', $s))
            ->when($request->query('priority'), fn ($q, $p) => $q->where('o.priority', $p))
            // Soonest expiry first (rows with no expiry after them); the "Expired" view lists the most
            // recently expired first. Then score, then the order researched rows always had.
            ->orderByRaw('(o.expires_at IS NULL) ASC')
            ->when($request->query('expired') === 'only', fn ($q) => $q->orderByDesc('o.expires_at'), fn ($q) => $q->orderBy('o.expires_at'))
            ->orderByRaw('o.score_total DESC')
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
            'data' => $this->presentMany($list->items(), $tenantId),
            'meta' => ['page' => $list->currentPage(), 'per_page' => $list->perPage(), 'total' => $list->total(), 'last_page' => $list->lastPage()] + ($request->boolean('latest_saved') ? ['resolved_run_date' => $resolvedDate] : []),
            'summary' => [
                'total' => (int) ($counts->total ?? 0),
                'new' => (int) ($counts->new_count ?? 0),
                'high_priority' => (int) ($counts->high_count ?? 0),
                'follow_up' => (int) ($counts->follow_up_count ?? 0),
                'immediate' => (int) ($counts->immediate_count ?? 0),
                'watchlist' => (int) ($counts->watchlist_count ?? 0),
                'market_intelligence' => (int) ($counts->market_intel_count ?? 0),
                'competitor_intelligence' => (int) ($counts->competitor_intel_count ?? 0),
                // Hidden by default; shown under the Expired filter / Include samples toggle.
                'expired' => (int) $this->query($tenantId)->where('o.is_sample', false)->whereNotNull('o.expires_at')->where('o.expires_at', '<', Carbon::today()->toDateString())->count(),
                'samples' => (int) $this->query($tenantId)->where('o.is_sample', true)->count(),
            ],
            'filters' => [
                'claim_levels' => ['confirmed', 'inference', 'hypothesis'],
                'fetch_levels' => ['full_document', 'page_text', 'search_snippet', 'blocked'],
                'business_fits' => ['eb', 'scholar', 'g2g', 'multiple'],
                'products' => collect(ProductCatalog::definitionsFor($tenantId))->map(fn ($d, $k) => ['value' => $k, 'label' => $d['label'], 'business_fit' => $d['business_fit']])->values(),
                'pipeline_statuses' => ['NEW', 'REVIEWING', 'ENGAGED', 'WON', 'LOST', 'ARCHIVED'],
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
            ? response()->json(['status' => 1, 'data' => $this->presentMany([$row], $identity['sub_institute_id'])[0] + $this->presentOpportunity($row, true)])
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

    /**
     * Sales & Marketing unification, Phase 4: promote an AI-discovered, unvetted
     * company opportunity into a `crm_leads` row - the existing, already-built
     * CRM-native landing spot for "something a human must qualify first"
     * (`CrmLeadController::convert()` takes over, unmodified, from there). See
     * Docs/sales-marketing-merge/DECISIONS.md for why this lands on a Lead and
     * not directly on an Organization/Opportunity. Idempotent on
     * `source_company_opportunity_id`, matching GTM's AccountController::fromCompany() contract.
     */
    public function promoteToCrm(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $t = $identity['sub_institute_id'];

        $opportunity = CompanyOpportunity::where('sub_institute_id', $t)->find($id);
        if (! $opportunity) {
            return response()->json(['status' => 0, 'message' => 'Opportunity not found'], 404);
        }

        $existing = DB::table('crm_leads')->where('sub_institute_id', $t)->where('source_company_opportunity_id', $id)->first('id');
        if ($existing) {
            return response()->json(['status' => 1, 'data' => ['lead_id' => (string) $existing->id, 'created' => false]]);
        }

        $company = $opportunity->company;
        $companyName = $company->name ?? (string) ($opportunity->product_name ?: 'Unnamed company');

        // crm_leads.description is a TINYTEXT: its real limit is 255 BYTES, not 255 characters -
        // mb_substr(...,0,255) counts characters, so a multi-byte UTF-8 character near the cutoff
        // can push the insert over the byte limit. mb_strcut cuts at a safe byte boundary instead.
        $summaryParts = array_filter([$opportunity->observed_event, $opportunity->why_indicates_need, $opportunity->recommended_action]);
        $description = mb_strcut(implode(' ', $summaryParts), 0, 255, 'UTF-8');

        $leadId = LeadFactory::create([
            'last_name' => mb_substr($companyName, 0, 191),
            'company' => mb_substr($companyName, 0, 191),
            'designation' => $opportunity->likely_stakeholder ? mb_substr($opportunity->likely_stakeholder, 0, 191) : null,
            'website' => $company->website ? mb_substr($company->website, 0, 191) : null,
            'industry' => $company->industry ?? null,
            'lead_source' => 'Signals',
            'lead_status' => 'New',
            'description' => $description !== '' ? $description : $opportunity->title,
            'source_company_opportunity_id' => $opportunity->id,
            'assigned_to' => $identity['user_id'],
        ], $t, $identity['user_id']);

        return response()->json(['status' => 1, 'data' => ['lead_id' => (string) $leadId, 'created' => true]], 201);
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
            ->select('o.*', 'c.name as company_name', 'c.website as company_website', 'c.industry as company_industry', 'c.location as company_location', 'c.type as company_type', 'c.state as company_state', 'r.report_date');
    }

    private function filtered(Request $request, int $tenantId)
    {
        $q = $this->query($tenantId);

        foreach (['category' => 'o.category', 'kind' => 'o.signal_kind', 'qualification' => 'o.qualification', 'run_id' => 'o.research_run_id', 'feed_section' => 'o.feed_section'] as $param => $column) {
            if ($value = $request->query($param)) {
                $q->where($column, $value);
            }
        }
        // Expiry: expired signals are hidden unless asked for (expired=only|include). A signal with no
        // expiry never expires.
        $today = Carbon::today()->toDateString();
        $expired = $request->query('expired', 'hide');
        if ($expired === 'hide') {
            $q->where(fn ($w) => $w->whereNull('o.expires_at')->orWhere('o.expires_at', '>=', $today));
        } elseif ($expired === 'only') {
            $q->whereNotNull('o.expires_at')->where('o.expires_at', '<', $today);
        }
        // Sample rows never appear unless the caller explicitly includes them.
        if (! $request->boolean('include_samples')) {
            $q->where('o.is_sample', false);
        }
        foreach (['claim_level' => 'o.claim_level', 'fetch_level' => 'o.fetch_level', 'business_fit' => 'o.business_fit', 'pipeline_status' => 'o.pipeline_status', 'trigger_type' => 'o.trigger_type'] as $param => $column) {
            if ($value = $request->query($param)) {
                $q->where($column, $value);
            }
        }
        // The product filter uses the product stored on each record (business_fit), never the title or a keyword.
        $product = (string) $request->query('product', '');
        if ($product === 'unassigned') {
            $q->whereNull('o.business_fit');
        } elseif ($product !== '' && $product !== 'all') {
            $q->where('o.business_fit', ProductCatalog::businessFit($product, $tenantId));
        }
        if ($product !== '' && $product !== 'all' && $request->query('customer_model')) {
            try {
                $region = \App\Domain\Signals\Support\RegionScope::fromInput($request->query('regions'));
            } catch (\App\Domain\Signals\Support\RegionInvalid $e) {
                abort(422, $e->getMessage());
            }
            $offerId = trim((string) $request->query('offer_id', '')) ?: null;
            if (\App\Support\ColumnExists::has('g2g_research_runs', 'scope_key')) {
                $q->where('r.scope_key', \App\Domain\Signals\Opportunities\ResearchRunScope::key($tenantId, $product, $offerId, (string) $request->query('customer_model'), $region?->selections()));
            }
        }
        if ($request->query('government_track') !== null && $request->query('government_track') !== '') {
            $q->where('o.is_government_track', $request->boolean('government_track'));
        }
        if ($code = $request->query('need_code')) {
            $q->where('o.candidate_need_codes', 'like', '%"' . $code . '"%');
        }
        // A past day's saved results: the opportunities that came from a SUCCESSFUL research run on that day. Reads stored rows only.
        if ($runDate = $request->query('run_date')) {
            $q->whereDate('r.report_date', $runDate)->whereIn('r.status', self::SUCCESS_STATUSES);
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

    /**
     * A page of rows, each with its stored offer matches (one query for the whole page).
     *
     * @param  iterable<object>  $rows
     * @return list<array<string, mixed>>
     */
    public function presentMany(iterable $rows, int $tenantId): array
    {
        $rows = collect($rows);
        $ids = $rows->pluck('id')->map(fn ($i) => (int) $i)->all();
        $matches = $this->matchedOffers($tenantId, $ids);

        $accounts = $this->accountsFor($tenantId, $rows->pluck('company_id')->filter()->map(fn ($i) => (int) $i)->unique()->all());

        $promotedLeads = \Illuminate\Support\Facades\Schema::hasTable('crm_leads') ? DB::table('crm_leads')
            ->where('sub_institute_id', $tenantId)
            ->whereIn('source_company_opportunity_id', $ids)
            ->get(['id', 'lead_no', 'source_company_opportunity_id', 'lead_status', 'first_name', 'last_name'])
            ->keyBy('source_company_opportunity_id') : collect();

        return $rows->map(function ($row) use ($matches, $accounts, $promotedLeads) {
            $out = $this->presentOpportunity($row);
            $out['matched_offers'] = $matches[(int) $row->id] ?? [];
            $out['account'] = $out['card']['account'] = $accounts[(int) $row->company_id] ?? null;

            $prom = $promotedLeads[(int) $row->id] ?? null;
            $out['promoted_lead_id'] = $prom ? (string) $prom->id : null;
            $out['promoted_lead_no'] = $prom ? (string) $prom->lead_no : null;
            $out['promoted_lead_status'] = $prom ? (string) $prom->lead_status : null;

            $out['crm_qualification'] = $this->qualifyForCrm($row, $accounts[(int) $row->company_id] ?? null);

            return $out;
        })->all();
    }

    /**
     * Identifies verified vs missing fields required for CRM lead creation and qualification for outreach.
     */
    public function qualifyForCrm(object $row, ?array $account): array
    {
        $contactName = $account['contact_name'] ?? null;
        $hasWebsite = ! empty($row->company_website);
        $hasStakeholder = ! empty($row->likely_stakeholder);

        $verified = [];
        if (! empty($row->company_name)) {
            $verified[] = 'School/Company: ' . $row->company_name;
        }
        if (! empty($row->source_title ?? $row->source_url)) {
            $verified[] = 'Source: ' . ($row->source_title ?? 'Verified source URL');
        }
        if (! empty($row->observed_event)) {
            $verified[] = 'Signal: ' . mb_strimwidth($row->observed_event, 0, 90, '...');
        }
        if ($hasWebsite) {
            $verified[] = 'Website: ' . $row->company_website;
        }
        if (! empty($contactName)) {
            $verified[] = 'Known Contact: ' . $contactName;
        }

        $missing = [];
        if (empty($contactName)) {
            $missing[] = 'Contact person name';
        }
        if (! $hasStakeholder) {
            $missing[] = 'Confirmed designation / role';
        }
        $missing[] = 'Direct email address';
        $missing[] = 'Direct phone number';
        if (! $hasWebsite) {
            $missing[] = 'Official website';
        }

        $enrichmentStatus = 'Needs Review';
        if (! empty($contactName) && $hasWebsite) {
            $enrichmentStatus = 'Partially Complete';
        }

        return [
            'can_promote' => true,
            'promotion_requirements_met' => true,
            'outreach_ready' => false,
            'verified_fields' => $verified,
            'missing_for_outreach' => $missing,
            'enrichment_status' => $enrichmentStatus,
        ];
    }

    /**
     * The target account (CRM school) behind each organisation, so the card can say where the contact is.
     * Phone numbers and emails are not stored; `crm_ref` is the lead number to look up in the CRM.
     *
     * @param  list<int>  $companyIds
     * @return array<int, array<string, mixed>>
     */
    private function accountsFor(int $tenantId, array $companyIds): array
    {
        if ($companyIds === [] || ! \Illuminate\Support\Facades\Schema::hasTable('g2g_target_accounts')) {
            return [];
        }
        $out = [];
        foreach (DB::table('g2g_target_accounts')->where('sub_institute_id', $tenantId)->whereIn('company_id', $companyIds)->orderByDesc('watch_priority')->get() as $a) {
            $out[(int) $a->company_id] = [
                'crm_ref' => $a->crm_ref, 'crm_owner' => $a->crm_owner, 'contact_name' => $a->contact_name,
                'city' => $a->city, 'icp_status' => $a->icp_status, 'icp_tier' => $a->icp_tier,
            ];
        }

        return $out;
    }

    /**
     * Stored matches per opportunity id, best first, with each offer's CURRENT readiness.
     * `is_deliverable` is what the offer is now; `readiness_at_match` is what it was when matched.
     * Readiness never affects the order: match_score decides it.
     *
     * @param  list<int>  $ids
     * @return array<int, list<array<string, mixed>>>
     */
    private function matchedOffers(int $tenantId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('g2g_opportunity_matches as m')
            ->leftJoin('g2g_product_offers as p', function ($join) use ($tenantId) {
                $join->on('p.offer_id', '=', 'm.offer_id')->where(fn ($w) => $w->where('p.sub_institute_id', $tenantId)->orWhereNull('p.sub_institute_id'));
            })
            ->where('m.sub_institute_id', $tenantId)->where('m.signal_type', 'opportunity')->whereIn('m.signal_id', $ids)
            ->orderByDesc('m.match_score')
            ->get(['m.signal_id', 'm.offer_id', 'm.match_score', 'm.matched_need_codes', 'm.readiness_at_match', 'm.match_status', 'm.rationale', 'p.name as offer_name', 'p.parent_product as offer_category', 'p.readiness_status', 'p.readiness_confirmed']);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->signal_id][] = [
                'offer_id' => $r->offer_id,
                'offer_name' => $r->offer_name,
                'offer_category' => $r->offer_category,
                // Why it matches, in the words of the signal and the product (what the matcher found shared). Empty when it was stored without one.
                'rationale' => $r->rationale,
                'match_score' => $r->match_score === null ? null : (int) $r->match_score,
                'matched_need_codes' => json_decode($r->matched_need_codes ?? '[]', true) ?: [],
                'readiness_status' => $r->readiness_status,
                'readiness_at_match' => $r->readiness_at_match,
                'is_deliverable' => (bool) $r->readiness_confirmed,
                'match_status' => $r->match_status,
            ];
        }

        return $out;
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
            // The ten-family classification of what kind of signal this is (null when its trigger type does not clearly belong to one).
            'signal_family' => ($fam = \App\Domain\Portfolio\TaxonomyService::familyForTrigger($row->trigger_type ?? null)) === null ? null
                : ['code' => $fam, 'label' => \App\Domain\Portfolio\TaxonomyService::signalFamilies()[$fam]['label'], 'examples' => \App\Domain\Portfolio\TaxonomyService::signalFamilies()[$fam]['examples']],
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
            // Was this signal first discovered on the research day it is listed under, or only re-observed by that day's run? (null = no run date to compare)
            'discovered_on_report_date' => $row->report_date
                ? Carbon::parse($row->first_discovered_at)->setTimezone((string) config('signals.timezone', 'Asia/Kolkata'))->toDateString() === Carbon::parse($row->report_date)->toDateString()
                : null,
            'research_run_id' => (int) $row->research_run_id,
            'research_run_id' => $row->research_run_id ? (int) $row->research_run_id : null,
        ];
        // Imported (demand-side) fields. Present as null on researched rows.
        $expiresAt = $row->expires_at ?? null;
        $daysLeft = $expiresAt ? (int) Carbon::today()->diffInDays(Carbon::parse($expiresAt)->startOfDay(), false) : null;
        $scoreKeys = ['buying_signal', 'problem_fit', 'product_fit', 'accessibility', 'urgency', 'potential_value', 'evidence_quality'];
        $scores = isset($row->score_total) ? array_combine($scoreKeys, array_map(fn ($k) => isset($row->{'score_' . $k}) ? (int) $row->{'score_' . $k} : null, $scoreKeys)) + ['total' => (int) $row->score_total] : null;
        $out['card'] = (new \App\Domain\Signals\Market\CardPresenter())->face([
            'company_name' => $out['company_name'], 'company_type' => $row->company_type ?? null, 'company_state' => $row->company_state ?? null,
            'location' => $out['location'], 'website' => $out['website'],
            'title' => $row->title, 'trigger_summary' => $row->trigger_summary ?? null,
            'observed_event' => $row->observed_event, 'likely_problem' => $row->likely_problem ?? null, 'why_indicates_need' => $row->why_indicates_need,
            'soft_marketing_angle' => $row->soft_marketing_angle ?? null, 'recommended_action' => $row->recommended_action,
            'entry_point' => $row->entry_point ?? null, 'urgency' => $row->urgency ?? null, 'score_urgency' => isset($row->score_urgency) ? (int) $row->score_urgency : null,
            'feed_section' => $row->feed_section ?? null, 'days_left' => $daysLeft, 'claim_level' => $row->claim_level ?? null,
            'import_source' => $row->import_source ?? null, 'source_url' => $row->source_url ?? null, 'primary_source' => $sources[0] ?? null,
            'source_published_at' => $row->source_published_at, 'event_date' => $row->event_date,
        ]);
        $out += [
            'strength' => ['act_this_week' => 'hot', 'act_this_month' => 'warm', 'nurture' => 'watch'][$out['card']['urgency_band'] ?? 'nurture'] ?? 'watch',
            'strength_reason' => $row->score_reasoning ?? null,
            'buying_intent' => $row->buying_intent ?? null,
            'assessment' => is_string($row->assessment ?? null) ? (json_decode($row->assessment, true) ?: null) : ($row->assessment ?? null),
            'pipeline_status' => $row->pipeline_status ?? 'NEW',
            'trigger_type' => $row->trigger_type ?? null,
            'trigger_summary' => $row->trigger_summary ?? null,
            'reference_no' => $row->reference_no ?? null,
            'source_url' => $row->source_url ?? null,
            'source_title' => $row->source_title ?? null,
            'expires_at' => $expiresAt ? Carbon::parse($expiresAt)->toDateString() : null,
            'days_left' => $daysLeft,
            'is_expired' => $daysLeft !== null && $daysLeft < 0,
            'claim_level' => $row->claim_level ?? null,
            'fetch_level' => $row->fetch_level ?? null,
            'evidence_note' => $row->evidence_note ?? null,
            'business_fit' => $row->business_fit ?? null,
            'product' => $this->productKeyOf($row->business_fit ?? null, (int) ($row->sub_institute_id ?? 0)),
            'candidate_need_codes' => json_decode($row->candidate_need_codes ?? '[]', true) ?: [],
            'buyer_segment' => $row->buyer_segment ?? null,
            'buyer_type' => $row->company_type ?? null,
            'buyer_state' => $row->company_state ?? null,
            'likely_problem' => $row->likely_problem ?? null,
            'likely_stakeholder' => $row->likely_stakeholder ?? null,
            'what_we_could_sell' => $row->what_we_could_sell ?? null,
            'entry_point' => $row->entry_point ?? null,
            'scale' => $row->scale ?? null,
            'estimated_value' => $row->estimated_value ?? null,
            'is_government_track' => (bool) ($row->is_government_track ?? false),
            'partner_route_note' => $row->partner_route_note ?? null,
            'soft_marketing_angle' => $row->soft_marketing_angle ?? null,
            'scores' => $scores,
            'score_reasoning' => $row->score_reasoning ?? null,
            'is_sample' => (bool) ($row->is_sample ?? false),
            'matched_offers' => [],
        ];

        if ($detail) {
            $out['sources'] = $sources;
            $out['reviewed_at'] = $row->reviewed_at ? Carbon::parse($row->reviewed_at)->toIso8601String() : null;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    /** The product key a stored `business_fit` value stands for (null for an unassigned or multi-product record). */
    /** Run statuses that count as a successful run for the date history (see runDates). */
    private const SUCCESS_STATUSES = ['success', 'partial'];

    private function productKeyOf(?string $fit, int $tenantId = 0): ?string
    {
        foreach (ProductCatalog::definitionsFor($tenantId) as $key => $d) {
            if ($fit !== null && $d['business_fit'] === $fit) {
                return $key;
            }
        }

        return null;
    }

    private function presentRun(ResearchRun $r): array
    {
        return [
            'id' => $r->id,
            'product_key' => $r->product_key,
            'customer_model' => $r->customer_model,
            'offer_id' => $r->offer_id,
            'region_scope' => $r->region_scope ? json_decode((string) $r->region_scope, true) : null,
            'scope_key' => $r->scope_key,
            'updated_at' => $r->updated_at?->toIso8601String(),   // the run's heartbeat: it changes whenever progress is saved
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
            // Stage-by-stage accounting (null for runs made before the stage_stats column existed).
            'stage_stats' => $r->getAttribute('stage_stats'),
            'stage_stats_persisted' => array_key_exists('stage_stats', $r->getAttributes()),
        ];
    }
}
