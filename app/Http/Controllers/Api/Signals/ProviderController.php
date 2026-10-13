<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\ProductProfileService;
use App\Domain\Signals\Providers\ProviderStatus;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Connection status and real connection tests for the two providers the Signals engine
 * depends on: the AI provider (existing G2G AI Providers configuration) and the web
 * search provider (backend environment). Nothing here returns a key, only masked values.
 */
class ProviderController extends Controller
{
    use ResolvesApiIdentity;

    public function __construct(private readonly ProviderStatus $status, private readonly ProductProfileService $profiles)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $profile = ProductProfile::where('sub_institute_id', $tenantId)->first();
        $next = $profile ? $this->profiles->nextRun($profile) : null;

        return response()->json(['status' => 1, 'data' => [
            'ai' => $this->status->ai($tenantId),
            'search' => $this->status->search($tenantId),
            'schedule' => [
                'enabled' => (bool) ($profile?->research_enabled),
                'frequency' => $profile?->research_frequency ?? 'daily',
                'time' => $profile ? $this->profiles->scheduleTime($profile) : config('signals.opportunities.default_schedule_time'),
                'timezone' => config('signals.timezone'),
                'next_run_at' => $next?->toIso8601String(),
            ],
            'runtime' => $this->status->runtime(),
        ]]);
    }

    public function testAi(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        return response()->json(['status' => 1, 'data' => $this->status->testAi($identity['sub_institute_id'], $identity['user_id'])]);
    }

    public function testSearch(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        return response()->json(['status' => 1, 'data' => $this->status->testSearch($identity['sub_institute_id'], $identity['user_id'])]);
    }
}
