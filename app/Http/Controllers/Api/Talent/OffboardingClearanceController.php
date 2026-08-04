<?php

namespace App\Http\Controllers\Api\Talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Talent\Concerns\ResolvesTalentContext;
use App\Models\talent\OffboardingClearance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Per-department clearance sign-offs on an exit case.
 *
 * The Talent Dashboard's "Clearances Pending" action item counts these
 * individually - one case commonly carries five or six.
 */
class OffboardingClearanceController extends Controller
{
    use ResolvesTalentContext;

    private const SORTABLE = ['due_date', 'status', 'clearance_type', 'sort_order', 'created_at'];

    /** GET /api/talent/offboarding/clearances */
    public function index(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        ['page' => $page, 'per_page' => $perPage] = $this->talentPaging($request, 50);
        [$sortBy, $sortDir] = $this->talentSort($request, self::SORTABLE, 'sort_order', 'asc');

        $query = $this->baseQuery($tenant, $request);
        $total = (clone $query)->count('cl.id');

        $rows = $query
            ->orderBy('cl.' . $sortBy, $sortDir)
            ->orderByDesc('cl.id')
            ->forPage($page, $perPage)
            ->get();

        $directory = $this->talentEmployeeDirectory(
            $tenant,
            $rows->pluck('owner_id')->merge($rows->pluck('employee_id'))->all()
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
                'summary' => [
                    'pending'     => (clone $this->baseQuery($tenant, $request))->where('cl.status', 'pending')->count(),
                    'in_progress' => (clone $this->baseQuery($tenant, $request))->where('cl.status', 'in-progress')->count(),
                    'cleared'     => (clone $this->baseQuery($tenant, $request))->where('cl.status', 'cleared')->count(),
                ],
            ]
        );
    }

    /** POST /api/talent/offboarding/clearances */
    public function store(Request $request)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];

        $validated = $request->validate([
            'case_id'        => 'required|integer',
            'clearance_type' => 'required|in:' . implode(',', OffboardingClearance::TYPES),
            'title'          => 'required|string|max:191',
            'description'    => 'nullable|string',
            'owner_id'       => 'nullable|integer',
            'owner_label'    => 'nullable|string|max:100',
            'due_date'       => 'nullable|date',
            'status'         => 'nullable|in:' . implode(',', OffboardingClearance::STATUSES),
            'sort_order'     => 'nullable|integer|min:0',
        ]);

        $case = DB::table('talent_offboarding_cases')
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->find($validated['case_id']);

        if (!$case) {
            return $this->talentError('Exit case not found', 404);
        }

        $clearance = OffboardingClearance::create(array_merge($validated, [
            'sub_institute_id' => $tenant,
            'status'           => $validated['status'] ?? 'pending',
            'due_date'         => $validated['due_date'] ?? $case->last_working_day,
            'sort_order'       => $validated['sort_order'] ?? 0,
            'created_by'       => $context['user_id'],
            'updated_by'       => $context['user_id'],
        ]));

        return $this->talentResponse($this->hydrate($tenant, $clearance->id), 'Clearance added', 201);
    }

    /** PUT /api/talent/offboarding/clearances/{id} */
    public function update(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $clearance = OffboardingClearance::where('sub_institute_id', $tenant)->find($id);

        if (!$clearance) {
            return $this->talentError('Clearance not found', 404);
        }

        $validated = $request->validate([
            'clearance_type'  => 'nullable|in:' . implode(',', OffboardingClearance::TYPES),
            'title'           => 'sometimes|required|string|max:191',
            'description'     => 'nullable|string',
            'owner_id'        => 'nullable|integer',
            'owner_label'     => 'nullable|string|max:100',
            'due_date'        => 'nullable|date',
            'status'          => 'nullable|in:' . implode(',', OffboardingClearance::STATUSES),
            'remarks'         => 'nullable|string',
            'recovery_amount' => 'nullable|numeric|min:0',
            'sort_order'      => 'nullable|integer|min:0',
        ]);

        $clearance->fill($validated);

        // cleared_at / cleared_by track the sign-off, so they are written on the
        // transition and cleared if the item is reopened.
        if (array_key_exists('status', $validated)) {
            if ($validated['status'] === 'cleared') {
                $clearance->cleared_at = $clearance->cleared_at ?: now();
                $clearance->cleared_by = $clearance->cleared_by ?: $context['user_id'];
            } elseif (in_array($validated['status'], ['pending', 'in-progress'], true)) {
                $clearance->cleared_at = null;
                $clearance->cleared_by = null;
            }
        }

        $clearance->updated_by = $context['user_id'];
        $clearance->save();

        return $this->talentResponse($this->hydrate($tenant, $clearance->id), 'Clearance updated');
    }

    /**
     * POST /api/talent/offboarding/clearances/{id}/clear
     *
     * The one-click sign-off the register's row action fires.
     */
    public function clear(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $clearance = OffboardingClearance::where('sub_institute_id', $tenant)->find($id);

        if (!$clearance) {
            return $this->talentError('Clearance not found', 404);
        }

        $validated = $request->validate([
            'remarks'         => 'nullable|string',
            'recovery_amount' => 'nullable|numeric|min:0',
        ]);

        $clearance->status = 'cleared';
        $clearance->cleared_at = now();
        $clearance->cleared_by = $context['user_id'];
        $clearance->remarks = $validated['remarks'] ?? $clearance->remarks;
        $clearance->recovery_amount = $validated['recovery_amount'] ?? $clearance->recovery_amount;
        $clearance->updated_by = $context['user_id'];
        $clearance->save();

        return $this->talentResponse($this->hydrate($tenant, $clearance->id), 'Clearance signed off');
    }

    /** DELETE /api/talent/offboarding/clearances/{id} */
    public function destroy(Request $request, $id)
    {
        $context = $this->talentContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = $context['sub_institute_id'];
        $clearance = OffboardingClearance::where('sub_institute_id', $tenant)->find($id);

        if (!$clearance) {
            return $this->talentError('Clearance not found', 404);
        }

        $clearance->deleted_by = $context['user_id'];
        $clearance->save();
        $clearance->delete();

        return $this->talentResponse(null, 'Clearance deleted');
    }

    /* -- internals -------------------------------------------------------- */

    private function baseQuery(int $tenant, Request $request)
    {
        $caseId = $this->activeTalentFilter($request->input('case_id'));
        $status = $this->activeTalentFilter($request->input('status'));
        $type = $this->activeTalentFilter($request->input('clearance_type'));
        $ownerId = $this->activeTalentFilter($request->input('owner_id'));

        return DB::table('talent_offboarding_clearances as cl')
            ->join('talent_offboarding_cases as c', 'c.id', '=', 'cl.case_id')
            ->select('cl.*', 'c.case_code', 'c.employee_id', 'c.last_working_day')
            ->where('cl.sub_institute_id', $tenant)
            ->whereNull('cl.deleted_at')
            ->whereNull('c.deleted_at')
            ->when($caseId, fn ($q) => $q->where('cl.case_id', $caseId))
            ->when($status, fn ($q) => $q->where('cl.status', $status))
            ->when($type, fn ($q) => $q->where('cl.clearance_type', $type))
            ->when($ownerId, fn ($q) => $q->where('cl.owner_id', $ownerId));
    }

    private function hydrate(int $tenant, $id): ?array
    {
        $row = $this->baseQuery($tenant, new Request())->where('cl.id', $id)->first();

        if (!$row) {
            return null;
        }

        return $this->present($row, $this->talentEmployeeDirectory($tenant, array_filter([$row->owner_id, $row->employee_id])));
    }

    private function present($row, array $directory): array
    {
        return [
            'id'              => (int) $row->id,
            'case_id'         => (int) $row->case_id,
            'case_code'       => $row->case_code ?? null,
            'employee'        => isset($row->employee_id) ? ($directory[(int) $row->employee_id] ?? null) : null,
            'clearance_type'  => $row->clearance_type,
            'clearance_label' => $this->talentLabel($row->clearance_type),
            'title'           => $row->title,
            'description'     => $row->description,
            'owner_id'        => $row->owner_id ? (int) $row->owner_id : null,
            'owner'           => $row->owner_id ? ($directory[(int) $row->owner_id] ?? null) : null,
            'owner_label'     => $row->owner_label ?: ($row->owner_id ? ($directory[(int) $row->owner_id]['name'] ?? null) : null),
            'due_date'        => $row->due_date,
            'due_date_label'  => $this->talentDateLabel($row->due_date),
            'status'          => $row->status,
            'status_label'    => $this->talentLabel($row->status),
            'cleared_at'      => $row->cleared_at,
            'remarks'         => $row->remarks,
            'recovery_amount' => $row->recovery_amount !== null ? (float) $row->recovery_amount : null,
            'sort_order'      => (int) $row->sort_order,
        ];
    }
}
