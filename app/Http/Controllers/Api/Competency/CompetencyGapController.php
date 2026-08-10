<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Services\Competency\ProficiencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * SLICE 1, ITEM 7 — THE GAP. Required minus measured, resolved BY KEY.
 *
 * ─── TWO NUMBERS, NOT ONE (Q-E2) ────────────────────────────────────────────
 *   1. the weighted ROLL-UP level, from ProficiencyService; and
 *   2. the LIST OF MANDATORY ITEMS BELOW REQUIRED.
 *
 * They answer different questions. A roll-up of 3.4 against a required 3 looks
 * met, while a mandatory item sitting at 1 inside it is not - and an average can
 * hide exactly that. Reporting one number would lose the finding.
 *
 * ─── UNMEASURED IS NEITHER ZERO NOR PASS ────────────────────────────────────
 * An unmeasured item is reported as UNMEASURED and feeds capability coverage. It
 * is NOT counted as a gap (that would assert a shortfall nobody measured) and NOT
 * counted as met (that would assert a pass nobody earned). It is its own state
 * and its own count.
 *
 * ─── ARITHMETIC ─────────────────────────────────────────────────────────────
 * This controller does NONE. Every level comes from ProficiencyService, which is
 * the one named roll-up. The only comparison here is required-vs-measured, which
 * is the gap itself.
 *
 * R20 - THE CHAIN:
 *   route      /competency/gap - read; no write, so no profile gate
 *   tenant     competencyContext() -> resolveApiIdentity()
 *   subject    an employee may read THEIR OWN gap; anyone else needs an elevated
 *              role_key. Same shape as competencySubject() (G-COMP-SEC-01)
 */
class CompetencyGapController extends Controller
{
    use ResolvesCompetencyContext;

    public function __construct(private ProficiencyService $proficiency)
    {
    }

    public function show(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'user_id'    => 'required|integer',
            'jobrole_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        // Own gap, or an elevated role. Never "any authenticated caller".
        $subject = $this->competencySubject($context, $request->integer('user_id'));
        if (!is_int($subject)) {
            return $subject;
        }

        $sid = $context['sub_institute_id'];

        // The employee's job role comes from `tbluser.allocated_standards`, which
        // holds an s_user_jobrole id (287 of 387 users populated). I first wrote
        // this against `s_user_jobrole_map` - A TABLE THAT DOES NOT EXIST. Caught
        // by checking, not by review.
        $jobroleId = $request->filled('jobrole_id')
            ? $request->integer('jobrole_id')
            : (int) DB::table('tbluser')
                ->where('id', $subject)
                ->where('sub_institute_id', $sid)
                ->value('allocated_standards');

        if (!$jobroleId) {
            return response()->json([
                'status'  => 0,
                'message' => 'No job role for this employee, so there is nothing to compare against.',
            ], 422);
        }

        // The requirements. Resolved BY KEY - the job role's name is read for
        // display and never used to match.
        $required = DB::table('jobrole_competency_map as m')
            ->join('competency as c', 'c.id', '=', 'm.competency_id')
            ->where('m.sub_institute_id', $sid)
            ->where('m.jobrole_id', $jobroleId)
            ->orderBy('c.name')
            ->get(['m.competency_id', 'm.required_proficiency', 'm.is_mandatory', 'c.name', 'c.code']);

        if ($required->isEmpty()) {
            return response()->json([
                'status'  => 1,
                'message' => 'This job role has no competency requirements defined yet.',
                'data'    => ['jobrole_id' => $jobroleId, 'competencies' => [], 'mandatory_below_required' => []],
            ]);
        }

        // THE ONE NAMED ROLL-UP. No arithmetic in this controller.
        $levels = $this->proficiency->rollUp($sid, $subject, $required->pluck('competency_id')->all());

        $competencies = [];
        $mandatoryBelow = [];
        $unmeasuredCount = 0;

        foreach ($required as $req) {
            $roll = $levels[$req->competency_id] ?? null;
            $level = $roll['level'] ?? null;

            // Three states, kept apart on purpose.
            $state = match (true) {
                $level === null                        => 'unmeasured',
                $level >= (float) $req->required_proficiency => 'met',
                default                                => 'gap',
            };

            if ($state === 'unmeasured') {
                $unmeasuredCount++;
            }

            $competencies[] = [
                'competency_id'        => (int) $req->competency_id,
                'competency_name'      => $req->name,     // display only
                'competency_code'      => $req->code,
                'required_proficiency' => (int) $req->required_proficiency,
                'is_mandatory'         => (bool) $req->is_mandatory,
                'measured_level'       => $level,          // NULL means unmeasured
                'state'                => $state,
                // A gap is only meaningful where something was measured.
                'gap'                  => $state === 'gap'
                    ? round((float) $req->required_proficiency - $level, 2)
                    : null,
                'coverage'             => $roll['coverage'] ?? 0.0,
            ];

            // NUMBER TWO: mandatory ITEMS below required, not competencies. An
            // average can sit above the bar while an item inside it does not.
            if ($req->is_mandatory) {
                foreach ($roll['items'] ?? [] as $item) {
                    if (!$item['measured']) {
                        continue;    // unmeasured is not a shortfall
                    }
                    if ($item['rating'] < (int) $req->required_proficiency) {
                        $mandatoryBelow[] = [
                            'competency_id'   => (int) $req->competency_id,
                            'competency_name' => $req->name,
                            'kasba_item_id'   => $item['kasba_item_id'],
                            'kasba_type'      => $item['kasba_type'],
                            'item_label'      => $item['item_label'],
                            'rating'          => $item['rating'],
                            'required'        => (int) $req->required_proficiency,
                        ];
                    }
                }
            }
        }

        return response()->json([
            'status' => 1,
            'data'   => [
                'user_id'     => $subject,
                'jobrole_id'  => $jobroleId,
                'competencies' => $competencies,
                // The second number, separately.
                'mandatory_below_required' => $mandatoryBelow,
                // Feeds the capability-coverage gate. Reported, never inferred
                // from the absence of a gap.
                'coverage' => [
                    'competencies_required'   => $required->count(),
                    'competencies_unmeasured' => $unmeasuredCount,
                ],
            ],
        ]);
    }
}
