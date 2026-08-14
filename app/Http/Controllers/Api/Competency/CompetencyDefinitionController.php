<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * SLICE 1, ITEM 1 — a competency is a NAMED BUNDLE OF KASBA ITEMS (Q-A2).
 *
 * NOT TO BE CONFUSED WITH {@see CompetencyController}, which manages SKILL
 * LIBRARY entries (`s_users_skills`) and is aliased in routes/api.php as
 * SkillLibraryCrudController. The two differ by NAME ONLY in the old routing,
 * which is a trap for whoever reads it next - hence the alias and these notes.
 *
 * WHY A NEW CONTROLLER RATHER THAN EXTENDING THE OLD ONE.
 * `CompetencyController::store()` - which `routes/api.php:360` serves under the
 * alias `CompetencyCrudController` - inserts into **`s_users_skills`**. It
 * creates a FLAT SKILL ROW and calls it a competency. That is the skill library,
 * a different concept, and it is left alone: the library screen reads it.
 *
 * A competency lives in `competency` + `competency_kasba_item`. Nothing wrote to
 * either table before this.
 *
 * THE item_label RULE (item 0):
 *   item_id populated = THE TARGET STATE, resolved by key.
 *   item_label alone  = A HOLDING STATE, counted as unresolved in coverage.
 * A label is never treated as a key. EVERY dimension now resolves against its own
 * canonical table - see self::ITEM_TABLES.
 *
 * CORRECTED 2026-08-12, and the correction matters (G-SEED-01):
 * this comment used to say knowledge, ability, attitude and behaviour "have no
 * canonical table yet, so they are held by label". THAT WAS NEVER TRUE of the
 * data. The four tables exist and hold 14,474 rows between them:
 *
 *     s_user_knowledge 6,950 · s_user_ability 6,175
 *     s_user_attitude    655 · s_user_behaviour  694
 *
 * The four library tabs have been writing them all along. What was missing was
 * anything that POINTED at them - so the rows were referenceable and nothing had
 * ever referenced one.
 *
 * R20 - THE CHAIN THIS RELIES ON:
 *   route      routes/api.php (added with this controller)
 *   middleware `profile:admin,hr` - RequireProfile, exact role_key since G-AUTH-02
 *   tenant     competencyContext() -> resolveApiIdentity(), never a request field
 *   actor      the same; created_by is never taken from the body (G-SEC-12)
 */
class CompetencyDefinitionController extends Controller
{
    use ResolvesCompetencyContext;

    private const KASBA = ['skill', 'knowledge', 'ability', 'attitude', 'behaviour'];

    /**
     * The canonical table behind each dimension - what an `item_id` must exist in,
     * inside the caller's own tenant, before it is stored as a key.
     *
     * KEYED BY THE SAME VOCABULARY AS self::KASBA AND AS THE COLUMN'S ENUM, so a
     * new dimension that is added to one and not the others fails closed: an
     * unmapped type drops its id to a label instead of storing it unverified.
     * The alias-map lesson - a vocabulary that must be kept in step by hand is a
     * defect waiting for the next person who does not know both halves.
     */
    private const ITEM_TABLES = [
        'skill'     => 's_users_skills',
        'knowledge' => 's_user_knowledge',
        'ability'   => 's_user_ability',
        'attitude'  => 's_user_attitude',
        'behaviour' => 's_user_behaviour',
    ];

    /** Competencies with their KASBA composition and its resolution state. */
    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $rows = DB::table('competency')
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        $items = DB::table('competency_kasba_item')
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->whereIn('competency_id', $rows->pluck('id'))
            ->get()
            ->groupBy('competency_id');

        return response()->json([
            'status' => 1,
            'data'   => $rows->map(function ($c) use ($items) {
                $own = $items->get($c->id, collect());

                return [
                    'id'           => (int) $c->id,
                    'code'         => $c->code,
                    'name'         => $c->name,
                    'type'         => $c->competency_type,
                    'criticality'  => $c->criticality,
                    'status'       => $c->status,
                    'items'        => $own->map(fn ($i) => [
                        'kasba_type' => $i->kasba_type,
                        'item_id'    => $i->item_id ? (int) $i->item_id : null,
                        'item_label' => $i->item_label,
                        'weight'     => (float) $i->weight,
                        // Honest about what is unresolved, rather than guessing.
                        'resolved'   => $i->item_id !== null,
                    ])->values(),
                    // Feeds the capability-coverage metric.
                    'unresolved_items' => $own->whereNull('item_id')->count(),
                ];
            })->values(),
        ]);
    }

    /** Create a competency together with its KASBA items, in one transaction. */
    public function store(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'name'                => 'required|string|max:191',
            'code'                => 'nullable|string|max:64',
            'description'         => 'nullable|string',
            // OPTIONAL, AND VERIFIED AGAINST THE CALLER'S OWN TENANT BELOW.
            // `exists:` alone would accept another organisation's framework id,
            // which is the shape of leak G-SEC-29 closed across twenty
            // controllers - a valid id that belongs to somebody else.
            'framework_id'        => 'nullable|integer',
            'competency_type'     => 'nullable|string|max:64',
            'criticality'         => 'nullable|string|max:32',
            'items'               => 'required|array|min:1',
            'items.*.kasba_type'  => 'required|in:' . implode(',', self::KASBA),
            'items.*.item_id'     => 'nullable|integer',
            'items.*.item_label'  => 'nullable|string|max:191',
            'items.*.weight'      => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        // uq_ck_item (competency_id, kasba_type, item_id) is the SECOND
        // user-trippable constraint in this table - adding the same skill twice
        // under the same dimension would escape as a raw SQLSTATE. Checked here
        // so the caller gets a sentence instead. (item_id NULL does not collide:
        // MySQL permits repeated NULLs in a unique index, so label-only items
        // are unaffected.)
        $pairs = [];
        foreach ($request->input('items') as $i => $item) {
            if (empty($item['item_id'])) {
                continue;
            }
            $key = $item['kasba_type'] . ':' . $item['item_id'];
            if (isset($pairs[$key])) {
                return response()->json([
                    'status'  => 0,
                    'message' => "Item " . ($i + 1) . " repeats the same {$item['kasba_type']} item already listed.",
                ], 422);
            }
            $pairs[$key] = true;
        }

        // A row naming nothing at all is refused - the holding state is a LABEL,
        // not an absence.
        foreach ($request->input('items') as $i => $item) {
            if (empty($item['item_id']) && trim((string) ($item['item_label'] ?? '')) === '') {
                return response()->json([
                    'status'  => 0,
                    'message' => "Item " . ($i + 1) . " needs an item_id or an item_label.",
                ], 422);
            }
        }

        $sid    = $context['sub_institute_id'];
        $actor  = $context['user_id'];

        // `uq_competency_tenant_code (sub_institute_id, code)` is doing its job,
        // but a duplicate reached the caller as a raw SQLSTATE[23000] 500. A
        // constraint the user can trip is a VALIDATION rule from their side.
        if ($request->filled('code')) {
            $clash = DB::table('competency')
                ->where('sub_institute_id', $sid)
                ->where('code', $request->input('code'))
                ->exists();

            if ($clash) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'That competency code is already used in this organisation.',
                ], 422);
            }
        }

        // THE FRAMEWORK MUST BE THE CALLER'S OWN. Checked here rather than with
        // `exists:` because a bare exists rule accepts ANY tenant's framework id -
        // a valid id belonging to somebody else, which is exactly the leak shape
        // G-SEC-29 closed across twenty controllers. `$sid` comes from the token.
        $frameworkId = $request->input('framework_id') !== null
            ? (int) $request->input('framework_id')
            : null;

        if ($frameworkId !== null) {
            $ok = DB::table('s_competency_frameworks')
                ->where('id', $frameworkId)->where('sub_institute_id', $sid)->exists();

            if (!$ok) {
                return response()->json([
                    'status'  => 0,
                    'message' => 'That framework does not exist in your organisation.',
                ], 404);
            }
        }

        $competencyId = DB::transaction(function () use ($request, $sid, $actor, $frameworkId) {
            $id = DB::table('competency')->insertGetId([
                'sub_institute_id' => $sid,
                // NULL is permitted and permanent: a competency not filed under a
                // framework is a normal competency, not an incomplete one.
                'framework_id'     => $frameworkId,
                'code'             => $request->input('code'),
                'name'             => $request->input('name'),
                'description'      => $request->input('description'),
                'competency_type'  => $request->input('competency_type'),
                'criticality'      => $request->input('criticality'),
                'requires_assessment' => 1,
                'status'           => 'draft',
                'version'          => 1,
                'created_by'       => $actor,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            foreach ($request->input('items') as $item) {
                $itemId = $item['item_id'] ?? null;

                // EVERY item must resolve inside the caller's own tenant, or it is
                // held by label rather than pointed at someone else's row.
                //
                // THIS BRANCH USED TO READ `=== 'skill'`, AND THAT WAS A HOLE, not
                // a limitation. `items.*.item_id` validates as `nullable|integer`
                // for every type, so an id sent for knowledge/ability/attitude/
                // behaviour was written STRAIGHT THROUGH, unchecked, into a column
                // with no foreign key. It had already happened once:
                //
                //     competency_kasba_item, kasba_type=behaviour, item_id=2645
                //     s_user_behaviour #2645 DOES NOT EXIST
                //
                // One dangling pointer from the one non-skill id ever written -
                // a 100% failure rate on the path nothing was guarding.
                $table = self::ITEM_TABLES[$item['kasba_type']] ?? null;
                if ($itemId && $table) {
                    $ok = DB::table($table)
                        ->where('id', $itemId)
                        ->where('sub_institute_id', $sid)
                        ->exists();
                    if (!$ok) {
                        $itemId = null;
                    }
                } elseif ($itemId && !$table) {
                    // An id for a type with no canonical table cannot be verified,
                    // so it is DROPPED to a label rather than stored on trust.
                    // Unreachable while ITEM_TABLES covers the enum; here because
                    // the enum is the thing most likely to grow.
                    $itemId = null;
                }

                DB::table('competency_kasba_item')->insert([
                    'sub_institute_id' => $sid,
                    'competency_id'    => $id,
                    'kasba_type'       => $item['kasba_type'],
                    'item_id'          => $itemId,
                    'item_label'       => $item['item_label'] ?? null,
                    'weight'           => $item['weight'],
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }

            return $id;
        });

        return response()->json([
            'status'  => 1,
            'message' => 'Competency created.',
            'data'    => ['id' => $competencyId],
        ], 201);
    }
}
