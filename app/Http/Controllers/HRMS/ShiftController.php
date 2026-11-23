<?php

namespace App\Http\Controllers\HRMS;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Shift Master API: /api/shifts.
 *
 * Tenant and actor come from the token, never from the request body, the same
 * as EmployeeDirectoryController. Reads need a valid token (the employee
 * profile and the directory list shifts); writes are gated on profile in
 * routes/api.php.
 *
 * A shift that people are on cannot be deleted - that would silently blank
 * their profile. The response says how many are on it so HR can move them, or
 * set the shift inactive, which hides it from new assignments and leaves
 * existing ones readable.
 */
class ShiftController extends Controller
{
    use ResolvesApiIdentity;

    private const COLUMNS = [
        'id', 'name', 'code', 'start_time', 'end_time', 'is_overnight',
        'break_minutes', 'grace_minutes', 'color', 'description', 'status',
        'created_at', 'updated_at',
    ];

    /** GET /api/shifts  (?status=1 for active only) */
    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $query = DB::table('hrms_shifts as s')
            ->where('s.sub_institute_id', $tenantId)
            ->whereNull('s.deleted_at')
            ->orderBy('s.start_time')
            ->orderBy('s.name');

        if ($request->filled('status')) {
            $query->where('s.status', (int) $request->input('status'));
        }

        $rows = $query->get(array_map(fn ($c) => 's.' . $c, self::COLUMNS));

        // One grouped query for the head-counts rather than one per shift.
        $counts = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereNotNull('shift_id')
            ->groupBy('shift_id')
            ->select('shift_id', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'shift_id');

        $rows = $rows->map(function ($row) use ($counts) {
            $row->employee_count = (int) ($counts[$row->id] ?? 0);
            $row->start_time = substr((string) $row->start_time, 0, 5);
            $row->end_time = substr((string) $row->end_time, 0, 5);

            return $row;
        });

        return response()->json(['status' => 1, 'message' => 'Success', 'data' => $rows]);
    }

    /** POST /api/shifts */
    public function store(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        if ($error = $this->checkNameFree($request, $tenantId, null)) {
            return $error;
        }

        $id = DB::table('hrms_shifts')->insertGetId(array_merge(
            $this->payload($request),
            [
                'sub_institute_id' => $tenantId,
                'created_by' => $identity['user_id'],
                'updated_by' => $identity['user_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ));

        return response()->json([
            'status' => 1,
            'message' => 'Shift created.',
            'data' => $this->find($id, $tenantId),
        ], 201);
    }

    /** PUT /api/shifts/{id} */
    public function update(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        if (!$this->find((int) $id, $tenantId)) {
            return response()->json(['status' => 0, 'message' => 'Shift not found'], 404);
        }

        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        if ($error = $this->checkNameFree($request, $tenantId, (int) $id)) {
            return $error;
        }

        DB::table('hrms_shifts')
            ->where('id', (int) $id)
            ->where('sub_institute_id', $tenantId)
            ->update(array_merge($this->payload($request), [
                'updated_by' => $identity['user_id'],
                'updated_at' => now(),
            ]));

        return response()->json([
            'status' => 1,
            'message' => 'Shift updated.',
            'data' => $this->find((int) $id, $tenantId),
        ]);
    }

    /** DELETE /api/shifts/{id} */
    public function destroy(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        if (!$this->find((int) $id, $tenantId)) {
            return response()->json(['status' => 0, 'message' => 'Shift not found'], 404);
        }

        $assigned = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('shift_id', (int) $id)
            ->count();

        if ($assigned > 0) {
            return response()->json([
                'status' => 0,
                'message' => $assigned . ' employee' . ($assigned === 1 ? ' is' : 's are')
                    . ' on this shift. Move them to another shift first, or set this shift to Inactive instead.',
                'employee_count' => $assigned,
            ], 409);
        }

        DB::table('hrms_shifts')
            ->where('id', (int) $id)
            ->where('sub_institute_id', $tenantId)
            ->update(['deleted_at' => now(), 'updated_by' => $identity['user_id']]);

        return response()->json(['status' => 1, 'message' => 'Shift deleted.']);
    }

    /**
     * POST /api/shifts/assign  { shift_id, employee_ids: [] }
     *
     * Puts a selection of employees on one shift - the Employee Directory's
     * bulk bar. The shift must be an ACTIVE one in the caller's tenant, and only
     * employees in that same tenant are touched, so a forged id list cannot
     * reach another organisation's people.
     */
    public function assign(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'shift_id' => 'required|integer|min:1',
            'employee_ids' => 'required|array|min:1|max:2000',
            'employee_ids.*' => 'integer|min:1',
        ]);
        if ($validator->fails()) {
            return $this->invalid($validator);
        }

        $shift = $this->find((int) $request->input('shift_id'), $tenantId);
        if (!$shift) {
            return response()->json(['status' => 0, 'message' => 'Shift not found'], 404);
        }
        if ((int) $shift->status !== 1) {
            return response()->json([
                'status' => 0,
                'message' => 'This shift is inactive. Set it to Active before putting people on it.',
            ], 422);
        }

        $ids = array_values(array_unique(array_map('intval', $request->input('employee_ids'))));

        $updated = DB::table('tbluser')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereIn('id', $ids)
            ->update(['shift_id' => $shift->id]);

        return response()->json([
            'status' => 1,
            'message' => "Put {$updated} employee" . ($updated === 1 ? '' : 's') . " on {$shift->name}."
                . ($updated < count($ids) ? ' ' . (count($ids) - $updated) . ' could not be found.' : ''),
            'updated' => $updated,
        ]);
    }

    private function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'code' => 'nullable|string|max:20',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'break_minutes' => 'nullable|integer|min:0|max:600',
            'grace_minutes' => 'nullable|integer|min:0|max:240',
            'color' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:1000',
            'status' => 'nullable|in:0,1',
        ];
    }

    /** Only the shift's own fields, with the overnight flag worked out here. */
    private function payload(Request $request): array
    {
        $start = (string) $request->input('start_time');
        $end = (string) $request->input('end_time');

        return [
            'name' => trim((string) $request->input('name')),
            'code' => $request->filled('code') ? trim((string) $request->input('code')) : null,
            'start_time' => $start,
            'end_time' => $end,
            // H:i strings compare correctly as text; equal times read as a full 24h shift.
            'is_overnight' => $end <= $start,
            'break_minutes' => (int) $request->input('break_minutes', 0),
            'grace_minutes' => (int) $request->input('grace_minutes', 0),
            'color' => $request->filled('color') ? $request->input('color') : null,
            'description' => $request->filled('description') ? $request->input('description') : null,
            'status' => (int) $request->input('status', 1),
        ];
    }

    private function checkNameFree(Request $request, int $tenantId, ?int $ignoreId)
    {
        $taken = DB::table('hrms_shifts')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $request->input('name')))])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($taken) {
            return response()->json([
                'status' => 0,
                'message' => 'A shift with this name already exists.',
            ], 422);
        }

        return null;
    }

    private function find(int $id, int $tenantId)
    {
        $row = DB::table('hrms_shifts')
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first(self::COLUMNS);

        if ($row) {
            $row->start_time = substr((string) $row->start_time, 0, 5);
            $row->end_time = substr((string) $row->end_time, 0, 5);
        }

        return $row;
    }

    private function invalid($validator)
    {
        return response()->json([
            'status' => 0,
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422);
    }
}
