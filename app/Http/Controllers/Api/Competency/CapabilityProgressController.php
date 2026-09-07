<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Http\Controllers\Concerns\ResolvesEmployeeJobRole;
use App\Http\Controllers\Controller;
use App\Services\Competency\ProficiencyService;
use App\Services\Competency\RatingWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HOW SOMEBODY'S CAPABILITY GOT TO WHERE IT IS.
 *
 * ── WHAT THE PRODUCT COULD NOT SAY ──────────────────────────────────────────
 *
 * Every capability surface showed a current number and nothing behind it. A
 * person reading "your level is 3 of 4 required" could not tell whether that 3
 * came from their own self-rating last week, a manager's judgement two years
 * ago, an AI-marked quiz, or a spreadsheet import - even though
 * competency_kasba_rating has carried `source`, `assessor_id`, `rated_at` and
 * `source_ref_id` the whole time and MyCapabilityController already returns
 * three of them. The client read `source` only, and only as a boolean, to grey
 * out a button.
 *
 * And there was no history at all. competency_kasba_rating is UNIQUE per
 * (tenant, user, item), so a re-rating overwrote the old value; the one
 * "history" screen an employee could reach was fabricated -
 * EmployeeCompetencyProfileController stamps both its entries with the CURRENT
 * level under the comment "Since we don't have a dedicated history table", so it
 * draws a flat line whatever actually happened. A gap that closed left no trace
 * of ever having been open, which is the one thing anybody wants to see.
 *
 * competency_rating_history now records every change with what it replaced and
 * what caused it. This reads it.
 *
 * ── ONE ENDPOINT, THREE SURFACES ────────────────────────────────────────────
 *
 * The employee's own profile, the HR drawer, and the Talent Management progress
 * record all ask the same question about different people, so they share this
 * rather than growing three shapes of the same answer. Who may ask about whom is
 * competencySubject()'s decision - the same rule the rest of the module uses:
 * your own record always, anyone else's only with an elevated role, and a
 * cross-tenant id is 404 rather than 403.
 */
class CapabilityProgressController extends Controller
{
    use ResolvesCompetencyContext;
    use ResolvesEmployeeJobRole;

    public function __construct(
        private readonly RatingWriter $ratings,
        private readonly ProficiencyService $proficiency,
    ) {
    }

    /**
     * GET /competency/capability-progress
     *
     * Query: user_id (optional - defaults to the caller), competency_id (optional)
     */
    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = (int) $context['sub_institute_id'];

        // Defaults to the caller, so the employee's own screen sends no id at
        // all and cannot be pointed at anybody by tampering with one.
        $subject = $this->competencySubject($context, $request->input('user_id') ?: $context['user_id']);
        if (!is_int($subject)) {
            return $subject;
        }

        $competencyId = $request->integer('competency_id') ?: null;

        $history = $this->ratings->historyFor($tenant, $subject, $competencyId);

        /*
         * The requirements this person is measured against, so a movement can be
         * read as progress toward something rather than as a bare number going
         * up. Resolved through the shared job-role rule, because jobtitle_id and
         * allocated_standards disagree for real employees.
         */
        $user = DB::table('tbluser')->where('id', $subject)
            ->first(['id', 'first_name', 'last_name', 'jobtitle_id', 'allocated_standards']);

        $jobroleId = $user ? $this->resolveJobRoleId($user) : null;

        $requirements = $jobroleId ? DB::table('jobrole_competency_map as m')
            ->join('competency as c', 'c.id', '=', 'm.competency_id')
            ->where('m.sub_institute_id', $tenant)
            ->where('m.jobrole_id', $jobroleId)
            ->orderBy('c.name')
            ->get(['m.competency_id', 'm.required_proficiency', 'm.is_mandatory', 'c.name', 'c.code'])
            : collect();

        $levels = $requirements->isEmpty() ? [] : $this->proficiency->rollUp(
            $tenant,
            $subject,
            $requirements->pluck('competency_id')->map(fn ($id) => (int) $id)->all()
        );

        /*
         * A per-competency summary: where they are now, what changed, and when
         * it last moved. The movement is computed from the history rows this
         * request already loaded rather than from a second query.
         */
        $movementsByCompetency = collect($history)->groupBy('competency_id');

        $competencies = $requirements->map(function ($req) use ($levels, $movementsByCompetency) {
            $cid = (int) $req->competency_id;
            $level = $levels[$cid]['level'] ?? null;
            $moves = $movementsByCompetency->get($cid) ?? collect();

            // The rating this person held before the earliest recorded change,
            // which is where the trend starts. NULL old_rating means the item
            // had never been measured, so there is no earlier point.
            $first = $moves->last();

            return [
                'competency_id' => $cid,
                'competency_name' => $req->name,
                'competency_code' => $req->code,
                'required_proficiency' => $req->required_proficiency === null
                    ? null
                    : (int) $req->required_proficiency,
                'is_mandatory' => (bool) $req->is_mandatory,
                'measured_level' => $level,
                'coverage' => $levels[$cid]['coverage'] ?? 0.0,
                // Unmeasured stays distinct from met and from gap, everywhere.
                'state' => $level === null
                    ? 'unmeasured'
                    : ($req->required_proficiency !== null && $level >= (float) $req->required_proficiency
                        ? 'met'
                        : 'gap'),
                'changes' => $moves->count(),
                'last_changed_at' => $moves->first()->changed_at ?? null,
                'started_from' => $first->old_rating ?? null,
            ];
        })->values();

        return response()->json([
            'status' => 1,
            'data' => [
                'user' => [
                    'id' => $subject,
                    'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                ],
                'competencies' => $competencies,
                'history' => $history,
                'is_self' => $subject === (int) $context['user_id'],
            ],
        ]);
    }

    /**
     * GET /competency/capability-progress/roster
     *
     * Everyone in the organisation, and whether their capability has moved.
     *
     * ── WHY A SEPARATE READ ─────────────────────────────────────────────────
     *
     * index() answers "what happened to this person". A development record
     * answers "who is developing", which is a different question and cannot be
     * built by calling index() per employee - that is one query set per person
     * against a list that is the whole organisation.
     *
     * Restricted to elevated roles, because it names other people. An ordinary
     * employee reaching it gets a 403 and their own record is index().
     */
    public function roster(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $tenant = (int) $context['sub_institute_id'];

        // Naming other people is an elevated act. The same alias vocabulary the
        // rest of the module uses.
        $roleKey = DB::table('tbluser as u')
            ->join('tbluserprofilemaster as p', 'p.id', '=', 'u.user_profile_id')
            ->where('u.id', $context['user_id'])
            ->value('p.role_key');

        if (!in_array((string) $roleKey, ['administrator', 'hr_manager', 'hr_executive', 'reporting_manager'], true)) {
            return response()->json([
                'status' => 0,
                "message" => "Your profile is not permitted to view other people's development records.",
            ], 403);
        }

        /*
         * Movement comes from the history table in ONE grouped read rather than
         * per person. A tenant with no history yet returns everybody with zero
         * movements, which is the honest answer - not an empty screen.
         */
        $movement = DB::table('competency_rating_history')
            ->where('sub_institute_id', $tenant)
            ->groupBy('user_id')
            ->get([
                'user_id',
                DB::raw('COUNT(*) as changes'),
                DB::raw('COUNT(DISTINCT competency_id) as competencies_moved'),
                DB::raw('COUNT(DISTINCT course_id) as courses'),
                DB::raw('MAX(changed_at) as last_changed_at'),
                // Only genuine rises count as improvement. A first measurement
                // (old_rating NULL) is not a rise, and a drop is not one either.
                DB::raw('SUM(CASE WHEN old_rating IS NOT NULL AND new_rating > old_rating THEN 1 ELSE 0 END) as improvements'),
            ])
            ->keyBy('user_id');

        $people = DB::table('tbluser as u')
            ->leftJoin('hrms_departments as d', 'd.id', '=', 'u.department_id')
            ->where('u.sub_institute_id', $tenant)
            ->orderBy('u.first_name')
            ->limit(500)
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.jobtitle_id',
                   'u.allocated_standards', 'd.department']);

        $roleNames = DB::table('s_user_jobrole')
            ->where('sub_institute_id', $tenant)
            ->pluck('jobrole', 'id');

        $rows = $people->map(function ($person) use ($movement, $roleNames) {
            $m = $movement->get($person->id);
            $jobroleId = $this->resolveJobRoleId($person);

            return [
                'user_id' => (int) $person->id,
                'name' => trim(($person->first_name ?? '') . ' ' . ($person->last_name ?? '')),
                'department' => $person->department,
                'jobrole' => $jobroleId ? ($roleNames[$jobroleId] ?? null) : null,
                'changes' => (int) ($m->changes ?? 0),
                'improvements' => (int) ($m->improvements ?? 0),
                'competencies_moved' => (int) ($m->competencies_moved ?? 0),
                'courses' => (int) ($m->courses ?? 0),
                'last_changed_at' => $m->last_changed_at ?? null,
            ];
        })
        // People who have actually moved first - that is what the screen is for.
        ->sortByDesc(fn ($r) => [$r['improvements'], $r['changes']])
        ->values();

        return response()->json([
            'status' => 1,
            'data' => [
                'people' => $rows,
                'with_movement' => $rows->where('changes', '>', 0)->count(),
                'total' => $rows->count(),
            ],
        ]);
    }
}
