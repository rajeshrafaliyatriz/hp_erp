<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * R-03 — the development plan report.
 *
 * THE FIRST REPORT IN THIS PLAN WHOSE SUBJECT IS POPULATED. 161 development plans
 * exist, 160 of them in a REAL tenant, and nothing has ever reported on them.
 *
 * BUILT STANDALONE, WITHOUT R-01. The plan's dependency column says R-02, R-03 and
 * R-04 all gate on R-01, "consolidated reporting home". Measured, none of them
 * does: R-01 is a CONTAINER, not a gate. R-03 needs no container to read a table.
 *
 * A DECLARED DEPENDENCY THAT DOES NOT HOLD IS WORSE THAN AN UNDECLARED ONE - it
 * enforces an order nobody chose and nobody re-examined.
 *
 * R20 - THE CHAIN THIS RELIES ON:
 *   route       routes/api.php
 *   tenant      competencyContext() -> resolveApiIdentity(), NEVER a request field
 *   read-only   this controller writes nothing
 */
class DevelopmentPlanReportController extends Controller
{
    use ResolvesCompetencyContext;

    /** Statuses actually present in the data, measured rather than assumed. */
    private const STATUSES = ['active', 'completed', 'on_hold', 'overdue'];

    /**
     * GET /competency/reports/development-plans
     *
     * Every figure is scoped to the caller's own tenant. `sub_institute_id` comes
     * from the token, so a caller cannot ask for another tenant's plans by
     * changing a parameter - the defect G-SEC-29 closed across twenty controllers.
     */
    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];

        $base = fn () => DB::table('s_competency_development_plans')
            // QUALIFIED. The plans list LEFT JOINs `competency`, which also has a
            // sub_institute_id, and an unqualified filter is ambiguous the moment
            // the join is added - a 500 that only appears on the one query that
            // joins, not on the counts above it.
            ->where('s_competency_development_plans.sub_institute_id', $sid)
            ->whereNull('s_competency_development_plans.deleted_at');

        $total = $base()->count();

        // BY STATUS. Statuses absent from the data are still reported at zero, so
        // a reader sees the full vocabulary rather than inferring it from what
        // happens to exist this month.
        $counts = $base()->select('s_competency_development_plans.status as status')
            ->selectRaw('COUNT(*) as n')->groupBy('s_competency_development_plans.status')->pluck('n', 'status')->all();
        $byStatus = [];
        foreach (self::STATUSES as $s) {
            $byStatus[$s] = (int) ($counts[$s] ?? 0);
        }
        $other = array_sum($counts) - array_sum($byStatus);
        if ($other > 0) {
            $byStatus['other'] = $other;   // never silently dropped
        }

        $overdue = $base()
            ->whereNotNull('s_competency_development_plans.due_date')
            ->whereDate('s_competency_development_plans.due_date', '<', now())
            ->whereNotIn('s_competency_development_plans.status', ['completed'])->count();

        $rows = $base()
            ->leftJoin('competency as c', 'c.id', '=', 's_competency_development_plans.competency_id')
            ->orderByRaw("FIELD(s_competency_development_plans.status,'overdue','on_hold','active','completed')")
            ->orderBy('s_competency_development_plans.due_date')
            ->limit(500)
            ->get([
                's_competency_development_plans.id',
                's_competency_development_plans.title',
                's_competency_development_plans.user_id',
                's_competency_development_plans.jobrole',
                's_competency_development_plans.status',
                's_competency_development_plans.progress',
                's_competency_development_plans.start_date',
                's_competency_development_plans.due_date',
                's_competency_development_plans.completed_at',
                'c.name as competency_name',
            ]);

        return response()->json([
            'status' => 1,
            'data'   => [
                'total'      => $total,
                'by_status'  => $byStatus,
                'overdue'    => $overdue,
                'plans'      => $rows,
            ],
            // Said in the payload, as the map screens now do: a tenant with no
            // plans is a normal tenant, not a failed query. The reader must not
            // have to infer "none yet" from an empty array.
            'empty_is_expected' => $total === 0,
            // Stated so nobody reads 500 as "all of them".
            'truncated' => $total > 500,
        ]);
    }
}
