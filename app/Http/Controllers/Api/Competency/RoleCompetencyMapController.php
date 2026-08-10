<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * SLICE 1, ITEM 3 — what a job role REQUIRES. The heart of the slice.
 *
 * Writes `jobrole_competency_map`: job role, competency, required proficiency,
 * mandatory or not.
 *
 * ─── EVERYTHING BY KEY ──────────────────────────────────────────────────────
 * NO NAME IS EVER WRITTEN INTO THIS TABLE. It has no text key at all, and that
 * is deliberate: it is what makes the rename proof (item 9) possible. Rename a
 * job role and the mapping survives, because the mapping never knew the name.
 *
 * That property is not a side effect to be preserved by care - it is enforced by
 * the schema having nowhere to put a name, and this controller must never add
 * one.
 *
 * ─── R20, THE CHAIN ─────────────────────────────────────────────────────────
 *   route      routes/api.php - /competency/role-map
 *   middleware `profile:admin,hr` on writes; RequireProfile matches exact
 *              role_key since G-AUTH-02
 *   tenant     competencyContext() -> resolveApiIdentity(). Never a request field
 *   actor      created_by from the same, never from the body (G-SEC-12)
 *
 * ─── BULK UPSERT ────────────────────────────────────────────────────────────
 * Modelled on masterSetupController's bulk `insert($insertData)` (M-03) rather
 * than written from scratch, but with the deduplication done by the database:
 * `uq_jcm (sub_institute_id, jobrole_id, competency_id)`.
 *
 * A user CAN trip that key by mapping the same competency twice, so it is
 * checked here and answered with a sentence - a raw SQLSTATE reaching a user is
 * the API failing to own its own schema.
 */
class RoleCompetencyMapController extends Controller
{
    use ResolvesCompetencyContext;

    /** What this job role requires, resolved to names for display only. */
    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), ['jobrole_id' => 'required|integer']);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid = $context['sub_institute_id'];

        $rows = DB::table('jobrole_competency_map as m')
            ->join('competency as c', 'c.id', '=', 'm.competency_id')
            ->where('m.sub_institute_id', $sid)
            ->where('m.jobrole_id', $request->integer('jobrole_id'))
            ->orderBy('c.name')
            ->get([
                'm.id', 'm.competency_id', 'm.required_proficiency', 'm.is_mandatory',
                'c.name as competency_name', 'c.code as competency_code',
            ]);

        return response()->json([
            'status' => 1,
            'data'   => $rows->map(fn ($r) => [
                'id'                   => (int) $r->id,
                'competency_id'        => (int) $r->competency_id,
                // Display only. The mapping is keyed on competency_id; this name
                // is read through the join every time and never stored here.
                'competency_name'      => $r->competency_name,
                'competency_code'      => $r->competency_code,
                'required_proficiency' => (int) $r->required_proficiency,
                'is_mandatory'         => (bool) $r->is_mandatory,
            ])->values(),
        ]);
    }

    /**
     * Bulk upsert the requirements for ONE job role.
     *
     * Upsert rather than insert: re-saving a role's requirements is the ordinary
     * case, and making the caller delete first would lose the mapping on any
     * failure between the two calls.
     */
    public function store(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'jobrole_id'                    => 'required|integer',
            'items'                         => 'required|array|min:1',
            'items.*.competency_id'         => 'required|integer',
            'items.*.required_proficiency'  => 'required|integer|min:1|max:5',
            'items.*.is_mandatory'          => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid       = $context['sub_institute_id'];
        $actor     = $context['user_id'];
        $jobroleId = $request->integer('jobrole_id');

        // The job role must be the caller's own. Without this a mapping could be
        // hung off another tenant's role id.
        $roleOk = DB::table('s_user_jobrole')
            ->where('id', $jobroleId)
            ->where('sub_institute_id', $sid)
            ->exists();

        if (!$roleOk) {
            return response()->json(['status' => 0, 'message' => 'Job role not found.'], 404);
        }

        // uq_jcm is user-trippable: the same competency listed twice.
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

        // Every competency must exist inside the caller's own tenant. Reported as
        // one message rather than failing on the first, so the user fixes the
        // whole list in one pass.
        $valid = DB::table('competency')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereIn('id', array_keys($seen))
            ->pluck('id')
            ->all();

        $unknown = array_diff(array_keys($seen), $valid);
        if ($unknown) {
            return response()->json([
                'status'  => 0,
                'message' => 'These competencies do not exist in this organisation: ' . implode(', ', $unknown),
            ], 422);
        }

        $written = DB::transaction(function () use ($request, $sid, $actor, $jobroleId) {
            $n = 0;
            foreach ($request->input('items') as $item) {
                DB::table('jobrole_competency_map')->updateOrInsert(
                    [
                        'sub_institute_id' => $sid,
                        'jobrole_id'       => $jobroleId,
                        'competency_id'    => (int) $item['competency_id'],
                    ],
                    [
                        'required_proficiency' => (int) $item['required_proficiency'],
                        'is_mandatory'         => !empty($item['is_mandatory']) ? 1 : 0,
                        'created_by'           => $actor,
                        'updated_at'           => now(),
                    ]
                );
                $n++;
            }

            return $n;
        });

        return response()->json([
            'status'  => 1,
            'message' => 'Requirements saved.',
            'data'    => ['jobrole_id' => $jobroleId, 'written' => $written],
        ], 201);
    }

    /** Remove one requirement. Scoped to the caller's tenant. */
    public function destroy(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $deleted = DB::table('jobrole_competency_map')
            ->where('id', $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->delete();

        return response()->json(
            $deleted
                ? ['status' => 1, 'message' => 'Requirement removed.']
                : ['status' => 0, 'message' => 'Requirement not found.'],
            $deleted ? 200 : 404
        );
    }
}
