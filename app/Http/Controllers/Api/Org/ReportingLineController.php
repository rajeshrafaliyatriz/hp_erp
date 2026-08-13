<?php

namespace App\Http\Controllers\Api\Org;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Services\Org\ReportingLineValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * X-16 — THE REPORTING-LINE ASSIGNMENT MECHANISM.
 *
 * `ReportingLineValidator` has existed since Foundation 5 with ZERO callers
 * (G-ORG-01), and `reporting_manager_id` was populated on 0 of 387 rows
 * (G-ORG-02). Those are the same fact from two sides: **there was no write path
 * for the validator to be called from.** F-05a ("call canAssign() from every write
 * path") could never be done before F-05b, and the plan had them the other way
 * round.
 *
 * THIS IS THE WRITE PATH. Every mutation here goes through canAssign() - single,
 * bulk, and clear - and there is deliberately no way to set the column without it.
 *
 * WHY A CONTROLLER AND NOT A MIGRATION: a reporting line is a continuing operation,
 * not a one-off backfill. People join, move and leave. A backfill would have filled
 * the column once and left the next change with nowhere to happen.
 */
class ReportingLineController extends Controller
{
    use ResolvesApiIdentity;

    public function __construct(private ReportingLineValidator $validator)
    {
    }

    /**
     * Coverage — the number G-ORG-02 is measured by, as an endpoint rather than a
     * script, so anyone can see it without asking me to run something.
     */
    public function coverage(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $tenant = $identity['sub_institute_id'];

        $total = DB::table('tbluser')->where('sub_institute_id', $tenant)->count();
        $withManager = DB::table('tbluser')->where('sub_institute_id', $tenant)
            ->whereNotNull('reporting_manager_id')->where('reporting_manager_id', '!=', 0)->count();
        $depts = DB::table('hrms_departments')->where('sub_institute_id', $tenant)->count();
        $withHead = DB::table('hrms_departments')->where('sub_institute_id', $tenant)
            ->whereNotNull('head_user_id')->where('head_user_id', '!=', 0)->count();

        // A manager id that does not resolve IN THIS TENANT is worse than a blank
        // one, because it looks populated. Counted separately, never folded in.
        $dangling = DB::table('tbluser as u')
            ->where('u.sub_institute_id', $tenant)
            ->whereNotNull('u.reporting_manager_id')->where('u.reporting_manager_id', '!=', 0)
            ->whereNotExists(function ($q) use ($tenant) {
                $q->select(DB::raw(1))->from('tbluser as m')
                    ->whereColumn('m.id', 'u.reporting_manager_id')
                    ->where('m.sub_institute_id', $tenant);
            })->count();

        return response()->json([
            'status'   => 1,
            'coverage' => [
                'employees'              => $total,
                'with_reporting_manager' => $withManager,
                'percent'                => $total ? round(100 * $withManager / $total, 1) : 0.0,
                'dangling_manager_ids'   => $dangling,
                'departments'            => $depts,
                'with_head'              => $withHead,
            ],
        ]);
    }

    /**
     * Assign one person's manager. `manager_id: null` CLEARS it, which is a
     * legitimate state - the org head reports to nobody.
     */
    public function assign(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $data = $request->validate([
            'user_id'    => 'required|integer|min:1',
            'manager_id' => 'nullable|integer|min:1',
        ]);

        $tenant = $identity['sub_institute_id'];
        $userId = (int) $data['user_id'];
        $managerId = $data['manager_id'] !== null ? (int) $data['manager_id'] : null;

        $result = $this->applyOne($tenant, $userId, $managerId, $identity['user_id']);

        return response()->json(
            ['status' => $result['ok'] ? 1 : 0] + $result,
            $result['ok'] ? 200 : 422
        );
    }

    /**
     * Bulk. ONE ROW'S FAILURE MUST NOT ABORT THE FILE.
     *
     * A bulk import that rolls back on the first bad row is useless against real HR
     * data, where a handful of rows are always wrong. Every row is attempted, every
     * failure is reported with its reason and its index, and the caller gets a
     * per-row verdict rather than one exception.
     */
    public function bulkAssign(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $data = $request->validate([
            'assignments'              => 'required|array|min:1|max:1000',
            'assignments.*.user_id'    => 'required|integer|min:1',
            'assignments.*.manager_id' => 'nullable|integer|min:1',
        ]);

        $tenant = $identity['sub_institute_id'];
        $applied = 0;
        $rows = [];

        foreach ($data['assignments'] as $i => $row) {
            $result = $this->applyOne(
                $tenant,
                (int) $row['user_id'],
                isset($row['manager_id']) && $row['manager_id'] !== null ? (int) $row['manager_id'] : null,
                $identity['user_id']
            );
            if ($result['ok']) {
                $applied++;
            }
            $rows[] = ['index' => $i, 'user_id' => (int) $row['user_id']] + $result;
        }

        return response()->json([
            'status'   => 1,
            'applied'  => $applied,
            'refused'  => count($rows) - $applied,
            'rows'     => $rows,
        ]);
    }

    /**
     * A department's head. Same tenant check, no cycle question - a head is not a
     * reporting line, so canAssign() does not apply and is not pretended to.
     */
    public function setDepartmentHead(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $data = $request->validate([
            'department_id' => 'required|integer|min:1',
            'user_id'       => 'nullable|integer|min:1',
        ]);

        $tenant = $identity['sub_institute_id'];

        $dept = DB::table('hrms_departments')->where('id', (int) $data['department_id'])
            ->where('sub_institute_id', $tenant)->exists();
        if (!$dept) {
            return response()->json(['status' => 0, 'reason' => 'No such department in your organisation.'], 404);
        }

        if ($data['user_id'] !== null && !$this->userInTenant((int) $data['user_id'], $tenant)) {
            return response()->json(['status' => 0, 'reason' => 'That person is not in your organisation.'], 422);
        }

        DB::table('hrms_departments')->where('id', (int) $data['department_id'])
            ->update(['head_user_id' => $data['user_id'] !== null ? (int) $data['user_id'] : null,
                      'updated_at' => now()]);

        return response()->json(['status' => 1, 'department_id' => (int) $data['department_id'],
                                 'head_user_id' => $data['user_id']]);
    }

    // ── the one place the column is written ─────────────────────────────────

    /**
     * @return array{ok:bool, reason:?string}
     */
    private function applyOne(int $tenant, int $userId, ?int $managerId, int $actorId): array
    {
        // BOTH PEOPLE MUST BE IN THE CALLER'S ORGANISATION. Checked before the
        // validator, because canAssign() answers "would this make a cycle?" and
        // not "are these your people?" - a cycle check on a stranger's id would
        // pass and write a cross-tenant reporting line.
        if (!$this->userInTenant($userId, $tenant)) {
            return ['ok' => false, 'reason' => 'That person is not in your organisation.'];
        }
        if ($managerId !== null && !$this->userInTenant($managerId, $tenant)) {
            return ['ok' => false, 'reason' => 'That manager is not in your organisation.'];
        }

        $verdict = $this->validator->canAssign($userId, $managerId);
        if (!$verdict['ok']) {
            return ['ok' => false, 'reason' => $verdict['reason']];
        }

        DB::table('tbluser')->where('id', $userId)->where('sub_institute_id', $tenant)
            ->update(['reporting_manager_id' => $managerId, 'updated_by' => $actorId, 'updated_at' => now()]);

        return ['ok' => true, 'reason' => null];
    }

    private function userInTenant(int $userId, int $tenant): bool
    {
        return DB::table('tbluser')->where('id', $userId)->where('sub_institute_id', $tenant)->exists();
    }
}
