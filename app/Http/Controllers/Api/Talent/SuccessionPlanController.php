<?php

namespace App\Http\Controllers\Api\Talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Talent\Concerns\ResolvesTalentContext;
use App\Models\talent\SuccessionCandidate;
use App\Models\talent\SuccessionPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Succession planning - critical roles, their incumbents, and the bench.
 *
 * Backs the Mobility & Succession screen's "Critical Roles" card and 9-box
 * matrix. Candidates are managed through this controller rather than their own,
 * because a candidate has no meaning outside the plan it sits on.
 */
class SuccessionPlanController extends Controller
{
    use ResolvesTalentContext;

    private const SORTABLE = ['position_title', 'criticality', 'coverage_status', 'created_at'];

    /** GET /api/talent/succession/plans */
    public function index(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        ['page' => $page, 'per_page' => $perPage] = $this->talentPaging($request);
        [$sortBy, $sortDir] = $this->talentSort($request, self::SORTABLE, 'criticality', 'asc');

        $query = $this->baseQuery($tenant, $request);
        // baseQuery is grouped (it aggregates the successor count), and
        // ->count() on a grouped query returns the first group's size, not the
        // number of groups - so the total comes from a wrapping subquery.
        $total = DB::query()->fromSub(clone $query, 'grouped')->count();

        $rows = $query
            ->orderBy('p.' . $sortBy, $sortDir)
            ->orderByDesc('p.id')
            ->forPage($page, $perPage)
            ->get();

        $directory = $this->talentEmployeeDirectory($tenant, $rows->pluck('incumbent_id')->all());

        return $this->talentResponse(
            $rows->map(fn ($row) => $this->present($row, $directory))->all(),
            'Success',
            200,
            [
                'pagination' => [
                    'page'      => $page,
                    'per_page'  => $perPage,
                    'total'     => $total,
                    'last_page' => (int) max(1, ceil($total / $perPage)),
                ],
                'summary' => $this->summary($tenant, $request),
            ]
        );
    }

    /** GET /api/talent/succession/plans/{id} - with its bench. */
    public function show(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $row = $this->baseQuery($tenant, new Request())->where('p.id', $id)->first();

        if (!$row) {
            return $this->talentError('Succession plan not found', 404);
        }

        $candidates = DB::table('talent_succession_candidates')
            ->where('plan_id', $id)
            ->whereNull('deleted_at')
            ->orderBy('rank_order')
            ->get();

        $directory = $this->talentEmployeeDirectory(
            $tenant,
            $candidates->pluck('employee_id')->push($row->incumbent_id)->all()
        );

        return $this->talentResponse(array_merge($this->present($row, $directory), [
            'candidates' => $candidates->map(fn ($candidate) => $this->presentCandidate($candidate, $directory))->all(),
        ]));
    }

    /** POST /api/talent/succession/plans */
    public function store(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];

        $validated = $request->validate([
            'position_title'  => 'required|string|max:191',
            'department_id'   => 'nullable|integer',
            'incumbent_id'    => 'nullable|integer',
            'grade'           => 'nullable|string|max:40',
            'location'        => 'nullable|string|max:191',
            'criticality'     => 'nullable|in:' . implode(',', SuccessionPlan::CRITICALITIES),
            'risk_of_loss'    => 'nullable|in:' . implode(',', SuccessionPlan::RISK_LEVELS),
            'coverage_status' => 'nullable|in:' . implode(',', SuccessionPlan::COVERAGE_STATUSES),
            'notes'           => 'nullable|string',
        ]);

        $plan = SuccessionPlan::create(array_merge($validated, [
            'sub_institute_id' => $tenant,
            'criticality'      => $validated['criticality'] ?? 'medium',
            'coverage_status'  => $validated['coverage_status'] ?? 'gap',
            'created_by'       => $context['user_id'],
            'updated_by'       => $context['user_id'],
        ]));

        return $this->talentResponse($this->hydrate($tenant, $plan->id), 'Succession plan created', 201);
    }

    /** PUT /api/talent/succession/plans/{id} */
    public function update(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $plan = SuccessionPlan::where('sub_institute_id', $tenant)->find($id);

        if (!$plan) {
            return $this->talentError('Succession plan not found', 404);
        }

        $validated = $request->validate([
            'position_title'  => 'sometimes|required|string|max:191',
            'department_id'   => 'nullable|integer',
            'incumbent_id'    => 'nullable|integer',
            'grade'           => 'nullable|string|max:40',
            'location'        => 'nullable|string|max:191',
            'criticality'     => 'nullable|in:' . implode(',', SuccessionPlan::CRITICALITIES),
            'risk_of_loss'    => 'nullable|in:' . implode(',', SuccessionPlan::RISK_LEVELS),
            'coverage_status' => 'nullable|in:' . implode(',', SuccessionPlan::COVERAGE_STATUSES),
            'notes'           => 'nullable|string',
        ]);

        $plan->fill($validated);
        $plan->updated_by = $context['user_id'];
        $plan->save();

        return $this->talentResponse($this->hydrate($tenant, $plan->id), 'Succession plan updated');
    }

    /** DELETE /api/talent/succession/plans/{id} */
    public function destroy(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $plan = SuccessionPlan::where('sub_institute_id', $tenant)->find($id);

        if (!$plan) {
            return $this->talentError('Succession plan not found', 404);
        }

        DB::transaction(function () use ($plan, $context) {
            $plan->deleted_by = $context['user_id'];
            $plan->save();
            $plan->delete();

            DB::table('talent_succession_candidates')
                ->where('plan_id', $plan->id)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'deleted_by' => $context['user_id']]);
        });

        return $this->talentResponse(null, 'Succession plan deleted');
    }

    /* -- bench ------------------------------------------------------------ */

    /** POST /api/talent/succession/plans/{id}/candidates */
    public function storeCandidate(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $plan = SuccessionPlan::where('sub_institute_id', $tenant)->find($id);

        if (!$plan) {
            return $this->talentError('Succession plan not found', 404);
        }

        $validated = $request->validate([
            'employee_id'       => 'required|integer',
            'readiness'         => 'nullable|in:' . implode(',', SuccessionCandidate::READINESS_LEVELS),
            'potential_score'   => 'nullable|integer|min:1|max:3',
            'performance_score' => 'nullable|integer|min:1|max:3',
            'development_notes' => 'nullable|string',
            'rank_order'        => 'nullable|integer|min:0',
        ]);

        if ((int) $validated['employee_id'] === (int) $plan->incumbent_id) {
            return $this->talentError('The incumbent cannot be their own successor', 422);
        }

        $duplicate = SuccessionCandidate::where('plan_id', $plan->id)
            ->where('employee_id', $validated['employee_id'])
            ->exists();

        if ($duplicate) {
            return $this->talentError('This employee is already on the bench for this role', 422);
        }

        $candidate = SuccessionCandidate::create(array_merge($validated, [
            'plan_id'          => $plan->id,
            'sub_institute_id' => $tenant,
            'readiness'        => $validated['readiness'] ?? 'ready-1-2-years',
            'rank_order'       => $validated['rank_order'] ?? 0,
            'created_by'       => $context['user_id'],
            'updated_by'       => $context['user_id'],
        ]));

        $this->syncCoverage($plan);

        $directory = $this->talentEmployeeDirectory($tenant, [$candidate->employee_id]);

        return $this->talentResponse($this->presentCandidate($candidate, $directory), 'Successor added', 201);
    }

    /** PUT /api/talent/succession/candidates/{id} */
    public function updateCandidate(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $candidate = SuccessionCandidate::where('sub_institute_id', $tenant)->find($id);

        if (!$candidate) {
            return $this->talentError('Successor not found', 404);
        }

        $validated = $request->validate([
            'readiness'         => 'nullable|in:' . implode(',', SuccessionCandidate::READINESS_LEVELS),
            'potential_score'   => 'nullable|integer|min:1|max:3',
            'performance_score' => 'nullable|integer|min:1|max:3',
            'development_notes' => 'nullable|string',
            'rank_order'        => 'nullable|integer|min:0',
        ]);

        // employee_id is not accepted: swapping the person would rewrite the
        // 9-box history rather than record a new placement.
        $candidate->fill($validated);
        $candidate->updated_by = $context['user_id'];
        $candidate->save();

        $plan = SuccessionPlan::find($candidate->plan_id);
        if ($plan) {
            $this->syncCoverage($plan);
        }

        $directory = $this->talentEmployeeDirectory($tenant, [$candidate->employee_id]);

        return $this->talentResponse($this->presentCandidate($candidate, $directory), 'Successor updated');
    }

    /** DELETE /api/talent/succession/candidates/{id} */
    public function destroyCandidate(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $candidate = SuccessionCandidate::where('sub_institute_id', $tenant)->find($id);

        if (!$candidate) {
            return $this->talentError('Successor not found', 404);
        }

        $planId = $candidate->plan_id;

        $candidate->deleted_by = $context['user_id'];
        $candidate->save();
        $candidate->delete();

        $plan = SuccessionPlan::find($planId);
        if ($plan) {
            $this->syncCoverage($plan);
        }

        return $this->talentResponse(null, 'Successor removed');
    }

    /* -- internals -------------------------------------------------------- */

    private function baseQuery(int $tenant, Request $request)
    {
        $criticality = $this->activeTalentFilter($request->input('criticality'));
        $coverage = $this->activeTalentFilter($request->input('coverage_status'));
        $departmentId = $this->activeTalentFilter($request->input('department_id'));
        $search = $this->activeTalentFilter($request->input('search'));

        return DB::table('talent_succession_plans as p')
            ->leftJoin('hrms_departments as d', 'd.id', '=', 'p.department_id')
            ->leftJoin('talent_succession_candidates as c', function ($join) {
                $join->on('c.plan_id', '=', 'p.id')->whereNull('c.deleted_at');
            })
            ->select('p.*', 'd.department as department_name', DB::raw('COUNT(DISTINCT c.id) as successor_count'))
            ->where('p.sub_institute_id', $tenant)
            ->whereNull('p.deleted_at')
            ->groupBy('p.id', 'd.department')
            ->when($criticality, fn ($q) => $q->where('p.criticality', $criticality))
            ->when($coverage, fn ($q) => $q->where('p.coverage_status', $coverage))
            ->when($departmentId, fn ($q) => $q->where('p.department_id', $departmentId))
            ->when($search, fn ($q) => $q->where('p.position_title', 'like', "%{$search}%"));
    }

    private function hydrate(int $tenant, $id): ?array
    {
        $row = $this->baseQuery($tenant, new Request())->where('p.id', $id)->first();

        if (!$row) {
            return null;
        }

        return $this->present($row, $this->talentEmployeeDirectory($tenant, array_filter([$row->incumbent_id])));
    }

    /**
     * The Succession panel's counters.
     *
     * Counted off an ungrouped query on purpose: baseQuery groups by plan to
     * aggregate the successor count, and COUNT on a grouped query returns the
     * first group's size rather than the number of groups.
     */
    private function summary(int $tenant, Request $request): array
    {
        $criticality = $this->activeTalentFilter($request->input('criticality'));
        $coverage = $this->activeTalentFilter($request->input('coverage_status'));
        $departmentId = $this->activeTalentFilter($request->input('department_id'));
        $search = $this->activeTalentFilter($request->input('search'));

        $scope = fn () => DB::table('talent_succession_plans as p')
            ->where('p.sub_institute_id', $tenant)
            ->whereNull('p.deleted_at')
            ->when($criticality, fn ($q) => $q->where('p.criticality', $criticality))
            ->when($coverage, fn ($q) => $q->where('p.coverage_status', $coverage))
            ->when($departmentId, fn ($q) => $q->where('p.department_id', $departmentId))
            ->when($search, fn ($q) => $q->where('p.position_title', 'like', "%{$search}%"));

        return [
            'total'    => $scope()->count(),
            'critical' => $scope()->where('p.criticality', 'critical')->count(),
            'covered'  => $scope()->where('p.coverage_status', 'covered')->count(),
            'at_risk'  => $scope()->where('p.coverage_status', 'at-risk')->count(),
            'gap'      => $scope()->where('p.coverage_status', 'gap')->count(),
        ];
    }

    private function present($row, array $directory): array
    {
        return [
            'id'                    => (int) $row->id,
            'position_title'        => $row->position_title,
            'department_id'         => $row->department_id ? (int) $row->department_id : null,
            'department'            => $row->department_name,
            'incumbent_id'          => $row->incumbent_id ? (int) $row->incumbent_id : null,
            'incumbent'             => $row->incumbent_id ? ($directory[(int) $row->incumbent_id] ?? null) : null,
            'grade'                 => $row->grade,
            'location'              => $row->location,
            'criticality'           => $row->criticality,
            'criticality_label'     => $this->talentLabel($row->criticality),
            'risk_of_loss'          => $row->risk_of_loss,
            'coverage_status'       => $row->coverage_status,
            'coverage_status_label' => $this->talentLabel($row->coverage_status),
            'successor_count'       => (int) ($row->successor_count ?? 0),
            'notes'                 => $row->notes,
            'created_at'            => $row->created_at,
            'updated_at'            => $row->updated_at,
        ];
    }

    private function presentCandidate($row, array $directory): array
    {
        return [
            'id'                => (int) $row->id,
            'plan_id'           => (int) $row->plan_id,
            'employee_id'       => (int) $row->employee_id,
            'employee'          => $directory[(int) $row->employee_id] ?? null,
            'readiness'         => $row->readiness,
            'readiness_label'   => $this->talentLabel($row->readiness),
            'potential_score'   => $row->potential_score ? (int) $row->potential_score : null,
            'performance_score' => $row->performance_score ? (int) $row->performance_score : null,
            'development_notes' => $row->development_notes,
            'rank_order'        => (int) $row->rank_order,
        ];
    }

    /**
     * Keep coverage_status honest as the bench changes.
     *
     * A role with a ready-now successor is covered; with a bench but nobody
     * ready it is at risk; with nobody at all it is a gap. Recomputed on every
     * bench write so the "Critical Roles" card and the matrix cannot drift.
     */
    private function syncCoverage(SuccessionPlan $plan): void
    {
        $counts = DB::table('talent_succession_candidates')
            ->where('plan_id', $plan->id)
            ->whereNull('deleted_at')
            ->selectRaw("COUNT(*) as total, SUM(readiness = 'ready-now') as ready_now")
            ->first();

        $coverage = match (true) {
            (int) ($counts->ready_now ?? 0) > 0 => 'covered',
            (int) ($counts->total ?? 0) > 0     => 'at-risk',
            default                             => 'gap',
        };

        if ($plan->coverage_status !== $coverage) {
            $plan->coverage_status = $coverage;
            $plan->save();
        }
    }
}
