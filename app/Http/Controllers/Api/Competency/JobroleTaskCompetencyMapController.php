<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * L-14 — the TASK CATALOGUE -> COMPETENCY write path.
 *
 * Mirrors `RoleCompetencyMapController` one level down: that maps a JOB ROLE to
 * competencies, this maps a JOB ROLE TASK to competencies. Same tenant column,
 * same store/destroy contract, same rule that the tenant comes from identity and
 * never from a request field.
 *
 * ── ⚠ 0 ROWS IS THE EXPECTED STATE, NOT AN UNFINISHED BUILD ────────────────
 *
 * `jobrole_task_competency_map` holds **0 rows and should**. This link is the
 * CATALOGUE half of Q-E1, and the catalogue must be **AUTHORED BY A CUSTOMER, NOT
 * DERIVED**. Deriving its first content from the instance link (`task.skill_id`,
 * 1,514 of 2,271) was tried and refuted: a derived catalogue is not an authority,
 * it is the instance wearing an authority's name, and every later override would
 * be measured against a claim nobody made.
 *
 * **So this controller is a BUILD whose CONTENT is an AUTHORING DEPENDENCY.**
 * Shipping it does not fill the table. It stays empty until a customer maps their
 * first task, and an empty table here is the product working correctly - the same
 * shape as X-08's importer.
 *
 * **Do not read 0 rows as work remaining.**
 *
 * ── ⚠ DECLARED REFERENT: `jobrole_task_id` -> `s_jobrole_task` ─────────────
 *
 * `s_jobrole_task` has **55,961 rows and NO `sub_institute_id`**. It is a GLOBAL
 * seed library shared by every tenant (Q-C1), and this map is the tenant-owned
 * mapping onto it — global library, tenant mapping, which is the pattern where
 * the referent is least obvious and most often guessed.
 *
 * Two consequences that are easy to get wrong:
 *
 *   1. **A task id CANNOT be validated against the caller's tenant**, because the
 *      library has no tenant. The check below confirms the task EXISTS; it cannot
 *      confirm it is "theirs", because nobody owns it.
 *   2. **Anything aggregated over this map is tenant-scoped only through
 *      `sub_institute_id` ON THIS TABLE.** A join that reaches `s_jobrole_task`
 *      and stops there has left the tenant behind — which is exactly why the
 *      `task_competency_link` readiness gate was dropped: it measured the global
 *      side and would have read identically for every tenant.
 *
 * The competency side IS tenant-checked, because `competency` is tenant-owned.
 */
class JobroleTaskCompetencyMapController extends Controller
{
    use ResolvesCompetencyContext;

    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];
        $taskId = $request->integer('jobrole_task_id');

        $q = DB::table('jobrole_task_competency_map as m')
            ->join('competency as c', 'c.id', '=', 'm.competency_id')
            // s_jobrole_task is GLOBAL - joined for its label only, never for scope.
            ->leftJoin('s_jobrole_task as t', 't.id', '=', 'm.jobrole_task_id')
            ->where('m.sub_institute_id', $sid)
            ->whereNull('c.deleted_at');

        if ($taskId) {
            $q->where('m.jobrole_task_id', $taskId);
        }

        $rows = $q->orderBy('m.id')
            ->get(['m.id', 'm.jobrole_task_id', 'm.competency_id', 'c.name as competency_name', 't.task as task_name']);

        return response()->json([
            'status' => 1,
            'data'   => $rows,
            // Stated in the payload, not only in a comment: a caller rendering an
            // empty list should say "none mapped yet", never "no data".
            'note'   => $rows->isEmpty()
                ? 'No task-to-competency mappings authored yet. This catalogue is filled by your organisation, not derived.'
                : null,
        ]);
    }

    public function store(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'jobrole_task_id'       => 'required|integer',
            'items'                 => 'required|array|min:1',
            'items.*.competency_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid    = $context['sub_institute_id'];
        $taskId = $request->integer('jobrole_task_id');

        // EXISTS, not OWNS. s_jobrole_task is global - see the class comment.
        // Checking tenancy here would be checking a column that is not there.
        if (!DB::table('s_jobrole_task')->where('id', $taskId)->exists()) {
            return response()->json(['status' => 0, 'message' => 'Job role task not found.'], 404);
        }

        // A competency repeated in one request is user-trippable, so it is caught
        // before the write rather than surfacing as a constraint error.
        $seen = [];
        foreach ($request->input('items') as $i => $item) {
            $cid = (int) $item['competency_id'];
            if (isset($seen[$cid])) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'Item ' . ($i + 1) . ' repeats a competency already in this list.',
                ], 422);
            }
            $seen[$cid] = true;
        }

        // Every competency must exist in the CALLER'S OWN tenant. Reported as one
        // message rather than failing on the first, so the whole list is fixable
        // in one pass.
        $valid = DB::table('competency')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereIn('id', array_keys($seen))
            ->pluck('id')
            ->all();

        $missing = array_diff(array_keys($seen), $valid);
        if ($missing) {
            return response()->json([
                'status'  => 0,
                'message' => 'Competency not found in this organisation: ' . implode(', ', $missing),
            ], 422);
        }

        // CHANGED FROM APPEND TO SYNC 2026-08-13, and the reason is the same one
        // the course map carries: A COMPETENCY REMOVED FROM A TASK MUST STOP
        // COUNTING. An append-only writer gives a user no way to unsay something -
        // the row would survive every later save and keep contributing to the
        // task's competency signal with nothing in the UI able to remove it.
        //
        // SAFE TO CHANGE: the table holds 0 rows, so no existing mapping depends
        // on the old behaviour. Left as append it would have shipped a third
        // writer whose save button means something different from the other two.
        //
        // Rows absent from `items` are deleted for THIS task and THIS tenant,
        // never wider.
        $now = now();
        $result = DB::transaction(function () use ($seen, $sid, $taskId, $now) {
            $removed = DB::table('jobrole_task_competency_map')
                ->where('sub_institute_id', $sid)
                ->where('jobrole_task_id', $taskId)
                ->whereNotIn('competency_id', array_keys($seen))
                ->delete();

            foreach (array_keys($seen) as $cid) {
                DB::table('jobrole_task_competency_map')->updateOrInsert(
                    ['sub_institute_id' => $sid, 'jobrole_task_id' => $taskId, 'competency_id' => $cid],
                    ['updated_at' => $now, 'created_at' => $now]
                );
            }

            return $removed;
        });

        $count = DB::table('jobrole_task_competency_map')
            ->where('sub_institute_id', $sid)->where('jobrole_task_id', $taskId)->count();

        return response()->json([
            'status'  => 1,
            'message' => 'Saved.',
            'mapped'  => $count,
            // Reported so the screen can say it in words: a silent deletion is
            // worse than no deletion, and this endpoint now deletes.
            'removed' => $result,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        // Scoped by tenant on the DELETE itself, not checked and then deleted -
        // one statement cannot race with another tenant's row.
        $deleted = DB::table('jobrole_task_competency_map')
            ->where('id', (int) $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->delete();

        if (!$deleted) {
            return response()->json(['status' => 0, 'message' => 'Mapping not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Removed.']);
    }

    /**
     * GET /competency/task-map/roles          - the catalogue's job roles
     * GET /competency/task-map/tasks?jobrole= - one role's tasks + what each maps to
     *
     * WHY A LIST ENDPOINT AND NOT A FILTER ON index()
     *
     * `index()` answers "what is mapped", filtered by one task id. A panel needs
     * the other question: "what tasks does this role have, and which of them are
     * mapped yet" - including the UNMAPPED ones, which by definition have no row
     * in the map and therefore cannot appear in a query over it.
     *
     * SCOPED PER JOB ROLE because the map keys on jobrole_task_id and THE JOB ROLE
     * IS ALREADY IN THE KEY. A flat picker would ask the user to search 55,961
     * rows the data model has already partitioned.
     *
     * NO PAGINATION, DELIBERATELY. Measured on the DECLARED REFERENT
     * (`s_jobrole_task`, not the tenant table): 2,761 roles, median 19 tasks,
     * p90 31, max 209, and only SEVEN roles above 100. A scrollable list with a
     * count is the honest form; a pager for seven roles is furniture. The count
     * is returned so the screen can say how many rather than implying it showed
     * everything.
     *
     * `s_jobrole_task` IS GLOBAL - no `sub_institute_id`. The ROLE LIST is
     * therefore the same for every tenant, and only the MAP rows are
     * tenant-scoped. That asymmetry is Q-C1's pattern and is stated here because
     * a reader who assumes both are scoped will not understand the join.
     */
    public function roles(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $rows = DB::table('s_jobrole_task')
            ->select('jobrole')
            ->selectRaw('COUNT(*) as task_count')
            ->whereNotNull('jobrole')->where('jobrole', '!=', '')
            ->groupBy('jobrole')
            ->orderBy('jobrole')
            ->get();

        return response()->json([
            'status' => 1,
            'data'   => $rows->map(fn ($r) => [
                'jobrole'    => $r->jobrole,
                'task_count' => (int) $r->task_count,
            ])->values(),
        ]);
    }

    public function tasks(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), ['jobrole' => 'required|string|max:191']);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid     = (int) $context['sub_institute_id'];
        $jobrole = (string) $request->input('jobrole');

        // The catalogue side is GLOBAL; the map side is the caller's tenant only.
        // A LEFT JOIN, because an unmapped task is the normal case and must still
        // appear - it is the whole point of the screen.
        $rows = DB::table('s_jobrole_task as t')
            ->leftJoin('jobrole_task_competency_map as m', function ($j) use ($sid) {
                $j->on('m.jobrole_task_id', '=', 't.id')
                  ->where('m.sub_institute_id', '=', $sid);
            })
            ->leftJoin('competency as c', function ($j) {
                $j->on('c.id', '=', 'm.competency_id')->whereNull('c.deleted_at');
            })
            ->where('t.jobrole', $jobrole)
            ->orderBy('t.task')
            ->get(['t.id as jobrole_task_id', 't.task', 't.critical_work_function',
                   'm.id as map_id', 'm.competency_id', 'c.name as competency_name']);

        // Fold to one entry per task, each carrying its competencies.
        $byTask = [];
        foreach ($rows as $r) {
            $k = (int) $r->jobrole_task_id;
            if (!isset($byTask[$k])) {
                $byTask[$k] = [
                    'jobrole_task_id'        => $k,
                    'task'                   => $r->task,
                    'critical_work_function' => $r->critical_work_function,
                    'competencies'           => [],
                ];
            }
            if ($r->map_id !== null) {
                $byTask[$k]['competencies'][] = [
                    'map_id'          => (int) $r->map_id,
                    'competency_id'   => (int) $r->competency_id,
                    'competency_name' => $r->competency_name,
                ];
            }
        }
        $data = array_values($byTask);
        $mapped = count(array_filter($data, fn ($t) => $t['competencies'] !== []));

        return response()->json([
            'status' => 1,
            'data'   => $data,
            'counts' => [
                'tasks'    => count($data),
                'mapped'   => $mapped,
                'unmapped' => count($data) - $mapped,
            ],
            // L-14's note, kept: nothing mapped is the EXPECTED state for a
            // catalogue a customer has not authored against yet, and the payload
            // says so rather than leaving a screen to infer it from zeroes.
            'empty_is_expected' => $mapped === 0,
        ]);
    }
}
