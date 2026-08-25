<?php

namespace App\Http\Controllers\Api\Competency\Concerns;

use App\Services\Competency\ProficiencyService;
use Illuminate\Support\Facades\DB;

/**
 * THE ONE COMPARISON: what a role REQUIRES against what a person has MEASURED.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * `CompetencyGapController` implemented this and got it right. Then
 * `DevelopmentPlanController::gaps()` answered the same question a second time
 * from a different source - `s_user_skill_jobrole` against `s_skill_matrix`,
 * matched by NAME - and got it wrong in the way that matters most:
 *
 *     $currentLevel = $held ? (int) $held->skill_level : 0;
 *
 * An unmeasured item scored as ZERO. Measured on live, that turned **3,328 of
 * 3,873 gap rows (85.9%) into shortfalls nobody had assessed**, and made 55 of
 * 164 development plans display a person failing every requirement when the
 * truth was that nobody had ever rated them.
 *
 * So the rule lives here, once, and both controllers call it. A second
 * implementation is a second answer, and the one that drifts is the one nobody
 * tested.
 *
 * ── WHAT IT WILL NOT DO ────────────────────────────────────────────────────
 *
 * NO ARITHMETIC ON LEVELS. Every level comes from `ProficiencyService::rollUp`,
 * the one named roll-up. This trait compares two numbers and classifies the
 * result; it never computes one.
 *
 * UNMEASURED IS NEITHER ZERO NOR PASS. It is its own state with its own count.
 * Calling it zero asserts a shortfall nobody measured; calling it met asserts a
 * pass nobody earned.
 */
trait ResolvesCompetencyGap
{
    /**
     * Required vs measured for one person against one job role.
     *
     * Returns the exact shape `CompetencyGapController` has always returned, so
     * that endpoint's payload is unchanged by the extraction:
     *
     *   competencies[]              one row per requirement, with its state
     *   mandatory_below_required[]  the SECOND number - see below
     *   coverage{}                  how much of the requirement was measurable
     *
     * @return array{competencies: array, mandatory_below_required: array, coverage: array}
     */
    protected function competencyGapFor(int $subInstituteId, int $userId, int $jobroleId): array
    {
        // Resolved BY KEY. The job role's name is read for display and never
        // used to match - that is the defect this whole chain removed.
        $required = DB::table('jobrole_competency_map as m')
            ->join('competency as c', 'c.id', '=', 'm.competency_id')
            ->where('m.sub_institute_id', $subInstituteId)
            ->where('m.jobrole_id', $jobroleId)
            ->orderBy('c.name')
            ->get(['m.competency_id', 'm.required_proficiency', 'm.is_mandatory', 'c.name', 'c.code']);

        if ($required->isEmpty()) {
            return [
                'competencies'             => [],
                'mandatory_below_required' => [],
                'coverage'                 => ['competencies_required' => 0, 'competencies_unmeasured' => 0],
            ];
        }

        /*
         * Resolved through the container rather than constructor injection so
         * any controller can use this trait without changing its signature.
         * ProficiencyService holds no per-request state, so the two paths give
         * the same instance the same answer.
         */
        $levels = app(ProficiencyService::class)
            ->rollUp($subInstituteId, $userId, $required->pluck('competency_id')->all());

        $competencies    = [];
        $mandatoryBelow  = [];
        $unmeasuredCount = 0;

        foreach ($required as $req) {
            $roll  = $levels[$req->competency_id] ?? null;
            $level = $roll['level'] ?? null;

            // Three states, kept apart on purpose.
            $state = match (true) {
                $level === null                             => 'unmeasured',
                $level >= (float) $req->required_proficiency => 'met',
                default                                     => 'gap',
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

            /*
             * THE SECOND NUMBER: mandatory ITEMS below required, not
             * competencies. An average can sit above the bar while an item
             * inside it does not, and for a regulated customer that distinction
             * is the whole product.
             */
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

        return [
            'competencies'             => $competencies,
            'mandatory_below_required' => $mandatoryBelow,
            // Reported, never inferred from the absence of a gap.
            'coverage' => [
                'competencies_required'   => $required->count(),
                'competencies_unmeasured' => $unmeasuredCount,
            ],
        ];
    }

    /**
     * The job role a development plan or profile row names, resolved to an id.
     *
     * A plan stores `jobrole` as TEXT. 159 of 164 live plans name exactly one
     * role in their tenant, 5 name several, 0 name none. The same rule this
     * work applies at every ambiguous join holds here:
     *
     *     EXACTLY ONE MATCH RESOLVES; ANYTHING ELSE RETURNS NULL.
     *
     * Picking one of several same-named roles would attach a person's gap
     * report to a role they may not hold, which is worse than reporting nothing.
     *
     * Prefers `jobrole_id` where the caller already has it - the migration adds
     * that column precisely so this lookup stops being needed over time.
     */
    protected function resolveJobroleIdByName(int $subInstituteId, ?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $ids = DB::table('s_user_jobrole')
            ->where('sub_institute_id', $subInstituteId)
            ->where('jobrole', $name)
            ->whereNull('deleted_at')
            ->limit(2)                       // two is enough to know it is ambiguous
            ->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }
}
