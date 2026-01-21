<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TalentAcquisitionService
{
    public function getKpiMetrics($subInstituteId)
    {
        $data = [];

        // Open Positions
        $openPositions = DB::table('talent_job_postings')->where('status', 'active')->whereDate('deadline', '>=', Carbon::today())->where('sub_institute_id', $subInstituteId)->count();
        $data['open_positions'] = $openPositions;

        // Interview-to-Offer Ratio
        $interviews = DB::table('talent_job_applications')
            ->whereNotNull('created_at')
            ->where('sub_institute_id', $subInstituteId)
            ->count();

        $offers = DB::table('talent_job_applications')
            ->where('status', 'Hired')
            ->where('sub_institute_id', $subInstituteId)
            ->count();

        $data['interview_to_offer_ratio'] = $interviews > 0
            ? round($offers / $interviews, 2)
            : 0;
        // // Offer Acceptance Rate
        // $totalOffers = DB::table('talent_offers')->where('sub_institute_id', $subInstituteId)->count();
        // $acceptedOffers = DB::table('talent_offers')->where('status', 'draft')->where('sub_institute_id', $subInstituteId)->count();
        // $data['offer_acceptance_rate'] = $totalOffers > 0 ? ($acceptedOffers / $totalOffers) * 100 : 0;

        // Drop-off Rate
        $totalCandidates = DB::table('talent_job_applications')->where('sub_institute_id', $subInstituteId)->count();
        $droppedCandidates = DB::table('talent_job_applications')->where('status', 'Rejected')->where('sub_institute_id', $subInstituteId)->count();
        $data['drop_off_rate'] = $totalCandidates > 0 ? ($droppedCandidates / $totalCandidates) * 100 : 0;

        // Team Performance Trend
        // $currentAvg = DB::table('recruiter_performance')->where('sub_institute_id', $subInstituteId)->avg('rating') ?? 0;
        // $historicalAvg = DB::table('recruiter_performance_history')->where('sub_institute_id', $subInstituteId)->avg('rating') ?? 0;
        // $data['team_performance_trend'] = $currentAvg - $historicalAvg;

        // Avg Time-to-Fill
        // $avgTimeToFill = DB::table('talent_job_postings')
        //     ->whereNotNull('updated_at')
        //     ->where('status', 'closed')
        //     ->where('sub_institute_id', $subInstituteId)
        //     ->selectRaw('AVG(DATEDIFF(updated_at, created_at)) as days')
        //     ->first()->days ?? 0;
        // $data['avg_time_to_fill'] = $avgTimeToFill;

        return $data;
    }
}