<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * THE COMPETENCY LIBRARY — REAL COMPETENCIES, BEHIND THE EXISTING RICH SCREEN.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `cm-competency-library.tsx` is 1,799 lines of good screen: list, filters,
 * sorting, detail drawer, import, taxonomy editing. It has always been fed by
 * SkillLibraryCrudController on `s_users_skills` - a SKILL table wearing
 * competency labels. That is G-RBAC-02b, and its own docblock admits it.
 *
 * `cm-competency-definitions.tsx` reads the REAL competency tables but is 186
 * lines: a list and a create form, nothing else.
 *
 *     ONE SCREEN HAD THE INTERFACE. THE OTHER HAD THE DATA.
 *
 * So this controller serves the SAME RESPONSE SHAPE the rich screen already
 * expects, from `competency` and `competency_kasba_item`. The screen keeps every
 * feature and starts showing competencies. No component is rewritten, and the
 * skill endpoints are left untouched so skill management is not orphaned.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * FIELDS THE SKILL TABLE HAD AND A COMPETENCY DOES NOT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Returned as NULL rather than invented or borrowed:
 *
 *   category / sub_category   a competency belongs to a FRAMEWORK, not a skill
 *                             taxonomy. framework_name is returned as `category`
 *                             because that is the honest equivalent - the thing
 *                             it is filed under.
 *   department                competencies are not departmental; job roles are.
 *   approve_status            there is a competency_approvals table, but it is
 *                             empty and its workflow is unbuilt. Reporting
 *                             "Approved" for everything would be a claim nobody
 *                             made.
 *   the eight detail columns  free-text skill fields with no competency
 *                             equivalent. NULL, not blank strings: absent and
 *                             empty are different answers.
 *
 * WHAT A COMPETENCY HAS THAT A SKILL NEVER DID is returned as well: its KASBA
 * items, counted by dimension. That is the whole point of the model and no skill
 * row could express it.
 */
class CompetencyLibraryCrudController extends Controller
{
    use ResolvesCompetencyContext;

    private const SORTABLE = [
        'title'           => 'c.name',
        'category'        => 'f.name',
        'competency_type' => 'c.competency_type',
        'updated_at'      => 'c.updated_at',
        'created_at'      => 'c.created_at',
    ];

    /** GET /competency-library/competency-list */
    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid     = (int) $context['sub_institute_id'];
        $perPage = min(max((int) $request->input('per_page', 25), 1), 200);
        $page    = max((int) $request->input('page', 1), 1);
        $search  = trim((string) $request->input('search', ''));
        $sort    = self::SORTABLE[$request->input('sort_by', 'updated_at')] ?? 'c.updated_at';
        $dir     = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $base = fn () => DB::table('competency as c')
            ->leftJoin('s_competency_frameworks as f', 'f.id', '=', 'c.framework_id')
            ->where('c.sub_institute_id', $sid)
            ->whereNull('c.deleted_at')
            ->when($search !== '', function ($w) use ($search) {
                $w->where(function ($x) use ($search) {
                    $x->where('c.name', 'like', "%{$search}%")
                      ->orWhere('c.code', 'like', "%{$search}%")
                      ->orWhere('c.description', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('competency_type'), fn ($w) => $w->where('c.competency_type', $request->input('competency_type')))
            ->when($request->filled('framework_id'), fn ($w) => $w->where('c.framework_id', $request->integer('framework_id')));

        $total = $base()->count();

        $rows = $base()->orderBy($sort, $dir)
            ->forPage($page, $perPage)
            ->get([
                'c.id', 'c.name', 'c.code', 'c.description', 'c.competency_type',
                'c.criticality', 'c.status', 'c.framework_id', 'c.created_by',
                'c.created_at', 'c.updated_at', 'f.name as framework_name',
            ]);

        // Item counts in one query rather than N - a library page listing 200
        // competencies must not fire 200 follow-ups.
        $counts = DB::table('competency_kasba_item')
            ->whereIn('competency_id', $rows->pluck('id'))
            ->selectRaw('competency_id, COUNT(*) n')
            ->groupBy('competency_id')->pluck('n', 'competency_id');

        return response()->json([
            'status'  => true,
            'message' => 'Success',
            'data'    => $rows->map(fn ($r) => $this->shape($r, (int) ($counts[$r->id] ?? 0)))->values(),
            'pagination' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) ceil($total / $perPage),
            ],
            'empty_is_expected' => $total === 0,
            'empty_reason' => $total === 0
                ? 'No competencies have been created for your organisation yet.'
                : null,
        ]);
    }

    /** GET /competency-library/competency/{id} */
    public function show(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];

        $row = DB::table('competency as c')
            ->leftJoin('s_competency_frameworks as f', 'f.id', '=', 'c.framework_id')
            ->where('c.id', (int) $id)->where('c.sub_institute_id', $sid)->whereNull('c.deleted_at')
            ->first([
                'c.id', 'c.name', 'c.code', 'c.description', 'c.competency_type',
                'c.criticality', 'c.status', 'c.framework_id', 'c.created_by',
                'c.created_at', 'c.updated_at', 'f.name as framework_name',
            ]);

        if (!$row) {
            return response()->json(['status' => false, 'message' => 'Competency not found.'], 404);
        }

        $items = DB::table('competency_kasba_item')
            ->where('competency_id', $row->id)->where('sub_institute_id', $sid)
            ->orderBy('kasba_type')->orderBy('item_label')
            ->get(['id', 'kasba_type', 'item_id', 'item_label', 'weight']);

        $shaped = $this->shape($row, $items->count());
        // THE PART NO SKILL ROW COULD CARRY.
        $shaped['items'] = $items;
        $shaped['items_by_type'] = $items->groupBy('kasba_type')->map->count();

        /*
         * The scale, so the edit dialog can seed it without a second request.
         * Each level carries what it INHERITS as well as what it overrides -
         * an editor that showed only an empty box could not tell an author
         * what they were replacing.
         */
        $shaped['levels'] = $this->readLevels((int) $row->id, $sid);

        return response()->json(['status' => true, 'message' => 'Success', 'data' => $shaped]);
    }

    /** POST /competency-library/competency */
    public function store(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'name'            => 'required|string|max:191',
            'code'            => 'nullable|string|max:64',
            'description'     => 'nullable|string',
            'competency_type' => 'nullable|string|max:64',
            'criticality'     => 'nullable|string|max:32',
            'framework_id'    => 'nullable|integer',
            // THE KASBA ITEMS, OPTIONAL ON CREATE BUT ACCEPTED HERE.
            // Without these the library could only make an empty competency -
            // a heading with nothing measurable under it. The Definitions screen
            // could build them and the library could not, which is why the two
            // screens could not simply be merged by renaming a menu.
            'items'              => 'nullable|array',
            'items.*.kasba_type' => 'required_with:items|string|in:knowledge,ability,skill,behaviour,attitude',
            'items.*.item_id'    => 'nullable|integer',
            'items.*.item_label' => 'nullable|string|max:191',
            'items.*.weight'     => 'nullable|numeric|min:0|max:100',

            /*
             * The competency's own L1-L5 scale, riding the same payload as its
             * bundle. Levels are keyed by competency_id, which does not exist
             * during create - sending them together is what lets create and
             * edit behave identically without threading a new id back through
             * the mutation layer.
             */
            'levels'              => 'nullable|array|max:5',
            'levels.*.level'      => 'required_with:levels|integer|min:1|max:5',
            'levels.*.descriptor' => 'nullable|string',
            'levels.*.indicators' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
        }

        $sid   = (int) $context['sub_institute_id'];
        $actor = (int) $context['user_id'];

        // Same tenant check as CompetencyDefinitionController: a bare exists rule
        // would accept another organisation's framework id.
        $fw = $request->input('framework_id') !== null ? (int) $request->input('framework_id') : null;
        if ($fw !== null && !DB::table('s_competency_frameworks')->where('id', $fw)->where('sub_institute_id', $sid)->exists()) {
            return response()->json(['status' => false, 'message' => 'That framework does not exist in your organisation.'], 404);
        }

        $code = $request->input('code');
        if ($code && DB::table('competency')->where('sub_institute_id', $sid)->where('code', $code)->exists()) {
            return response()->json(['status' => false, 'message' => 'That competency code is already used in this organisation.'], 422);
        }

        /*
         * competency.code is NOT NULL, and the library form has no Code field.
         *
         * So every create from that screen inserted NULL and died with
         * "Column 'code' cannot be null" - a 500, not a validation message.
         * Creating a competency from the Competency Library has never worked.
         *
         * A code is generated from the name rather than made required: it is a
         * human reference, the existing 226 rows carry curated ones like
         * HC-CLIN-01, and asking every author to invent a unique string before
         * they can save is a worse screen than deriving one they can edit later.
         */
        if (!$code) {
            $code = $this->generateCode($sid, (string) $request->input('name'));
        }

        $items = $request->input('items', []);
        $id = null;
        $written = 0;

        // ONE TRANSACTION. A competency that lands without its items is worse
        // than one that fails outright: it looks authored and measures nothing.
        DB::transaction(function () use (&$id, &$written, $sid, $fw, $code, $actor, $request, $items) {
            $id = DB::table('competency')->insertGetId([
                'sub_institute_id' => $sid,
                'framework_id'     => $fw,
                'code'             => $code,
                'name'             => $request->input('name'),
                'description'      => $request->input('description'),
                'competency_type'  => $request->input('competency_type'),
                'criticality'      => $request->input('criticality'),
                'status'           => 1,
                'created_by'       => $actor,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            foreach ($items as $item) {
                // item_id is the TARGET - a row in a canonical table. item_label
                // is the HOLDING - free text for something not yet canonical.
                // Both are permitted; neither is invented from the other.
                DB::table('competency_kasba_item')->insert([
                    'sub_institute_id' => $sid,
                    'competency_id'    => $id,
                    'kasba_type'       => $item['kasba_type'],
                    'item_id'          => isset($item['item_id']) ? (int) $item['item_id'] : null,
                    'item_label'       => $item['item_label'] ?? null,
                    'weight'           => $item['weight'] ?? 1,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
                $written++;
            }

            // The competency's own L1-L5 scale, in the SAME transaction and
            // through the SAME writer the standalone endpoint uses. A blank
            // level stores nothing and inherits the organisation default.
            $this->writeLevels($id, $sid, $request->input('levels', []), $actor);
        });

        return response()->json([
            'status'  => true,
            'message' => $written
                ? sprintf('Competency created with %d capability item(s).', $written)
                : 'Competency created.',
            'data'    => ['id' => $id, 'items_created' => $written],
            // Said plainly rather than left for the user to discover: a
            // competency with no items cannot be rated against.
            'next_step' => $written
                ? null
                : 'Add capability items to this competency so people can be rated against it.',
        ], 201);
    }

    /** PUT /competency-library/competency/{id} */
    /**
     * A tenant-unique code derived from the competency's name.
     *
     * Shape follows what is already in the table - uppercase, hyphenated, short
     * - so a generated code sits beside the curated ones without looking alien.
     * The numeric suffix only appears when it has to, and uq_competency_tenant_code
     * is what it is defending.
     */
    private function generateCode(int $sid, string $name): string
    {
        $stem = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', trim($name)) ?: 'COMPETENCY');
        $stem = trim($stem, '-');
        // Leave room for a "-99" suffix inside varchar(64).
        $stem = substr($stem, 0, 60) ?: 'COMPETENCY';

        $candidate = $stem;
        $suffix    = 1;

        while (DB::table('competency')->where('sub_institute_id', $sid)->where('code', $candidate)->exists()) {
            $candidate = $stem . '-' . (++$suffix);
        }

        return $candidate;
    }

    public function update(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];

        $exists = DB::table('competency')->where('id', (int) $id)
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')->exists();

        if (!$exists) {
            return response()->json(['status' => false, 'message' => 'Competency not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name'            => 'sometimes|required|string|max:191',
            'code'            => 'nullable|string|max:64',
            'description'     => 'nullable|string',
            'competency_type' => 'nullable|string|max:64',
            'criticality'     => 'nullable|string|max:32',
            'framework_id'    => 'nullable|integer',
            'status'          => 'nullable|integer',
            // THE COMPOSITION, EDITABLE AT LAST.
            //
            // store() has always accepted items; update() did not, so once a
            // competency existed its KASBA bundle was frozen - there was no
            // route and no screen that could add, correct or remove one. That
            // is a large part of why 66 of 266 items on live are still
            // free-text labels: whoever typed them had no way back in.
            //
            // Omitting `items` leaves the composition untouched. Sending it
            // REPLACES it, the same sync semantics RoleCompetencyMapController
            // uses, so the client sends the state it wants rather than a diff.
            'items'              => 'nullable|array',
            'items.*.kasba_type' => 'required_with:items|string|in:knowledge,ability,skill,behaviour,attitude',
            'items.*.item_id'    => 'nullable|integer',
            'items.*.item_label' => 'nullable|string|max:191',
            'items.*.weight'     => 'nullable|numeric|min:0|max:100',

            /*
             * The competency's own L1-L5 scale, riding the same payload as its
             * bundle. Levels are keyed by competency_id, which does not exist
             * during create - sending them together is what lets create and
             * edit behave identically without threading a new id back through
             * the mutation layer.
             */
            'levels'              => 'nullable|array|max:5',
            'levels.*.level'      => 'required_with:levels|integer|min:1|max:5',
            'levels.*.descriptor' => 'nullable|string',
            'levels.*.indicators' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
        }

        $update = array_filter([
            'name'            => $request->input('name'),
            'code'            => $request->input('code'),
            'description'     => $request->input('description'),
            'competency_type' => $request->input('competency_type'),
            'criticality'     => $request->input('criticality'),
            'status'          => $request->input('status'),
        ], fn ($v) => $v !== null);

        if ($request->has('framework_id')) {
            $fw = $request->input('framework_id') !== null ? (int) $request->input('framework_id') : null;
            if ($fw !== null && !DB::table('s_competency_frameworks')->where('id', $fw)->where('sub_institute_id', $sid)->exists()) {
                return response()->json(['status' => false, 'message' => 'That framework does not exist in your organisation.'], 404);
            }
            $update['framework_id'] = $fw;
        }

        $update['updated_by'] = (int) $context['user_id'];
        $update['updated_at'] = now();

        $itemsGiven = $request->has('items');
        $items      = $request->input('items', []);
        $written    = 0;

        // One transaction: a competency whose row updated but whose composition
        // did not is a competency that measures something other than it claims.
        DB::transaction(function () use ($id, $sid, $update, $itemsGiven, $items, $request, $context, &$written) {
            DB::table('competency')->where('id', (int) $id)->where('sub_institute_id', $sid)->update($update);

            /*
             * Levels are written BEFORE the items early-return, because a
             * caller may edit the scale without touching the bundle. Putting
             * it after `if (!$itemsGiven) return;` would silently drop every
             * scale edit that did not also resend the items.
             */
            if ($request->has('levels')) {
                $this->writeLevels((int) $id, $sid, $request->input('levels', []), $context['user_id']);
            }

            if (!$itemsGiven) {
                return;
            }

            // Replace, tenant-scoped. competency_kasba_item has no deleted_at,
            // so this is a hard delete by design - an item removed from a
            // competency was never part of it, rather than retired from it.
            DB::table('competency_kasba_item')
                ->where('competency_id', (int) $id)
                ->where('sub_institute_id', $sid)
                ->delete();

            foreach ($items as $item) {
                DB::table('competency_kasba_item')->insert([
                    'sub_institute_id' => $sid,
                    'competency_id'    => (int) $id,
                    'kasba_type'       => $item['kasba_type'],
                    // Same rule as store(): item_id is the resolved target,
                    // item_label the holding state. Neither is invented from
                    // the other.
                    'item_id'          => isset($item['item_id']) ? (int) $item['item_id'] : null,
                    'item_label'       => $item['item_label'] ?? null,
                    'weight'           => $item['weight'] ?? 1,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
                $written++;
            }
        });

        return response()->json([
            'status'  => true,
            'message' => 'Competency updated.',
            'data'    => ['id' => (int) $id, 'items_written' => $itemsGiven ? $written : null],
        ]);
    }

    /** DELETE /competency-library/competency/{id} — SOFT, and it says what it kept. */
    /**
     * WHAT L1 VERSUS L4 ACTUALLY LOOKS LIKE, PER COMPETENCY.
     *
     * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
     *
     * A target level was a number with no definition behind it. `s_proficiency_
     * levels` is keyed by `skill_id` and has no `competency_id` column at all,
     * so a competency could not say what "Level 3" means for it — which is why
     * the proficiency scale never felt real. `competency_proficiency_levels`
     * (2026_08_24_100000) fixes that, and this is its only reader and writer.
     *
     * ── SPARSE BY DESIGN: BLANK MEANS "USE THE ORGANISATION DEFAULT" ────────
     *
     * The table holds ONLY authored overrides. Seeding five rows per competency
     * would be 1,135 copies of the same boilerplate and would make every
     * competency look authored when none were. A level with no row falls back
     * to the organisation's generic descriptor, returned here as `default_
     * descriptor` so the editor can show what it would inherit rather than an
     * empty box that says nothing.
     *
     * ── SAVING A BLANK DELETES, IT DOES NOT STORE AN EMPTY STRING ──────────
     *
     * Clearing a descriptor must return the level to the default, not pin it to
     * "". An empty override and no override look identical on screen and mean
     * opposite things.
     */
    public function levels(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = (int) $context['sub_institute_id'];

        $competency = DB::table('competency')
            ->where('id', $id)->where('sub_institute_id', $sid)->whereNull('deleted_at')->first();

        if (!$competency) {
            return response()->json(['status' => 0, 'message' => 'Competency not found'], 404);
        }

        return response()->json([
            'status'  => 1,
            'message' => 'Proficiency levels fetched successfully',
            'data'    => [
                'competency_id' => (int) $id,
                'levels'        => $this->readLevels((int) $id, $sid),
            ],
        ]);
    }

    /**
     * THE ONE READER for a competency's scale — used by `levels()` and by
     * `show()`, so the edit dialog and the standalone endpoint cannot disagree
     * about what a level says.
     *
     * Always returns five rows, authored or not. A level the competency has not
     * overridden carries `is_authored: false` and the `default_descriptor` it
     * inherits, because an editor showing only an empty box cannot tell an
     * author what they would be replacing.
     *
     * @return list<array{level:int, descriptor:?string, indicators:?string, default_descriptor:?string, is_authored:bool}>
     */
    private function readLevels(int $competencyId, int $subInstituteId): array
    {
        // keyBy, not pluck: the whole row is wanted, keyed by level.
        $authored = DB::table('competency_proficiency_levels')
            ->where('competency_id', $competencyId)
            ->get(['level', 'descriptor', 'indicators'])
            ->keyBy(fn ($r) => (int) $r->level);

        /*
         * The organisation's generic scale. `skill_id IS NULL` marks the
         * generic rows; `proficiency_type` holds the level number.
         *
         * NULL sub_institute_id is accepted alongside the tenant's own, because
         * the platform ships a default scale that predates per-tenant ones.
         */
        $defaults = DB::table('s_proficiency_levels')
            ->whereNull('skill_id')
            ->where(function ($q) use ($subInstituteId) {
                $q->where('sub_institute_id', $subInstituteId)->orWhereNull('sub_institute_id');
            })
            ->orderBy('id')
            ->get(['proficiency_type', 'description'])
            ->keyBy(fn ($r) => (int) $r->proficiency_type);

        $levels = [];
        foreach (range(1, 5) as $level) {
            $own = $authored->get($level);
            $levels[] = [
                'level'              => $level,
                'descriptor'         => $own->descriptor ?? null,
                'indicators'         => $own->indicators ?? null,
                'default_descriptor' => $defaults[$level]->description ?? null,
                'is_authored'        => $own !== null,
            ];
        }

        return $levels;
    }

    /** PUT — replace this competency's authored levels. */
    public function saveLevels(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }
        $sid = (int) $context['sub_institute_id'];

        $competency = DB::table('competency')
            ->where('id', $id)->where('sub_institute_id', $sid)->whereNull('deleted_at')->first();

        if (!$competency) {
            return response()->json(['status' => 0, 'message' => 'Competency not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'levels'              => 'required|array|max:5',
            'levels.*.level'      => 'required|integer|min:1|max:5',
            'levels.*.descriptor' => 'nullable|string',
            'levels.*.indicators' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 0,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $result = DB::transaction(
            fn () => $this->writeLevels((int) $id, $sid, $request->input('levels', []), $context['user_id'])
        );

        return response()->json([
            'status'  => 1,
            'message' => 'Proficiency levels saved successfully',
            'data'    => ['competency_id' => (int) $id] + $result,
        ]);
    }

    /**
     * THE ONE WRITER for a competency's authored levels.
     *
     * Called by three entry points — `PUT .../levels`, `store()` and `update()`
     * — because the rule below must have exactly one implementation. Two copies
     * of it would be two behaviours, and the one that drifted would be the one
     * nobody tested.
     *
     * ── A BLANK DELETES; IT DOES NOT STORE AN EMPTY STRING ─────────────────
     *
     * Clearing a descriptor returns that level to the organisation default. An
     * empty override and no override render identically and mean opposite
     * things — "we decided L3 means nothing" versus "we have not decided yet" —
     * so the empty one must not be storable.
     *
     * @param  array<int, array<string, mixed>>  $levels
     * @return array{written:int, cleared:int}
     */
    private function writeLevels(int $competencyId, int $subInstituteId, array $levels, $userId): array
    {
        $written = 0;
        $cleared = 0;

        foreach ($levels as $row) {
            $level = (int) ($row['level'] ?? 0);
            if ($level < 1 || $level > 5) {
                continue;               // outside the scale is not a level
            }

            $descriptor = trim((string) ($row['descriptor'] ?? ''));
            $indicators = trim((string) ($row['indicators'] ?? ''));

            if ($descriptor === '' && $indicators === '') {
                $cleared += DB::table('competency_proficiency_levels')
                    ->where('competency_id', $competencyId)->where('level', $level)->delete();
                continue;
            }

            DB::table('competency_proficiency_levels')->updateOrInsert(
                ['competency_id' => $competencyId, 'level' => $level],
                [
                    'sub_institute_id' => $subInstituteId,
                    'descriptor'       => $descriptor !== '' ? $descriptor : null,
                    'indicators'       => $indicators !== '' ? $indicators : null,
                    'updated_by'       => $userId,
                    'updated_at'       => now(),
                    'created_at'       => now(),
                ],
            );
            $written++;
        }

        return ['written' => $written, 'cleared' => $cleared];
    }

    public function destroy(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];

        $row = DB::table('competency')->where('id', (int) $id)
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')->first(['id']);

        if (!$row) {
            return response()->json(['status' => false, 'message' => 'Competency not found.'], 404);
        }

        // WHAT WOULD BE ORPHANED, COUNTED AND REPORTED. A competency in use by a
        // job role is not a free deletion, and the caller should know before the
        // gap analysis quietly loses a requirement.
        $roles = DB::table('jobrole_competency_map')->where('competency_id', $row->id)->count();
        $items = DB::table('competency_kasba_item')->where('competency_id', $row->id)->count();

        DB::table('competency')->where('id', $row->id)->update([
            'deleted_at' => now(),
            'deleted_by' => (int) $context['user_id'],
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Competency removed.',
            // SOFT DELETE: the row is retained and can be restored. Its items and
            // role mappings are NOT removed - deleting them would destroy history
            // that a restore could not rebuild.
            'retained' => ['kasba_items' => $items, 'jobrole_mappings' => $roles],
        ]);
    }

    /**
     * The shape the existing screen expects, from a competency row.
     *
     * Fields with no competency equivalent are NULL, never a placeholder string.
     * Absent and empty are different answers, and a screen that shows "-" for
     * both cannot tell a user which it is looking at.
     */
    private function shape($r, int $itemCount): array
    {
        return [
            'id'              => (int) $r->id,
            'name'            => $r->name,
            'code'            => $r->code,
            'description'     => $r->description,
            // The framework is what a competency is filed under - the honest
            // equivalent of the skill taxonomy's category.
            'category'        => $r->framework_name,
            'sub_category'    => null,
            'competency_type' => $r->competency_type,
            'proficiency_level' => $r->criticality,
            'department'      => null,
            'department_id'   => null,
            'status'          => ((int) ($r->status ?? 1)) === 1 ? 'Active' : 'Inactive',
            // The approvals table exists and its workflow does not. Reporting a
            // status nobody set would be a claim nobody made.
            'approve_status'  => null,
            'owner'           => null,
            'created_at'      => $r->created_at,
            'updated_at'      => $r->updated_at,
            'created_by'      => $r->created_by !== null ? (int) $r->created_by : null,
            'framework_id'    => $r->framework_id !== null ? (int) $r->framework_id : null,
            // What no skill row could carry.
            'items_count'     => $itemCount,
        ];
    }
}
