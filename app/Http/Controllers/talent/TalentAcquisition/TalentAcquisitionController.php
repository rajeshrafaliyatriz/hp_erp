<?php

namespace App\Http\Controllers\talent\TalentAcquisition;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\TalentAcquisitionService;

class TalentAcquisitionController extends Controller
{
    public function getKpis(Request $request, TalentAcquisitionService $service)
    {
        try {
            $subInstituteId = $request->input('sub_institute_id');
            $data = $service->getKpiMetrics($subInstituteId);
            return response()->json($data, 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => true,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}