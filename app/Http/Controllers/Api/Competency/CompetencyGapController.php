<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyGap;
use App\Http\Controllers\Concerns\ResolvesEmployeeJobRole;
use App\Services\Competency\ProficiencyService;
use App\Services\DeepSeekService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

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
    // The ONE role resolver - two columns, one answer. See show().
    use ResolvesEmployeeJobRole;

    public function __construct(
        private ProficiencyService $proficiency,
        private DeepSeekService $ai
    ) {
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

        /*
         * THE EMPLOYEE'S JOB ROLE, THROUGH THE SHARED RESOLVER.
         *
         * This read `tbluser.allocated_standards` directly, which
         * `Concerns\ResolvesEmployeeJobRole` explicitly forbids: there are TWO
         * role columns and they disagree for some employees - 6 of 21 on dev
         * tenant 6, 1 of 20 on live.
         *
         * The cost was visible in the employee drawer. Its competency ROWS come
         * from here (allocated_standards) and its KASBA ATOMS come from
         * `KasbaRatingController` (which uses the trait, so jobtitle_id first).
         * Where the columns differed, the drawer listed one role's competencies
         * against another role's atoms - every competency rendered "no items
         * bundled" while the atoms existed the whole time.
         */
        $user = DB::table('tbluser')->where('id', $subject)
            ->where('sub_institute_id', $sid)
            ->first(['id', 'jobtitle_id', 'allocated_standards']);

        $jobroleId = $request->filled('jobrole_id')
            ? $request->integer('jobrole_id')
            : (int) ($user ? ($this->resolveJobRoleId($user) ?? 0) : 0);

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

    /**
     * GET /api/competency/gap/narrative?user_id=&jobrole_id=
     *
     * One short paragraph for a manager, grounded only in show()'s own
     * numbers - calls show() rather than recomputing anything, so the two
     * endpoints can never disagree about what the gap actually is. Same
     * retrieval/generation split as the K-12 Graph RAG reference
     * implementation: the gap computation above is the retrieval step, this
     * is the generation step, and a refusal/empty/unconfigured-model case
     * is a clean failure, never a fabricated narrative.
     */
    public function narrative(Request $request)
    {
        $gapResponse = $this->show($request);
        $gapPayload = json_decode((string) $gapResponse->getContent(), true) ?: [];

        if (($gapPayload['status'] ?? 0) !== 1) {
            return $gapResponse; // same refusal/error show() already produced
        }

        $data = $gapPayload['data'];

        if (($data['competencies'] ?? []) === []) {
            return response()->json([
                'status' => 1,
                'data'   => $data + ['narrative' => null],
            ]);
        }

        if (!$this->ai->isConfigured()) {
            return response()->json(['status' => 0, 'message' => 'No AI model is configured.'], 503);
        }

        try {
            $narrative = $this->ai->chat([
                ['role' => 'system', 'content' => $this->narrativeSystemPrompt()],
                ['role' => 'user', 'content' => $this->narrativeUserPrompt($data)],
            ]);
        } catch (Throwable $e) {
            return response()->json(['status' => 0, 'message' => 'The narrative could not be generated.'], 502);
        }

        return response()->json([
            'status' => 1,
            'data'   => $data + ['narrative' => trim($narrative)],
        ]);
    }

    private function narrativeSystemPrompt(): string
    {
        return 'You are summarising one employee\'s competency gap report for their manager. Use only the '
            . 'facts given in the message - never invent a competency name, proficiency figure, or count that '
            . 'is not present in the input. Call out mandatory items below the required level first, since '
            . 'those are the most consequential finding; only mention a non-mandatory gap if nothing mandatory '
            . 'is short. If every competency is met or unmeasured, say so plainly rather than inventing a '
            . 'problem. Keep the answer to 3-5 sentences of plain language, addressed to the manager.';
    }

    /** @param array{competencies:array,mandatory_below_required:array,coverage:array} $data */
    private function narrativeUserPrompt(array $data): string
    {
        $lines = [];

        foreach ($data['competencies'] as $c) {
            $lines[] = sprintf(
                '- %s (%s): required %d, %s%s',
                $c['competency_name'],
                $c['competency_code'] ?: 'no code',
                $c['required_proficiency'],
                $c['state'],
                $c['measured_level'] !== null ? sprintf(', measured %.1f', $c['measured_level']) : ''
            );
        }

        if ($data['mandatory_below_required'] !== []) {
            $lines[] = '';
            $lines[] = 'Mandatory items below required level:';
            foreach ($data['mandatory_below_required'] as $m) {
                $lines[] = sprintf(
                    '- %s / %s: rated %d, required %d',
                    $m['competency_name'],
                    $m['kasba_type'],
                    $m['rating'],
                    $m['required']
                );
            }
        }

        $lines[] = '';
        $lines[] = sprintf(
            'Coverage: %d competencies required, %d unmeasured.',
            $data['coverage']['competencies_required'],
            $data['coverage']['competencies_unmeasured']
        );

        return implode("\n", $lines);
    }
}
