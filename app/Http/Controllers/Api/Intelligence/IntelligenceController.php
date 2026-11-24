<?php

namespace App\Http\Controllers\Api\Intelligence;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use App\Domain\Intelligence\DetectorRegistry;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\InsightService;
use App\Domain\Intelligence\Support\SmallGroupGuard;
use App\Domain\Intelligence\Support\Thresholds;
use App\Domain\Intelligence\Taxonomy;
use App\Http\Controllers\Controller;
use App\Http\Middleware\IntelligenceIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The Intelligence API. READ-ONLY: three GET endpoints, no write, no cache, no job, no model call. Routes: routes/intelligence.php.
 *
 * The organisation, the person, the role and the data scope come ONLY from the IntelligenceCaller that IntelligenceIdentity placed on the request from the
 * token. This controller reads nothing named like a tenant, user, role or scope from the request: it reads the explicit allow-list of query keys below and
 * nothing else.
 *
 * Envelope (same as the Agent API): 200 `{status: 1, data: {...}}`; refusals `{status: 0, message, errors?}`.
 */
class IntelligenceController extends Controller
{
    /** @var array<string, array{0: array<string, mixed>, 1: InsightService}> */
    private array $memo = [];

    private const QUERY_KEYS = ['domain', 'severity', 'category', 'surface', 'detector', 'page', 'per_page'];

    /** GET /overview -> {summary: {total, by_severity, by_domain}, top: [insight x<=5], meta} */
    public function overview(Request $request): JsonResponse
    {
        [$caller, $refusal] = $this->caller($request);
        if ($refusal !== null) {
            return $refusal;
        }

        [$run, $service] = $this->execute($caller);
        $bySeverity = array_fill_keys(array_reverse(Taxonomy::SEVERITIES), 0);
        $byDomain = array_fill_keys(Taxonomy::DOMAINS, 0);
        foreach ($run['insights'] as $insight) {
            $bySeverity[$insight->severity]++;
            $byDomain[$insight->domain]++;
        }

        return response()->json(['status' => 1, 'data' => [
            'summary' => ['total' => count($run['insights']), 'by_severity' => $bySeverity, 'by_domain' => $byDomain],
            'top' => $service->serialiseInsights($caller, array_slice($run['insights'], 0, 5)),
            'meta' => $run['meta'],
        ]]);
    }

    /** GET /insights?domain&severity&category&surface&detector&page&per_page -> {insights, pagination, meta} */
    public function insights(Request $request): JsonResponse
    {
        [$caller, $refusal] = $this->caller($request);
        if ($refusal !== null) {
            return $refusal;
        }

        $query = $this->query($request);
        $validator = Validator::make($query, [
            'domain' => ['nullable', Rule::in(Taxonomy::DOMAINS)],
            'severity' => ['nullable', Rule::in(Taxonomy::SEVERITIES)],
            'category' => ['nullable', 'string', 'max:64'],
            'surface' => ['nullable', Rule::in(Taxonomy::SURFACES)],
            'detector' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_.]+$/'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => 'The request is not valid.', 'errors' => $validator->errors()->toArray()], 422);
        }

        [$run, $service] = $this->execute($caller, $query);
        $page = InsightService::paginate($run['insights'], (int) ($query['page'] ?? 1), (int) ($query['per_page'] ?? 20));

        return response()->json(['status' => 1, 'data' => [
            'insights' => $service->serialiseInsights($caller, $page['items']),
            'pagination' => $page['pagination'],
            'meta' => $run['meta'],
        ]]);
    }

    /** GET /detectors -> {detectors: [{detector, domain, title, state, insight_count, coverage, as_of, reason}], meta}. One entry per detector; those the caller may not use appear as state access_restricted (no counts, no coverage). See Taxonomy::STATE_OK. */
    public function detectors(Request $request): JsonResponse
    {
        [$caller, $refusal] = $this->caller($request);
        if ($refusal !== null) {
            return $refusal;
        }

        [$run, $service] = $this->execute($caller);

        return response()->json(['status' => 1, 'data' => [
            'detectors' => $service->detectorSummaries($caller, $run['results']),
            'meta' => $run['meta'],
        ]]);
    }

    /** @return array{0: ?IntelligenceCaller, 1: ?JsonResponse} */
    private function caller(Request $request): array
    {
        if (! (bool) config('intelligence.enabled', true)) {
            return [null, response()->json(['status' => 0, 'message' => 'Intelligence is switched off.'], 503)];
        }

        $caller = $request->attributes->get(IntelligenceIdentity::ATTRIBUTE);
        if (! $caller instanceof IntelligenceCaller) {
            // Defence in depth: the route middleware always sets it. A request that reached here without it is refused, never defaulted.
            return [null, response()->json(['status' => 0, 'message' => 'Intelligence is not available for this account.'], 403)];
        }

        return [$caller, null];
    }

    /**
     * ONE InsightService run per request and per filter set, shared by everything the endpoint needs (the controller instance lives for one request, so this is
     * per-request memoisation only: there is no cross-request cache, and nothing is stored).
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: array<string, mixed>, 1: InsightService}
     */
    private function execute(IntelligenceCaller $caller, array $filters = []): array
    {
        $key = spl_object_id($caller) . ':' . md5(json_encode($filters));
        if (! isset($this->memo[$key])) {
            $service = $this->service();
            $this->memo[$key] = [$service->run($caller, $filters), $service];
        }

        return $this->memo[$key];
    }

    private function service(): InsightService
    {
        $thresholds = Thresholds::with((array) config('intelligence.thresholds', []));

        return new InsightService(DetectorRegistry::resolve(DB::connection(), $thresholds), SmallGroupGuard::fromThresholds($thresholds));
    }

    /** The allow-listed query keys only; everything else the client sent is never read. @return array<string, mixed> */
    private function query(Request $request): array
    {
        $query = [];
        foreach (self::QUERY_KEYS as $key) {
            $value = $request->query($key);
            if ($value !== null && $value !== '') {
                $query[$key] = $value;
            }
        }

        return $query;
    }
}
