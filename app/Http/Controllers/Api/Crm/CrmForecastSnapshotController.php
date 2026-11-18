<?php

namespace App\Http\Controllers\Api\Crm;

use App\Domain\Crm\Sales\ForecastSnapshots;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * The daily record of the CRM pipeline, read only (slice 11, task 6): GET /api/crm/opportunities/forecast-snapshots. It answers for the caller's organisation
 * only, under the Opportunities screen's own view right. Nothing here writes: the rows are taken by `opportunities:forecast-snapshot`.
 */
class CrmForecastSnapshotController extends Controller
{
    use ResolvesApiIdentity;

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);

        if (! is_array($identity)) {
            return $identity;
        }

        $validator = Validator::make($request->all(), [
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'scope' => 'nullable|in:total,owner',
            'owner_id' => 'nullable|integer|min:0',
            'currency' => 'nullable|string|size:3|regex:/^[A-Za-z]{3}$/',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        // A host that has not had the table yet answers "nothing recorded", not an error.
        $result = Schema::hasTable('g2g_forecast_snapshots')
            ? (new ForecastSnapshots)->read((int) $identity['sub_institute_id'], $request->input('from'), $request->input('to'), $request->input('scope'),
                $request->filled('owner_id') ? (int) $request->input('owner_id') : null, $request->input('currency'))
            : ['items' => [], 'first_date' => null, 'last_date' => null];

        return response()->json([
            'status' => 1,
            'message' => 'Pipeline snapshots.',
            'data' => $result + [
                'basis' => 'taken daily from the open opportunities as they stood that day; money is never converted between currencies, and XXX means no currency was recorded',
            ],
        ]);
    }
}
