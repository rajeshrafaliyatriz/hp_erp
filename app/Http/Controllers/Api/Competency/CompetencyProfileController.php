<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Services\Competency\ProficiencyService;
use App\Services\DeepSeekService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * "Summarize this employee's capability profile" — built on
 * `competency_kasba_rating`, the active rating system (confirmed live:
 * updated within the last week, 177 rated employees), not
 * `s_skill_matrix`/`EmployeeCompetencyProfileController::show()`, which is a
 * separate, older system last touched nine months ago. Narrating over the
 * legacy table would show a different, inconsistent picture of this
 * employee from the one CompetencyGapController and RoleFitController
 * already give — both of which read the KASBA tables this controller does.
 *
 * UNLIKE THE GAP REPORT, THIS IS NOT SCOPED TO ONE JOB ROLE'S REQUIREMENTS.
 * It summarises every competency the employee has at least one rated KASBA
 * item against, required or not — "what do we know about this person",
 * not "how do they compare to one role".
 */
class CompetencyProfileController extends Controller
{
    use ResolvesCompetencyContext;

    public function __construct(
        private ProficiencyService $proficiency,
        private DeepSeekService $ai
    ) {
    }

    public function show(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $subject = $this->competencySubject($context, $id);
        if (!is_int($subject)) {
            return $subject;
        }

        $sid = $context['sub_institute_id'];

        $employee = DB::table('tbluser')->where('id', $subject)->where('sub_institute_id', $sid)
            ->first(['first_name', 'last_name', 'employee_no']);

        $competencyIds = DB::table('competency_kasba_rating as r')
            ->join('competency_kasba_item as i', 'i.id', '=', 'r.kasba_item_id')
            ->where('r.sub_institute_id', $sid)
            ->where('r.user_id', $subject)
            ->distinct()
            ->pluck('i.competency_id')
            ->all();

        if ($competencyIds === []) {
            return response()->json([
                'status' => 1,
                'message' => 'No rated competencies for this employee yet.',
                'data' => ['user_id' => $subject, 'competencies' => []],
            ]);
        }

        $names = DB::table('competency')
            ->whereIn('id', $competencyIds)
            ->where('sub_institute_id', $sid)
            ->get(['id', 'name', 'code'])
            ->keyBy('id');

        $levels = $this->proficiency->rollUp($sid, $subject, $competencyIds);

        $competencies = [];
        foreach ($competencyIds as $cid) {
            $name = $names[$cid] ?? null;
            if ($name === null) {
                continue; // competency row missing/different tenant - not this employee's to show
            }
            $roll = $levels[$cid] ?? null;
            $competencies[] = [
                'competency_id' => (int) $cid,
                'name'          => $name->name,
                'code'          => $name->code,
                'level'         => $roll['level'] ?? null,
                'coverage'      => $roll['coverage'] ?? 0.0,
            ];
        }

        // Highest level first; unmeasured (level null) last, same convention
        // as RoleFitController's ranking.
        usort($competencies, fn ($a, $b) => $b['level'] === null ? -1 : ($a['level'] === null ? 1 : $b['level'] <=> $a['level']));

        $data = [
            'user_id'      => $subject,
            'name'         => $employee ? trim($employee->first_name . ' ' . $employee->last_name) : null,
            'employee_no'  => $employee->employee_no ?? null,
            'competencies' => $competencies,
        ];

        if ($request->boolean('narrate', true) && $this->ai->isConfigured()) {
            try {
                $data['narrative'] = trim($this->ai->chat([
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => $this->userPrompt($data)],
                ]));
            } catch (Throwable $e) {
                // The profile itself is still a complete, honest answer.
            }
        }

        return response()->json(['status' => 1, 'data' => $data]);
    }

    private function systemPrompt(): string
    {
        return 'You are summarising one employee\'s capability profile for their manager. Use only the '
            . 'competencies and levels given - never invent a name or figure not present in the input. '
            . 'Call out their strongest and weakest rated competencies. If coverage is low (few KASBA items '
            . 'measured per competency), say the picture is partial rather than presenting it as complete. '
            . 'Keep the answer to 3-5 sentences of plain language.';
    }

    /** @param array{name:?string,competencies:array} $data */
    private function userPrompt(array $data): string
    {
        $lines = ['Employee: ' . ($data['name'] ?? 'Unknown')];
        $lines[] = '';
        $lines[] = 'Rated competencies (level 1-5, or unmeasured):';

        foreach ($data['competencies'] as $c) {
            $lines[] = sprintf(
                '- %s (%s): %s, coverage %.0f%%',
                $c['name'],
                $c['code'] ?: 'no code',
                $c['level'] !== null ? number_format($c['level'], 1) : 'unmeasured',
                $c['coverage'] * 100
            );
        }

        return implode("\n", $lines);
    }
}
