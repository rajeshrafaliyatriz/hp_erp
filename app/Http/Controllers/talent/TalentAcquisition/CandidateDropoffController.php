<?php

namespace App\Http\Controllers\talent\TalentAcquisition;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Laravel\Sanctum\PersonalAccessToken;

class CandidateDropoffController extends Controller
{
    public function getDropoff(Request $request)
    {
        try {
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
            }

            $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');

            // Fetch aggregated drop-off data filtered by sub_institute_id
            $data = DB::table('talent_job_applications')
                ->select(
                    DB::raw("CASE
                        WHEN status = 'Pending Review' THEN 'Application'
                        WHEN status = 'Shortlisted' THEN 'Shortlist'
                        WHEN status = 'Interview Scheduled' THEN 'Interview'
                        WHEN status = 'Hired' THEN 'Offer'
                        WHEN status = 'Rejected' THEN 'Rejected'
                        ELSE status
                    END as stage"),
                    DB::raw('0 as voluntary'),
                    DB::raw('COUNT(*) as involuntary')
                )
                ->where('sub_institute_id', $subInstituteId)
                ->whereIn('status', ['Pending Review', 'Under Review', 'Shortlisted', 'Interview Scheduled', 'Hired', 'Rejected'])
                ->groupBy(DB::raw("CASE
                    WHEN status = 'Pending Review' THEN 'Application'
                    WHEN status = 'Shortlisted' THEN 'Shortlist'
                    WHEN status = 'Interview Scheduled' THEN 'Interview'
                    WHEN status = 'Hired' THEN 'Offer'
                    WHEN status = 'Rejected' THEN 'Rejected'
                    ELSE status
                END"))
                ->orderByRaw("FIELD(CASE
                    WHEN status = 'Pending Review' THEN 'Application'
                    WHEN status = 'Shortlisted' THEN 'Shortlist'
                    WHEN status = 'Interview Scheduled' THEN 'Interview'
                    WHEN status = 'Hired' THEN 'Offer'
                    WHEN status = 'Rejected' THEN 'Rejected'
                    ELSE status
                END, 'Application', 'Shortlist', 'Interview', 'Offer', 'Rejected')")
                ->get();

            // Ensure all stages are present, even with 0 counts
            $allStages = ['Application', 'Shortlist', 'Interview', 'Offer', 'Rejected'];
            $stageData = [];
            foreach ($allStages as $stage) {
                $existing = $data->firstWhere('stage', $stage);
                $stageData[] = [
                    'stage' => $stage,
                    'voluntary' => $existing ? $existing->voluntary : 0,
                    'involuntary' => $existing ? $existing->involuntary : 0
                ];
            }

            return response()->json([
                'status' => true,
                'data' => $stageData
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to load drop-off data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getFunnelData(Request $request)
    {
        try {
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
            }

            $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');

            // Query counts from talent_job_applications table
            $applications = DB::table('talent_job_applications')
                ->where('sub_institute_id', $subInstituteId)
                ->count();

            $shortlisted = DB::table('talent_job_applications')
                ->where('sub_institute_id', $subInstituteId)
                ->where('status', 'Shortlisted')
                ->count();

            $interviewed = DB::table('talent_job_applications')
                ->where('sub_institute_id', $subInstituteId)
                ->where('status', 'Interview Scheduled')
                ->count();

            $offers = DB::table('talent_job_applications')
                ->where('sub_institute_id', $subInstituteId)
                ->where('status', 'Hired')
                ->count();

            $hired = DB::table('talent_job_applications')
                ->where('sub_institute_id', $subInstituteId)
                ->where('status', 'Hired')
                ->count();

            // Build funnel response
            $funnel = [
                ["name" => "Applications", "value" => $applications],
                ["name" => "Shortlisted", "value" => $shortlisted],
                ["name" => "Interviewed", "value" => $interviewed],
                ["name" => "Offers", "value" => $offers],
                ["name" => "Hired", "value" => $hired],
            ];

            return response()->json([
                "success" => true,
                "data" => $funnel
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Server error while fetching recruitment funnel.",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    public function getRequisitions(Request $request)
    {
        try {
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
            }

            $subInstituteId = $request->sub_institute_id ?? $request->header('sub_institute_id');

            // ---------------------------------------
            // 1. READ QUERY PARAMETERS
            // ---------------------------------------
            $page       = $request->get('page', 1);
            $limit      = $request->get('limit', 10);

            $department = $request->get('department', 'all-dept');
            $location   = $request->get('location', 'all-loc');
            $timePeriod = $request->get('timePeriod', 'monthly');
            $jobLevel   = $request->get('jobLevel', 'all-level');
            $diversity  = $request->get('diversity', 'all-gender');
            $status     = $request->get('status', 'active');

            $sortBy     = $request->get('sortBy', 'age');   // age | title | interviewed | offers | hires
            $order      = $request->get('order', 'desc');   // asc | desc


            // ---------------------------------------
            // 2. BUILD BASE QUERY
            // ---------------------------------------
            $query = DB::table('talent_job_postings as r')
                ->selectRaw("
                    r.id,
                    r.title,
                    d.department as department,
                    r.location,
                    r.priority_level as job_level,
                    r.status,
                    DATEDIFF(CURDATE(), r.created_at) AS age,
                    COUNT(DISTINCT i.id) AS interviewed,
                    COUNT(DISTINCT o.id) AS offers,
                    COUNT(DISTINCT h.id) AS hires
                ")
                ->leftJoin('hrms_departments as d', 'r.department_id', '=', 'd.id')
                ->leftJoin('talent_interview_schedules as i', 'i.job_id', '=', 'r.id')
                ->leftJoin('talent_offers as o', 'o.job_id', '=', 'r.id')
                ->leftJoin('talent_job_applications as h', function($join) {
                    $join->on('h.job_id', '=', 'r.id')
                         ->where('h.status', '=', 'Hired');
                })
                ->where('r.sub_institute_id', $subInstituteId)
                ->groupBy('r.id', 'd.department', 'r.title', 'r.location', 'r.priority_level', 'r.status', 'r.created_at');


            // ---------------------------------------
            // 3. APPLY FILTERS
            // ---------------------------------------

            if ($department !== 'all-dept') {
                $query->where('d.department', $department);
            }

            if ($location !== 'all-loc') {
                $query->where('r.location', $location);
            }

            if ($jobLevel !== 'all-level') {
                $query->where('r.job_level', $jobLevel);
            }

            if ($status !== 'all') {
                $query->where('r.status', ucfirst($status)); // Active / Closed
            }

            // Time period filtering (last 30 days, last 7 days, etc.)
            if ($timePeriod === 'weekly') {
                $query->where('r.created_at', '>=', now()->subDays(7));
            }
            if ($timePeriod === 'monthly') {
                $query->where('r.created_at', '>=', now()->subDays(30));
            }
            if ($timePeriod === 'quarterly') {
                $query->where('r.created_at', '>=', now()->subDays(90));
            }

            // Diversity filter (gender) - commented out as gender field may not exist in talent_job_applications
            // if ($diversity !== 'all-gender') {
            //     $query->leftJoin('talent_job_applications as c', 'c.job_id', '=', 'r.id')
            //           ->where('c.gender', $diversity);
            // }

            // ---------------------------------------
            // 4. APPLY SORTING
            // ---------------------------------------
            $allowedSort = [
                'age' => 'age',
                'title' => 'r.title',
                'interviewed' => 'interviewed',
                'offers' => 'offers',
                'hires' => 'hires',
            ];

            $sortColumn = $allowedSort[$sortBy] ?? 'age';

            // ---------------------------------------
            // 5. PAGINATION
            // ---------------------------------------
            $offset = ($page - 1) * $limit;

            $countQuery = clone $query;
            $total = $countQuery->count(DB::raw('distinct r.id'));

            $query->orderBy($sortColumn, $order);

            $records = $query
                ->offset($offset)
                ->limit($limit)
                ->get();


            return response()->json([
                "success" => true,
                "page"    => $page,
                "limit"   => $limit,
                "total"   => $total,
                "data"    => $records
            ]);

        } catch (\Exception $e) {

            return response()->json([
                "success" => false,
                "message" => "Failed to fetch requisitions",
                "error"   => $e->getMessage()
            ], 500);
        }
    }
}