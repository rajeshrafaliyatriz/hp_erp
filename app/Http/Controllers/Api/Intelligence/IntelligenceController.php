<?php

namespace App\Http\Controllers\Api\Intelligence;

use App\Http\Controllers\Api\Dashboard\Concerns\ResolvesDashboardContext;
use App\Http\Controllers\Controller;
use App\Services\Intelligence\IntelligenceLinks;
use App\Services\Intelligence\IntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * GET /api/intelligence/overview - the Intelligence page and the Home panel read this.
 *
 * AUTHORISATION IS THE ROUTE: `profile:admin,hr` reads role_key from the TOKEN OWNER, exactly as /api/dashboard/hr/*
 * does. The tenant comes from the same token via ResolvesDashboardContext; the request body is never consulted for
 * identity. An employee token gets a 403 from the middleware, not an empty payload.
 *
 * Read-only: no writes, no events, no notifications, no AI call.
 */
class IntelligenceController extends Controller
{
    use ResolvesDashboardContext;

    public function __construct(private IntelligenceService $service, private IntelligenceLinks $links)
    {
    }

    public function overview(Request $request)
    {
        $context = $this->dashboardContext($request);

        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];

        try {
            $data = $this->service->overview($sid, $this->links->resolve($sid));
        } catch (\Throwable $e) {
            // Never hand the client $e->getMessage(): it carries SQL fragments and table names.
            Log::error('intelligence.overview failed', ['sub_institute_id' => $sid, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return $this->dashboardFail('The Intelligence overview could not be built. Please try again.', 500);
        }

        $nothing = $data['summary']['insight_count'] === 0;

        return $this->dashboardOk($data, 'Intelligence overview fetched successfully', [
            'empty_is_expected' => $nothing,
            'empty_reason' => $nothing
                ? ($data['unavailable_sources'] !== []
                    ? 'No findings were produced, but some sources could not be read, so this is not an all-clear.'
                    : 'Nothing needs attention based on the records that can be measured. See the data-readiness checks for how much that covers.')
                : null,
            'meta' => ['as_of' => $data['generated_at']],
        ]);
    }
}
