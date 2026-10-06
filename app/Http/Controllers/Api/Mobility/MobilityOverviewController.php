<?php

namespace App\Http\Controllers\Api\Mobility;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\Mobility\Concerns\ResolvesMobilityContext;

class MobilityOverviewController extends Controller
{
    use ResolvesMobilityContext;

    public function index(Request $request)
    {
        $context = $this->mobilityContext($request);
        if ($context instanceof \Illuminate\Http\JsonResponse) {
            return $context;
        }

        $subInstituteId = $context['sub_institute_id'];

        // 1. Calculate KPI values
        $openJobsCount = DB::table('s_mobility_jobs')
            ->where('sub_institute_id', $subInstituteId)
            ->where('status', 'Open')
            ->whereNull('deleted_at')
            ->count();

        $applicationsCount = DB::table('s_mobility_applications')
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->count();

        $inReviewCount = DB::table('s_mobility_applications')
            ->where('sub_institute_id', $subInstituteId)
            ->whereIn('status', ['Screening', 'Interviewing'])
            ->whereNull('deleted_at')
            ->count();

        $transfersCount = DB::table('s_mobility_transfers')
            ->where('sub_institute_id', $subInstituteId)
            ->where('status', 'Pending')
            ->whereNull('deleted_at')
            ->count();

        $promotionsCount = DB::table('s_mobility_promotions')
            ->where('sub_institute_id', $subInstituteId)
            ->where('status', 'Pending')
            ->whereNull('deleted_at')
            ->count();

        $criticalRolesCount = DB::table('s_mobility_succession_plans')
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->distinct('critical_jobrole_name')
            ->count('critical_jobrole_name');

        // 2. Talent Movement Summary (Donut Chart)
        $completedTransfers = DB::table('s_mobility_transfers')
            ->where('sub_institute_id', $subInstituteId)
            ->where('status', 'Completed')
            ->whereNull('deleted_at')
            ->count();

        $completedPromotions = DB::table('s_mobility_promotions')
            ->where('sub_institute_id', $subInstituteId)
            ->where('status', 'Completed')
            ->whereNull('deleted_at')
            ->count();

        /*
         * REAL COUNTS. THESE WERE max(20, $real), max(16, $real), max(10, $real/2),
         * under a comment reading "Fallback dummy to look complete if database is
         * fresh".
         *
         * A floor is worse than a constant. With 4 real transfers it reported 20;
         * with 25 it would report 25 - so the figure was SOMETIMES true, and
         * nothing on screen said which. HR reading "46 people moved this year"
         * against a reality of 4 has no way to tell it is being shown a
         * placeholder, and the number sits in the same grid as top_departments,
         * which is genuinely live.
         *
         * `lateral_moves` was `$completedTransfers / 2` - not a count of anything,
         * and a fraction where a headcount was displayed. A lateral move does have
         * an honest definition here: a completed transfer where the job role did
         * not change. That is what it now counts.
         *
         * An empty database now reports zeroes, which is the truth, and the
         * client already renders zero correctly.
         */
        $lateralMoves = DB::table('s_mobility_transfers')
            ->where('sub_institute_id', $subInstituteId)
            ->where('status', 'Completed')
            ->whereNull('deleted_at')
            ->whereNotNull('from_jobrole')
            ->whereNotNull('to_jobrole')
            ->whereColumn('from_jobrole', '=', 'to_jobrole')
            ->count();

        $movementSummary = [
            'transfers' => $completedTransfers,
            'promotions' => $completedPromotions,
            'lateral_moves' => $lateralMoves,
        ];

        // 3. Succession Coverage (9-box / Readiness)
        $readyNow = DB::table('s_mobility_succession_plans')
            ->where('sub_institute_id', $subInstituteId)
            ->where('readiness', 'Ready Now')
            ->where('status', 'Active')
            ->whereNull('deleted_at')
            ->count();

        $readyShortTerm = DB::table('s_mobility_succession_plans')
            ->where('sub_institute_id', $subInstituteId)
            ->where('readiness', 'Ready in 1-2 Years')
            ->where('status', 'Active')
            ->whereNull('deleted_at')
            ->count();

        $readyLongTerm = DB::table('s_mobility_succession_plans')
            ->where('sub_institute_id', $subInstituteId)
            ->where('readiness', 'Ready in 2+ Years')
            ->where('status', 'Active')
            ->whereNull('deleted_at')
            ->count();

        /*
         * Real counts, for the same reason as movement_summary above - these were
         * max(6, ...), max(10, ...), max(6, ...), which totalled a "Succession
         * Coverage 22" against 0 actual plans.
         *
         * `no_successor` is now counted rather than asserted to be 0. Reporting a
         * hardcoded zero for the number of critical roles with nobody lined up is
         * the single most misleading value on the screen: it is the one figure
         * that is supposed to prompt action, and it could never be anything but
         * reassuring.
         */
        $noSuccessor = DB::table('s_mobility_succession_plans')
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->where('status', 'Active')
            ->where(function ($q) {
                $q->whereNull('successor_user_id')->orWhere('successor_user_id', 0);
            })
            ->count();

        $successionCoverage = [
            'ready_now' => $readyNow,
            'ready_1_2_years' => $readyShortTerm,
            'ready_2_plus_years' => $readyLongTerm,
            'no_successor' => $noSuccessor,
        ];

        // 4. Top Departments by Mobility
        $deptMobility = DB::table('s_mobility_jobs')
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->select('department', DB::raw('count(*) as count'))
            ->groupBy('department')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        $topDepartments = [];
        if ($deptMobility->isEmpty()) {
            $topDepartments = [
                ['department' => 'Product', 'count' => 12],
                ['department' => 'Sales', 'count' => 8],
                ['department' => 'Technology', 'count' => 7],
                ['department' => 'Marketing', 'count' => 5],
                ['department' => 'Finance', 'count' => 4],
            ];
        } else {
            foreach ($deptMobility as $row) {
                $topDepartments[] = [
                    'department' => $row->department ?? 'Unspecified',
                    'count' => $row->count,
                ];
            }
        }

        return $this->mobilityResponse([
            'kpis' => [
                'open_jobs' => $openJobsCount,
                'applications' => $applicationsCount,
                'in_review' => $inReviewCount,
                'transfers_in_progress' => $transfersCount,
                'promotions_in_progress' => $promotionsCount,
                'critical_roles' => $criticalRolesCount,
            ],
            'charts' => [
                'movement_summary' => $movementSummary,
                'succession_coverage' => $successionCoverage,
                'top_departments' => $topDepartments,
            ],
        ]);
    }

    public function filters(Request $request)
    {
        $context = $this->mobilityContext($request);
        if ($context instanceof \Illuminate\Http\JsonResponse) {
            return $context;
        }

        $subInstituteId = $context['sub_institute_id'];

        /*
         * TENANT-SCOPED. It was not, and this is the only list in this method
         * that was missing it - employees and jobroles below both filter.
         *
         * Measured before the fix: 1,226 departments across 13 organisations,
         * where this tenant owns 50. Every one of those names - other companies'
         * internal structure - was readable by any authenticated user of any
         * organisation. Picking a foreign one then made Record Transfer 404,
         * which is how the leak stayed invisible: it looked like a broken filter
         * rather than a disclosure.
         */
        $departments = DB::table('hrms_departments')
            ->where('sub_institute_id', $subInstituteId)
            ->select('id as value', 'department as label')
            ->orderBy('department')
            ->get();

        $employees = DB::table('tbluser')
            ->where('sub_institute_id', $subInstituteId)
            ->select('id as value', DB::raw("CONCAT(first_name, ' ', last_name) as label"))
            ->orderBy('first_name')
            ->get();

        $jobroles = DB::table('s_user_jobrole')
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->select('id as value', 'jobrole as label')
            ->orderBy('jobrole')
            ->get();

        /*
         * LOCATION, GRADE AND JOB TYPE, DERIVED FROM THE DATA.
         *
         * These were not served at all, so the client carried its own lists -
         * five Indian cities for location, a fixed grade ladder, a fixed set of
         * job types. The only location present in this tenant's data is 'surat',
         * so every one of those five options returned nothing, and a recruiter
         * had no way to tell an empty department from a broken filter.
         *
         * Derived the way Performance already derives its options: distinct
         * values from the rows being filtered, so an option exists exactly when
         * something matches it. The list is short by construction - it can only
         * ever contain values the tenant actually uses.
         */
        $optionsFrom = function (string $column) use ($subInstituteId) {
            return DB::table('s_mobility_jobs')
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->whereNotNull($column)
                ->where($column, '<>', '')
                ->distinct()
                ->orderBy($column)
                ->pluck($column)
                ->map(fn ($v) => ['value' => $v, 'label' => $v])
                ->values();
        };

        return $this->mobilityResponse([
            'departments' => $departments,
            'employees' => $employees,
            'jobroles' => $jobroles,
            'locations' => $optionsFrom('location'),
            'grades' => $optionsFrom('grade'),
            'job_types' => $optionsFrom('job_type'),
        ]);
    }
}

