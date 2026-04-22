<?php

namespace App\Http\Controllers\talent\TalentAcquisition;

use App\Http\Controllers\Controller;
use App\Services\TalentAcquisitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TalentAcquisitionController extends Controller
{
    /**
     * Mask sensitive fields from payload
     */
    private function maskSensitiveData(array $data): array
    {
        $masked = $data;
        $sensitiveFields = ['token', 'password', 'secret'];

        foreach ($sensitiveFields as $field) {
            if (isset($masked[$field])) {
                $masked[$field] = '***MASKED***';
            }
        }

        return $masked;
    }

    /**
     * Get KPIs API
     */
    public function getKpis(Request $request, TalentAcquisitionService $service)
    {
        try {
            // Log request safely (payload masked)
            Log::info('Talent Acquisition KPIs request', [
                'payload' => $this->maskSensitiveData($request->all()),
            ]);

            // Get input
            $subInstituteId = $request->input('sub_institute_id');

            // Call service
            $data = $service->getKpiMetrics($subInstituteId);

            return response()->json($data, 200);

        } catch (\Exception $e) {

            // Log error safely
            Log::error('Talent Acquisition KPIs error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
