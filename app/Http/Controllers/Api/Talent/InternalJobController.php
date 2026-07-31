<?php

namespace App\Http\Controllers\Api\Talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Talent\Concerns\ResolvesTalentContext;
use App\Models\talent\InternalJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Internal job postings - roles opened to existing employees only.
 *
 * Backs the Mobility & Succession screen's Internal Jobs table and the Talent
 * Dashboard's "Internal Job Posting" quick link.
 */
class InternalJobController extends Controller
{
    use ResolvesTalentContext;

    private const SORTABLE = ['posted_on', 'closing_date', 'title', 'status', 'created_at'];

    /** GET /api/talent/mobility/internal-jobs */
    public function index(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        ['page' => $page, 'per_page' => $perPage] = $this->talentPaging($request);
        [$sortBy, $sortDir] = $this->talentSort($request, self::SORTABLE, 'posted_on');

        $query = $this->baseQuery($tenant, $request);
        // baseQuery is grouped (it aggregates the application count), and
        // ->count() on a grouped query returns the first group's size, not the
        // number of groups - so the total comes from a wrapping subquery.
        $total = DB::query()->fromSub(clone $query, 'grouped')->count();

        $rows = $query
            ->orderBy('ij.' . $sortBy, $sortDir)
            ->orderByDesc('ij.id')
            ->forPage($page, $perPage)
            ->get();

        return $this->talentResponse(
            $rows->map(fn ($row) => $this->present($row))->all(),
            'Success',
            200,
            [
                'pagination' => [
                    'page'      => $page,
                    'per_page'  => $perPage,
                    'total'     => $total,
                    'last_page' => (int) max(1, ceil($total / $perPage)),
                ],
            ]
        );
    }

    /** GET /api/talent/mobility/internal-jobs/{id} */
    public function show(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $row = $this->baseQuery($context['sub_institute_id'], new Request())->where('ij.id', $id)->first();

        if (!$row) {
            return $this->talentError('Internal job not found', 404);
        }

        return $this->talentResponse($this->present($row));
    }

    /** POST /api/talent/mobility/internal-jobs */
    public function store(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];

        $validated = $request->validate([
            'title'             => 'required|string|max:191',
            'department_id'     => 'nullable|integer',
            'location'          => 'nullable|string|max:191',
            'grade'             => 'nullable|string|max:40',
            'description'       => 'nullable|string',
            'eligibility'       => 'nullable|string',
            'positions'         => 'nullable|integer|min:1',
            'hiring_manager_id' => 'nullable|integer',
            'posted_on'         => 'nullable|date',
            'closing_date'      => 'nullable|date|after_or_equal:posted_on',
            'status'            => 'nullable|in:' . implode(',', InternalJob::STATUSES),
        ]);

        $job = InternalJob::create(array_merge($validated, [
            'sub_institute_id' => $tenant,
            'job_code'         => $this->nextTalentCode('talent_internal_jobs', 'job_code', 'INT', $tenant),
            'positions'        => $validated['positions'] ?? 1,
            'posted_on'        => $validated['posted_on'] ?? now()->toDateString(),
            'status'           => $validated['status'] ?? 'open',
            'created_by'       => $context['user_id'],
            'updated_by'       => $context['user_id'],
        ]));

        return $this->talentResponse($this->hydrate($tenant, $job->id), 'Internal job posted', 201);
    }

    /** PUT /api/talent/mobility/internal-jobs/{id} */
    public function update(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $job = InternalJob::where('sub_institute_id', $tenant)->find($id);

        if (!$job) {
            return $this->talentError('Internal job not found', 404);
        }

        $validated = $request->validate([
            'title'             => 'sometimes|required|string|max:191',
            'department_id'     => 'nullable|integer',
            'location'          => 'nullable|string|max:191',
            'grade'             => 'nullable|string|max:40',
            'description'       => 'nullable|string',
            'eligibility'       => 'nullable|string',
            'positions'         => 'nullable|integer|min:1',
            'hiring_manager_id' => 'nullable|integer',
            'posted_on'         => 'nullable|date',
            'closing_date'      => 'nullable|date',
            'status'            => 'nullable|in:' . implode(',', InternalJob::STATUSES),
        ]);

        $job->fill($validated);
        $job->updated_by = $context['user_id'];
        $job->save();

        return $this->talentResponse($this->hydrate($tenant, $job->id), 'Internal job updated');
    }

    /** DELETE /api/talent/mobility/internal-jobs/{id} */
    public function destroy(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $job = InternalJob::where('sub_institute_id', $tenant)->find($id);

        if (!$job) {
            return $this->talentError('Internal job not found', 404);
        }

        // An applied-to posting is closed, not removed: deleting it would orphan
        // every mobility request pointing at it.
        $hasApplications = DB::table('talent_mobility_requests')
            ->where('internal_job_id', $job->id)
            ->whereNull('deleted_at')
            ->exists();

        if ($hasApplications) {
            return $this->talentError('This posting has applications. Close it instead of deleting it.', 422);
        }

        $job->deleted_by = $context['user_id'];
        $job->save();
        $job->delete();

        return $this->talentResponse(null, 'Internal job deleted');
    }

    /* -- internals -------------------------------------------------------- */

    private function baseQuery(int $tenant, Request $request)
    {
        $status = $this->activeTalentFilter($request->input('status'));
        $departmentId = $this->activeTalentFilter($request->input('department_id'));
        $location = $this->activeTalentFilter($request->input('location'));
        $grade = $this->activeTalentFilter($request->input('grade'));
        $search = $this->activeTalentFilter($request->input('search'));

        return DB::table('talent_internal_jobs as ij')
            ->leftJoin('hrms_departments as d', 'd.id', '=', 'ij.department_id')
            ->leftJoin('talent_mobility_requests as r', function ($join) {
                $join->on('r.internal_job_id', '=', 'ij.id')->whereNull('r.deleted_at');
            })
            ->select('ij.*', 'd.department as department_name', DB::raw('COUNT(DISTINCT r.id) as applications'))
            ->where('ij.sub_institute_id', $tenant)
            ->whereNull('ij.deleted_at')
            ->groupBy('ij.id', 'd.department')
            ->when($status, fn ($q) => $q->where('ij.status', $status))
            ->when($departmentId, fn ($q) => $q->where('ij.department_id', $departmentId))
            ->when($location, fn ($q) => $q->where('ij.location', $location))
            ->when($grade, fn ($q) => $q->where('ij.grade', $grade))
            ->when($search, fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('ij.title', 'like', "%{$search}%")
                    ->orWhere('ij.job_code', 'like', "%{$search}%");
            }));
    }

    private function hydrate(int $tenant, $id): ?array
    {
        $row = $this->baseQuery($tenant, new Request())->where('ij.id', $id)->first();

        return $row ? $this->present($row) : null;
    }

    private function present($row): array
    {
        return [
            'id'                 => (int) $row->id,
            'job_code'           => $row->job_code,
            'title'              => $row->title,
            'department_id'      => $row->department_id ? (int) $row->department_id : null,
            'department'         => $row->department_name,
            'location'           => $row->location,
            'grade'              => $row->grade,
            'description'        => $row->description,
            'eligibility'        => $row->eligibility,
            'positions'          => (int) $row->positions,
            'hiring_manager_id'  => $row->hiring_manager_id ? (int) $row->hiring_manager_id : null,
            'posted_on'          => $row->posted_on,
            'posted_on_label'    => $this->talentDateLabel($row->posted_on),
            'closing_date'       => $row->closing_date,
            'applications'       => (int) ($row->applications ?? 0),
            'status'             => $row->status,
            'status_label'       => $this->talentLabel($row->status),
            'created_at'         => $row->created_at,
            'updated_at'         => $row->updated_at,
        ];
    }
}
