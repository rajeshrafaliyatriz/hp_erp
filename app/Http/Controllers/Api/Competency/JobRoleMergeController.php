<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Services\Org\JobRoleMergeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Merging two job roles into one.
 *
 * The engine is JobRoleMergeService; this decides whether a merge is ALLOWED,
 * which is a separate question and the one the tenant rule lives in.
 *
 * THE SAME-DEPARTMENT RULE IS ENFORCED HERE, not only in the UI. The dialog
 * lists one department's roles, so it cannot offer a cross-department merge -
 * but a dialog is not a guarantee, and merging a role into another department's
 * role would rewrite name-keyed rows that no longer have any department that
 * can claim them.
 */
class JobRoleMergeController extends Controller
{
    use ResolvesApiIdentity;

    public function __construct(private readonly JobRoleMergeService $merges)
    {
    }

    /**
     * What a merge would do, before it does it.
     *
     * Takes a `target_id`, unlike the department equivalent. For a job role the
     * collisions decide what the merge MEANS - which proficiency level the
     * surviving role requires - so a preview without a target would be showing
     * a number while hiding the decision.
     */
    public function impact(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = $identity['sub_institute_id'];

        $source = $this->merges->role(DB::connection(), $id, $tenantId);
        if (!$source) {
            return response()->json(['status' => 0, 'message' => 'Job role not found.'], 404);
        }

        $targetId = $request->filled('target_id') ? (int) $request->input('target_id') : null;
        $target = $targetId ? $this->merges->role(DB::connection(), $targetId, $tenantId) : null;

        if ($targetId && !$target) {
            return response()->json(['status' => 0, 'message' => 'Target job role not found.'], 404);
        }

        if ($target && ($error = $this->rejectIllegalPair($source, $target))) {
            return $error;
        }

        $impact = $this->merges->impact($id, $targetId, $tenantId);

        return response()->json([
            'status'  => 1,
            'message' => 'Job role merge impact retrieved successfully.',
            'data'    => $impact + [
                'jobrole'    => $source->jobrole,
                'department' => $source->department,
                'target'     => $target?->jobrole,
            ],
        ]);
    }

    /**
     * Merge this role into another, or into a new one.
     *
     * Two shapes, one endpoint, because they are the same operation with a
     * different survivor:
     *
     *   {"target_jobrole_id": 42}                       into an existing role
     *   {"new_jobrole_name": "Full Stack Engineer",
     *    "source_jobrole_ids": [11, 12]}                into a role created now
     */
    public function merge(Request $request, int $id)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $tenantId = $identity['sub_institute_id'];
        $actorId  = $identity['user_id'];

        $validator = Validator::make($request->all(), [
            'target_jobrole_id'   => 'required_without:new_jobrole_name|integer|min:1',
            'new_jobrole_name'    => 'required_without:target_jobrole_id|string|max:191',
            'source_jobrole_ids'  => 'sometimes|array',
            'source_jobrole_ids.*' => 'integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 0, 'message' => 'Validation failed', 'errors' => $validator->errors(),
            ], 422);
        }

        $db = DB::connection();

        $source = $this->merges->role($db, $id, $tenantId);
        if (!$source) {
            return response()->json(['status' => 0, 'message' => 'Job role not found.'], 404);
        }

        return $request->filled('new_jobrole_name')
            ? $this->mergeIntoNew($request, $db, $source, $tenantId, $actorId)
            : $this->mergeIntoExisting($request, $db, $source, $tenantId, $actorId);
    }

    private function mergeIntoExisting(Request $request, $db, object $source, int $tenantId, ?int $actorId)
    {
        $targetId = (int) $request->input('target_jobrole_id');
        $target = $this->merges->role($db, $targetId, $tenantId);

        if (!$target) {
            return response()->json(['status' => 0, 'message' => 'Target job role not found.'], 422);
        }

        if ($error = $this->rejectIllegalPair($source, $target)) {
            return $error;
        }

        $result = $this->merges->merge((int) $source->id, $targetId, $tenantId, $actorId);
        $this->record($tenantId, $actorId, $source, $target, $result);

        return response()->json([
            'status'  => 1,
            'message' => $this->summarise($source->jobrole, $target->jobrole, $result),
            'data'    => $result + ['target_jobrole_id' => $targetId],
        ]);
    }

    private function mergeIntoNew(Request $request, $db, object $source, int $tenantId, ?int $actorId)
    {
        // The role this route was called on is always one of the sources; any
        // others come from the body. Order matters - the first source seeds the
        // new role's description and level.
        $sourceIds = array_values(array_unique(array_merge(
            [(int) $source->id],
            array_map('intval', $request->input('source_jobrole_ids', []))
        )));

        $roles = [];
        foreach ($sourceIds as $sourceId) {
            $role = $this->merges->role($db, $sourceId, $tenantId);
            if (!$role) {
                return response()->json([
                    'status' => 0, 'message' => 'One of the job roles to merge is not in this organisation.',
                ], 422);
            }
            if ($error = $this->rejectIllegalPair($source, $role, true)) {
                return $error;
            }
            $roles[] = $role;
        }

        if (count($roles) < 2) {
            return response()->json([
                'status'  => 0,
                'message' => 'Merging into a new job role needs at least two roles to merge.',
            ], 422);
        }

        $name = trim((string) $request->input('new_jobrole_name'));
        $outcome = $this->merges->mergeIntoNew($sourceIds, $name, $tenantId, $actorId);

        $employees = array_sum(array_column($outcome['results'], 'employees'));
        $this->record($tenantId, $actorId, $source, (object) [
            'id' => $outcome['new_role_id'], 'jobrole' => $name,
        ], ['moved' => [], 'employees' => $employees] + $outcome['results'][0]);

        return response()->json([
            'status'  => 1,
            'message' => sprintf(
                '%d job roles merged into the new role "%s". %d employee(s) moved.',
                count($sourceIds), $name, $employees
            ),
            'data'    => $outcome,
        ]);
    }

    /**
     * The guards, in one place so impact() and merge() cannot disagree about
     * what is legal.
     *
     * @param bool $allowSame the new-role path compares a role against itself
     *                        while walking the source list
     */
    private function rejectIllegalPair(object $source, object $target, bool $allowSame = false)
    {
        if (!$allowSame && (int) $source->id === (int) $target->id) {
            return response()->json([
                'status' => 0, 'message' => 'A job role cannot be merged into itself.',
            ], 422);
        }

        /*
         * SAME DEPARTMENT ONLY.
         *
         * department_id is authoritative; the `department` name is the fallback
         * for the 2 live rows that have no id. The two agree on 4,884 of 4,886
         * live rows, so the fallback is safe - but a role with NEITHER is
         * refused rather than guessed, because there is then nothing to compare.
         */
        $sourceDept = (int) ($source->department_id ?? 0);
        $targetDept = (int) ($target->department_id ?? 0);

        if ($sourceDept > 0 && $targetDept > 0) {
            if ($sourceDept !== $targetDept) {
                return $this->crossDepartment($source, $target);
            }
            return null;
        }

        $sourceName = mb_strtolower(trim((string) ($source->department ?? '')));
        $targetName = mb_strtolower(trim((string) ($target->department ?? '')));

        if ($sourceName === '' || $targetName === '') {
            return response()->json([
                'status'  => 0,
                'message' => sprintf(
                    'Job role "%s" has no department recorded, so it cannot be checked against "%s". Set its department first.',
                    $sourceName === '' ? $source->jobrole : $target->jobrole,
                    $sourceName === '' ? $target->jobrole : $source->jobrole
                ),
            ], 422);
        }

        return $sourceName === $targetName ? null : $this->crossDepartment($source, $target);
    }

    private function crossDepartment(object $source, object $target)
    {
        return response()->json([
            'status'  => 0,
            'message' => sprintf(
                'Job roles can only be merged within the same department. "%s" is in %s and "%s" is in %s.',
                $source->jobrole, $source->department ?: 'no department',
                $target->jobrole, $target->department ?: 'no department'
            ),
        ], 422);
    }

    /** The backend's own sentence, so the UI does not have to invent one. */
    private function summarise(string $from, string $to, array $result): string
    {
        $parts = [sprintf('"%s" merged into "%s".', $from, $to)];

        if ($result['employees']) {
            $parts[] = sprintf('%d employee(s) moved.', $result['employees']);
        }
        if ($result['tasks_folded'] || $result['skills_folded']) {
            $parts[] = sprintf('%d duplicate task(s) and %d duplicate skill(s) folded.',
                $result['tasks_folded'], $result['skills_folded']);
        }
        if ($result['competencies_raised'] || $result['skills_raised']) {
            $parts[] = sprintf('%d competency and %d skill requirement(s) raised to the higher level.',
                $result['competencies_raised'], $result['skills_raised']);
        }
        if ($result['ambiguous']) {
            $parts[] = sprintf('%d row(s) were left unchanged because the old role name is used in more than one department.',
                array_sum(array_column($result['ambiguous'], 'count')));
        }

        return implode(' ', $parts);
    }

    /** Audit never fails a merge that already committed. */
    private function record(int $tenantId, ?int $actorId, object $source, object $target, array $result): void
    {
        try {
            app(\App\Services\Events\EventRecorder::class)->record(
                'jobrole.merged',
                $tenantId,
                'jobrole',
                (int) $source->id,
                $actorId,
                [
                    'from'      => ['id' => (int) $source->id, 'name' => $source->jobrole],
                    'to'        => ['id' => (int) $target->id, 'name' => $target->jobrole],
                    'employees' => $result['employees'] ?? 0,
                    'moved'     => $result['moved'] ?? [],
                    'ambiguous' => $result['ambiguous'] ?? [],
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Job role merge committed but not recorded in the event store', [
                'from'  => $source->id,
                'to'    => $target->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
