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
 *   approve_status            NO LONGER NULL — corrected 2026-08-31. The claim
 *                             below was wrong twice over: `s_competency_approvals`
 *                             has a complete submit/review workflow in
 *                             ApprovalController, and it was pointed at
 *                             `s_users_skills` while this screen moved to
 *                             `competency`. Since the two tables' ids overlap, a
 *                             submission from here would have stamped an
 *                             unrelated skill row. Both are fixed; this returns
 *                             the real value, and NULL still means "never
 *                             submitted" rather than a decision nobody made.
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
        /*
         * THE SCREEN SENDS `sort` AND `direction`. THIS READ `sort_by` AND
         * `sort_dir`, SO EVERY COLUMN-HEADER CLICK RETURNED THE SAME PAGE.
         *
         * Both spellings are accepted rather than picking one and breaking the
         * other caller: `sort_by`/`sort_dir` is what this controller has always
         * documented, `sort`/`direction` is what cm-competency-library.tsx has
         * always sent. An unknown column still falls back to `updated_at` rather
         * than erroring, which is what made the mismatch invisible.
         */
        $sortKey = (string) ($request->input('sort_by') ?? $request->input('sort') ?? 'updated_at');
        $dirRaw  = (string) ($request->input('sort_dir') ?? $request->input('direction') ?? 'desc');

        $sort = self::SORTABLE[$sortKey] ?? 'c.updated_at';
        $dir  = strtolower($dirRaw) === 'asc' ? 'asc' : 'desc';

        $base = fn () => $this->listQuery($request, $sid);

        $total = $base()->count();

        $rows = $base()->orderBy($sort, $dir)
            ->forPage($page, $perPage)
            ->get([
                'c.id', 'c.name', 'c.code', 'c.description', 'c.competency_type',
                'c.criticality', 'c.status', 'c.approve_status', 'c.framework_id', 'c.created_by',
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

    /**
     * The filtered library, WITHOUT paging or ordering.
     *
     * Extracted so index() and export() cannot drift apart. They had no shared
     * definition, which is how an export silently answering a different question
     * from the screen that launched it becomes possible.
     */
    private function listQuery(Request $request, int $sid)
    {
        $search = trim((string) $request->input('search', ''));

        return DB::table('competency as c')
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
            ->when($request->filled('framework_id'), fn ($w) => $w->where('c.framework_id', $request->integer('framework_id')))
            /*
             * THE STATUS DROPDOWN NOW DOES SOMETHING. There was no status filter
             * here at all, so the control was inert.
             *
             * Matched case-insensitively against BOTH columns because the screen's
             * one dropdown covers two different lifecycles: `status` holds
             * active/draft/published (what the competency IS) and `approve_status`
             * holds Pending/Approved/Rejected (where it is in review). Requiring
             * the user to know which column their word lives in would be a schema
             * detail leaking into a filter.
             */
            ->when($request->filled('status') && $request->input('status') !== 'all', function ($w) use ($request) {
                $value = strtolower(trim((string) $request->input('status')));
                $w->where(function ($x) use ($value) {
                    $x->whereRaw('LOWER(c.status) = ?', [$value])
                      ->orWhereRaw('LOWER(COALESCE(c.approve_status, \'\')) = ?', [$value]);
                });
            });
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
                'c.criticality', 'c.status', 'c.approve_status', 'c.framework_id', 'c.created_by',
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

        /*
         * WHO A CHANGE HERE WOULD REACH.
         *
         * A competency's bundle and weights are shared by everyone assessed
         * against it: re-weighting one item silently re-scores every employee
         * who holds it, including people the editor is not looking at. An
         * editor that does not say so is asking for a decision without showing
         * its cost.
         *
         * Two different numbers, deliberately:
         *   roles_requiring  - how many job roles ask for this competency
         *   employees_rated  - how many people already have a rating that
         *                      feeds its roll-up, i.e. whose LEVEL moves
         *
         * The second is the one that matters for a weight change; the first
         * is what matters for renaming or re-describing it.
         */
        $shaped['usage'] = [
            'roles_requiring' => (int) DB::table('jobrole_competency_map')
                ->where('sub_institute_id', $sid)
                ->where('competency_id', $row->id)
                ->count(),
            'employees_rated' => (int) DB::table('competency_kasba_rating as r')
                ->join('competency_kasba_item as k', 'k.id', '=', 'r.kasba_item_id')
                ->where('k.competency_id', $row->id)
                ->where('r.sub_institute_id', $sid)
                ->distinct()
                ->count('r.user_id'),
        ];

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

            /*
             * ── RECONCILE, DO NOT DELETE-AND-REINSERT ──────────────────────
             *
             * This block used to hard-delete every row and insert the list
             * afresh. That is data loss, and it is not obvious from here:
             * `competency_kasba_rating.kasba_item_id` points at these rows, so
             * deleting them ORPHANS EVERY RATING on the competency. Adjusting
             * one weight silently destroyed every employee's assessment for it.
             *
             * Proved by doing it: changing a weight on a 4-item competency
             * orphaned 4 ratings, whose levels then read "not assessed".
             *
             * So an item that is STILL PRESENT keeps its id and just has its
             * weight updated; only genuinely removed items are deleted, and
             * only genuinely new ones inserted. Identity is (kasba_type, item_id)
             * for a library-linked atom and (kasba_type, item_label) for a held
             * label - the same two shapes store() writes, and the same pair the
             * ratings themselves carry.
             */
            $existing = DB::table('competency_kasba_item')
                ->where('competency_id', (int) $id)
                ->where('sub_institute_id', $sid)
                ->get(['id', 'kasba_type', 'item_id', 'item_label']);

            $identity = static function ($type, $itemId, $label): string {
                return strtolower(trim((string) $type)) . '|'
                    . ($itemId ? 'id:' . (int) $itemId : 'label:' . strtolower(trim((string) $label)));
            };

            $byIdentity = [];
            foreach ($existing as $row) {
                $byIdentity[$identity($row->kasba_type, $row->item_id, $row->item_label)] = (int) $row->id;
            }

            $keptIds = [];
            foreach ($items as $item) {
                $itemId = isset($item['item_id']) ? (int) $item['item_id'] : null;
                $label  = $item['item_label'] ?? null;
                $key    = $identity($item['kasba_type'], $itemId, $label);

                if (isset($byIdentity[$key])) {
                    // SAME ATOM, POSSIBLY A NEW WEIGHT. Its id survives, so
                    // every rating hanging off it survives with it.
                    DB::table('competency_kasba_item')
                        ->where('id', $byIdentity[$key])
                        ->update(['weight' => $item['weight'] ?? 1, 'updated_at' => now()]);
                    $keptIds[] = $byIdentity[$key];
                } else {
                    $keptIds[] = DB::table('competency_kasba_item')->insertGetId([
                        'sub_institute_id' => $sid,
                        'competency_id'    => (int) $id,
                        'kasba_type'       => $item['kasba_type'],
                        // Same rule as store(): item_id is the resolved target,
                        // item_label the holding state. Neither is invented from
                        // the other.
                        'item_id'          => $itemId,
                        'item_label'       => $label,
                        'weight'           => $item['weight'] ?? 1,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ]);
                }
                $written++;
            }

            /*
             * Anything the author actually removed. Still a hard delete - an
             * item taken out of a competency was never part of it rather than
             * retired from it - and its ratings go with it, which is correct:
             * they measured something this competency no longer contains.
             */
            DB::table('competency_kasba_item')
                ->where('competency_id', (int) $id)
                ->where('sub_institute_id', $sid)
                ->when($keptIds !== [], fn ($q) => $q->whereNotIn('id', $keptIds))
                ->delete();
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

    /*
     * ═══════════════════════════════════════════════════════════════════════
     * THE FIVE ENDPOINTS THE SCREEN CALLED AND NOBODY REGISTERED
     * ═══════════════════════════════════════════════════════════════════════
     *
     * `services/competency/library.ts` moved its BASE to `/competency-library`
     * when this controller took over the screen, but only six routes came with
     * it. Five service methods kept calling paths that were never registered:
     *
     *   getDetail   /competency-library/competency/{id}/detail
     *   exportRows  /competency-library/competency-export
     *   importRows  /competency-library/competency-import
     *   clone       /competency-library/competency/{id}/clone
     *   archive     /competency-library/competency/{id}/archive
     *
     * All five are wired to visible controls on menu 34 — the detail drawer,
     * Export Library, Import Competencies, Clone and Archive/Restore — so every
     * one of those buttons returned a 404.
     *
     * They are NOT re-pointed at the surviving /skill_library equivalents. Those
     * read `s_users_skills`, so the detail drawer would have described a skill
     * that merely shared an id with the competency on screen — a wrong answer
     * rendered confidently, which is worse than the 404 it replaced.
     */

    /**
     * GET /competency-library/competency/{id}/detail
     *
     * The Overview tab: where this competency is actually used. Every count is a
     * real query — none is a placeholder — and a table that does not exist on
     * this database contributes 0 rather than aborting the drawer.
     */
    public function detail(Request $request, $id)
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
                'c.criticality', 'c.status', 'c.approve_status', 'c.framework_id',
                'c.created_by', 'c.created_at', 'c.updated_at', 'f.name as framework_name',
            ]);

        if (!$row) {
            return response()->json(['status' => false, 'message' => 'Competency not found.'], 404);
        }

        $cid = (int) $row->id;

        // ── where it is in use ──────────────────────────────────────────────
        $roles = DB::table('jobrole_competency_map as m')
            ->leftJoin('s_user_jobrole as r', 'r.id', '=', 'm.jobrole_id')
            ->where('m.competency_id', $cid)
            ->where('m.sub_institute_id', $sid)
            ->orderBy('r.jobrole')
            ->limit(200)
            ->get(['r.jobrole', 'm.required_proficiency', 'r.department']);

        $frameworks = DB::table('s_competency_framework_items as fi')
            ->join('s_competency_frameworks as fr', 'fr.id', '=', 'fi.framework_id')
            ->where('fi.competency_id', $cid)
            ->where('fi.sub_institute_id', $sid)
            ->limit(100)
            ->get(['fr.id', 'fr.name', 'fr.status', 'fi.required_proficiency']);

        $ratedEmployees = (int) DB::table('competency_kasba_rating as r')
            ->join('competency_kasba_item as i', 'i.id', '=', 'r.kasba_item_id')
            ->where('i.competency_id', $cid)
            ->where('r.sub_institute_id', $sid)
            ->distinct()
            ->count('r.user_id');

        $summary = [
            'description'        => $row->description,
            'category'           => $row->framework_name,
            'sub_category'       => null,
            'competency_type'    => $row->competency_type,
            'status'             => $row->status !== null && $row->status !== '' ? ucfirst((string) $row->status) : null,
            'role_count'         => $roles->count(),
            'framework_count'    => $frameworks->count(),
            'rated_employees'    => $ratedEmployees,
            'plan_count'         => $this->countIfTable('s_competency_development_plans', 'competency_id', $cid, $sid),
            'certification_count' => $this->countIfTable('s_competency_certifications', 'competency_id', $cid, $sid),
            'assessment_count'   => $this->countIfTable('competency_assessment_test', 'competency_id', $cid, $sid),
            'learning_count'     => $this->countIfTable('course_competency_map', 'competency_id', $cid, $sid),
            'evidence_count'     => $this->countIfTable('competency_evidence', 'competency_id', $cid, $sid),
        ];

        // ── the proficiency scale, reusing the one levels() already builds ──
        // `competency_proficiency_levels`, plural — the same table levels() reads.
        $levels = DB::table('competency_proficiency_levels')
            ->where('competency_id', $cid)
            ->orderBy('level')
            ->get(['level', 'descriptor', 'indicators']);

        $scale = $levels->isNotEmpty()
            ? $levels->map(fn ($l) => [
                'level'       => (int) $l->level,
                'label'       => 'Level ' . $l->level,
                'name'        => $l->descriptor,
                'description' => $l->indicators,
            ])->values()
            /*
             * The organisation's generic scale, read exactly as levels() reads it
             * — `skill_id IS NULL` marks the generic rows and `proficiency_type`
             * holds the level number. This table has no `level` or `name` column;
             * assuming it did is what made the detail drawer's first call throw.
             *
             * A NULL sub_institute_id is accepted alongside the tenant's own,
             * because the platform ships a default scale predating per-tenant ones.
             */
            : DB::table('s_proficiency_levels')
                ->whereNull('skill_id')
                ->where(function ($q) use ($sid) {
                    $q->where('sub_institute_id', $sid)->orWhereNull('sub_institute_id');
                })
                ->orderBy('id')
                ->limit(10)
                ->get(['proficiency_type', 'description'])
                ->map(fn ($l) => [
                    'level'       => (int) $l->proficiency_type,
                    'label'       => 'Level ' . (int) $l->proficiency_type,
                    'name'        => null,
                    'description' => $l->description,
                ])->values();

        return response()->json([
            'status'  => true,
            'message' => 'Success',
            'data'    => [
                'summary'   => $summary,
                'top_roles' => $roles->take(5)->map(fn ($r) => [
                    'jobrole'           => $r->jobrole,
                    'proficiency_level' => $r->required_proficiency,
                    'department'        => $r->department ?? null,
                ])->values(),
                'proficiency' => [
                    // Named honestly: a competency with no levels of its own is
                    // shown the tenant's default scale, and the scope says which
                    // of the two the reader is looking at.
                    'scale_label' => $levels->isNotEmpty() ? 'Competency scale' : 'Organisation default scale',
                    'scope'       => $levels->isNotEmpty() ? 'competency' : 'organisation',
                    'levels'      => $scale,
                ],
                'associations' => [
                    'roles' => $roles->map(fn ($r) => [
                        'jobrole'           => $r->jobrole,
                        'proficiency_level' => $r->required_proficiency,
                    ])->values(),
                    'frameworks' => $frameworks->map(fn ($f) => [
                        'id'                   => (int) $f->id,
                        'name'                 => $f->name,
                        'status'               => $f->status,
                        'required_proficiency' => $f->required_proficiency,
                    ])->values(),
                    'role_count'      => $roles->count(),
                    'framework_count' => $frameworks->count(),
                ],
                // EMPTY, AND SAYING SO. There is no attachment store for a
                // competency and no per-row change log; returning [] is the
                // truthful answer, and inventing entries from created_at/
                // updated_at would be fabricating a history.
                'attachments' => [],
                'history'     => [],
            ],
        ]);
    }

    /**
     * GET /competency-library/competency-export
     *
     * Every row matching the CURRENT filters, unpaginated. Deliberately shares
     * listQuery() with index() so an export can never disagree with the screen
     * it was launched from.
     */
    public function export(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid  = (int) $context['sub_institute_id'];
        $rows = $this->listQuery($request, $sid)
            ->orderBy('c.name')
            // A ceiling, so one tenant cannot pull an unbounded result set into
            // memory. Named in the response rather than silently truncating.
            ->limit(5000)
            ->get([
                'c.id', 'c.name', 'c.code', 'c.description', 'c.competency_type',
                'c.criticality', 'c.status', 'c.approve_status', 'c.framework_id',
                'c.created_by', 'c.created_at', 'c.updated_at', 'f.name as framework_name',
            ]);

        $counts = DB::table('competency_kasba_item')
            ->whereIn('competency_id', $rows->pluck('id'))
            ->selectRaw('competency_id, COUNT(*) n')
            ->groupBy('competency_id')->pluck('n', 'competency_id');

        return response()->json([
            'status'   => true,
            'message'  => 'Success',
            'data'     => $rows->map(fn ($r) => $this->shape($r, (int) ($counts[$r->id] ?? 0)))->values(),
            'truncated' => $rows->count() >= 5000,
        ]);
    }

    /**
     * POST /competency-library/competency-import
     *
     * Rows parsed in the browser. Reports per-row outcomes rather than a single
     * pass/fail: a 200-row file with three bad rows should import 197 and name
     * the three, not refuse the file.
     */
    public function import(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'rows'          => 'required|array|min:1|max:2000',
            'rows.*.name'   => 'required|string|max:191',
            'rows.*.code'   => 'nullable|string|max:64',
            'rows.*.description'     => 'nullable|string',
            'rows.*.competency_type' => 'nullable|string|max:64',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()], 422);
        }

        $sid    = (int) $context['sub_institute_id'];
        $userId = (int) $context['user_id'];

        $created = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($request->input('rows') as $index => $raw) {
            $name = trim((string) ($raw['name'] ?? ''));
            if ($name === '') {
                $errors[] = ['row' => $index + 1, 'reason' => 'Name is required.'];
                continue;
            }

            // A NAME COLLISION IS A SKIP, NOT AN ERROR. Re-importing a file that
            // overlaps one already loaded is normal, and failing the row would
            // make a partially-applied import impossible to finish.
            $exists = DB::table('competency')
                ->where('sub_institute_id', $sid)
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            DB::table('competency')->insert([
                'sub_institute_id' => $sid,
                'name'             => $name,
                // `competency.code` is NOT NULL, and a spreadsheet's Code column
                // is very often blank. Generated from the name when absent —
                // the same fallback store() uses — so a valid file is not
                // rejected row by row over a column nobody filled in.
                'code'             => trim((string) ($raw['code'] ?? '')) !== ''
                    ? $raw['code']
                    : $this->generateCode($sid, $name),
                'description'      => $raw['description'] ?? null,
                'competency_type'  => $raw['competency_type'] ?? null,
                // An imported competency is a draft until somebody reviews it.
                // Landing a file straight into 'active' would let a spreadsheet
                // publish to the whole organisation.
                'status'           => 'draft',
                'approve_status'   => null,
                'created_by'       => $userId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
            $created++;
        }

        return response()->json([
            'status'  => true,
            'message' => sprintf('%d imported, %d already present%s.',
                $created, $skipped, $errors === [] ? '' : ', ' . count($errors) . ' rejected'),
            'data'    => [
                'created' => $created,
                'skipped' => $skipped,
                'errors'  => $errors,
            ],
        ]);
    }

    /**
     * POST /competency-library/competency/{id}/clone
     *
     * Copies the competency AND its KASBA items. Copying the row alone would
     * produce an empty shell that looks like a competency and measures nothing,
     * which is the opposite of what "duplicate" means to the person clicking it.
     */
    public function clone(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid    = (int) $context['sub_institute_id'];
        $userId = (int) $context['user_id'];

        $row = DB::table('competency')
            ->where('id', (int) $id)->where('sub_institute_id', $sid)->whereNull('deleted_at')
            ->first();

        if (!$row) {
            return response()->json(['status' => false, 'message' => 'Competency not found.'], 404);
        }

        $name = trim((string) $request->input('name', '')) ?: $this->uniqueCopyName($sid, (string) $row->name);

        $newId = DB::transaction(function () use ($row, $sid, $userId, $name) {
            $newId = (int) DB::table('competency')->insertGetId([
                'sub_institute_id' => $sid,
                'name'             => $name,
                /*
                 * A FRESH CODE, NOT THE ORIGINAL'S AND NOT NULL.
                 *
                 * Copying it would put two competencies under one organisational
                 * identifier, which is a data problem rather than a duplicate.
                 * NULL is not available either — `competency.code` is NOT NULL,
                 * and store() carries a note about that same constraint
                 * surfacing as a 500 instead of a validation message. So the
                 * clone generates one the same way a new competency does.
                 */
                'code'             => $this->generateCode($sid, $name),
                'description'      => $row->description,
                'competency_type'  => $row->competency_type,
                'criticality'      => $row->criticality,
                'framework_id'     => $row->framework_id,
                'status'           => 'draft',
                'approve_status'   => null,
                'created_by'       => $userId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $items = DB::table('competency_kasba_item')
                ->where('competency_id', $row->id)->where('sub_institute_id', $sid)
                ->get(['kasba_type', 'item_id', 'item_label', 'weight']);

            foreach ($items as $item) {
                DB::table('competency_kasba_item')->insert([
                    'competency_id'    => $newId,
                    'sub_institute_id' => $sid,
                    'kasba_type'       => $item->kasba_type,
                    'item_id'          => $item->item_id,
                    'item_label'       => $item->item_label,
                    'weight'           => $item->weight,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }

            return $newId;
        });

        return response()->json([
            'status'  => true,
            'message' => 'Competency duplicated.',
            'data'    => ['id' => $newId, 'name' => $name],
        ]);
    }

    /**
     * PUT /competency-library/competency/{id}/archive
     *
     * Archive is `approve_status = 'Cancelled'`, and restore clears it back to
     * NULL. NOT a delete: the competency stays referenced by role mappings,
     * framework items, ratings, plans and certifications, and removing the row
     * would orphan every one of them.
     *
     * Restore returns it to NULL — never-submitted — rather than to 'Approved'.
     * Un-archiving is not an approval, and the row's real review state before it
     * was archived is not recorded anywhere.
     */
    public function archive(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid     = (int) $context['sub_institute_id'];
        $restore = filter_var($request->input('restore', false), FILTER_VALIDATE_BOOLEAN);

        $row = DB::table('competency')
            ->where('id', (int) $id)->where('sub_institute_id', $sid)->whereNull('deleted_at')
            ->first(['id', 'name', 'approve_status']);

        if (!$row) {
            return response()->json(['status' => false, 'message' => 'Competency not found.'], 404);
        }

        DB::table('competency')->where('id', $row->id)->update([
            'approve_status' => $restore ? null : 'Cancelled',
            'updated_by'     => (int) $context['user_id'],
            'updated_at'     => now(),
        ]);

        return response()->json([
            'status'  => true,
            'message' => $restore ? 'Competency restored.' : 'Competency archived.',
            'data'    => ['id' => (int) $row->id, 'approve_status' => $restore ? null : 'Cancelled'],
        ]);
    }

    /** "Name (copy)", then "(copy 2)" and so on — never a silent duplicate. */
    private function uniqueCopyName(int $sid, string $base): string
    {
        $candidate = $base . ' (copy)';
        $n = 2;

        while (DB::table('competency')->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($candidate)])->exists()) {
            $candidate = $base . ' (copy ' . $n . ')';
            $n++;

            if ($n > 50) {
                // Bounded rather than looping forever on a pathological library.
                return $base . ' (copy ' . uniqid() . ')';
            }
        }

        return $candidate;
    }

    /**
     * COUNT, OR 0 IF THAT TABLE IS NOT ON THIS DATABASE.
     *
     * The detail drawer summarises across eight subsystems and the two databases
     * do not carry an identical set of them. A missing table must contribute 0 to
     * a summary rather than taking the whole drawer down with a SQL error —
     * "nothing recorded" is the right answer for a subsystem that is not
     * installed. Schema::hasTable() is avoided because it throws on live
     * (MariaDB 10.1.48).
     */
    private function countIfTable(string $table, string $column, int $competencyId, int $sid): int
    {
        $exists = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        if ((int) ($exists->c ?? 0) === 0) {
            return 0;
        }

        $hasTenant = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'sub_institute_id']
        );

        return (int) DB::table($table)
            ->where($column, $competencyId)
            ->when((int) ($hasTenant->c ?? 0) > 0, fn ($q) => $q->where('sub_institute_id', $sid))
            ->count();
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
            /*
             * `competency.status` IS A VARCHAR, NOT A FLAG.
             *
             * This read `((int) $r->status) === 1 ? 'Active' : 'Inactive'`. The
             * column holds 'active' / 'draft' / 'published', and `(int) 'active'`
             * is 0 — so every one of the 231 competencies on dev and 232 on live
             * was labelled Inactive, which also meant isArchived() was never true
             * and the Archive/Restore toggle was permanently mislabelled.
             *
             * Passed through as stored, capitalised for display. An unrecognised
             * value shows itself rather than collapsing into a wrong label.
             */
            'status'          => $r->status !== null && $r->status !== ''
                ? ucfirst((string) $r->status)
                : null,
            /*
             * The workflow DOES exist — ApprovalController submits and reviews —
             * it was simply pointed at `s_users_skills` while this screen moved to
             * `competency`. Repointed, and this now reports what it finds.
             *
             * NULL means NEVER SUBMITTED, and stays null. It is a different fact
             * from Approved and from Pending, and none of the existing rows has
             * been through the workflow; defaulting them to either would be a
             * claim nobody made. The client renders the null case explicitly
             * rather than falling back to 'Pending'.
             */
            'approve_status'  => $r->approve_status !== null && $r->approve_status !== ''
                ? (string) $r->approve_status
                : null,
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
