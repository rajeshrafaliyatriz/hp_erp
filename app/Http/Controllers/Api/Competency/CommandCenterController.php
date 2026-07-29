<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Controller;
use App\Services\Competency\CommandCenterService;
use Illuminate\Http\Request;

/**
 * Competency Command Center dashboard.
 *
 * GET /api/competency/command-center          -> the full dashboard payload
 * GET /api/competency/command-center/filters  -> options for the five filters
 *
 * Token authenticated + tenant scoped via ResolvesCompetencyContext.
 */
class CommandCenterController extends Controller
{
    use ResolvesCompetencyContext;

    public function __construct(private CommandCenterService $service) {}

    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $filters = $this->competencyFilters($request);

        $data = $this->service->dashboard(
            $context['sub_institute_id'],
            $context['user_id'],
            $filters
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Competency command center fetched successfully',
            'data'    => $data,
        ]);
    }

    public function filters(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        return response()->json([
            'status'  => 1,
            'message' => 'Competency filters fetched successfully',
            'data'    => $this->service->filterOptions($context['sub_institute_id']),
        ]);
    }
}
