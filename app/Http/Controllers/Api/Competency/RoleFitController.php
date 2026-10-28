<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Services\Competency\ProficiencyService;
use App\Services\DeepSeekService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * "Which employees best fit this role?" — a genuinely new feature. Despite
 * an earlier audit attributing this to SkillMatchingController, that
 * controller does something unrelated (matching a user's rejected tasks to
 * courses); nothing in this codebase ranked employees against a role before
 * this file.
 *
 * THE RANKING IS DETERMINISTIC AND STANDS ALONE. Per-competency fit is
 * min(measured / required, 1.0), averaged over MEASURED competencies only -
 * an unmeasured one is excluded from the average rather than counted as a
 * zero, the same unmeasured-is-not-a-zero rule CompetencyGapController and
 * ProficiencyService already enforce. A candidate with zero measured
 * competencies gets a null score, not a 0, and sorts last rather than
 * looking like the worst fit on record.
 *
 * NARRATION IS OPTIONAL AND NEVER BLOCKS THE RANKING. If no model is
 * configured, or generation fails, the ranked list still returns - only
 * `narrative` is absent. The LLM explains the top matches; it never
 * re-ranks or decides anything, same rule as every other narration in this
 * project.
 */
class RoleFitController extends Controller
{
    use ResolvesCompetencyContext;

    private const ELEVATED = [
        'administrator', 'hr_manager', 'hr_executive', 'executive', 'auditor',
    ];

    public function __construct(
        private ProficiencyService $proficiency,
        private DeepSeekService $ai
    ) {
    }

    public function rank(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];

        // Ranking reads many employees' ratings at once - this is a
        // management view, not something every employee may run against
        // their colleagues. Same elevated-role list CompetencyGapController
        // uses for "act on somebody else's profile".
        $roleKey = DB::table('tbluser as u')
            ->join('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')
            ->where('u.id', (int) $context['user_id'])
            ->value('p.role_key');

        if (!in_array((string) $roleKey, self::ELEVATED, true)) {
            return response()->json([
                'status'  => 0,
                'message' => 'Ranking employees against a role requires an elevated role.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'jobrole_id'    => 'required|integer',
            'department_id' => 'nullable|integer',
            'limit'         => 'nullable|integer|min:1|max:100',
            'narrate'       => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $jobroleId = $request->integer('jobrole_id');
        $limit = $request->filled('limit') ? $request->integer('limit') : 20;

        $required = DB::table('jobrole_competency_map as m')
            ->join('competency as c', 'c.id', '=', 'm.competency_id')
            ->where('m.sub_institute_id', $sid)
            ->where('m.jobrole_id', $jobroleId)
            ->get(['m.competency_id', 'm.required_proficiency', 'c.name', 'c.code']);

        if ($required->isEmpty()) {
            return response()->json([
                'status'  => 1,
                'message' => 'This job role has no competency requirements defined yet.',
                'data'    => ['jobrole_id' => $jobroleId, 'ranked' => []],
            ]);
        }

        $competencyIds = $required->pluck('competency_id')->all();

        // Capped, not the whole tenant: rollUp() is one query per candidate,
        // and an unbounded loop over every employee on every ranking request
        // is not a cost this endpoint should impose by default.
        $candidates = DB::table('tbluser')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->orderBy('id')
            ->limit(min($limit * 3, 300)) // headroom so a department with many unmeasured employees still fills $limit ranked slots
            ->get(['id', 'first_name', 'last_name']);

        $ranked = [];

        foreach ($candidates as $candidate) {
            $levels = $this->proficiency->rollUp($sid, (int) $candidate->id, $competencyIds);

            $ratios = [];
            $measuredCount = 0;

            foreach ($required as $req) {
                $level = $levels[$req->competency_id]['level'] ?? null;

                if ($level === null) {
                    continue; // unmeasured - excluded from the average, not scored as 0
                }

                $measuredCount++;
                $ratios[] = min($level / (float) $req->required_proficiency, 1.0);
            }

            $ranked[] = [
                'user_id'         => (int) $candidate->id,
                'name'            => trim(($candidate->first_name ?? '') . ' ' . ($candidate->last_name ?? '')),
                'fit_score'       => $ratios !== [] ? round(array_sum($ratios) / count($ratios), 4) : null,
                'measured_count'  => $measuredCount,
                'required_count'  => $required->count(),
            ];
        }

        // Nulls (nothing measured) sort last - a candidate with no evidence
        // is not thereby the worst fit, it is simply not rankable yet.
        usort($ranked, function ($a, $b) {
            if ($a['fit_score'] === null && $b['fit_score'] === null) return 0;
            if ($a['fit_score'] === null) return 1;
            if ($b['fit_score'] === null) return -1;
            return $b['fit_score'] <=> $a['fit_score'] ?: $b['measured_count'] <=> $a['measured_count'];
        });

        $ranked = array_slice($ranked, 0, $limit);

        $data = [
            'jobrole_id' => $jobroleId,
            'required'   => $required->map(fn ($r) => [
                'competency_id' => (int) $r->competency_id,
                'name'          => $r->name,
                'required_proficiency' => (int) $r->required_proficiency,
            ])->all(),
            'ranked' => $ranked,
        ];

        if ($request->boolean('narrate') && $ranked !== [] && $this->ai->isConfigured()) {
            try {
                $data['narrative'] = trim($this->ai->chat([
                    ['role' => 'system', 'content' => $this->narrativeSystemPrompt()],
                    ['role' => 'user', 'content' => $this->narrativeUserPrompt($data)],
                ]));
            } catch (Throwable $e) {
                // Narration is optional. The ranking above is the answer either way.
            }
        }

        return response()->json(['status' => 1, 'data' => $data]);
    }

    private function narrativeSystemPrompt(): string
    {
        return 'You are explaining a ranked list of employees against a job role\'s competency requirements, '
            . 'to the manager who will decide who to consider. Use only the facts given - never invent a name, '
            . 'score, or competency not present in the input. Explain the strongest and weakest areas of the '
            . 'top 3 candidates only. Do not re-rank them or suggest a different order than the one given - '
            . 'the ranking is already decided; you are explaining it, not making it. Keep the answer to 3-5 '
            . 'sentences of plain language.';
    }

    /** @param array{required:array,ranked:array} $data */
    private function narrativeUserPrompt(array $data): string
    {
        $lines = ['Required competencies: ' . implode(', ', array_map(
            fn ($r) => $r['name'] . ' (required ' . $r['required_proficiency'] . ')',
            $data['required']
        ))];

        $lines[] = '';
        $lines[] = 'Top candidates, in ranked order:';

        foreach (array_slice($data['ranked'], 0, 3) as $i => $r) {
            $lines[] = sprintf(
                '%d. %s - fit score %s, %d of %d competencies measured',
                $i + 1,
                $r['name'],
                $r['fit_score'] !== null ? number_format($r['fit_score'] * 100, 0) . '%' : 'unmeasured',
                $r['measured_count'],
                $r['required_count']
            );
        }

        return implode("\n", $lines);
    }
}
