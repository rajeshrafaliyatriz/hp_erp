<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyGap;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Named career paths (s_competency_career_paths / _steps) for the Development &
 * Career Path Workspace's "Career Paths" tab and "Career Path Explorer" card.
 *
 * Two existing sources are read, never duplicated:
 *   - s_user_jobrole      : the role catalog a path's steps point at
 *   - career_journey      : the tenant's role-progression edge graph, used to
 *                           suggest the next roles when authoring a path and as
 *                           the fallback chain when a plan has no named path
 *                           (the same table CareerJourneyController reads and
 *                           the old frontend's Succession screen consumed).
 *
 * "% Match" on an explorer node is readiness: how much of the target role's
 * required proficiency (s_user_skill_jobrole) the employee already holds
 * (s_skill_matrix) - the same comparison the old frontend computed from
 * next-role KASA vs user ratings.
 */
class CareerPathController extends Controller
{
    use ResolvesCompetencyContext;
    // The ONE gap rule. `competencyMatch()` compares nothing itself - it asks
    // the same engine the drawer and the development plan ask, so this screen
    // cannot disagree with them about whether somebody meets a requirement.
    use ResolvesCompetencyGap;

    private const PATHS = 's_competency_career_paths';
    private const STEPS = 's_competency_career_path_steps';

    /* ================================================================== *
     * Paths CRUD
     * ================================================================== */

    /** GET /competency/career-paths */
    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $perPage = min(max((int) $request->input('per_page', 25), 1), 200);
        $page = max((int) $request->input('page', 1), 1);

        $query = DB::table(self::PATHS)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at');

        if ($search = $this->activeFilter($request->input('search'))) {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($status = $this->activeFilter($request->input('status'))) {
            $query->where('status', $status);
        }
        if ($departmentId = $this->activeFilter($request->input('department_id'))) {
            $query->where('department_id', $departmentId);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $perPage)->get();

        $pathIds = $rows->pluck('id')->all();

        $stepCounts = [];
        $firstSteps = [];
        if ($pathIds) {
            $stepCounts = DB::table(self::STEPS)
                ->whereIn('career_path_id', $pathIds)->whereNull('deleted_at')
                ->selectRaw('career_path_id, COUNT(*) as c')
                ->groupBy('career_path_id')->pluck('c', 'career_path_id')->all();

            $firstSteps = DB::table(self::STEPS)
                ->whereIn('career_path_id', $pathIds)->whereNull('deleted_at')
                ->orderBy('step_order')->get(['career_path_id', 'jobrole', 'step_order'])
                ->groupBy('career_path_id')
                ->map(fn ($group) => $group->pluck('jobrole')->all())
                ->all();
        }

        $planCounts = [];
        if ($pathIds) {
            $planCounts = DB::table('s_competency_development_plans')
                ->where('sub_institute_id', $sid)->whereNull('deleted_at')
                ->whereIn('career_path_id', $pathIds)
                ->selectRaw('career_path_id, COUNT(*) as c')
                ->groupBy('career_path_id')->pluck('c', 'career_path_id')->all();
        }

        $data = $rows->map(fn ($r) => [
            'id'           => (int) $r->id,
            'name'         => $r->name,
            'description'  => $r->description,
            'department_id'=> $r->department_id ? (int) $r->department_id : null,
            'department'   => $r->department,
            'job_family'   => $r->job_family,
            'status'       => $r->status,
            'step_count'   => (int) ($stepCounts[$r->id] ?? 0),
            'roles'        => $firstSteps[$r->id] ?? [],
            'linked_plans' => (int) ($planCounts[$r->id] ?? 0),
            'created_at'   => $r->created_at ? date('d M Y', strtotime($r->created_at)) : null,
        ])->all();

        return response()->json([
            'status'     => 1,
            'message'    => 'Career paths fetched successfully',
            'data'       => $data,
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int) max(ceil($total / $perPage), 1),
            ],
        ]);
    }

    /** GET /competency/career-paths/{id} */
    public function show(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $path = $this->findPath($id, $sid);
        if (!$path) {
            return response()->json(['status' => 0, 'message' => 'Career path not found'], 404);
        }

        return response()->json([
            'status'  => 1,
            'message' => 'Career path fetched successfully',
            'data'    => [
                'id'            => (int) $path->id,
                'name'          => $path->name,
                'description'   => $path->description,
                'department_id' => $path->department_id ? (int) $path->department_id : null,
                'department'    => $path->department,
                'job_family'    => $path->job_family,
                'status'        => $path->status,
                'steps'         => $this->stepsFor((int) $path->id),
            ],
        ]);
    }

    /** POST /competency/career-paths */
    public function store(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $validator = Validator::make($request->all(), $this->pathRules(true));
        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $pathId = DB::table(self::PATHS)->insertGetId([
            'sub_institute_id' => $sid,
            'name'             => $request->input('name'),
            'description'      => $request->input('description'),
            'department_id'    => $request->input('department_id'),
            'department'       => $request->input('department'),
            'job_family'       => $request->input('job_family'),
            'status'           => $request->input('status', 'active'),
            'created_by'       => $context['user_id'],
            'updated_by'       => $context['user_id'],
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->replaceSteps($pathId, $sid, $request->input('steps', []), $context['user_id']);

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'created_career_path',
            'Created career path "' . $request->input('name') . '"',
            'career_path',
            $pathId,
            $request->input('name')
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Career path created successfully',
            'data'    => ['id' => $pathId],
        ], 201);
    }

    /** PUT /competency/career-paths/{id} */
    public function update(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $path = $this->findPath($id, $sid);
        if (!$path) {
            return response()->json(['status' => 0, 'message' => 'Career path not found'], 404);
        }

        $validator = Validator::make($request->all(), $this->pathRules(false));
        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $update = ['updated_by' => $context['user_id'], 'updated_at' => now()];
        foreach (['name', 'description', 'department_id', 'department', 'job_family', 'status'] as $field) {
            if ($request->has($field)) {
                $update[$field] = $request->input($field);
            }
        }

        DB::table(self::PATHS)->where('id', $path->id)->update($update);

        if ($request->has('steps')) {
            $this->replaceSteps((int) $path->id, $sid, $request->input('steps', []), $context['user_id']);
        }

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'updated_career_path',
            'Updated career path "' . ($update['name'] ?? $path->name) . '"',
            'career_path',
            (int) $path->id,
            $update['name'] ?? $path->name
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Career path updated successfully',
            'data'    => ['id' => (int) $path->id],
        ]);
    }

    /** DELETE /competency/career-paths/{id} */
    public function destroy(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $path = $this->findPath($id, $sid);
        if (!$path) {
            return response()->json(['status' => 0, 'message' => 'Career path not found'], 404);
        }

        DB::table(self::PATHS)->where('id', $path->id)->update([
            'deleted_at' => now(),
            'deleted_by' => $context['user_id'],
        ]);
        DB::table(self::STEPS)->where('career_path_id', $path->id)->update(['deleted_at' => now()]);

        // Unlink plans so the detail panel does not point at a deleted path.
        DB::table('s_competency_development_plans')
            ->where('sub_institute_id', $sid)->where('career_path_id', $path->id)
            ->update(['career_path_id' => null, 'updated_at' => now()]);

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'deleted_career_path',
            'Deleted career path "' . $path->name . '"',
            'career_path',
            (int) $path->id,
            $path->name
        );

        return response()->json(['status' => 1, 'message' => 'Career path deleted successfully']);
    }

    /* ================================================================== *
     * Explorer
     * ================================================================== */

    /**
     * GET /competency/career-paths/explorer
     *
     * Powers the Career Path Explorer card. Resolution order:
     *   1. explicit ?career_path_id=      - the named path's own steps
     *   2. ?plan_id=                      - that plan's linked path, else the
     *                                       employee's role walked through
     *                                       career_journey
     *   3. the tenant's most recent path
     * Each node past the current role carries match_percent for the employee in
     * scope (when one can be resolved).
     */
    public function explorer(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $employeeId = null;
        $currentRole = null;
        $path = null;

        if ($planId = $this->activeFilter($request->input('plan_id'))) {
            $plan = DB::table('s_competency_development_plans')
                ->where('id', $planId)->where('sub_institute_id', $sid)->whereNull('deleted_at')->first();
            if ($plan) {
                $employeeId = $plan->user_id;
                $currentRole = $plan->jobrole;
                if ($plan->career_path_id) {
                    $path = $this->findPath($plan->career_path_id, $sid);
                }
            }
        }

        if ($userId = $this->activeFilter($request->input('employee_id'))) {
            $employeeId = (int) $userId;
            $currentRole = null; // re-resolve below for the employee actually asked for
        }

        // Knowing the employee's own role is what lets the explorer mark the
        // "(Current Role)" node and score the ones ahead of it.
        if ($employeeId && !$currentRole) {
            $currentRole = $this->roleForUser($sid, (int) $employeeId)->jobrole ?? null;
        }

        if (!$path && ($pathId = $this->activeFilter($request->input('career_path_id')))) {
            $path = $this->findPath($pathId, $sid);
        }

        $source = 'career_path';
        $nodes = [];

        if ($path) {
            $nodes = $this->stepsFor((int) $path->id);
        } else {
            // No named path: walk the tenant's progression graph from the
            // employee's current role, exactly as CareerJourneyController does.
            [$nodes, $source, $currentRole] = $this->derivePath($sid, $employeeId, $currentRole);

            if (!$nodes) {
                $path = DB::table(self::PATHS)
                    ->where('sub_institute_id', $sid)->whereNull('deleted_at')
                    ->where('status', '!=', 'archived')->orderByDesc('id')->first();
                if ($path) {
                    $nodes = $this->stepsFor((int) $path->id);
                    $source = 'career_path';
                }
            }
        }

        $held = $employeeId ? $this->heldLevels((int) $employeeId) : [];

        // Without an employee to anchor on, honour the step the author marked
        // as "current" rather than pretending the whole path lies ahead.
        $matchedCurrent = false;
        if ($currentRole) {
            foreach ($nodes as $node) {
                if (strcasecmp((string) $node['jobrole'], (string) $currentRole) === 0) {
                    $matchedCurrent = true;
                    break;
                }
            }
        }

        // The employee's own role AS AN ID, so the current node is identified by
        // key rather than by comparing two strings that a rename can separate.
        $currentRoleId = $employeeId ? ($this->roleForUser($sid, (int) $employeeId)->id ?? null) : null;

        foreach ($nodes as $index => $node) {
            // Prefer the id. `s_competency_career_path_steps.jobrole_id` is
            // populated on all 72 live rows, every one valid, and 0 of them
            // disagree with the name stored beside it - so the id path is
            // always available and the name comparison is only a fallback for
            // a derived node that has none.
            $isCurrent = $currentRoleId && $node['jobrole_id']
                ? (int) $node['jobrole_id'] === (int) $currentRoleId
                : ($matchedCurrent
                    ? strcasecmp((string) $node['jobrole'], (string) $currentRole) === 0
                    : ($node['step_type'] ?? '') === 'current');

            $nodes[$index]['is_current'] = $isCurrent;
            $nodes[$index]['step_type'] = $isCurrent ? 'current' : 'future';

            // TWO READINGS, NAMED - the same two arms the development plan's
            // gaps tab shows, so one idea is learned rather than two.
            $nodes[$index]['competency_match'] = $isCurrent
                ? null
                : $this->competencyMatch($sid, $node['jobrole_id'] ?? null, $employeeId ? (int) $employeeId : null);
            $nodes[$index]['skill_match'] = $isCurrent
                ? null
                : $this->matchPercent($sid, (string) $node['jobrole'], $held);

            // Kept for callers that still read a single number. It is the SKILL
            // percent, which is what this field always carried - now NULL where
            // nothing was measured rather than a confident 0.
            $nodes[$index]['match_percent'] = $isCurrent
                ? 100
                : ($nodes[$index]['skill_match']['percent'] ?? null);

            $nodes[$index]['required_skills'] = $this->requiredCount($sid, (string) $node['jobrole']);
        }

        // The immediate next role is the first node AFTER the current one -
        // never one behind it. With no current node at all, it is the first.
        $currentIndex = null;
        foreach ($nodes as $index => $node) {
            if ($node['is_current']) {
                $currentIndex = $index;
                break;
            }
        }
        for ($index = ($currentIndex === null ? 0 : $currentIndex + 1); $index < count($nodes); $index++) {
            if (!$nodes[$index]['is_current']) {
                $nodes[$index]['step_type'] = 'next';
                break;
            }
        }

        // Anything before the current role has already been held - label it so
        // the card does not present it as a step still to come.
        for ($index = 0; $index < ($currentIndex ?? 0); $index++) {
            $nodes[$index]['step_type'] = 'past';
        }

        return response()->json([
            'status'  => 1,
            'message' => 'Career path explorer fetched successfully',
            'data'    => [
                'career_path_id' => $path ? (int) $path->id : null,
                'name'           => $path->name ?? ($currentRole ? $currentRole . ' Progression' : 'Career Path'),
                'current_role'   => $currentRole,
                'employee_id'    => $employeeId ? (int) $employeeId : null,
                'source'         => $source,
                'nodes'          => array_values($nodes),
                'lateral_roles'  => $this->lateralRoles($sid, $currentRole, $held),
            ],
        ]);
    }

    /**
     * GET /competency/career-paths/role-options
     * Job roles available as path steps, plus the roles career_journey says can
     * follow a given ?from_jobrole= (authoring aid for Create Career Path).
     */
    public function roleOptions(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $query = DB::table('s_user_jobrole')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')
            ->whereNotNull('jobrole')->where('jobrole', '!=', '');

        if ($search = $this->activeFilter($request->input('search'))) {
            $query->where('jobrole', 'like', "%{$search}%");
        }
        if ($departmentId = $this->activeFilter($request->input('department_id'))) {
            $query->where('department_id', $departmentId);
        }

        $roles = $query->orderBy('jobrole')->limit(500)
            ->get(['id', 'jobrole', 'job_level', 'department', 'department_id'])
            ->unique('jobrole')->values()
            ->map(fn ($r) => [
                'id'            => (int) $r->id,
                'jobrole'       => $r->jobrole,
                'job_level'     => $r->job_level,
                'department'    => $r->department,
                'department_id' => $r->department_id ? (int) $r->department_id : null,
            ])->all();

        return response()->json([
            'status'  => 1,
            'message' => 'Role options fetched successfully',
            'data'    => $roles,
        ]);
    }

    /* ================================================================== *
     * Helpers
     * ================================================================== */

    private function pathRules(bool $creating): array
    {
        return [
            'name'          => ($creating ? 'required' : 'sometimes|required') . '|string|max:191',
            'description'   => 'nullable|string',
            'department_id' => 'nullable|integer',
            'department'    => 'nullable|string|max:191',
            'job_family'    => 'nullable|string|max:191',
            'status'        => 'nullable|in:draft,active,archived',
            'steps'                => 'nullable|array',
            'steps.*.jobrole'      => 'required_with:steps|string|max:191',
            'steps.*.jobrole_id'   => 'nullable|integer',
            'steps.*.job_level'    => 'nullable|string|max:191',
            'steps.*.step_type'    => 'nullable|in:current,next,future',
            'steps.*.description'  => 'nullable|string',
        ];
    }

    private function findPath($id, int $sid)
    {
        return DB::table(self::PATHS)
            ->where('id', $id)->where('sub_institute_id', $sid)->whereNull('deleted_at')->first();
    }

    /** Steps are replaced wholesale - the editor always submits the full list. */
    private function replaceSteps(int $pathId, int $sid, $steps, ?int $actorId): void
    {
        DB::table(self::STEPS)->where('career_path_id', $pathId)->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        if (!is_array($steps) || !$steps) {
            return;
        }

        $rows = [];
        foreach (array_values($steps) as $order => $step) {
            $jobrole = trim((string) ($step['jobrole'] ?? ''));
            if ($jobrole === '') {
                continue;
            }
            // Editors submit role names; resolve the id so the step still joins
            // s_user_jobrole for job_level and required-skill counts.
            $role = ($step['jobrole_id'] ?? null) ? null : $this->roleByName($sid, $jobrole);

            $rows[] = [
                'sub_institute_id' => $sid,
                'career_path_id'   => $pathId,
                'jobrole_id'       => $step['jobrole_id'] ?? ($role->id ?? null),
                'jobrole'          => $jobrole,
                'job_level'        => $step['job_level'] ?? ($role->job_level ?? null),
                'step_order'       => $order,
                'step_type'        => $step['step_type'] ?? ($order === 0 ? 'current' : ($order === 1 ? 'next' : 'future')),
                'description'      => $step['description'] ?? null,
                'created_by'       => $actorId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ];
        }

        if ($rows) {
            DB::table(self::STEPS)->insert($rows);
        }
    }

    private function stepsFor(int $pathId): array
    {
        return DB::table(self::STEPS)
            ->where('career_path_id', $pathId)->whereNull('deleted_at')
            ->orderBy('step_order')->orderBy('id')->get()
            ->map(fn ($s) => [
                'id'          => (int) $s->id,
                'jobrole'     => $s->jobrole,
                'jobrole_id'  => $s->jobrole_id ? (int) $s->jobrole_id : null,
                'job_level'   => $s->job_level,
                'step_order'  => (int) $s->step_order,
                'step_type'   => $s->step_type,
                'description' => $s->description,
            ])->all();
    }

    /**
     * Walk career_journey (vertical edges) from the employee's current role.
     * Falls back to s_user_jobrole.related_jobrole, then to other roles in the
     * same department - the same ladder of fallbacks
     * EmployeeCompetencyProfileController@careerPath already uses, because this
     * tenant does not populate every progression edge.
     *
     * @return array{0:array,1:string,2:?string}
     */
    private function derivePath(int $sid, $employeeId, ?string $currentRole): array
    {
        $role = $currentRole ? $this->roleByName($sid, $currentRole) : null;

        if (!$role && $employeeId) {
            $role = $this->roleForUser($sid, (int) $employeeId);
            $currentRole = $role->jobrole ?? $currentRole;
        }

        if (!$role) {
            return [[], 'none', $currentRole];
        }

        $nodes = [[
            'jobrole'     => $role->jobrole,
            'jobrole_id'  => (int) $role->id,
            'job_level'   => $role->job_level,
            'step_order'  => 0,
            'step_type'   => 'current',
            'description' => null,
        ]];

        // 1. career_journey vertical chain
        $visited = [(int) $role->id];
        $cursor = (int) $role->id;
        while (count($nodes) < 6) {
            $edge = DB::table('career_journey as cj')
                ->join('s_user_jobrole as jr', 'jr.id', '=', 'cj.to_jobrole_id')
                ->where('cj.sub_institute_id', $sid)
                ->where('cj.jobrole_id', $cursor)
                ->where('cj.vertical_lateral_movement', 1)
                ->whereNull('jr.deleted_at')
                ->orderBy('jr.sequence_order')->orderBy('cj.id')
                ->first(['jr.id', 'jr.jobrole', 'jr.job_level']);

            if (!$edge || in_array((int) $edge->id, $visited, true)) {
                break;
            }

            $visited[] = (int) $edge->id;
            $nodes[] = [
                'jobrole'     => $edge->jobrole,
                'jobrole_id'  => (int) $edge->id,
                'job_level'   => $edge->job_level,
                'step_order'  => count($nodes),
                'step_type'   => 'future',
                'description' => null,
            ];
            $cursor = (int) $edge->id;
        }

        if (count($nodes) > 1) {
            return [$nodes, 'career_journey', $currentRole];
        }

        // 1b. Nothing ahead - this role is the top of its track. Walk the graph
        // BACKWARDS instead so the card shows the ladder that leads up to the
        // current role (which stays last) rather than listing junior roles as
        // if they were still to come.
        $prefix = [];
        $cursor = (int) $role->id;
        while (count($prefix) < 4) {
            $edge = DB::table('career_journey as cj')
                ->join('s_user_jobrole as jr', 'jr.id', '=', 'cj.jobrole_id')
                ->where('cj.sub_institute_id', $sid)
                ->where('cj.to_jobrole_id', $cursor)
                ->where('cj.vertical_lateral_movement', 1)
                ->whereNull('jr.deleted_at')
                ->orderByDesc('jr.sequence_order')->orderBy('cj.id')
                ->first(['jr.id', 'jr.jobrole', 'jr.job_level']);

            if (!$edge || in_array((int) $edge->id, $visited, true)) {
                break;
            }

            $visited[] = (int) $edge->id;
            array_unshift($prefix, [
                'jobrole'     => $edge->jobrole,
                'jobrole_id'  => (int) $edge->id,
                'job_level'   => $edge->job_level,
                'step_type'   => 'future',
                'description' => null,
            ]);
            $cursor = (int) $edge->id;
        }

        if ($prefix) {
            $nodes = array_merge($prefix, $nodes);
            foreach ($nodes as $index => $node) {
                $nodes[$index]['step_order'] = $index;
            }
            return [$nodes, 'career_journey', $currentRole];
        }

        // 2. related_jobrole, 3. same department
        $names = [];
        $source = 'related';
        if (!empty($role->related_jobrole)) {
            $names = array_values(array_unique(array_filter(array_map('trim', explode(',', $role->related_jobrole)))));
        }
        if (!$names && !empty($role->department)) {
            $names = DB::table('s_user_jobrole')
                ->where('sub_institute_id', $sid)->whereNull('deleted_at')
                ->where('department', $role->department)
                ->where('jobrole', '!=', $role->jobrole)
                ->whereNotNull('jobrole')->where('jobrole', '!=', '')
                ->distinct()->orderBy('sequence_order')->orderBy('jobrole')
                ->limit(3)->pluck('jobrole')->all();
            $source = 'department';
        }

        foreach (array_slice($names, 0, 3) as $name) {
            $next = $this->roleByName($sid, $name);
            $nodes[] = [
                'jobrole'     => $name,
                'jobrole_id'  => $next ? (int) $next->id : null,
                'job_level'   => $next->job_level ?? null,
                'step_order'  => count($nodes),
                'step_type'   => 'future',
                'description' => null,
            ];
        }

        return [$nodes, count($nodes) > 1 ? $source : 'none', $currentRole];
    }

    /** Lateral moves - preserved from the old frontend's Succession screen. */
    private function lateralRoles(int $sid, ?string $currentRole, array $held): array
    {
        if (!$currentRole) {
            return [];
        }
        $role = $this->roleByName($sid, $currentRole);
        if (!$role) {
            return [];
        }

        return DB::table('career_journey as cj')
            ->join('s_user_jobrole as jr', 'jr.id', '=', 'cj.to_jobrole_id')
            ->where('cj.sub_institute_id', $sid)
            ->where('cj.jobrole_id', $role->id)
            ->where('cj.vertical_lateral_movement', 0)
            ->whereNull('jr.deleted_at')
            ->orderBy('jr.sequence_order')->limit(6)
            ->get(['jr.id', 'jr.jobrole', 'jr.job_level'])
            ->unique('jobrole')->values()
            ->map(function ($r) use ($sid, $held) {
                $skill = $this->matchPercent($sid, (string) $r->jobrole, $held);

                return [
                    'jobrole'       => $r->jobrole,
                    'jobrole_id'    => (int) $r->id,
                    'job_level'     => $r->job_level,
                    'skill_match'   => $skill,
                    // NULL where nothing was measured, not 0 - a lateral role
                    // nobody has been assessed against is unknown, not unsuitable.
                    'match_percent' => $skill['percent'] ?? null,
                ];
            })->all();
    }

    private function roleByName(int $sid, string $name)
    {
        return DB::table('s_user_jobrole')
            ->where('sub_institute_id', $sid)->where('jobrole', $name)->whereNull('deleted_at')->first();
    }

    /**
     * Resolve an employee's current role. tbluser.jobtitle_id is 0 on some
     * tenants, so fall back to allocated_standards (a role id) and then to the
     * jobrole on their most recent assessment.
     */
    private function roleForUser(int $sid, int $userId)
    {
        $user = DB::table('tbluser')->where('id', $userId)->where('sub_institute_id', $sid)->first();
        if (!$user) {
            return null;
        }

        foreach ([$user->jobtitle_id ?? null, $this->firstNumeric($user->allocated_standards ?? null)] as $roleId) {
            if ($roleId) {
                $role = DB::table('s_user_jobrole')->where('id', $roleId)
                    ->where('sub_institute_id', $sid)->whereNull('deleted_at')->first();
                if ($role) {
                    return $role;
                }
            }
        }

        $recent = DB::table('s_competency_assessments')
            ->where('sub_institute_id', $sid)->where('user_id', $userId)->whereNull('deleted_at')
            ->whereNotNull('jobrole')->where('jobrole', '!=', '')->orderByDesc('id')->first();

        return $recent ? $this->roleByName($sid, $recent->jobrole) : null;
    }

    private function firstNumeric($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        foreach (array_map('trim', explode(',', (string) $value)) as $part) {
            if (is_numeric($part)) {
                return (int) $part;
            }
        }
        return null;
    }

    /** @return array<string,int> skill title => level the employee holds */
    private function heldLevels(int $userId): array
    {
        return DB::table('s_skill_matrix as m')
            ->join('s_users_skills as s', 'm.skill_id', '=', 's.id')
            ->where('m.user_id', $userId)
            ->whereNull('m.deleted_at')->whereNull('s.deleted_at')
            ->pluck('m.skill_level', 's.title')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Readiness for a target role on the SKILL arm: the share of its required
     * proficiency the employee already holds, capped per skill so one
     * over-qualified skill cannot mask several missing ones.
     *
     * ── WHAT CHANGED, AND WHY IT MATTERED ──────────────────────────────────
     *
     * This used to read `$held[$row->skill] ?? 0` - an UNRATED skill counted as
     * a zero against the target, and therefore as a shortfall. Live holds
     * 84,380 skill requirements against **169 ratings**, so almost every
     * requirement scored zero and every future role looked unreachable. A
     * readiness number computed mostly from things nobody measured is not a low
     * score; it is not a score at all.
     *
     * Unmeasured skills are now EXCLUDED from both sides of the fraction, and
     * `coverage` says how much of the requirement the number actually speaks
     * for - the same shape `ProficiencyService` returns one level down.
     *
     * @return array{percent:?int, coverage:float, measured:int, required:int}|null
     */
    private function matchPercent(int $sid, string $jobrole, array $held): ?array
    {
        $required = DB::table('s_user_skill_jobrole')
            ->where('sub_institute_id', $sid)->where('jobrole', $jobrole)->whereNull('deleted_at')
            ->get(['skill', 'proficiency_level']);

        if ($required->isEmpty()) {
            return null;
        }

        $needed = 0;
        $have = 0;
        $countRequired = 0;
        $countMeasured = 0;

        foreach ($required as $row) {
            // A word is not a level. Live holds "Advanced" / "Intermediate" /
            // "Basic" in this column; `(int)` turns each into 0, which used to
            // make an unrated skill compare as satisfied.
            if (!is_numeric($row->proficiency_level) || (int) $row->proficiency_level <= 0) {
                continue;
            }
            $level = (int) $row->proficiency_level;
            $countRequired++;

            if (!array_key_exists($row->skill, $held)) {
                continue;            // UNMEASURED - excluded, never a zero
            }

            $countMeasured++;
            $needed += $level;
            $have   += min((int) $held[$row->skill], $level);
        }

        return [
            // NULL, not 0, when nothing was measured: "we do not know" and
            // "they have none of it" are different answers.
            'percent'  => $needed > 0 ? (int) round($have / $needed * 100) : null,
            'coverage' => $countRequired > 0 ? round($countMeasured / $countRequired, 2) : 0.0,
            'measured' => $countMeasured,
            'required' => $countRequired,
        ];
    }

    /**
     * Readiness for a target role on the COMPETENCY arm.
     *
     * The assessment half of the model, and the one the gap engine speaks for.
     * Reuses `competencyGapFor()` rather than comparing anything here, so this
     * screen cannot disagree with the employee drawer or the development plan
     * about whether somebody meets a requirement.
     *
     * @return array{percent:?int, coverage:float, measured:int, required:int}|null
     */
    private function competencyMatch(int $sid, ?int $jobroleId, ?int $userId): ?array
    {
        if (!$jobroleId || !$userId) {
            return null;
        }

        $gap = $this->competencyGapFor($sid, $userId, $jobroleId);
        $items = $gap['competencies'];

        if ($items === []) {
            return null;              // no requirements: nothing to be ready for
        }

        $measured = array_values(array_filter($items, fn ($c) => $c['state'] !== 'unmeasured'));
        $met      = count(array_filter($measured, fn ($c) => $c['state'] === 'met'));

        return [
            // The share of what was MEASURED, so a role with one competency
            // assessed out of eight cannot report 100% ready.
            'percent'  => $measured === [] ? null : (int) round($met / count($measured) * 100),
            'coverage' => round(count($measured) / count($items), 2),
            'measured' => count($measured),
            'required' => count($items),
        ];
    }

    private function requiredCount(int $sid, string $jobrole): int
    {
        return DB::table('s_user_skill_jobrole')
            ->where('sub_institute_id', $sid)->where('jobrole', $jobrole)->whereNull('deleted_at')
            ->distinct()->count('skill');
    }
}
