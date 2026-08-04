<?php

namespace App\Http\Controllers\Api\Talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Talent\Concerns\ResolvesTalentContext;
use App\Models\talent\MobilityRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Internal applications, transfers, promotions and deputations.
 *
 * Backs the Mobility & Succession screen's request queue and the Talent
 * Dashboard's Mobility KPI card, which reads active requests overall and the
 * internal-application subset separately.
 *
 * `user_id` on the request is the CONTEXT ACTOR - the employee moving travels
 * as `employee_id`, and the decision maker as `reviewed_by`.
 */
class MobilityRequestController extends Controller
{
    use ResolvesTalentContext;

    private const SORTABLE = ['requested_on', 'effective_date', 'status', 'request_type', 'created_at'];

    /** GET /api/talent/mobility/requests */
    public function index(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        ['page' => $page, 'per_page' => $perPage] = $this->talentPaging($request);
        [$sortBy, $sortDir] = $this->talentSort($request, self::SORTABLE, 'requested_on');

        $query = $this->baseQuery($tenant, $request);
        $total = (clone $query)->count('r.id');

        $rows = $query
            ->orderBy('r.' . $sortBy, $sortDir)
            ->orderByDesc('r.id')
            ->forPage($page, $perPage)
            ->get();

        $directory = $this->talentEmployeeDirectory(
            $tenant,
            $rows->pluck('employee_id')->merge($rows->pluck('reviewed_by'))->all()
        );

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

    /** GET /api/talent/mobility/requests/{id} */
    public function show(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $row = $this->baseQuery($tenant, new Request())->where('r.id', $id)->first();

        if (!$row) {
            return $this->talentError('Mobility request not found', 404);
        }

        $directory = $this->talentEmployeeDirectory($tenant, array_filter([$row->employee_id, $row->reviewed_by]));

        return $this->talentResponse($this->present($row, $directory));
    }

    /** POST /api/talent/mobility/requests */
    public function store(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];

        $validated = $request->validate([
            'employee_id'        => 'required|integer',
            'internal_job_id'    => 'nullable|integer',
            'job_posting_id'     => 'nullable|integer',
            'request_type'       => 'nullable|in:' . implode(',', MobilityRequest::TYPES),
            'from_department_id' => 'nullable|integer',
            'to_department_id'   => 'nullable|integer',
            'from_jobrole'       => 'nullable|string|max:191',
            'to_jobrole'         => 'nullable|string|max:191',
            'from_location'      => 'nullable|string|max:191',
            'to_location'        => 'nullable|string|max:191',
            // Grade is not carried on talent_mobility_requests, and free-text
            // notes belong in `reason` - the table has no separate notes column.
            'reason'             => 'nullable|string',
            'requested_on'       => 'nullable|date',
            'effective_date'     => 'nullable|date',
            'reviewed_by'        => 'nullable|integer',
        ]);

        $type = $validated['request_type'] ?? 'internal-application';

        if ($type === 'internal-application') {
            if (empty($validated['internal_job_id'])) {
                return $this->talentError('internal_job_id is required for an internal application', 422);
            }

            $job = DB::table('talent_internal_jobs')
                ->where('sub_institute_id', $tenant)
                ->whereNull('deleted_at')
                ->find($validated['internal_job_id']);

            if (!$job) {
                return $this->talentError('Internal job not found', 404);
            }

            if ($job->status !== 'open') {
                return $this->talentError('This internal job is no longer accepting applications', 422);
            }

            // One application per employee per posting, mirroring how the ATS
            // guards duplicate external applications.
            $duplicate = MobilityRequest::where('sub_institute_id', $tenant)
                ->where('employee_id', $validated['employee_id'])
                ->where('internal_job_id', $validated['internal_job_id'])
                ->whereNotIn('status', ['withdrawn', 'rejected'])
                ->exists();

            if ($duplicate) {
                return $this->talentError('This employee has already applied to this internal job', 422);
            }
        }

        $current = $this->currentPlacement($tenant, (int) $validated['employee_id']);

        $mobilityRequest = MobilityRequest::create(array_merge($validated, [
            'sub_institute_id'   => $tenant,
            'request_type'       => $type,
            'from_department_id' => $validated['from_department_id'] ?? $current['department_id'],
            'from_jobrole'   => $validated['from_jobrole'] ?? $current['designation'],
            'status'             => 'pending',
            'requested_on'     => $validated['requested_on'] ?? now()->toDateString(),
            'created_by'         => $context['user_id'],
            'updated_by'         => $context['user_id'],
        ]));

        return $this->talentResponse($this->hydrate($tenant, $mobilityRequest->id), 'Mobility request created', 201);
    }

    /** PUT /api/talent/mobility/requests/{id} */
    public function update(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $mobilityRequest = MobilityRequest::where('sub_institute_id', $tenant)->find($id);

        if (!$mobilityRequest) {
            return $this->talentError('Mobility request not found', 404);
        }

        if (in_array($mobilityRequest->status, ['approved', 'rejected'], true)) {
            return $this->talentError('A decided request can no longer be edited', 422);
        }

        $validated = $request->validate([
            'request_type'     => 'nullable|in:' . implode(',', MobilityRequest::TYPES),
            'to_department_id' => 'nullable|integer',
            'to_jobrole'       => 'nullable|string|max:191',
            'to_location'      => 'nullable|string|max:191',
            'reason'           => 'nullable|string',
            'effective_date'   => 'nullable|date',
            'reviewed_by'      => 'nullable|integer',
            'status'           => 'nullable|in:pending,in-review,withdrawn',
        ]);

        // approved / rejected are not reachable here on purpose - they go
        // through decide(), which records who decided and when.
        $mobilityRequest->fill($validated);
        $mobilityRequest->updated_by = $context['user_id'];
        $mobilityRequest->save();

        return $this->talentResponse($this->hydrate($tenant, $mobilityRequest->id), 'Mobility request updated');
    }

    /**
     * PUT /api/talent/mobility/requests/{id}/decision
     *
     * Approve or reject. Separate from update() so the decision, the decider
     * and the timestamp are always written together.
     */
    public function decision(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $mobilityRequest = MobilityRequest::where('sub_institute_id', $tenant)->find($id);

        if (!$mobilityRequest) {
            return $this->talentError('Mobility request not found', 404);
        }

        if (in_array($mobilityRequest->status, ['approved', 'rejected', 'withdrawn'], true)) {
            return $this->talentError('This request has already been closed', 422);
        }

        $validated = $request->validate([
            'status'         => 'required|in:approved,rejected',
            'review_note' => 'nullable|string',
            'effective_date' => 'nullable|date',
        ]);

        $mobilityRequest->status = $validated['status'];
        $mobilityRequest->review_note = $validated['review_note'] ?? null;
        $mobilityRequest->effective_date = $validated['effective_date'] ?? $mobilityRequest->effective_date;
        // The decider is the actor, which is exactly what reviewed_by means here.
        $mobilityRequest->reviewed_by = $context['user_id'] ?? $mobilityRequest->reviewed_by;
        $mobilityRequest->reviewed_at = now();
        $mobilityRequest->updated_by = $context['user_id'];
        $mobilityRequest->save();

        return $this->talentResponse(
            $this->hydrate($tenant, $mobilityRequest->id),
            $validated['status'] === 'approved' ? 'Mobility request approved' : 'Mobility request rejected'
        );
    }

    /** DELETE /api/talent/mobility/requests/{id} */
    public function destroy(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $mobilityRequest = MobilityRequest::where('sub_institute_id', $tenant)->find($id);

        if (!$mobilityRequest) {
            return $this->talentError('Mobility request not found', 404);
        }

        $mobilityRequest->deleted_by = $context['user_id'];
        $mobilityRequest->save();
        $mobilityRequest->delete();

        return $this->talentResponse(null, 'Mobility request deleted');
    }

    /* -- internals -------------------------------------------------------- */

    private function baseQuery(int $tenant, Request $request)
    {
        $status = $this->activeTalentFilter($request->input('status'));
        $type = $this->activeTalentFilter($request->input('request_type'));
        $jobId = $this->activeTalentFilter($request->input('internal_job_id'));
        $employeeId = $this->activeTalentFilter($request->input('employee_id'));
        $search = $this->activeTalentFilter($request->input('search'));

        return DB::table('talent_mobility_requests as r')
            ->leftJoin('talent_internal_jobs as ij', 'ij.id', '=', 'r.internal_job_id')
            ->leftJoin('hrms_departments as fd', 'fd.id', '=', 'r.from_department_id')
            ->leftJoin('hrms_departments as td', 'td.id', '=', 'r.to_department_id')
            ->leftJoin('tbluser as u', 'u.id', '=', 'r.employee_id')
            ->select(
                'r.*',
                'ij.title as internal_job_title',
                'ij.job_code as internal_job_code',
                'fd.department as from_department',
                'td.department as to_department'
            )
            ->where('r.sub_institute_id', $tenant)
            ->whereNull('r.deleted_at')
            ->when($status, fn ($q) => $q->where('r.status', $status))
            ->when($type, fn ($q) => $q->where('r.request_type', $type))
            ->when($jobId, fn ($q) => $q->where('r.internal_job_id', $jobId))
            ->when($employeeId, fn ($q) => $q->where('r.employee_id', $employeeId))
            ->when($search, fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('u.first_name', 'like', "%{$search}%")
                    ->orWhere('u.last_name', 'like', "%{$search}%")
                    ->orWhere('ij.title', 'like', "%{$search}%");
            }));
    }

    private function hydrate(int $tenant, $id): ?array
    {
        $row = $this->baseQuery($tenant, new Request())->where('r.id', $id)->first();

        if (!$row) {
            return null;
        }

        return $this->present($row, $this->talentEmployeeDirectory($tenant, array_filter([$row->employee_id, $row->reviewed_by])));
    }

    private function present($row, array $directory): array
    {
        $employee = $directory[(int) $row->employee_id] ?? null;

        return [
            'id'                   => (int) $row->id,
            'employee_id'          => (int) $row->employee_id,
            'employee'             => $employee,
            'employee_name'        => $employee['name'] ?? null,
            'internal_job_id'      => $row->internal_job_id ? (int) $row->internal_job_id : null,
            'internal_job_title'   => $row->internal_job_title,
            'internal_job_code'    => $row->internal_job_code,
            'request_type'         => $row->request_type,
            'request_type_label'   => $this->talentLabel($row->request_type),
            'from_department_id'   => $row->from_department_id ? (int) $row->from_department_id : null,
            'from_department'      => $row->from_department,
            'to_department_id'     => $row->to_department_id ? (int) $row->to_department_id : null,
            'to_department'        => $row->to_department,
            'from_jobrole'     => $row->from_jobrole,
            'to_jobrole'       => $row->to_jobrole,
            'from_location'        => $row->from_location,
            'to_location'          => $row->to_location,
            'from_grade'           => $row->from_grade,
            'to_grade'             => $row->to_grade,
            'reason'               => $row->reason,
            'status'               => $row->status,
            'status_label'         => $this->talentLabel($row->status),
            'requested_on'       => $row->requested_on,
            'requested_on_label' => $this->talentDateLabel($row->requested_on),
            'effective_date'       => $row->effective_date,
            'approver'             => $row->reviewed_by ? ($directory[(int) $row->reviewed_by] ?? null) : null,
            'reviewed_at'           => $row->reviewed_at,
            'review_note'       => $row->review_note,
            'notes'                => $row->notes,
            'created_at'           => $row->created_at,
            'updated_at'           => $row->updated_at,
        ];
    }

    /** The Mobility screen's KPI row. */
    private function summary(int $tenant, Request $request): array
    {
        $scope = fn () => $this->baseQuery($tenant, $request);

        return [
            'total'       => (clone $scope())->count(),
            'pending'     => (clone $scope())->where('r.status', 'pending')->count(),
            'in_review'   => (clone $scope())->where('r.status', 'in-review')->count(),
            'approved'    => (clone $scope())->where('r.status', 'approved')->count(),
            'rejected'    => (clone $scope())->where('r.status', 'rejected')->count(),
            'transfers'   => (clone $scope())->where('r.request_type', 'transfer')->whereIn('r.status', MobilityRequest::ACTIVE_STATUSES)->count(),
            'promotions'  => (clone $scope())->where('r.request_type', 'promotion')->whereIn('r.status', MobilityRequest::ACTIVE_STATUSES)->count(),
            'applications' => (clone $scope())->where('r.request_type', 'internal-application')->count(),
        ];
    }

    /**
     * Where the employee sits today, used to prefill the "from" side.
     *
     * Read from the same masters the Performance module reads - tbluser for the
     * department and org_designation for the title.
     */
    private function currentPlacement(int $tenant, int $employeeId): array
    {
        $departmentId = DB::table('tbluser')
            ->where('sub_institute_id', $tenant)
            ->where('id', $employeeId)
            ->value('department_id');

        $designation = DB::table('org_designation')
            ->where('sub_institute_id', $tenant)
            ->where('user_id', $employeeId)
            ->value('designation');

        return [
            'department_id' => $departmentId ? (int) $departmentId : null,
            'designation'   => $designation,
        ];
    }
}
