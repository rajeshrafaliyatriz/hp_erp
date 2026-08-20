<?php

namespace App\Http\Controllers\HRMS;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Shared CRUD for the three per-department content types: SOPs, Policies and
 * Rules.
 *
 * All three tabs shipped as mock arrays in the React bundle - same four or five
 * records for every department in every tenant, every edit discarded on tab
 * switch. Giving them real storage means three near-identical controllers:
 * same tenant resolution, same department ownership check, same soft delete,
 * differing only in table name and which columns are writable.
 *
 * Written once here. The subclasses declare the table, the writable columns and
 * the validation rules, and nothing else - so a fix to the ownership check or
 * the soft-delete shape lands in all three at once, which is precisely what
 * three copy-pasted controllers would not do.
 *
 * TENANT COMES FROM THE TOKEN, never the request - see ResolvesApiIdentity and
 * the note at the top of DepartmentManagementController. Every query here is
 * bounded by BOTH sub_institute_id and department_id: the department is itself
 * verified to belong to the caller first, so the tenant filter is redundant by
 * design rather than by accident.
 */
abstract class DepartmentContentController extends Controller
{
    use ResolvesApiIdentity;

    /** The table this controller owns. */
    abstract protected function table(): string;

    /** Columns a client may write, beyond the ones every type shares. */
    abstract protected function writableColumns(): array;

    /** Validation rules, keyed by column. */
    abstract protected function rules(bool $creating): array;

    /** Human name used in response messages. */
    abstract protected function label(): string;

    /**
     * Records for one department.
     */
    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $departmentId = (int) $request->query('department_id');

        if ($departmentId <= 0) {
            return response()->json(['status' => 0, 'message' => 'department_id is required'], 422);
        }

        if (!$this->departmentBelongsToTenant($departmentId, $tenantId)) {
            return response()->json(['status' => 0, 'message' => 'Department not found'], 404);
        }

        $query = DB::table($this->table())
            ->where('department_id', $departmentId)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at');

        if ($status = trim((string) $request->query('status', ''))) {
            $query->where('status', $status);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%' . $search . '%')
                  ->orWhere('code', 'like', '%' . $search . '%');
            });
        }

        return response()->json([
            'status' => 1,
            'data'   => $query->orderByDesc('updated_at')->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $validator = Validator::make($request->all(), array_merge(
            ['department_id' => 'required|integer'],
            $this->rules(true)
        ));

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $departmentId = (int) $request->input('department_id');

        if (!$this->departmentBelongsToTenant($departmentId, $tenantId)) {
            return response()->json(['status' => 0, 'message' => 'Department not found'], 404);
        }

        $row = $this->collectWritable($request, true);

        $row['department_id']    = $departmentId;
        $row['sub_institute_id'] = $tenantId;
        $row['created_by']       = $actorId;
        $row['created_at']       = now();
        $row['updated_at']       = now();

        $id = DB::table($this->table())->insertGetId($row);

        return response()->json([
            'status'  => 1,
            'message' => $this->label() . ' created successfully',
            'data'    => DB::table($this->table())->where('id', $id)->first(),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $existing = $this->findForTenant($id, $tenantId);
        if (!$existing) {
            return response()->json(['status' => 0, 'message' => $this->label() . ' not found'], 404);
        }

        $validator = Validator::make($request->all(), $this->rules(false));

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $updates = $this->collectWritable($request, false);

        // A request that names no writable field is a no-op, not an error, but
        // it must not bump updated_at and claim something changed.
        if ($updates === []) {
            return response()->json(['status' => 1, 'message' => 'Nothing to update']);
        }

        $updates['updated_by'] = $actorId;
        $updates['updated_at'] = now();

        DB::table($this->table())
            ->where('id', $existing->id)
            ->where('sub_institute_id', $tenantId)
            ->update($updates);

        return response()->json([
            'status'  => 1,
            'message' => $this->label() . ' updated successfully',
            'data'    => DB::table($this->table())->where('id', $existing->id)->first(),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $existing = $this->findForTenant($id, $tenantId);
        if (!$existing) {
            return response()->json(['status' => 0, 'message' => $this->label() . ' not found'], 404);
        }

        DB::table($this->table())
            ->where('id', $existing->id)
            ->where('sub_institute_id', $tenantId)
            ->update([
                'deleted_by' => $actorId,
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['status' => 1, 'message' => $this->label() . ' deleted successfully']);
    }

    // ---------------------------------------------------------------------

    /**
     * Pull only declared columns off the request.
     *
     * On create, absent optional fields fall through to the column default. On
     * update, only fields actually present are touched - so a partial edit
     * cannot blank out the fields the form did not show.
     */
    protected function collectWritable(Request $request, bool $creating): array
    {
        $row = [];

        foreach ($this->writableColumns() as $column) {
            if (!$request->has($column)) {
                continue;
            }

            $value = $request->input($column);

            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }

            $row[$column] = $value;
        }

        if ($creating && !array_key_exists('title', $row)) {
            $row['title'] = trim((string) $request->input('title'));
        }

        return $row;
    }

    protected function findForTenant($id, int $tenantId)
    {
        return DB::table($this->table())
            ->where('id', $id)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    protected function departmentBelongsToTenant(int $departmentId, int $tenantId): bool
    {
        return DB::table('hrms_departments')
            ->where('id', $departmentId)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->exists();
    }
}
