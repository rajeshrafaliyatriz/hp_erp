<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyGap;
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
    use ResolvesCompetencyGap;

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

        // ── READINESS GATE: capability_coverage. THE FIRST ENFORCEMENT POINT. ──
        //
        // Gap reporting reads capability measurements. Below the threshold there
        // are not enough of them for a gap to mean anything, and a gap computed
        // from 4% coverage is a confident-looking number about nothing.
        //
        // THE REFUSAL SAYS WHY AND WHAT WOULD FIX IT. A feature that silently
        // returned an empty list here would leave the customer unable to tell
        // "no gaps" from "gap reporting is switched off" - the unmeasured-as-zero
        // error in a new place.
        //
        // A NEVER-COMPUTED GATE DOES NOT BLOCK. See ReadinessGateEnforcer: a gate
        // nobody has run has made no claim, and a feature must not be switched
        // off by the absence of a measurement.
        //
        // at_risk ALLOWS. Falling below the threshold starts a warning period; it
        // does not switch anything off. Only a human acknowledgement blocks.
        $gate = app(\App\Services\Readiness\ReadinessGateEnforcer::class)
            ->check((int) $sid, 'capability_coverage');
        if (!$gate['allowed']) {
            return response()->json(
                app(\App\Services\Readiness\ReadinessGateEnforcer::class)->refusalPayload($gate),
                409
            );
        }

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

        /*
         * THE COMPARISON MOVED TO `Concerns\ResolvesCompetencyGap`, UNCHANGED.
         *
         * Not because this controller needed it elsewhere, but because
         * `DevelopmentPlanController::gaps()` was answering the same question a
         * second time from the skill library, keyed by name, and scoring
         * unmeasured items as ZERO - which turned 3,328 of 3,873 live gap rows
         * into shortfalls nobody had assessed.
         *
         * The rule now has one home and two callers. The payload below is
         * byte-identical to what this endpoint returned before the extraction,
         * verified against a captured baseline across four employees.
         */
        $gap = $this->competencyGapFor((int) $sid, $subject, $jobroleId);

        if ($gap['competencies'] === []) {
            return response()->json([
                'status'  => 1,
                'message' => 'This job role has no competency requirements defined yet.',
                'data'    => ['jobrole_id' => $jobroleId, 'competencies' => [], 'mandatory_below_required' => []],
            ]);
        }

        return response()->json([
            'status' => 1,
            'data'   => [
                'user_id'      => $subject,
                'jobrole_id'   => $jobroleId,
                'competencies' => $gap['competencies'],
                // The second number, separately.
                'mandatory_below_required' => $gap['mandatory_below_required'],
                // Feeds the capability-coverage gate. Reported, never inferred
                // from the absence of a gap.
                'coverage' => $gap['coverage'],
            ],
        ]);
    }
}
