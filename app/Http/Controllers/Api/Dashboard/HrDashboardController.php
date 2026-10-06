<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Dashboard\Concerns\ResolvesDashboardContext;
use App\Services\Dashboard\HrDashboardService;
use App\Services\Dashboard\WorkforceTrendService;
use App\Services\Dashboard\DashboardLinkResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * THE HR/ADMIN HOME DASHBOARD.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * WHY SECTIONS RATHER THAN ONE AGGREGATE
 * ═══════════════════════════════════════════════════════════════════════
 *
 *   /filters    vocabularies, no filter dependencies
 *   /summary    cheap COUNTs over core tables — paints first
 *   /workforce  the six-month attendance scan and module rollups (batch 2)
 *   /signals    events, readiness, derived facts (batch 3)
 *
 * The whole dashboard is roughly 45 aggregate queries against a remote MySQL
 * host. Behind a single endpoint and a single try/catch, one slow or broken
 * source blanks the entire page — which is exactly how the LMS dashboard fails
 * today with its one Promise.all. Split by latency and failure domain, the
 * headline numbers paint while the heavy work is still running, and a failure
 * is contained to its own section.
 *
 * Twelve per-widget endpoints would be the opposite mistake: each one re-runs
 * the token lookup and the RequireProfile join over the wire before any
 * business query starts.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * AUTHORISATION IS THE ROUTE, NOT THIS CLASS
 * ═══════════════════════════════════════════════════════════════════════
 *
 * `profile:admin,hr` resolves role_key from the TOKEN OWNER's profile and
 * matches it exactly. This controller therefore never inspects profile_id from
 * the request — the frontend sends it from localStorage on every call, so it is
 * a hint about which view to render and never a statement about what data the
 * caller may see. An employee reaching these routes gets a 403 from the
 * middleware, not an empty payload from a query that matched nothing.
 *
 * The employee dashboard will live at /api/dashboard/me/* with its own guard,
 * which is why this prefix is named /hr from the first line rather than being
 * renamed later.
 */
class HrDashboardController extends Controller
{
    use ResolvesDashboardContext;

    public function __construct(
        private HrDashboardService $service,
        private WorkforceTrendService $trend,
        private DashboardLinkResolver $links,
    ) {
    }

    /** GET /api/dashboard/hr/filters */
    public function filters(Request $request)
    {
        $context = $this->dashboardContext($request);

        if (!is_array($context)) {
            return $context;
        }

        try {
            $data = $this->service->filters($context['sub_institute_id']);

            // DESTINATIONS COME FROM tblmenumaster_g2g, not from the component.
            // The menu tree is tenant data that moves - five submenus were
            // renamed and three moved modules in one week - so a path typed
            // into a component becomes a dead link the next time that happens.
            $data['links']    = $this->links->resolve($context['sub_institute_id']);
            $data['menu_map'] = $this->links->menuKeyMap();
        } catch (\Throwable $e) {
            return $this->failed($e, 'filters', $context['sub_institute_id']);
        }

        return $this->dashboardOk($data, 'Dashboard filters fetched successfully', [
            'empty_is_expected' => empty($data['departments']),
            'empty_reason'      => $data['departments_empty_reason'] ?? null,
        ]);
    }

    /** GET /api/dashboard/hr/summary */
    public function summary(Request $request)
    {
        $context = $this->dashboardContext($request);

        if (!is_array($context)) {
            return $context;
        }

        $filters = $this->dashboardFilters($request);
        $syear   = $filters['syear'];

        // The task tables are scoped by academic year as well as tenant, so
        // omitting syear would silently mix years into one count. The frontend
        // already sends it on every call via withLaravelParams; refusing here
        // matches ResolvesTaskContext rather than quietly guessing a year.
        if ($syear === null) {
            return $this->dashboardFail('syear is required to read task figures for this organisation.', 422);
        }

        try {
            $data = $this->service->summary($context['sub_institute_id'], $syear, $filters);
        } catch (\Throwable $e) {
            return $this->failed($e, 'summary', $context['sub_institute_id']);
        }

        $noPeople = ($data['departments']['total'] ?? 0) === 0;

        return $this->dashboardOk($data, 'HR dashboard summary fetched successfully', [
            'empty_is_expected' => $noPeople,
            'empty_reason'      => $noPeople
                ? 'No active employees are recorded for this organisation yet, so there is nothing to summarise.'
                : null,
            'meta' => [
                'as_of'   => now()->toIso8601String(),
                'syear'   => $syear,
                'filters' => $filters,
            ],
        ]);
    }

    /**
     * GET /api/dashboard/hr/workforce
     *
     * The heavy section: a six-month attendance scan plus the module rollups.
     * It is separate from /summary precisely so this scan cannot delay or blank
     * the headline numbers.
     */
    public function workforce(Request $request)
    {
        $context = $this->dashboardContext($request);

        if (!is_array($context)) {
            return $context;
        }

        try {
            $series  = $this->trend->series($context['sub_institute_id']);
            $modules = $this->service->moduleSummary($context['sub_institute_id'], $this->dashboardFilters($request)['syear']);
        } catch (\Throwable $e) {
            return $this->failed($e, 'workforce', $context['sub_institute_id']);
        }

        return $this->dashboardOk([
            'workforce' => $series,
            'modules'   => $modules,
            'learning'  => $this->service->learningProgress($context['sub_institute_id']),
            'funnel'    => $this->service->talentFunnel($context['sub_institute_id']),
        ], 'HR dashboard workforce fetched successfully', [
            'empty_is_expected' => !$series['calendar_available'],
            'empty_reason'      => $series['note'],
        ]);
    }

    /**
     * GET /api/dashboard/hr/signals
     *
     * Upcoming events, merged from every dated source this tenant owns.
     */
    public function signals(Request $request)
    {
        $context = $this->dashboardContext($request);

        if (!is_array($context)) {
            return $context;
        }

        try {
            $events = $this->service->upcomingEvents($context['sub_institute_id']);
        } catch (\Throwable $e) {
            return $this->failed($e, 'signals', $context['sub_institute_id']);
        }

        return $this->dashboardOk(['events' => $events], 'HR dashboard signals fetched successfully', [
            'empty_is_expected' => empty($events),
            'empty_reason'      => empty($events)
                ? 'Nothing is scheduled. Holidays, training sessions and interviews all appear here once they exist.'
                : null,
        ]);
    }

    /**
     * One place to fail.
     *
     * The exception is logged with its tenant and section; the CLIENT is told
     * only what failed. TalentDashboardController returns $e->getMessage(),
     * which hands out SQL fragments, table names and file paths on any
     * unexpected error — that is the pattern being avoided, not followed.
     */
    private function failed(\Throwable $e, string $section, int $sid)
    {
        Log::error('HR dashboard section failed', [
            'section' => $section,
            'tenant'  => $sid,
            'error'   => $e->getMessage(),
            'file'    => $e->getFile() . ':' . $e->getLine(),
        ]);

        return $this->dashboardFail('The ' . $section . ' section could not be loaded.', 500);
    }
}
