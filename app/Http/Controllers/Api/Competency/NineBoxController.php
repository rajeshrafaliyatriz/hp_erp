<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Concerns\ResolvesEmployeeJobRole;
use App\Services\Competency\ProficiencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * THE 9-BOX'S SECOND AXIS. (G-FLOW-26's single illustration, corrected 2026-08-11)
 *
 * The diagnosis said: "this product's 9-box has performance on one axis and
 * nothing to put on the other, because performance has never been able to read a
 * capability measurement." **That was true when written and is no longer.**
 *
 *   performance axis .... s_performance_reviews.manager_rating, 8 rated
 *   capability axis ..... ProficiencyService over jobrole_competency_map, 23 rows
 *   the join ............ THIS FILE. It was the only piece missing.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * TWO DECISIONS THAT KEEP THE GRID HONEST.
 *
 * 1. UNMEASURED IS NOT A BOX. An employee with no capability measurement does not
 *    belong at the bottom of the grid - that would assert a low capability nobody
 *    assessed. They are returned with `capability = null` and `box = null`, and
 *    counted separately. **The whole point of ProficiencyService's null is lost
 *    the moment a grid coerces it to a position.**
 *
 * 2. THE AXES ARE NOT THE SAME SCALE AND ARE NOT RESCALED TO MATCH. Performance
 *    is a 1-5 rating a manager typed; capability is a weighted roll-up of KASBA
 *    items. Both are banded into low/medium/high by their OWN thresholds, and the
 *    band boundaries are returned with the data so a reader can see what produced
 *    a position rather than trusting the box.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class NineBoxController extends Controller
{
    use ResolvesApiIdentity;
    // The ONE role resolver. tbluser has TWO role columns that disagree for
    // some employees; reading either directly puts this grid on a different
    // role from the gap engine for exactly those people.
    use ResolvesEmployeeJobRole;

    /** Performance is a 1-5 manager rating. */
    private const PERF_BANDS = ['low' => 2.5, 'medium' => 3.5];

    /** Capability is ProficiencyService's weighted level, same 1-5 space. */
    private const CAP_BANDS = ['low' => 2.5, 'medium' => 3.5];

    public function __construct(private ProficiencyService $proficiency)
    {
    }

    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $tenant = $identity['sub_institute_id'];

        // Most recent rated review per employee. A person with three reviews is
        // one point on the grid, not three.
        $reviews = DB::table('s_performance_reviews')
            ->where('sub_institute_id', $tenant)
            ->whereNotNull('manager_rating')
            ->orderByDesc('id')
            ->get(['user_id', 'manager_rating', 'overall_rating', 'potential_rating']);

        $latest = [];
        foreach ($reviews as $r) {
            $latest[(int) $r->user_id] ??= $r;
        }

        if ($latest === []) {
            return response()->json(['status' => 1, 'data' => [
                'employees' => [], 'grid' => [], 'unplaced' => 0,
                'bands' => ['performance' => self::PERF_BANDS, 'capability' => self::CAP_BANDS],
                'note' => 'No rated performance reviews in this organisation, so there is no grid to draw.',
            ]]);
        }

        $userIds = array_keys($latest);

        // Each employee's job role, then that role's required competencies.
        $roleRows = DB::table('tbluser')
            ->whereIn('id', $userIds)
            ->where('sub_institute_id', $tenant)
            ->get(['id', 'jobtitle_id', 'allocated_standards']);

        // jobRoleFromUserRow's sibling: resolve per already-loaded row rather
        // than re-querying tbluser once per employee.
        $roles = [];
        foreach ($roleRows as $row) {
            $roles[(int) $row->id] = $this->resolveJobRoleId($row);
        }

        $employees = [];
        $grid = [];
        $unplaced = 0;

        foreach ($userIds as $uid) {
            $roleId = (int) ($roles[$uid] ?? 0);

            $competencyIds = $roleId > 0
                ? DB::table('jobrole_competency_map')
                    ->where('jobrole_id', $roleId)
                    ->where('sub_institute_id', $tenant)
                    ->pluck('competency_id')->map(fn ($v) => (int) $v)->all()
                : [];

            // THE ONE NAMED ROLL-UP. Not recomputed here.
            $capability = null;
            if ($competencyIds !== []) {
                $rollUp = $this->proficiency->rollUp($tenant, $uid, $competencyIds);
                $levels = array_values(array_filter(
                    array_map(fn ($c) => $c['level'] ?? null, $rollUp),
                    fn ($l) => $l !== null
                ));
                // Unmeasured competencies are EXCLUDED, never counted as zero -
                // the same rule ProficiencyService applies one level down.
                $capability = $levels === [] ? null : round(array_sum($levels) / count($levels), 2);
            }

            $performance = (float) $latest[$uid]->manager_rating;
            $box = $capability === null ? null
                : $this->band($performance, self::PERF_BANDS) . ':' . $this->band($capability, self::CAP_BANDS);

            if ($box === null) {
                $unplaced++;
            } else {
                $grid[$box] = ($grid[$box] ?? 0) + 1;
            }

            $employees[] = [
                'user_id'     => $uid,
                'performance' => $performance,
                'capability'  => $capability,        // NULL means unmeasured, never 0
                'box'         => $box,               // NULL means unplaceable, not bottom-left
                'competencies_required' => count($competencyIds),
            ];
        }

        return response()->json(['status' => 1, 'data' => [
            'employees' => $employees,
            'grid'      => $grid,
            // Reported, never hidden: an employee absent from the grid is a
            // measurement gap, and a grid that silently drops people overstates
            // how much of the workforce it describes.
            'unplaced'  => $unplaced,
            'placed'    => count($employees) - $unplaced,
            'bands'     => ['performance' => self::PERF_BANDS, 'capability' => self::CAP_BANDS],
        ]]);
    }

    private function band(float $value, array $bands): string
    {
        if ($value < $bands['low']) return 'low';
        if ($value < $bands['medium']) return 'medium';
        return 'high';
    }
}
