<?php

namespace App\Http\Controllers\Api\Leave;

use App\Http\Controllers\Api\Leave\Concerns\ResolvesLeaveAuthority;
use App\Http\Controllers\Api\Leave\Concerns\ResolvesLeaveContext;
use App\Http\Controllers\Controller;
use App\Services\Leave\LeaveAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Leave entitlement - the module's missing front door. F-96.
 *
 * Entitlement is the number every balance, every "remaining", and every
 * over-application check depends on. It lives in hrms_leave_allocation, and on
 * the live database that table holds ONE ROW FOR THE WHOLE PLATFORM:
 *
 *   {"id":1,"employee_id":null,"department_id":572,"leave_type_id":10,
 *    "year":"2024","value":12,"sub_institute_id":1}
 *
 * Tenants 3, 6 and 7 have none. The only writer anywhere in the codebase was a
 * private helper, LeaveTypeApiController::syncAllocation(), firing as a side
 * effect of saving a leave type. There was no screen.
 *
 * So GET /api/leave/balances answered, for a real administrator on tenant 3:
 *
 *   {"overall":{"total":0,"used":7,"remaining":0}}
 *
 * - seven days taken against an entitlement of zero, with zero remaining. Every
 * balance the product showed was meaningless, and no balance rule could be
 * written until somebody could set one.
 *
 * SHAPE. A grant is (year, leave type, department) with an optional per-employee
 * override, which is what LeaveAnalyticsService::entitlementByType() already
 * reads. This controller does not invent a new shape; it gives the existing one
 * a way in.
 */
class LeaveAllocationApiController extends Controller
{
    use ResolvesLeaveContext;
    use ResolvesLeaveAuthority;

    public function __construct(private LeaveAnalyticsService $analytics)
    {
    }

    /**
     * GET /api/leave/allocations
     *
     * The grid the screen edits: one row per department x leave type for the
     * year, plus any per-employee overrides.
     */
    public function index(Request $request)
    {
        $context = $this->leaveContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = (int) $context['sub_institute_id'];
        $year   = (int) $context['year'];

        $leaveTypes = $this->analytics->leaveTypes($tenant)->map(fn ($type) => [
            'value' => (string) $type->id,
            'label' => $type->leave_type,
            'code'  => $type->leave_type_id,
        ])->values();

        $departments = DB::table('hrms_departments')
            ->where('sub_institute_id', $tenant)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->orderBy('department')
            ->get(['id', 'department'])
            ->map(fn ($row) => ['value' => (string) $row->id, 'label' => $row->department])
            ->values();

        $rows = DB::table('hrms_leave_allocation')
            ->where('sub_institute_id', $tenant)
            ->where('year', $year)
            ->whereNull('deleted_at')
            ->get(['id', 'department_id', 'employee_id', 'leave_type_id', 'value']);

        // department_id => leave_type_id => days
        $grid = [];
        $overrides = [];

        foreach ($rows as $row) {
            if (empty($row->employee_id)) {
                $grid[(string) $row->department_id][(string) $row->leave_type_id] = (float) $row->value;
                continue;
            }

            $overrides[] = [
                'id'            => (int) $row->id,
                'employee_id'   => (int) $row->employee_id,
                'leave_type_id' => (int) $row->leave_type_id,
                'value'         => (float) $row->value,
            ];
        }

        // How many people each grant actually covers, so the screen can say
        // "12 days x 34 people" rather than showing a number with no weight.
        $headcount = DB::table('tbluser')
            ->where('sub_institute_id', $tenant)
            ->where('status', 1)
            ->whereNotNull('department_id')
            ->groupBy('department_id')
            ->pluck(DB::raw('COUNT(*)'), 'department_id')
            ->map(fn ($count) => (int) $count)
            ->all();

        return response()->json([
            'status'  => 1,
            'message' => 'Leave allocations fetched successfully',
            'year'    => $year,
            'data'    => [
                'leave_types' => $leaveTypes,
                'departments' => $departments,
                'grid'        => (object) $grid,
                'overrides'   => $overrides,
                'headcount'   => (object) $headcount,
                'configured'  => $rows->count(),
            ],
        ]);
    }

    /**
     * PUT /api/leave/allocations
     *
     * Saves the whole grid for one year, the way the screen edits it.
     *
     * A cell set to 0 or cleared DELETES its row rather than storing a zero:
     * "no grant" and "a grant of nothing" are the same thing to
     * entitlementByType(), and keeping both makes the table harder to read
     * without changing any answer.
     */
    public function save(Request $request)
    {
        $context = $this->leaveContext($request);
        if (!is_array($context)) {
            return $context;
        }

        if ($denied = $this->denyUnlessLeaveCan($context, 'configure_settings', 'You do not have permission to set leave entitlements.')) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'allocations'                 => 'required|array',
            'allocations.*.department_id' => 'required|integer',
            'allocations.*.leave_type_id' => 'required|integer',
            // 0 is allowed and means "remove the grant". The cap matches
            // LeaveAnalyticsService::MAX_OPENING_LEAVE, which silently clamps
            // anything larger - so it is refused here instead of being accepted
            // and quietly reduced.
            'allocations.*.value'         => 'required|numeric|min:0|max:' . LeaveAnalyticsService::MAX_OPENING_LEAVE,
        ], [
            'allocations.*.value.max' => 'An entitlement cannot exceed '
                . LeaveAnalyticsService::MAX_OPENING_LEAVE . ' days.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $tenant = (int) $context['sub_institute_id'];
        $year   = (int) $context['year'];

        // Everything the caller names must belong to this tenant. Without this
        // the grid is a way to write entitlements into another organisation -
        // the same defect as F-101, one table over.
        $validTypes = DB::table('hrms_leave_types')
            ->where('sub_institute_id', $tenant)->whereNull('deleted_at')->pluck('id')
            ->map(fn ($id) => (int) $id)->all();

        $validDepartments = DB::table('hrms_departments')
            ->where('sub_institute_id', $tenant)->whereNull('deleted_at')->pluck('id')
            ->map(fn ($id) => (int) $id)->all();

        $saved = $removed = 0;

        DB::transaction(function () use ($request, $context, $tenant, $year, $validTypes, $validDepartments, &$saved, &$removed) {
            foreach ($request->input('allocations') as $allocation) {
                $departmentId = (int) $allocation['department_id'];
                $leaveTypeId  = (int) $allocation['leave_type_id'];
                $value        = (float) $allocation['value'];

                if (!in_array($leaveTypeId, $validTypes, true) || !in_array($departmentId, $validDepartments, true)) {
                    continue;
                }

                $existing = DB::table('hrms_leave_allocation')
                    ->where('sub_institute_id', $tenant)
                    ->where('year', $year)
                    ->where('department_id', $departmentId)
                    ->where('leave_type_id', $leaveTypeId)
                    ->whereNull('employee_id')
                    ->whereNull('deleted_at')
                    ->first(['id']);

                if ($value <= 0) {
                    if ($existing) {
                        DB::table('hrms_leave_allocation')->where('id', $existing->id)->update([
                            'deleted_at' => now(),
                            'deleted_by' => $context['user_id'],
                        ]);
                        $removed++;
                    }
                    continue;
                }

                if ($existing) {
                    DB::table('hrms_leave_allocation')->where('id', $existing->id)->update([
                        'value'      => $value,
                        'updated_at' => now(),
                        'updated_by' => $context['user_id'],
                    ]);
                } else {
                    DB::table('hrms_leave_allocation')->insert([
                        'sub_institute_id' => $tenant,
                        'year'             => $year,
                        'department_id'    => $departmentId,
                        'leave_type_id'    => $leaveTypeId,
                        'employee_id'      => null,
                        'value'            => $value,
                        'created_at'       => now(),
                        'created_by'       => $context['user_id'],
                    ]);
                }

                $saved++;
            }
        });

        return response()->json([
            'status'  => 1,
            'message' => $saved . ' entitlement(s) saved'
                . ($removed > 0 ? ', ' . $removed . ' removed' : '') . '.',
            'saved'   => $saved,
            'removed' => $removed,
        ]);
    }
}
