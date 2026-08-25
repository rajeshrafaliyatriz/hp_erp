<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ManagesCompetencySettings;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesKasbaTitles;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Read models for the Framework & Role Mapping Studio that don't belong to a
 * single CRUD resource: the five summary cards, the Framework Structure
 * category tree, and the Proficiency Scale (+ KASA behavioural indicators).
 *
 * Everything here reads EXISTING tables:
 *   - competencies / categories -> s_users_skills
 *   - job roles                 -> s_user_jobrole
 *   - role mapping coverage      -> s_user_skill_jobrole (tenant) vs s_jobrole_skills (master baseline)
 *   - proficiency scale          -> s_proficiency_levels (+ s_proficiency_{knowledge,ability,attitude,behaviour})
 *   - frameworks                 -> s_competency_frameworks
 */
class StudioController extends Controller
{
    use ResolvesCompetencyContext;
    use ResolvesKasbaTitles;
    use ManagesCompetencySettings;

    /**
     * The "Weighting Configuration" panel's scoring rules, with the defaults a
     * tenant that has never saved them gets. Keys are the allowed set: anything
     * else in the request body is ignored.
     */
    private const WEIGHTING_DEFAULTS = [
        // weighted | simple - how a competency score rolls up across categories
        'scoring_model'     => 'weighted',
        // none | half | whole - rounding applied to the rolled-up score
        'rounding'          => 'half',
        // the score a role is expected to reach, as a percentage
        'target_threshold'  => 80,
        // zero | exclude - how a competency with no mapping counts
        'unmapped_handling' => 'exclude',
        // which surfaces the category weights are applied to
        'apply_to'          => ['assessments', 'gap_analysis'],
    ];

    private const SCORING_MODELS = ['weighted', 'simple'];
    private const ROUNDING_MODES = ['none', 'half', 'whole'];
    private const UNMAPPED_MODES = ['zero', 'exclude'];
    private const APPLY_TARGETS = ['assessments', 'gap_analysis', 'role_readiness', 'development_plans'];

    /* ----------------------------------------------------------------- *
     * Weighting Configuration (scoring rules behind the category weights)
     * ----------------------------------------------------------------- */

    /** GET /competency/studio/weighting-config */
    public function weightingConfig(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        return response()->json([
            'status'  => 1,
            'message' => 'Weighting configuration fetched successfully',
            'data'    => [
                'settings' => $this->competencySettings(
                    $context['sub_institute_id'],
                    'weighting',
                    self::WEIGHTING_DEFAULTS
                ),
                'options'  => [
                    'scoring_model'     => [
                        ['value' => 'weighted', 'label' => 'Weighted average', 'hint' => 'Each category contributes in proportion to its weight.'],
                        ['value' => 'simple',   'label' => 'Simple average',   'hint' => 'Every competency counts equally; weights are ignored.'],
                    ],
                    'rounding'          => [
                        ['value' => 'none',  'label' => 'No rounding'],
                        ['value' => 'half',  'label' => 'Nearest 0.5'],
                        ['value' => 'whole', 'label' => 'Nearest whole level'],
                    ],
                    'unmapped_handling' => [
                        ['value' => 'exclude', 'label' => 'Excluded', 'hint' => 'Competencies with no mapping are left out of the score.'],
                        ['value' => 'zero',    'label' => 'Counted as zero', 'hint' => 'An unmapped competency drags the score down.'],
                    ],
                    'apply_to'          => [
                        ['value' => 'assessments',       'label' => 'Assessment scoring'],
                        ['value' => 'gap_analysis',      'label' => 'Gap analysis'],
                        ['value' => 'role_readiness',    'label' => 'Role readiness / % match'],
                        ['value' => 'development_plans', 'label' => 'Development plan priorities'],
                    ],
                ],
            ],
        ]);
    }

    /** PUT /competency/studio/weighting-config */
    public function saveWeightingConfig(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'scoring_model'     => 'sometimes|required|in:' . implode(',', self::SCORING_MODELS),
            'rounding'          => 'sometimes|required|in:' . implode(',', self::ROUNDING_MODES),
            'target_threshold'  => 'sometimes|required|integer|min:0|max:100',
            'unmapped_handling' => 'sometimes|required|in:' . implode(',', self::UNMAPPED_MODES),
            'apply_to'          => 'sometimes|array',
            'apply_to.*'        => 'in:' . implode(',', self::APPLY_TARGETS),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $before = $this->competencySettings($sid, 'weighting', self::WEIGHTING_DEFAULTS);

        $settings = $this->saveCompetencySettings(
            $sid,
            'weighting',
            $request->all(),
            self::WEIGHTING_DEFAULTS,
            $context['user_id']
        );

        $labels = [
            'scoring_model'     => 'Scoring Model',
            'rounding'          => 'Rounding',
            'target_threshold'  => 'Target Threshold',
            'unmapped_handling' => 'Unmapped Competencies',
            'apply_to'          => 'Applied To',
        ];

        $changes = [];
        foreach ($labels as $key => $label) {
            $old = is_array($before[$key]) ? implode(', ', $before[$key]) : $before[$key];
            $new = is_array($settings[$key]) ? implode(', ', $settings[$key]) : $settings[$key];
            if ((string) $old !== (string) $new) {
                $changes[] = ['field' => $key, 'label' => $label, 'old' => $old, 'new' => $new];
            }
        }

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'updated_weighting_config',
            'Updated the competency scoring configuration',
            'framework_weight',
            null,
            'Weighting Configuration',
            $changes
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Weighting configuration saved successfully',
            'data'    => ['settings' => $settings],
        ]);
    }

    /* ----------------------------------------------------------------- *
     * Summary cards + mapping-coverage donut
     * ----------------------------------------------------------------- */
    public function summary(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        // Active framework (most recently touched 'active' one).
        $activeFramework = DB::table('s_competency_frameworks')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        // Total published competencies == approved skills in the catalog.
        $totalCompetencies = DB::table('s_users_skills')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->where('approve_status', 'Approved')
            ->count();

        // Roles + mapping coverage (distinct jobrole names, internally consistent).
        $allRoles = DB::table('s_user_jobrole')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereNotNull('jobrole')
            ->where('jobrole', '!=', '')
            ->distinct()
            ->pluck('jobrole')
            ->all();
        $totalRoles = count($allRoles);

        // Tenant per-role distinct skill counts (actual mapping).
        $tenantCounts = DB::table('s_user_skill_jobrole')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereNotNull('jobrole')
            ->select('jobrole', DB::raw('COUNT(DISTINCT skill) as c'))
            ->groupBy('jobrole')
            ->pluck('c', 'jobrole')
            ->all();

        // Master baseline distinct skill counts per role (recommended set).
        $masterCounts = DB::table('s_jobrole_skills')
            ->whereIn('jobrole', $totalRoles > 0 ? $allRoles : ['\0__none__'])
            ->select('jobrole', DB::raw('COUNT(DISTINCT skill) as c'))
            ->groupBy('jobrole')
            ->pluck('c', 'jobrole')
            ->all();

        $fully = 0;
        $partial = 0;
        $notMapped = 0;
        foreach ($allRoles as $role) {
            $actual = (int) ($tenantCounts[$role] ?? 0);
            $expected = (int) ($masterCounts[$role] ?? 0);

            if ($actual === 0) {
                $notMapped++;
            } elseif ($expected > 0 && $actual < $expected) {
                $partial++;
            } else {
                $fully++;
            }
        }
        $rolesMapped = $fully + $partial;
        $coverage = $totalRoles > 0 ? (int) round(($rolesMapped / $totalRoles) * 100) : 0;

        $pct = fn ($n) => $totalRoles > 0 ? (int) round(($n / $totalRoles) * 100) : 0;

        return response()->json([
            'status'  => 1,
            'message' => 'Studio summary fetched successfully',
            'data'    => [
                'active_framework' => $activeFramework ? [
                    'id'      => (int) $activeFramework->id,
                    'name'    => $activeFramework->name,
                    'status'  => $activeFramework->status,
                    'version' => $activeFramework->version,
                ] : null,
                'total_competencies' => $totalCompetencies,
                'roles_mapped'       => $rolesMapped,
                'total_roles'        => $totalRoles,
                'coverage_percent'   => $coverage,
                // Last published: the active framework's timestamp, else the most
                // recently touched framework of any status (so it's never blank
                // when frameworks exist).
                'last_published'     => $this->lastPublished($sid, $activeFramework),
                'mapping_summary' => [
                    'total_roles'     => $totalRoles,
                    'fully_mapped'    => $fully,
                    'partially_mapped'=> $partial,
                    'not_mapped'      => $notMapped,
                    'fully_pct'       => $pct($fully),
                    'partial_pct'     => $pct($partial),
                    'not_pct'         => $pct($notMapped),
                ],
            ],
        ]);
    }

    /* ----------------------------------------------------------------- *
     * Framework Structure — category tree from the skill catalog
     * ----------------------------------------------------------------- */
    public function frameworkStructure(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $search = $this->activeFilter($request->input('search'));

        /*
         * FRAMEWORK -> COMPETENCY -> KASBA BUNDLE.
         *
         * This method used to return a SKILL CATEGORY tree read from
         * `s_users_skills`, which is why a screen called Competency Framework
         * showed the skill taxonomy and why framework, competency and KASBA felt
         * impossible to tell apart. The competency table was never consulted.
         *
         * Three flat queries assembled in PHP rather than one nested join: the
         * populations are small (33 frameworks, 227 competencies, 269 bundle
         * items on live) and a three-level join returns the framework row once
         * per leaf, which is more rows to ship and more shape to unpick than the
         * tree it is meant to produce.
         */

        $frameworks = DB::table('s_competency_frameworks')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'version', 'department_id', 'jobrole_id', 'jobrole']);

        /*
         * ⚠ TWO LINKS EXIST BETWEEN A COMPETENCY AND A FRAMEWORK, AND THEY HAVE
         * NEVER ONCE AGREED.
         *
         *     competency.framework_id            18 competencies (live)
         *     s_competency_framework_items      155 rows
         *     rows where the two agree            0
         *
         * Measured on both databases. Not "mostly disagree" - ZERO overlap. They
         * are two parallel systems that have never described the same competency,
         * which is a broken link of the same family as the varchar/tinyint target
         * fixed in 2026_08_24_100000.
         *
         * WHICH ONE IS THE FILING: `competency.framework_id`. It is the column
         * the Competency Library form writes (the Framework picker added in
         * Phase 1), it is a real foreign key, and it expresses the rule that a
         * competency belongs to exactly ONE framework - which a join table
         * cannot enforce.
         *
         * `s_competency_framework_items` keeps a narrower job: the framework's
         * DEFAULT TARGET LEVEL for a competency. Its rows are read here as a
         * lookup, never as the filing, and any row that does not correspond to a
         * competency's actual filing is REPORTED rather than silently honoured.
         */
        $competencies = DB::table('competency')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereNotNull('framework_id')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'framework_id']);

        $targetRows = DB::table('s_competency_framework_items')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            // TINYINT since 2026_08_24_100000. It was varchar 'Level 3', which
            // could never be compared with a role's integer target.
            ->get(['framework_id', 'competency_id', 'required_proficiency']);

        $targetBy = [];
        foreach ($targetRows as $t) {
            $targetBy[(int) $t->framework_id . ':' . (int) $t->competency_id] = $t->required_proficiency;
        }

        // Shaped like the old $items so the assembly below is unchanged.
        $items = $competencies->map(function ($c) use ($targetBy) {
            $key = (int) $c->framework_id . ':' . (int) $c->id;

            return (object) [
                'framework_id'         => $c->framework_id,
                'competency_id'        => $c->id,
                'required_proficiency' => $targetBy[$key] ?? null,
                'competency_name'      => $c->name,
                'competency_code'      => $c->code,
            ];
        });

        $kasba = collect();
        if ($items->isNotEmpty()) {
            $kasba = DB::table('competency_kasba_item')
                ->where('sub_institute_id', $sid)
                ->whereIn('competency_id', $items->pluck('competency_id')->unique()->all())
                ->get(['competency_id', 'kasba_type', 'item_id', 'item_label', 'weight']);

            // THE SHARED RESOLVER, not a second copy - see ResolvesKasbaTitles.
            $kasba = $this->attachKasbaTitles($kasba, (int) $sid);
        }

        $bundleByCompetency = [];
        foreach ($kasba as $k) {
            $bundleByCompetency[(int) $k->competency_id][] = [
                'kasba_type'    => $k->kasba_type,
                'item_id'       => $k->item_id !== null ? (int) $k->item_id : null,
                'title'         => $k->title,
                'title_missing' => $k->title_missing,
                'weight'        => (float) $k->weight,
            ];
        }

        $competenciesByFramework = [];
        foreach ($items as $row) {
            $competenciesByFramework[(int) $row->framework_id][] = [
                'competency_id'   => (int) $row->competency_id,
                'name'            => $row->competency_name,
                'code'            => $row->competency_code,
                // The framework DEFAULT. A role may override it; the effective
                // target is `role override ?? this`.
                'framework_target' => $row->required_proficiency !== null
                    ? (int) $row->required_proficiency
                    : null,
                'items'           => $bundleByCompetency[(int) $row->competency_id] ?? [],
            ];
        }

        $matches = function (string $haystack) use ($search): bool {
            return !$search || stripos($haystack, $search) !== false;
        };

        $tree = [];
        $index = 0;
        foreach ($frameworks as $f) {
            $own = $competenciesByFramework[(int) $f->id] ?? [];

            // A framework survives the search if it matches, or if any
            // competency inside it does - searching for a competency should
            // show you where it lives, not hide it.
            if ($search) {
                $childMatch = collect($own)->contains(fn ($c) => $matches((string) $c['name']));
                if (!$matches((string) $f->name) && !$childMatch) {
                    continue;
                }
            }

            $index++;
            $tree[] = [
                'index'         => $index,
                'framework_id'  => (int) $f->id,
                'name'          => $f->name,
                'status'        => $f->status,
                'version'       => $f->version,
                'department_id' => $f->department_id !== null ? (int) $f->department_id : null,
                'jobrole_id'    => $f->jobrole_id !== null ? (int) $f->jobrole_id : null,
                'jobrole'       => $f->jobrole,
                'count'         => count($own),
                'competencies'  => $own,
            ];
        }

        /*
         * COMPETENCIES BELONGING TO NO FRAMEWORK ARE REPORTED, NOT HIDDEN.
         *
         * On live, tenant 1 holds 199 competencies with no `framework_id` -
         * damage from the period when the create form sent a skill `category`
         * that the backend correctly discarded. A structure view that silently
         * omitted them would show an author a tidy tree and no hint that most of
         * their library is missing from it.
         */
        $unfiled = DB::table('competency')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereNull('framework_id')
            ->when($search !== null && $search !== '', fn ($q) => $q->where('name', 'like', '%' . $search . '%'))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return response()->json([
            'status'  => 1,
            'message' => 'Framework structure fetched successfully',
            'data'    => $tree,
            'meta'    => [
                'frameworks'    => count($tree),
                'competencies'  => $items->count(),
                'unfiled_count' => $unfiled->count(),
                'unfiled'       => $unfiled,
                /*
                 * Target rows that describe a (framework, competency) pairing
                 * the competency itself does not claim. Currently that is EVERY
                 * row - the two links have zero overlap - so this number is the
                 * size of the disagreement, and it belongs on screen rather than
                 * in a comment.
                 */
                'orphan_targets' => $targetRows
                    ->reject(function ($t) use ($competencies) {
                        return $competencies->contains(
                            fn ($c) => (int) $c->id === (int) $t->competency_id
                                && (int) $c->framework_id === (int) $t->framework_id
                        );
                    })
                    ->count(),
            ],
        ]);
    }

    /* ----------------------------------------------------------------- *
     * Proficiency scale + KASA behavioural indicators
     * ----------------------------------------------------------------- */
    public function proficiencyScale(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        // Tenant-global scale (skill_id NULL). Fall back to any tenant rows.
        $levelQuery = DB::table('s_proficiency_levels')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at');

        $levels = (clone $levelQuery)->whereNull('skill_id')
            ->orderByRaw('CAST(proficiency_type AS UNSIGNED)')
            ->get();

        if ($levels->isEmpty()) {
            $levels = $levelQuery->orderByRaw('CAST(proficiency_type AS UNSIGNED)')->get();
        }

        $scale = $levels->map(fn ($row) => [
            'id'          => (int) $row->id,
            'level'       => (int) $row->proficiency_type,
            'label'       => $row->proficiency_level,      // "Level 3"
            'name'        => $row->type_description,        // "Applied Expertise"
            'description' => $row->description,
        ])->values()->all();

        // KASA descriptors (one small read per dimension).
        $kasa = [];
        foreach ([
            'knowledge' => 's_proficiency_knowledge',
            'ability'   => 's_proficiency_ability',
            'attitude'  => 's_proficiency_attitude',
            'behaviour' => 's_proficiency_behaviour',
        ] as $key => $table) {
            $rows = DB::table($table)
                ->where('sub_institute_id', $sid)
                ->orderByRaw('CAST(level AS UNSIGNED)')
                ->get(['level', 'descriptor', 'indicators']);

            $kasa[$key] = $rows->map(fn ($r) => [
                'level'      => (int) $r->level,
                'descriptor' => $r->descriptor,
                'indicators' => $r->indicators,
            ])->values()->all();
        }

        return response()->json([
            'status'  => 1,
            'message' => 'Proficiency scale fetched successfully',
            'data'    => [
                'levels' => $scale,
                'kasa'   => $kasa,
            ],
        ]);
    }

    /** Add a level to the tenant-global proficiency scale. */
    public function storeLevel(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:191',
            'description' => 'nullable|string',
            'level'       => 'nullable|integer|min:1|max:20',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $next = $request->input('level');
        if (!$next) {
            $max = DB::table('s_proficiency_levels')
                ->where('sub_institute_id', $sid)
                ->whereNull('skill_id')
                ->whereNull('deleted_at')
                ->max(DB::raw('CAST(proficiency_type AS UNSIGNED)'));
            $next = (int) $max + 1;
        }

        $id = DB::table('s_proficiency_levels')->insertGetId([
            'skill_id'          => null,
            'proficiency_level' => 'Level ' . $next,
            'proficiency_type'  => (string) $next,
            'type_description'  => $request->input('name'),
            'description'       => $request->input('description'),
            'sub_institute_id'  => $sid,
            'created_by'        => $context['user_id'],
            'updated_by'        => $context['user_id'],
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'created_proficiency_level',
            'Added proficiency level ' . $next . ' "' . $request->input('name') . '"',
            'proficiency_level',
            $id,
            $request->input('name')
        );

        return response()->json(['status' => 1, 'message' => 'Level added successfully', 'data' => ['id' => $id]], 201);
    }

    /** Edit a proficiency level's name / description / label. */
    public function updateLevel(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:191',
            'description' => 'nullable|string',
            'label'       => 'nullable|string|max:191',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $existing = DB::table('s_proficiency_levels')
            ->where('id', $id)->where('sub_institute_id', $sid)->whereNull('deleted_at')->first();
        if (!$existing) {
            return response()->json(['status' => 0, 'message' => 'Level not found'], 404);
        }

        $update = [
            'type_description' => $request->input('name'),
            'description'      => $request->input('description'),
        ];
        if ($request->filled('label')) {
            $update['proficiency_level'] = $request->input('label');
        }

        DB::table('s_proficiency_levels')->where('id', $id)->update($update + [
            'updated_by'       => $context['user_id'],
            'updated_at'       => now(),
        ]);

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'updated_proficiency_level',
            'Updated proficiency level "' . $request->input('name') . '"',
            'proficiency_level',
            (int) $id,
            $request->input('name'),
            $this->diffChanges($existing, $update, [
                'type_description'  => 'Level Name',
                'description'       => 'Description',
                'proficiency_level' => 'Level Label',
            ])
        );

        return response()->json(['status' => 1, 'message' => 'Level updated successfully']);
    }

    /** Soft-delete a proficiency level. */
    public function deleteLevel(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

        $existing = DB::table('s_proficiency_levels')
            ->where('id', $id)->where('sub_institute_id', $sid)->whereNull('deleted_at')->first();
        if (!$existing) {
            return response()->json(['status' => 0, 'message' => 'Level not found'], 404);
        }

        DB::table('s_proficiency_levels')->where('id', $id)->update([
            'deleted_at' => now(),
            'deleted_by' => $context['user_id'],
        ]);

        $levelName = $existing->type_description ?: $existing->proficiency_level;

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'deleted_proficiency_level',
            'Deleted proficiency level "' . $levelName . '"',
            'proficiency_level',
            (int) $id,
            $levelName
        );

        return response()->json(['status' => 1, 'message' => 'Level deleted successfully']);
    }

    /** Formatted last-published date: active framework, else latest of any status. */
    private function lastPublished(int $sid, $activeFramework): ?string
    {
        $latest = $activeFramework ?: DB::table('s_competency_frameworks')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        return $latest && $latest->updated_at
            ? Carbon::parse($latest->updated_at)->format('M j, Y')
            : null;
    }

    /* ----------------------------------------------------------------- *
     * Default category weighting profile (framework_id = null).
     * The Weighting & Configuration tab edits this tenant-wide profile;
     * per-framework overrides live on FrameworkController::weights.
     * ----------------------------------------------------------------- */
    public function weights(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = $context['sub_institute_id'];

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
            ->whereNull('framework_id')
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

    public function saveWeights(Request $request)
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
                ->whereNull('framework_id')
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
                    'framework_id'     => null,
                    'category'         => $category,
                    'weight'           => $weight,
                    'created_by'       => $context['user_id'],
                    'updated_by'       => $context['user_id'],
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }
        }

        $this->logCompetencyActivity(
            $sid,
            $context['user_id'],
            'updated_framework_weights',
            'Updated the tenant default competency weighting profile',
            'framework_weight',
            null,
            'Default Weighting Profile',
            $changes
        );

        return response()->json(['status' => 1, 'message' => 'Weighting saved successfully']);
    }
}
