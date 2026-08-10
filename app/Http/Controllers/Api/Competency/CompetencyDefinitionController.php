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
 * A label is never treated as a key. `skill` items resolve against
 * `s_users_skills`; knowledge, ability, attitude and behaviour have no canonical
 * table yet, so they are held by label and reported as such.
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

        $competencyId = DB::transaction(function () use ($request, $sid, $actor) {
            $id = DB::table('competency')->insertGetId([
                'sub_institute_id' => $sid,
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

                // A skill item MUST resolve inside the caller's own tenant, or it
                // is held by label rather than pointed at someone else's row.
                if ($itemId && $item['kasba_type'] === 'skill') {
                    $ok = DB::table('s_users_skills')
                        ->where('id', $itemId)
                        ->where('sub_institute_id', $sid)
                        ->exists();
                    if (!$ok) {
                        $itemId = null;
                    }
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
