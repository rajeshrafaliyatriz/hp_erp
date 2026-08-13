<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Validator;

class OrganizationGrowthController extends Controller
{
    use ResolvesApiIdentity;

    public function index(Request $request)
    {
        $type = $request->type;

        if ($type == "API") {
            $token = $request->input('token');
            if (!$token) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            $accessToken = PersonalAccessToken::findToken($token);
            if (!$accessToken) {
                return response()->json(['message' => 'Invalid token'], 401);
            }

            $validator = Validator::make($request->all(), [
                'sub_institute_id' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 0,
                    'message' => $validator->errors()->first()
                ], 400);
            }

            $sub_institute_id = $this->apiTenantId($request);
        } else {
            $sub_institute_id = $this->apiTenantId($request) ?? null;
        }

        try {
            // Fetch month-wise growth data (using hired applications as growth indicator)
            $growthData = DB::table('talent_job_applications as tja')
                ->join('talent_job_postings as tjp', 'tja.job_id', '=', 'tjp.id')
                ->join('hrms_departments as d', 'tjp.department_id', '=', 'd.id')
                ->where('tja.sub_institute_id', $sub_institute_id)
                ->where('tja.status', 'hired')
                ->selectRaw("
                    DATE_FORMAT(tja.applied_date, '%b') as month,
                    LOWER(d.department) as department,
                    COUNT(*) as count
                ")
                ->groupByRaw("YEAR(tja.applied_date), MONTH(tja.applied_date), d.id, d.department")
                ->orderByRaw("YEAR(tja.applied_date), MONTH(tja.applied_date)")
                ->get();

            // Pivot the data to match the required format
            $pivotedData = [];
            foreach ($growthData as $row) {
                $month = $row->month;
                $dept = $row->department;
                $count = $row->count;

                if (!isset($pivotedData[$month])) {
                    $pivotedData[$month] = [
                        'month' => $month,
                        'engineering' => 0,
                        'sales' => 0,
                        'marketing' => 0,
                        'operations' => 0,
                        'hr' => 0
                    ];
                }

                // Map department names to the expected keys (assuming lowercase matches)
                if (in_array($dept, ['engineering', 'sales', 'marketing', 'operations', 'hr'])) {
                    $pivotedData[$month][$dept] = $count;
                }
            }

            $result = array_values($pivotedData);

            if (empty($result)) {
                return response()->json([
                    'status' => 'warning',
                    'message' => 'No growth data found',
                    'data' => []
                ], 200);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Growth data retrieved successfully.',
                'data' => $result
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch growth data.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}