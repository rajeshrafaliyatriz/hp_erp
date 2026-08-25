<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * CRUD for competency frameworks (s_competency_frameworks). Backs the
 * "Create Framework" quick action and the Framework Mapping screen.
 */
class FrameworkController extends Controller
{
    use ResolvesCompetencyContext;

    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 200);
        $page = max((int) $request->input('page', 1), 1);

        $query = DB::table('s_competency_frameworks')
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at');

        if ($status = $this->activeFilter($request->input('status'))) {
            $query->where('status', $status);
        }
        if ($search = $this->activeFilter($request->input('search'))) {
            $query->where('name', 'like', "%{$search}%");
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $perPage)->get();

        return response()->json([
            'status'     => 1,
            'message'    => 'Frameworks fetched successfully',
            'data'       => $rows,
            'pagination' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int) max(ceil($total / $perPage), 1),
            ],
        ]);
    }

    /**
     * THE FRAMEWORK'S LINK TO A JOB ROLE, RESOLVED TO AN ID.
     *
     * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
     *
     * `s_competency_frameworks.jobrole` is a TEXT column holding the role's
     * NAME, while its sibling `department_id` is already a proper id - so the
     * name was the odd one out, not a house style. A name is not a key: rename
     * the role and the framework quietly stops pointing at it, and two roles
     * sharing a name inside one organisation cannot be told apart. This was the
     * last place in the capability chain still keyed that way.
     *
     * ── THE PRECEDENCE ──────────────────────────────────────────────────────
     *
     *   1. an explicit `jobrole_id`, IF the tenant owns it
     *   2. otherwise the name, IF it resolves to exactly one live role
     *   3. otherwise NULL
     *
     * ── IT REFUSES TO GUESS, AND THAT IS THE POINT ──────────────────────────
     *
     * An ambiguous name yields NULL rather than the first match. The backfill
     * migration made the same choice and left 2 of 32 frameworks unkeyed - both
     * "Head of Treasury", which exists twice in tenant 1. A coin-toss link that
     * looks authoritative is worse than a visibly missing one: the earlier
     * name-matched provenance backfill resolved 5,470 rows by guessing and none
     * of them can now be trusted.
     *
     * ── THE OWNERSHIP CHECK IS NOT OPTIONAL ─────────────────────────────────
     *
     * Whitelisting an id column without it is how a tenant-1 caller could attach
     * a task to tenant 2's role - a hole I opened and then found by testing when
     * `jobrole_id` was added to job role tasks. The foreign key added alongside
     * this enforces EXISTENCE only, never tenancy, so this check is the guard
     * that actually keeps organisations apart.
     */
    private function resolveJobroleId(Request $request, int $subInstituteId): ?int
    {
        $explicit = $request->input('jobrole_id');

        if ($explicit !== null && $explicit !== '') {
            $owned = DB::table('s_user_jobrole')
                ->where('id', (int) $explicit)
                ->where('sub_institute_id', $subInstituteId)
                ->whereNull('deleted_at')
                ->exists();

            return $owned ? (int) $explicit : null;
        }

        $name = trim((string) $request->input('jobrole', ''));
        if ($name === '') {
            return null;
        }

        // limit(2) is enough to tell "exactly one" from "more than one", and
        // avoids dragging back every namesake just to count them.
        $matches = DB::table('s_user_jobrole')
            ->where('sub_institute_id', $subInstituteId)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(TRIM(jobrole)) = ?', [mb_strtolower($name)])
            ->limit(2)
            ->pluck('id');

        return $matches->count() === 1 ? (int) $matches->first() : null;
    }

    /**
     * A PROFICIENCY TARGET, AS A NUMBER ON THE 1-5 SCALE.
     *
     * Accepts 3, '3' or 'Level 3' and returns 3. The string forms are tolerated
     * because this column held `varchar` until 2026_08_24_100000 and an older
     * client mid-deploy will still send 'Level 3'; normalising is what stops
     * that landing in a TINYINT column as 0.
     *
     * ANYTHING OUTSIDE 1-5 BECOMES NULL RATHER THAN BEING CLAMPED. A level the
     * scale cannot express is missing information, and rounding it into a real
     * level would invent a requirement somebody could then be measured against.
     * The measured scale is 1-5 across every operational table:
     * `s_proficiency_knowledge`/`_ability`/`_attitude`/`_behaviour` and the
     * framework targets themselves.
     */
    private function normaliseProficiency($value): ?int
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', (string) $value);
        if ($digits === '') {
            return null;
        }

        $level = (int) $digits;

        return ($level >= 1 && $level <= 5) ? $level : null;
    }

    public function store(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'name'          => 'required|string|max:191',
            'description'   => 'nullable|string',
            'version'       => 'nullable|string|max:30',
            'status'        => 'nullable|in:draft,active,archived',
            'department_id' => 'nullable|integer',
            'jobrole'       => 'nullable|string|max:191',
            // The id is what the chain resolves through. `jobrole` stays as the
            // human label and as the fallback when no id is sent.
            'jobrole_id'    => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $id = DB::table('s_competency_frameworks')->insertGetId([
            'sub_institute_id' => $context['sub_institute_id'],
            'name'             => $request->input('name'),
            'description'      => $request->input('description'),
            'version'          => $request->input('version', 'v1.0'),
            'status'           => $request->input('status', 'draft'),
            'department_id'    => $request->input('department_id'),
            'jobrole'          => $request->input('jobrole'),
            'jobrole_id'       => $this->resolveJobroleId($request, (int) $context['sub_institute_id']),
            'created_by'       => $context['user_id'],
            'updated_by'       => $context['user_id'],
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->logCompetencyActivity(
            $context['sub_institute_id'],
            $context['user_id'],
            'created_framework',
            'Created framework "' . $request->input('name') . '"',
            'framework',
            $id,
            $request->input('name')
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Framework created successfully',
            'data'    => ['id' => $id],
        ], 201);
    }

    public function destroy(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $existing = DB::table('s_competency_frameworks')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (!$existing) {
            return response()->json(['status' => 0, 'message' => 'Framework not found'], 404);
        }

        DB::table('s_competency_frameworks')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $context['user_id'],
        ]);

        $this->logCompetencyActivity(
            $context['sub_institute_id'],
            $context['user_id'],
            'deleted_framework',
            'Deleted framework "' . $existing->name . '"',
            'framework',
            (int) $id,
            $existing->name
        );

        return response()->json(['status' => 1, 'message' => 'Framework deleted successfully']);
    }

    /* ----------------------------------------------------------------- *
     * Show one framework with its competency items.
     * ----------------------------------------------------------------- */
    public function show(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $framework = DB::table('s_competency_frameworks')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (!$framework) {
            return response()->json(['status' => 0, 'message' => 'Framework not found'], 404);
        }

        $framework->items = $this->frameworkItems($context['sub_institute_id'], (int) $id);

        return response()->json([
            'status'  => 1,
            'message' => 'Framework fetched successfully',
            'data'    => $framework,
        ]);
    }

    /* ----------------------------------------------------------------- *
     * Update framework metadata.
     * ----------------------------------------------------------------- */
    public function update(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $framework = DB::table('s_competency_frameworks')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (!$framework) {
            return response()->json(['status' => 0, 'message' => 'Framework not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name'          => 'required|string|max:191',
            'description'   => 'nullable|string',
            'version'       => 'nullable|string|max:30',
            'status'        => 'nullable|in:draft,active,archived',
            'department_id' => 'nullable|integer',
            'jobrole'       => 'nullable|string|max:191',
            // The id is what the chain resolves through. `jobrole` stays as the
            // human label and as the fallback when no id is sent.
            'jobrole_id'    => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $update = [
            'name'          => $request->input('name'),
            'description'   => $request->input('description'),
            'version'       => $request->input('version', $framework->version),
            'status'        => $request->input('status', $framework->status),
            'department_id' => $request->input('department_id'),
            'jobrole'       => $request->input('jobrole'),
            'jobrole_id'    => $this->resolveJobroleId($request, (int) $context['sub_institute_id']),
        ];

        DB::table('s_competency_frameworks')->where('id', $id)->update($update + [
            'updated_by'    => $context['user_id'],
            'updated_at'    => now(),
        ]);

        $this->logCompetencyActivity(
            $context['sub_institute_id'],
            $context['user_id'],
            'updated_framework',
            'Updated framework "' . $request->input('name') . '"',
            'framework',
            (int) $id,
            $request->input('name'),
            $this->diffChanges($framework, $update, [
                'name'          => 'Framework Name',
                'description'   => 'Description',
                'version'       => 'Version',
                'status'        => 'Status',
                'department_id' => 'Department',
                'jobrole'       => 'Job Role',
                'jobrole_id'    => 'Job Role (link)',
            ])
        );

        return response()->json(['status' => 1, 'message' => 'Framework updated successfully', 'data' => ['id' => (int) $id]]);
    }

    /* ----------------------------------------------------------------- *
     * Clone a framework (metadata + items) as a fresh draft.
     * ----------------------------------------------------------------- */
    public function clone(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $source = DB::table('s_competency_frameworks')
            ->where('id', $id)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->first();

        if (!$source) {
            return response()->json(['status' => 0, 'message' => 'Framework not found'], 404);
        }

        $name = $request->input('name') ?: ($source->name . ' (Copy)');

        $newId = DB::table('s_competency_frameworks')->insertGetId([
            'sub_institute_id' => $sid,
            'name'             => $name,
            'description'      => $source->description,
            'version'          => 'v1.0',
            'status'           => 'draft',
            'department_id'    => $source->department_id,
            'jobrole'          => $source->jobrole,
            // Carried too, or a clone would keep the label and silently lose the
            // link - leaving a copy that looks role-scoped and resolves to nothing.
            // Safe to copy directly: a clone is always within the same tenant.
            'jobrole_id'       => $source->jobrole_id ?? null,
            'created_by'       => $context['user_id'],
            'updated_by'       => $context['user_id'],
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $items = DB::table('s_competency_framework_items')
            ->where('framework_id', $id)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->get();

        foreach ($items as $item) {
            DB::table('s_competency_framework_items')->insert([
                'sub_institute_id'     => $sid,
                'framework_id'         => $newId,
                'competency_id'        => $item->competency_id,
                'required_proficiency' => $item->required_proficiency,
                'created_by'           => $context['user_id'],
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        }

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'cloned_framework',
            'Cloned framework "' . $source->name . '" as "' . $name . '"',
            'framework',
            (int) $newId,
            $name
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Framework cloned successfully',
            'data'    => ['id' => (int) $newId, 'name' => $name],
        ], 201);
    }

    /* ----------------------------------------------------------------- *
     * Framework items (competency -> required proficiency).
     * ----------------------------------------------------------------- */
    public function items(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        return response()->json([
            'status'  => 1,
            'message' => 'Framework items fetched successfully',
            'data'    => $this->frameworkItems($context['sub_institute_id'], (int) $id),
        ]);
    }

    public function storeItem(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        /*
         * `required_proficiency` IS A LEVEL ON A 1-5 SCALE, NOT A SENTENCE.
         *
         * It was validated as `string|max:50` and written raw, so this column
         * held 'Level 3' while `jobrole_competency_map.required_proficiency`
         * held the integer 3 for the identical idea. Two incompatible types for
         * one concept is why a framework's target could never be compared to,
         * defaulted into, or reconciled against a role's target - the broken
         * link between frameworks and job roles.
         *
         * The column is now TINYINT (2026_08_24_100000). `string` is still
         * accepted so an older client sending 'Level 3' is not rejected mid-
         * deploy, but it is NORMALISED below rather than stored as written.
         */
        $validator = Validator::make($request->all(), [
            'competency_id'        => 'required|integer',
            'required_proficiency' => 'nullable',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $framework = DB::table('s_competency_frameworks')
            ->where('id', $id)->where('sub_institute_id', $sid)->whereNull('deleted_at')->first();
        if (!$framework) {
            return response()->json(['status' => 0, 'message' => 'Framework not found'], 404);
        }

        $competencyId = (int) $request->input('competency_id');
        $required     = $this->normaliseProficiency($request->input('required_proficiency'));

        $existing = DB::table('s_competency_framework_items')
            ->where('framework_id', $id)
            ->where('competency_id', $competencyId)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            DB::table('s_competency_framework_items')->where('id', $existing->id)->update([
                'required_proficiency' => $required,
                'updated_at'           => now(),
            ]);
            $itemId = (int) $existing->id;
        } else {
            $itemId = DB::table('s_competency_framework_items')->insertGetId([
                'sub_institute_id'     => $sid,
                'framework_id'         => (int) $id,
                'competency_id'        => $competencyId,
                'required_proficiency' => $required,
                'created_by'           => $context['user_id'],
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        }

        $competencyName = DB::table('s_users_skills')->where('id', $competencyId)->value('title');

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            $existing ? 'updated_framework_item' : 'added_framework_item',
            ($existing ? 'Updated' : 'Added') . ' competency "' . ($competencyName ?: ('#' . $competencyId))
                . '" in framework "' . $framework->name . '"',
            'framework_item',
            $itemId,
            $competencyName ?: ('Competency #' . $competencyId),
            $existing
                ? $this->diffChanges(
                    $existing,
                    ['required_proficiency' => $required],
                    ['required_proficiency' => 'Required Proficiency']
                )
                : null
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Framework item saved successfully',
            'data'    => ['id' => $itemId],
        ], 201);
    }

    public function destroyItem(Request $request, $id, $itemId)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $item = DB::table('s_competency_framework_items')
            ->where('id', $itemId)
            ->where('framework_id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->first();

        if (!$item) {
            return response()->json(['status' => 0, 'message' => 'Framework item not found'], 404);
        }

        DB::table('s_competency_framework_items')->where('id', $itemId)->update([
            'deleted_at' => now(),
        ]);

        $competencyName = DB::table('s_users_skills')->where('id', $item->competency_id)->value('title');
        $frameworkName = DB::table('s_competency_frameworks')->where('id', $id)->value('name');

        $this->logCompetencyActivity(
            $context['sub_institute_id'],
            $context['user_id'],
            'deleted_framework_item',
            'Removed competency "' . ($competencyName ?: ('#' . $item->competency_id))
                . '" from framework "' . ($frameworkName ?: ('#' . $id)) . '"',
            'framework_item',
            (int) $itemId,
            $competencyName ?: ('Competency #' . $item->competency_id)
        );

        return response()->json(['status' => 1, 'message' => 'Framework item removed successfully']);
    }

    /* ----------------------------------------------------------------- *
     * Per-category weighting (s_competency_framework_weights).
     * ----------------------------------------------------------------- */
    public function weights(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        // All approved categories in the catalog, so every category can be weighted.
        $categories = DB::table('s_users_skills')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->where('approve_status', 'Approved')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->select('category', DB::raw('COUNT(*) as c'))
            ->groupBy('category')
            ->orderByDesc('c')
            ->pluck('category')
            ->all();

        $saved = DB::table('s_competency_framework_weights')
            ->where('sub_institute_id', $sid)
            ->where('framework_id', (int) $id)
            ->whereNull('deleted_at')
            ->pluck('weight', 'category')
            ->all();

        $rows = [];
        foreach ($categories as $category) {
            $rows[] = [
                'category' => $category,
                'weight'   => isset($saved[$category]) ? (float) $saved[$category] : 0.0,
            ];
        }

        return response()->json([
            'status'  => 1,
            'message' => 'Weighting fetched successfully',
            'data'    => $rows,
        ]);
    }

    public function saveWeights(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'weights'            => 'required|array',
            'weights.*.category' => 'required|string|max:191',
            'weights.*.weight'   => 'required|numeric|min:0|max:100',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Each category that actually moved becomes one line of the audit
        // entry's Change Summary, so a re-weighting reads as a single event.
        $changes = [];

        foreach ($request->input('weights') as $row) {
            $category = $row['category'];
            $weight = (float) $row['weight'];

            $existing = DB::table('s_competency_framework_weights')
                ->where('sub_institute_id', $sid)
                ->where('framework_id', (int) $id)
                ->where('category', $category)
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                if ((float) $existing->weight !== $weight) {
                    $changes[] = [
                        'field' => 'weight:' . $category,
                        'label' => $category . ' Weight',
                        'old'   => $existing->weight . '%',
                        'new'   => $weight . '%',
                    ];
                }

                DB::table('s_competency_framework_weights')->where('id', $existing->id)->update([
                    'weight'     => $weight,
                    'updated_by' => $context['user_id'],
                    'updated_at' => now(),
                ]);
            } else {
                $changes[] = [
                    'field' => 'weight:' . $category,
                    'label' => $category . ' Weight',
                    'old'   => null,
                    'new'   => $weight . '%',
                ];

                DB::table('s_competency_framework_weights')->insert([
                    'sub_institute_id' => $sid,
                    'framework_id'     => (int) $id,
                    'category'         => $category,
                    'weight'           => $weight,
                    'created_by'       => $context['user_id'],
                    'updated_by'       => $context['user_id'],
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }
        }

        $frameworkName = DB::table('s_competency_frameworks')->where('id', $id)->value('name');

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'updated_framework_weights',
            'Updated category weighting for "' . ($frameworkName ?: ('framework #' . $id)) . '"',
            'framework_weight',
            (int) $id,
            $frameworkName ?: ('Framework #' . $id),
            $changes
        );

        return response()->json(['status' => 1, 'message' => 'Weighting saved successfully']);
    }

    /* ----------------------------------------------------------------- *
     * Shared: framework items joined to the skill catalog.
     * ----------------------------------------------------------------- */
    private function frameworkItems(int $sid, int $frameworkId): array
    {
        return DB::table('s_competency_framework_items as fi')
            ->leftJoin('s_users_skills as s', 's.id', '=', 'fi.competency_id')
            ->where('fi.framework_id', $frameworkId)
            ->where('fi.sub_institute_id', $sid)
            ->whereNull('fi.deleted_at')
            ->orderBy('s.category')
            ->orderBy('s.title')
            ->get([
                'fi.id',
                'fi.competency_id',
                'fi.required_proficiency',
                's.title as competency_name',
                's.category',
                's.sub_category',
                's.competency_type',
            ])
            ->map(fn ($r) => [
                'id'                   => (int) $r->id,
                'competency_id'        => (int) $r->competency_id,
                'competency_name'      => $r->competency_name,
                'category'             => $r->category,
                'sub_category'         => $r->sub_category,
                'competency_type'      => $r->competency_type,
                'required_proficiency' => $r->required_proficiency,
            ])
            ->all();
    }
}
