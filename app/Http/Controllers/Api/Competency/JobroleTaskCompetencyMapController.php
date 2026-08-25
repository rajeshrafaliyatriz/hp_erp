<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * L-14 — the TASK CATALOGUE -> COMPETENCY write path.
 *
 * Mirrors `RoleCompetencyMapController` one level down: that maps a JOB ROLE to
 * competencies, this maps a JOB ROLE TASK to competencies. Same tenant column,
 * same store/destroy contract, same rule that the tenant comes from identity and
 * never from a request field.
 *
 * ── ⚠ 0 ROWS IS THE EXPECTED STATE, NOT AN UNFINISHED BUILD ────────────────
 *
 * `jobrole_task_competency_map` holds **0 rows and should**. This link is the
 * CATALOGUE half of Q-E1, and the catalogue must be **AUTHORED BY A CUSTOMER, NOT
 * DERIVED**. Deriving its first content from the instance link (`task.skill_id`,
 * 1,514 of 2,271) was tried and refuted: a derived catalogue is not an authority,
 * it is the instance wearing an authority's name, and every later override would
 * be measured against a claim nobody made.
 *
 * **So this controller is a BUILD whose CONTENT is an AUTHORING DEPENDENCY.**
 * Shipping it does not fill the table. It stays empty until a customer maps their
 * first task, and an empty table here is the product working correctly - the same
 * shape as X-08's importer.
 *
 * **Do not read 0 rows as work remaining.**
 *
 * ── ⚠ DECLARED REFERENT: `jobrole_task_id` -> `s_jobrole_task` ─────────────
 *
 * `s_jobrole_task` has **55,961 rows and NO `sub_institute_id`**. It is a GLOBAL
 * seed library shared by every tenant (Q-C1), and this map is the tenant-owned
 * mapping onto it — global library, tenant mapping, which is the pattern where
 * the referent is least obvious and most often guessed.
 *
 * Two consequences that are easy to get wrong:
 *
 *   1. **A task id CANNOT be validated against the caller's tenant**, because the
 *      library has no tenant. The check below confirms the task EXISTS; it cannot
 *      confirm it is "theirs", because nobody owns it.
 *   2. **Anything aggregated over this map is tenant-scoped only through
 *      `sub_institute_id` ON THIS TABLE.** A join that reaches `s_jobrole_task`
 *      and stops there has left the tenant behind — which is exactly why the
 *      `task_competency_link` readiness gate was dropped: it measured the global
 *      side and would have read identically for every tenant.
 *
 * The competency side IS tenant-checked, because `competency` is tenant-owned.
 *
 * ── ⚠ TWO KEY COLUMNS SINCE 2026_08_24_110000, EXACTLY ONE SET ─────────────
 *
 * A row now names EITHER a shared catalogue task (`jobrole_task_id`) OR the
 * organisation's own (`user_jobrole_task_id`). The second exists because a
 * tenant that AUTHORED its task has no catalogue row to point at, so under the
 * old shape it could not map that task at all - the feature was structurally
 * unavailable to exactly the organisations the empty-start work was built for.
 *
 * MariaDB 10.1 has no usable CHECK constraint, so "exactly one" is enforced
 * HERE, in `taskKey()`, and nowhere else. Every read and write goes through
 * that one resolver; a second place deciding which column it means would be a
 * second answer to the same question.
 */
class JobroleTaskCompetencyMapController extends Controller
{
    use ResolvesCompetencyContext;

    /**
     * THE ONE PLACE THAT DECIDES WHICH COLUMN A MAPPING IS KEYED ON.
     *
     * Accepts whatever id a caller happens to hold and returns the definite key,
     * or null when neither resolves. Callers never choose a column themselves.
     *
     * ── THE RULE: CATALOGUE WINS WHERE A BRIDGE EXISTS ─────────────────────
     *
     * A tenant task that bridges to the catalogue is keyed on the CATALOGUE, so
     * one mapping serves that standard task across the organisation and the 121
     * existing rows keep working unchanged. `user_jobrole_task_id` is the
     * fallback for exactly the tasks that have no bridge - authored in-house, or
     * left NULL by the name backfill because the match was ambiguous.
     *
     * Measured before choosing: on live, 89,528 of 89,752 (tenant, catalogue
     * task) pairs are one-to-one and the maximum is two copies, so keying on the
     * catalogue fragments nothing.
     *
     * @return array{jobrole_task_id:?int,user_jobrole_task_id:?int,task:?string,jobrole:?string,resolved_from:string}|null
     */
    private function taskKey(int $sid, ?int $anyTaskId, ?int $userTaskId = null): ?array
    {
        // An explicit tenant-task id is a statement about which table is meant,
        // so it is honoured first and never silently reinterpreted.
        if ($userTaskId) {
            return $this->keyFromUserTask($sid, $userTaskId);
        }

        if (!$anyTaskId) {
            return null;
        }

        $cat = DB::table('s_jobrole_task')->where('id', $anyTaskId)->first(['id', 'task', 'jobrole']);
        if ($cat) {
            return [
                'jobrole_task_id'      => (int) $cat->id,
                'user_jobrole_task_id' => null,
                'task'                 => $cat->task,
                'jobrole'              => $cat->jobrole,
                'resolved_from'        => 'catalogue',
                'alias_catalogue'      => (int) $cat->id,
                'alias_own'            => $this->ownTasksBridgedTo($sid, (int) $cat->id),
            ];
        }

        // Not a catalogue id - the caller is holding a tenant task row. This is
        // the ambiguity `forTask` already absorbed rather than pushing a schema
        // detail into every screen.
        return $this->keyFromUserTask($sid, $anyTaskId);
    }

    /** A tenant task row: bridged to the catalogue if it can be, keyed on itself if not. */
    private function keyFromUserTask(int $sid, int $userTaskId): ?array
    {
        $cols = ['id', 'task', 'jobrole'];
        if (self::hasCatalogueBridge()) {
            $cols[] = 'catalogue_task_id';
        }

        // TENANT-SCOPED, unlike the catalogue lookup above. s_user_jobrole_task
        // DOES carry sub_institute_id, so one organisation must not be able to
        // map another's task by guessing an id.
        $own = DB::table('s_user_jobrole_task')
            ->where('id', $userTaskId)
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->first($cols);

        if (!$own) {
            return null;
        }

        $bridged = $own->catalogue_task_id ?? null;
        if ($bridged) {
            $cat = DB::table('s_jobrole_task')->where('id', $bridged)->first(['id', 'task', 'jobrole']);

            if ($cat) {
                return [
                    'jobrole_task_id'      => (int) $cat->id,
                    'user_jobrole_task_id' => null,
                    'task'                 => $cat->task,
                    'jobrole'              => $cat->jobrole,
                    'resolved_from'        => 'tenant_task',
                    'alias_catalogue'      => (int) $cat->id,
                    'alias_own'            => $this->ownTasksBridgedTo($sid, (int) $cat->id),
                ];
            }
        }

        return [
            'jobrole_task_id'      => null,
            'user_jobrole_task_id' => (int) $own->id,
            'task'                 => $own->task,
            'jobrole'              => $own->jobrole,
            'resolved_from'        => 'tenant_task_unbridged',
            'alias_catalogue'      => null,
            'alias_own'            => [(int) $own->id],
        ];
    }

    /** Every tenant row of THIS organisation that bridges to one catalogue task. */
    private function ownTasksBridgedTo(int $sid, int $catalogueId): array
    {
        if (!self::hasCatalogueBridge()) {
            return [];
        }

        return DB::table('s_user_jobrole_task')
            ->where('sub_institute_id', $sid)
            ->where('catalogue_task_id', $catalogueId)
            ->whereNull('deleted_at')
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * IS THIS PERSON AT THE LEVEL THIS COMPETENCY DEMANDS?
     *
     * The single implementation, shared by `forTask()` (one task, during
     * assignment) and `readiness()` (every task on a role). Two copies of this
     * comparison would let the assign modal and the employee drawer disagree
     * about whether somebody can do a job — which is the one thing a capability
     * system must never do.
     *
     * `met` and `below` are MEASURED VERDICTS. `unknown` is not a verdict at
     * all, and it carries the reason so a screen can say which of the two
     * absences it is:
     *
     *   not_assessed - nobody has rated this person on this competency
     *   no_target    - their role does not say what level this competency needs
     *
     * @return array{state:string,reason:?string}
     */
    private static function competencyVerdict(?float $level, ?float $required): array
    {
        /*
         * NO TARGET IS CHECKED FIRST, and the order matters for the words the
         * screen shows.
         *
         * Both absences produce `unknown`, so no count moves either way. But a
         * person whose role does not require this competency AND who is
         * unrated used to be reported as "not assessed" - which tells an
         * assigner to go and rate them, when rating them would change nothing.
         * There is no bar to clear. "No target" is the actionable truth: this
         * competency is not part of that role's requirements.
         */
        if ($required === null) return ['state' => 'unknown', 'reason' => 'no_target'];
        if ($level === null)    return ['state' => 'unknown', 'reason' => 'not_assessed'];

        return $level >= $required
            ? ['state' => 'met',   'reason' => null]
            : ['state' => 'below', 'reason' => null];
    }

    /**
     * The task's own verdict, from its competencies' verdicts.
     *
     * NOT_CLEARED OUTRANKS UNKNOWN, deliberately: if one competency is known to
     * be short, the person is not cleared whatever else is unmeasured. More
     * measurement cannot make a known shortfall go away.
     *
     * `unmapped` is separate from `unknown` because the fix differs — one needs
     * somebody to map the task, the other needs an assessment.
     *
     * @param  array<int,array{state:string}>  $competencies
     */
    private static function taskVerdict(array $competencies): string
    {
        if ($competencies === []) {
            return 'unmapped';
        }

        $states = array_column($competencies, 'state');

        if (in_array('below', $states, true))   return 'not_cleared';
        if (in_array('unknown', $states, true)) return 'unknown';

        return 'cleared';
    }

    /**
     * A job role's competency targets, keyed by competency id.
     *
     * THE LEVEL A TASK DEMANDS COMES FROM THE ROLE, NOT THE TASK.
     * `jobrole_task_competency_map` carries no `required_proficiency` and should
     * not: a task says WHICH competencies it exercises, the role says AT WHAT
     * LEVEL. Putting a level on the task too would create a third place a target
     * could disagree with the other two.
     *
     * So "can this person perform this task" resolves as: the competencies the
     * task exercises, measured against THEIR OWN ROLE's targets for those
     * competencies.
     */
    private function roleTargets(int $sid, ?int $jobroleId)
    {
        if (!$jobroleId) {
            return collect();
        }

        return DB::table('jobrole_competency_map')
            ->where('sub_institute_id', $sid)
            ->where('jobrole_id', $jobroleId)
            ->get(['competency_id', 'required_proficiency', 'is_mandatory'])
            ->keyBy('competency_id');
    }

    /** The job role an employee holds, as an id. */
    private function jobroleOf(int $sid, int $userId): ?int
    {
        $id = (int) DB::table('tbluser')->where('id', $userId)
            ->where('sub_institute_id', $sid)->value('allocated_standards');

        return $id ?: null;
    }

    /** The two key columns as a where-clause fragment. Exactly one is non-null. */
    private static function keyColumns(array $key): array
    {
        return [
            'jobrole_task_id'      => $key['jobrole_task_id'],
            'user_jobrole_task_id' => $key['user_jobrole_task_id'],
        ];
    }

    /**
     * EVERY ROW THAT NAMES THIS TASK, whichever column it was written on.
     *
     * ── WHY READS TAKE THE UNION AND WRITES DO NOT ─────────────────────────
     *
     * `taskKey()` picks ONE column to write on, preferring the catalogue where a
     * bridge exists. That preference can change under a row's feet: a task
     * authored in-house is keyed on `user_jobrole_task_id`, and if it later
     * gains a `catalogue_task_id` - a second backfill run, an adopt - then
     * `taskKey()` starts resolving it to the catalogue and the mapping somebody
     * authored becomes INVISIBLE rather than wrong. Invisible is worse: a screen
     * showing "no competencies mapped" reads as a fact.
     *
     * So a read matches either column, and `store()` clears both before writing,
     * which normalises the task onto the current key. Nothing is ever stranded,
     * and nothing is ever counted twice.
     *
     * @param  string  $prefix  table alias plus dot, or '' when unaliased
     */
    private static function scopeToTask(array $key, string $prefix = '')
    {
        $catalogue = $key['alias_catalogue'] ?? null;
        $own       = $key['alias_own'] ?? [];

        return function ($w) use ($catalogue, $own, $prefix) {
            if ($catalogue) {
                $w->where("{$prefix}jobrole_task_id", $catalogue);
            }
            if ($own !== []) {
                $catalogue
                    ? $w->orWhereIn("{$prefix}user_jobrole_task_id", $own)
                    : $w->whereIn("{$prefix}user_jobrole_task_id", $own);
            }
            if (!$catalogue && $own === []) {
                $w->whereRaw('1 = 0');   // names nothing; must not match everything
            }
        };
    }

    public function index(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = $context['sub_institute_id'];
        $taskId = $request->integer('jobrole_task_id');

        $q = DB::table('jobrole_task_competency_map as m')
            ->join('competency as c', 'c.id', '=', 'm.competency_id')
            // s_jobrole_task is GLOBAL - joined for its label only, never for scope.
            ->leftJoin('s_jobrole_task as t', 't.id', '=', 'm.jobrole_task_id')
            // The tenant's own task, for rows keyed that way. Scoped on the join
            // so a mapping can never borrow a label from another organisation.
            ->leftJoin('s_user_jobrole_task as ut', function ($j) use ($sid) {
                $j->on('ut.id', '=', 'm.user_jobrole_task_id')
                  ->where('ut.sub_institute_id', '=', $sid);
            })
            ->where('m.sub_institute_id', $sid)
            ->whereNull('c.deleted_at');

        if ($taskId) {
            // The filter accepts either kind of id, matching `taskKey()`'s
            // contract: a caller filtering a list should not have to know which
            // column its id belongs to any more than a caller saving one does.
            $q->where(function ($w) use ($taskId) {
                $w->where('m.jobrole_task_id', $taskId)
                  ->orWhere('m.user_jobrole_task_id', $taskId);
            });
        }

        $rows = $q->orderBy('m.id')
            ->get(['m.id', 'm.jobrole_task_id', 'm.user_jobrole_task_id', 'm.competency_id',
                   'c.name as competency_name', 't.task as catalogue_task', 'ut.task as own_task'])
            ->map(fn ($r) => (object) [
                'id'                   => (int) $r->id,
                'jobrole_task_id'      => $r->jobrole_task_id !== null ? (int) $r->jobrole_task_id : null,
                'user_jobrole_task_id' => $r->user_jobrole_task_id !== null ? (int) $r->user_jobrole_task_id : null,
                'keyed_on'             => $r->jobrole_task_id !== null ? 'catalogue' : 'own_task',
                'competency_id'        => (int) $r->competency_id,
                'competency_name'      => $r->competency_name,
                // One label whichever column carried it - the caller renders a
                // task name, not a schema choice.
                'task_name'            => $r->catalogue_task ?? $r->own_task,
            ]);

        return response()->json([
            'status' => 1,
            'data'   => $rows,
            // Stated in the payload, not only in a comment: a caller rendering an
            // empty list should say "none mapped yet", never "no data".
            'note'   => $rows->isEmpty()
                ? 'No task-to-competency mappings authored yet. This catalogue is filled by your organisation, not derived.'
                : null,
        ]);
    }

    /**
     * GET /competency/task-map/for-task — WHAT THIS TASK EXERCISES, AND WHERE
     * THE PERSON BEING ASSIGNED IT STANDS.
     *
     * Built for the assign-task modal, which is where this mapping belongs. The
     * separate Task Competencies screen is a matrix somebody must remember to
     * open, and the evidence that nobody does is in the data: 121 mappings exist
     * because a script wrote them, not a person.
     *
     * THE MAPPING IS PER-TENANT EVEN THOUGH THE TASK IS NOT. s_jobrole_task has
     * no sub_institute_id - it is the shared catalogue - but
     * jobrole_task_competency_map does. So two organisations can hold the same
     * standard task and decide it demands entirely different capabilities, and
     * neither sees the other. THAT IS WHY EDITING THIS FROM AN OPERATIONAL SCREEN
     * IS SAFE: a manager is describing their own organisation, not everyone.
     *
     * `user_id` is OPTIONAL and names the SUBJECT - the person being assigned the
     * task. Their rating is rolled up from the KASBA items beneath each
     * competency. Absent user_id means "just show me the mapping".
     */
    public function forTask(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'jobrole_task_id' => 'required|integer',
            'user_id'         => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid     = (int) $context['sub_institute_id'];
        $taskId  = $request->integer('jobrole_task_id');
        $subject = $request->input('user_id') !== null ? (int) $request->input('user_id') : null;

        // ACCEPTS EITHER ID, AND RESOLVES - through `taskKey()`, the one resolver
        // every path in this controller shares. The assign modal holds an
        // s_user_jobrole_task id (the tenant's own task row); a mapping may be
        // keyed on the shared catalogue or on that row. Requiring the caller to
        // know which is which pushes a schema detail into every screen - and it
        // is exactly the mistake that produced a 404 the first time this was
        // mounted.
        $key = $this->taskKey($sid, $taskId);

        if (!$key) {
            return response()->json([
                'status'  => 0,
                'message' => 'Job role task not found.',
                'reason'  => 'not_found',
            ], 404);
        }

        /*
         * THE `no_catalogue_bridge` 404 IS GONE, AND THAT IS THE POINT OF E3.
         *
         * This used to refuse an unbridged tenant task outright - "competencies
         * cannot be mapped to it yet" - which was true of the schema and fatal
         * for the product: a task the organisation WROTE ITSELF was, by
         * definition, unbridgeable, so the one path that mattered most for a
         * clean-start tenant was the one path that always failed. Such a task is
         * now keyed on `user_jobrole_task_id` and works like any other.
         */
        $resolvedFrom = $key['resolved_from'];

        // Mapped competencies for THIS tenant, matching EITHER key column - see
        // scopeToTask(). `distinct` because a task with both a catalogue row and
        // a stale tenant-keyed row would otherwise list a competency twice.
        $mapped = DB::table('jobrole_task_competency_map as m')
            ->join('competency as c', 'c.id', '=', 'm.competency_id')
            ->where('m.sub_institute_id', $sid)
            ->where(self::scopeToTask($key, 'm.'))
            ->whereNull('c.deleted_at')
            ->distinct()
            ->get(['c.id', 'c.name', 'c.code', 'c.criticality']);

        /*
         * THE SUBJECT'S LEVEL COMES FROM THE ONE NAMED ROLL-UP.
         *
         * ── WHAT THIS REPLACED, AND WHY IT MATTERED ────────────────────────
         *
         * This method used to compute its own:
         *
         *     selectRaw('... COUNT(r.rating) rated, AVG(r.rating) avg_rating')
         *
         * `AVG(r.rating)` is an UNWEIGHTED mean. `ProficiencyService::rollUp()`
         * weights each item by `competency_kasba_item.weight`. Measured on live,
         * weights run 1 to 5 and **15 competencies hold items of differing
         * weight** — so for those, this panel and the employee drawer showed
         * DIFFERENT LEVELS for the same person and the same competency.
         *
         * That is the exact failure `ProficiencyService` exists to prevent, in
         * the one endpoint nobody was reading: two numbers that disagree are
         * worse than one that is wrong, because nobody knows which to trust.
         *
         * ── COVERAGE COMES WITH IT ─────────────────────────────────────────
         *
         * The old shape reported `items` and `items_rated` but no coverage, so
         * a level drawn from one rated item of five looked identical to a
         * complete measurement. The service returns coverage as a fraction of
         * WEIGHT, which is the honest denominator.
         */
        $rollUp = [];
        if ($subject && $mapped->isNotEmpty()) {
            $rollUp = app(\App\Services\Competency\ProficiencyService::class)
                ->rollUp($sid, $subject, $mapped->pluck('id')->map(fn ($i) => (int) $i)->all());
        }

        /*
         * ── CAN THIS PERSON PERFORM THIS TASK? ─────────────────────────────
         *
         * The question an assigner is actually asking, answered here rather
         * than left for them to work out from a rating with no bar beside it.
         *
         * THE BAR COMES FROM THE ASSIGNEE'S OWN JOB ROLE. A task carries no
         * level of its own — see roleTargets() — so "what does this task
         * demand" resolves as "what does this person's role require of the
         * competencies this task exercises". Assigning the same task to two
         * people on different roles can therefore give two different answers,
         * and that is correct: the standard they are held to is their own.
         */
        $targets = $subject ? $this->roleTargets($sid, $this->jobroleOf($sid, $subject)) : collect();

        $competencies = $mapped->map(function ($c) use ($rollUp, $targets, $subject) {
            $r      = $rollUp[(int) $c->id] ?? null;
            $target = $targets[(int) $c->id] ?? null;

            $required = $target && $target->required_proficiency !== null
                ? (float) $target->required_proficiency
                : null;
            $level = $r['level'] ?? null;

            // The ONE comparison, shared with readiness(). No arithmetic here.
            $verdict = $subject
                ? self::competencyVerdict($level === null ? null : (float) $level, $required)
                : ['state' => 'unknown', 'reason' => 'no_subject'];

            return [
                'id'          => (int) $c->id,
                'name'        => $c->name,
                'code'        => $c->code,
                'criticality' => $c->criticality,
                'items'       => $r ? count($r['items']) : 0,
                'items_rated' => $r ? count(array_filter($r['items'], fn ($i) => $i['measured'])) : 0,
                // NULL means UNMEASURED. A zero here would read as "scored
                // nothing", which is a different and much worse claim.
                'rating'      => $level,
                // How much of the competency's weight that level speaks for.
                'coverage'    => $r['coverage'] ?? 0.0,
                // The bar, and whether they clear it.
                'required'     => $required,
                'is_mandatory' => (bool) ($target->is_mandatory ?? false),
                'state'        => $verdict['state'],
                'reason'       => $verdict['reason'],
                // How far short, where that is a knowable number.
                'shortfall'    => $verdict['state'] === 'below'
                    ? round($required - (float) $level, 2)
                    : null,
            ];
        })->values();

        /*
         * THE TASK'S OWN VERDICT, for the banner the assigner reads first.
         * Only meaningful with a subject: without one this endpoint is just
         * showing the mapping, and "unknown" would imply a judgement was
         * attempted.
         */
        $readiness = $subject ? self::taskVerdict($competencies->all()) : null;

        // Everything this tenant could map, so the picker has options without a
        // second request.
        $available = DB::table('competency')
            ->where('sub_institute_id', $sid)->whereNull('deleted_at')
            ->orderBy('name')->get(['id', 'name', 'code']);

        return response()->json([
            'status' => 1,
            'data'   => [
                // BOTH KEY COLUMNS ARE RETURNED, exactly one of them set, so the
                // caller can echo them straight back to `store()` and never has
                // to know which kind of id it started with. Returning only a
                // single "jobrole_task_id" would have forced every screen to
                // re-derive the distinction this resolver exists to absorb.
                'jobrole_task_id'      => $key['jobrole_task_id'],
                'user_jobrole_task_id' => $key['user_jobrole_task_id'],
                'keyed_on'             => $key['jobrole_task_id'] ? 'catalogue' : 'own_task',
                'resolved_from'        => $resolvedFrom,
                'task'                 => $key['task'],
                'jobrole'              => $key['jobrole'],
                'user_id'              => $subject,

                /*
                 * cleared / not_cleared / unknown / unmapped — or NULL when no
                 * subject was named. NULL and 'unknown' are different: one
                 * means nobody asked, the other means we asked and cannot say.
                 */
                'readiness'            => $readiness,
                'readiness_summary'    => $subject ? [
                    'met'          => $competencies->where('state', 'met')->count(),
                    'below'        => $competencies->where('state', 'below')->count(),
                    'unknown'      => $competencies->where('state', 'unknown')->count(),
                    'total'        => $competencies->count(),
                    // Called out separately: an average can clear the bar while
                    // a MANDATORY competency inside the task does not.
                    'mandatory_below' => $competencies->where('state', 'below')->where('is_mandatory', true)->count(),
                ] : null,

                'competencies'         => $competencies,
                'available'            => $available,
            ],
            'empty_is_expected' => $competencies->isEmpty(),
            'empty_reason'      => $competencies->isEmpty()
                ? 'No competencies are mapped to this task yet. Add them here so this work counts towards capability.'
                : null,
        ]);
    }

    /**
     * GET /competency/task-map/readiness — WHICH OF THIS PERSON'S TASKS THEY ARE
     * ACTUALLY CLEARED TO PERFORM.
     *
     * The gap engine answers "where is this person short". This answers the
     * question a manager actually asks: "can they do the work in front of them".
     * Same inputs, one level down - no new resolver, no new arithmetic.
     *
     * ── FOUR STATES, KEPT APART ────────────────────────────────────────────
     *
     *   cleared      every competency the task exercises is measured and at or
     *                above the role's target
     *   not_cleared  at least one measured competency is BELOW target
     *   unknown      nothing is known to be below, but something is unmeasured
     *                or has no target - so no claim can be made
     *   unmapped     no competency is mapped to this task at all
     *
     * `unknown` and `unmapped` are separate because they have different fixes:
     * one needs an assessment, the other needs somebody to map the task. Folding
     * them together - or worse, calling either of them "cleared" - would assert
     * a readiness nobody measured, which for a regulated customer is the single
     * most dangerous thing this system could say.
     *
     * NOT_CLEARED OUTRANKS UNKNOWN. If one competency is known to be short, the
     * person is not cleared whatever else is unmeasured; more measurement cannot
     * make a known shortfall go away.
     *
     * ── THE TARGET COMES FROM THE ROLE, EXACTLY AS THE GAP ENGINE READS IT ──
     *
     * `jobrole_competency_map` only, with no framework fallback. The Matrix
     * inherits framework defaults for display, but readiness must agree with
     * `CompetencyGapController` to the number - two screens disagreeing about
     * whether somebody is cleared is worse than either being incomplete. A
     * mapped competency with no role target is reported `no_target`, not
     * silently passed.
     */
    public function readiness(Request $request)
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

        // Own readiness, or an elevated role - the same rule the gap endpoint
        // applies, because this exposes the same measurements.
        $subject = $this->competencySubject($context, $request->integer('user_id'));
        if (!is_int($subject)) {
            return $subject;
        }

        $sid = (int) $context['sub_institute_id'];

        $jobroleId = $request->filled('jobrole_id')
            ? $request->integer('jobrole_id')
            : (int) DB::table('tbluser')->where('id', $subject)
                ->where('sub_institute_id', $sid)->value('allocated_standards');

        if (!$jobroleId) {
            return response()->json([
                'status'  => 0,
                'message' => 'No job role for this employee, so there are no tasks to be ready for.',
            ], 422);
        }

        $jobrole = DB::table('s_user_jobrole')->where('id', $jobroleId)
            ->where('sub_institute_id', $sid)->value('jobrole');

        // ── the role's tasks ────────────────────────────────────────────────
        $cols  = ['id', 'task', 'critical_work_function', 'task_type'];
        $bridged = self::hasCatalogueBridge();
        if ($bridged) {
            $cols[] = 'catalogue_task_id';
        }

        $tasks = DB::table('s_user_jobrole_task')
            ->where('sub_institute_id', $sid)
            ->where('jobrole_id', $jobroleId)
            ->whereNull('deleted_at')
            ->orderBy('task')
            ->get($cols);

        if ($tasks->isEmpty()) {
            return response()->json([
                'status'  => 1,
                'message' => 'This job role has no tasks recorded yet.',
                'data'    => ['user_id' => $subject, 'jobrole_id' => $jobroleId, 'jobrole' => $jobrole, 'tasks' => []],
                'counts'  => ['total' => 0, 'cleared' => 0, 'not_cleared' => 0, 'unknown' => 0, 'unmapped' => 0],
            ]);
        }

        // ── the mappings, in ONE query for every task ───────────────────────
        //
        // Both key columns, for the reason scopeToTask() carries: a mapping
        // written on either column belongs to this task and must not vanish
        // because the preferred key changed.
        $ownIds = $tasks->pluck('id')->map(fn ($i) => (int) $i)->all();
        $catIds = $bridged
            ? $tasks->pluck('catalogue_task_id')->filter()->map(fn ($i) => (int) $i)->unique()->values()->all()
            : [];

        $mapRows = DB::table('jobrole_task_competency_map as m')
            ->join('competency as c', 'c.id', '=', 'm.competency_id')
            ->where('m.sub_institute_id', $sid)
            ->whereNull('c.deleted_at')
            ->where(function ($w) use ($catIds, $ownIds) {
                // Opens on a false so both lists are optional and the clause
                // stays an OR-chain. Without it, an empty $catIds would leave
                // the first condition as a bare whereIn and change the meaning
                // of everything after it.
                $w->whereRaw('1 = 0');
                if ($catIds !== []) $w->orWhereIn('m.jobrole_task_id', $catIds);
                if ($ownIds !== []) $w->orWhereIn('m.user_jobrole_task_id', $ownIds);
            })
            ->get(['m.jobrole_task_id', 'm.user_jobrole_task_id', 'c.id as competency_id', 'c.name', 'c.code']);

        $byCat = []; $byOwn = []; $competencyMeta = [];
        foreach ($mapRows as $r) {
            $cid = (int) $r->competency_id;
            $competencyMeta[$cid] = ['id' => $cid, 'name' => $r->name, 'code' => $r->code];
            if ($r->jobrole_task_id !== null)      $byCat[(int) $r->jobrole_task_id][] = $cid;
            if ($r->user_jobrole_task_id !== null) $byOwn[(int) $r->user_jobrole_task_id][] = $cid;
        }

        // ── targets and levels, each read once for the whole page ───────────
        // Same reader `forTask()` uses, so one task viewed during assignment
        // and the same task viewed in the drawer resolve the same bar.
        $targets = $this->roleTargets($sid, $jobroleId);

        // THE ONE NAMED ROLL-UP. No arithmetic in this controller.
        $levels = $competencyMeta === []
            ? []
            : app(\App\Services\Competency\ProficiencyService::class)
                ->rollUp($sid, $subject, array_keys($competencyMeta));

        // ── per task ────────────────────────────────────────────────────────
        $counts = ['total' => 0, 'cleared' => 0, 'not_cleared' => 0, 'unknown' => 0, 'unmapped' => 0];

        $out = $tasks->map(function ($t) use ($byCat, $byOwn, $competencyMeta, $targets, $levels, &$counts) {
            $ids = array_values(array_unique(array_merge(
                $byCat[(int) ($t->catalogue_task_id ?? 0)] ?? [],
                $byOwn[(int) $t->id] ?? []
            )));

            $comps = [];

            foreach ($ids as $cid) {
                $target = $targets[$cid] ?? null;
                $req    = $target && $target->required_proficiency !== null ? (float) $target->required_proficiency : null;
                $level  = $levels[$cid]['level'] ?? null;

                // THE ONE COMPARISON, shared with forTask().
                ['state' => $state, 'reason' => $reason] =
                    self::competencyVerdict($level === null ? null : (float) $level, $req);

                $comps[] = [
                    'id'           => $cid,
                    'name'         => $competencyMeta[$cid]['name'],
                    'code'         => $competencyMeta[$cid]['code'],
                    'required'     => $req,
                    // NULL is UNMEASURED, never 0 - a zero would read as "scored
                    // nothing", a different and much worse claim.
                    'level'        => $level,
                    'coverage'     => $levels[$cid]['coverage'] ?? 0.0,
                    'is_mandatory' => (bool) ($target->is_mandatory ?? false),
                    'state'        => $state,
                    'reason'       => $reason,
                ];
            }

            // THE ONE ROLL-UP OF THOSE VERDICTS, shared with forTask().
            $state = self::taskVerdict($comps);

            $counts['total']++;
            $counts[$state]++;

            return [
                'user_jobrole_task_id'   => (int) $t->id,
                'jobrole_task_id'        => isset($t->catalogue_task_id) ? (int) $t->catalogue_task_id : null,
                'task'                   => $t->task,
                'critical_work_function' => $t->critical_work_function,
                'task_type'              => $t->task_type,
                'state'                  => $state,
                'competencies'           => $comps,
            ];
        })->values();

        return response()->json([
            'status' => 1,
            'data'   => [
                'user_id'    => $subject,
                'jobrole_id' => $jobroleId,
                'jobrole'    => $jobrole,
                'tasks'      => $out,
            ],
            'counts' => $counts,
            // Stated in the payload rather than left for a screen to infer from
            // zeroes: "no tasks mapped yet" and "cleared for nothing" look
            // identical in a count and mean opposite things.
            'note'   => $counts['unmapped'] === $counts['total'] && $counts['total'] > 0
                ? 'None of this role\'s tasks are mapped to competencies yet, so readiness cannot be assessed. Map them under Studio → Task Competencies.'
                : null,
        ]);
    }

    public function store(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            // NEITHER IS `required` ALONE - exactly one must arrive, which no
            // single-field rule can express, so it is checked below and reported
            // in words rather than as a validator message about a field name.
            'jobrole_task_id'       => 'nullable|integer',
            'user_jobrole_task_id'  => 'nullable|integer',
            'items'                 => 'required|array|min:1',
            'items.*.competency_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid        = (int) $context['sub_institute_id'];
        $catId      = $request->input('jobrole_task_id') !== null ? (int) $request->input('jobrole_task_id') : null;
        $userTaskId = $request->input('user_jobrole_task_id') !== null ? (int) $request->input('user_jobrole_task_id') : null;

        if (!$catId && !$userTaskId) {
            return response()->json([
                'status'  => 0,
                'message' => 'Name the task to map: send jobrole_task_id (shared catalogue) or user_jobrole_task_id (your own).',
            ], 422);
        }

        /*
         * "EXACTLY ONE" IS ENFORCED HERE BECAUSE THE DATABASE CANNOT.
         *
         * MariaDB 10.1 parses CHECK and ignores it, so a constraint there would
         * be decoration. Rejecting the request is the honest alternative to
         * storing a row whose meaning every reader would then have to guess -
         * and a row with both ids set has no meaning, because the two may name
         * different tasks.
         */
        if ($catId && $userTaskId) {
            return response()->json([
                'status'  => 0,
                'message' => 'Send only one of jobrole_task_id or user_jobrole_task_id, not both.',
            ], 422);
        }

        $key = $this->taskKey($sid, $catId, $userTaskId);

        if (!$key) {
            return response()->json(['status' => 0, 'message' => 'Job role task not found.'], 404);
        }

        // A competency repeated in one request is user-trippable, so it is caught
        // before the write rather than surfacing as a constraint error.
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

        // Every competency must exist in the CALLER'S OWN tenant. Reported as one
        // message rather than failing on the first, so the whole list is fixable
        // in one pass.
        $valid = DB::table('competency')
            ->where('sub_institute_id', $sid)
            ->whereNull('deleted_at')
            ->whereIn('id', array_keys($seen))
            ->pluck('id')
            ->all();

        $missing = array_diff(array_keys($seen), $valid);
        if ($missing) {
            return response()->json([
                'status'  => 0,
                'message' => 'Competency not found in this organisation: ' . implode(', ', $missing),
            ], 422);
        }

        // CHANGED FROM APPEND TO SYNC 2026-08-13, and the reason is the same one
        // the course map carries: A COMPETENCY REMOVED FROM A TASK MUST STOP
        // COUNTING. An append-only writer gives a user no way to unsay something -
        // the row would survive every later save and keep contributing to the
        // task's competency signal with nothing in the UI able to remove it.
        //
        // SAFE TO CHANGE: the table holds 0 rows, so no existing mapping depends
        // on the old behaviour. Left as append it would have shipped a third
        // writer whose save button means something different from the other two.
        //
        // Rows absent from `items` are deleted for THIS task and THIS tenant,
        // never wider.
        //
        // THE SYNC CLEARS EVERY ROW NAMING THIS TASK ON EITHER COLUMN, then
        // writes on the one key `taskKey()` resolved. That is what normalises a
        // task whose keying changed - see scopeToTask(). Deleting only on the
        // resolved column would leave the other one's rows behind, still read
        // and no longer removable from any screen.
        $cols = self::keyColumns($key);
        $now  = now();

        $result = DB::transaction(function () use ($seen, $sid, $cols, $key, $now) {
            /*
             * DELETE EVERY ROW NAMING THIS TASK, THEN INSERT THE KEPT ONES.
             *
             * A replace rather than a selective upsert, because "delete the rows
             * that differ from the resolved key" is a condition nobody can check
             * by reading it, and this table is small per task (a handful of
             * competencies). The replace is trivially correct and normalises a
             * task whose keying changed, in one step.
             *
             * No created_at is lost that was not already being lost: the previous
             * upsert passed `created_at` in its update values too.
             */
            $before = DB::table('jobrole_task_competency_map')
                ->where('sub_institute_id', $sid)
                ->where(self::scopeToTask($key))
                ->pluck('competency_id')->map(fn ($i) => (int) $i)->unique();

            DB::table('jobrole_task_competency_map')
                ->where('sub_institute_id', $sid)
                ->where(self::scopeToTask($key))
                ->delete();

            DB::table('jobrole_task_competency_map')->insert(
                array_map(fn ($cid) => array_merge(
                    ['sub_institute_id' => $sid, 'competency_id' => $cid, 'created_at' => $now, 'updated_at' => $now],
                    $cols
                ), array_keys($seen))
            );

            // What the USER unsaid - not how many rows the replace touched. A
            // screen reporting "3 removed" after a no-op save would be lying.
            return $before->diff(array_keys($seen))->count();
        });

        $count = DB::table('jobrole_task_competency_map')
            ->where('sub_institute_id', $sid)
            ->where(self::scopeToTask($key))
            ->count();

        return response()->json([
            'status'  => 1,
            'message' => 'Saved.',
            'mapped'  => $count,
            // Reported so the screen can say it in words: a silent deletion is
            // worse than no deletion, and this endpoint now deletes.
            'removed' => $result,
            // Which column the row landed on, so a caller can tell a mapping
            // against the shared catalogue from one against its own task.
            'keyed_on'      => $key['jobrole_task_id'] ? 'catalogue' : 'own_task',
            'resolved_from' => $key['resolved_from'],
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        // Scoped by tenant on the DELETE itself, not checked and then deleted -
        // one statement cannot race with another tenant's row.
        $deleted = DB::table('jobrole_task_competency_map')
            ->where('id', (int) $id)
            ->where('sub_institute_id', $context['sub_institute_id'])
            ->delete();

        if (!$deleted) {
            return response()->json(['status' => 0, 'message' => 'Mapping not found.'], 404);
        }

        return response()->json(['status' => 1, 'message' => 'Removed.']);
    }

    /**
     * GET /competency/task-map/roles          - the catalogue's job roles
     * GET /competency/task-map/tasks?jobrole= - one role's tasks + what each maps to
     *
     * WHY A LIST ENDPOINT AND NOT A FILTER ON index()
     *
     * `index()` answers "what is mapped", filtered by one task id. A panel needs
     * the other question: "what tasks does this role have, and which of them are
     * mapped yet" - including the UNMAPPED ones, which by definition have no row
     * in the map and therefore cannot appear in a query over it.
     *
     * SCOPED PER JOB ROLE because the map keys on jobrole_task_id and THE JOB ROLE
     * IS ALREADY IN THE KEY. A flat picker would ask the user to search 55,961
     * rows the data model has already partitioned.
     *
     * NO PAGINATION, DELIBERATELY. Measured on the DECLARED REFERENT
     * (`s_jobrole_task`, not the tenant table): 2,761 roles, median 19 tasks,
     * p90 31, max 209, and only SEVEN roles above 100. A scrollable list with a
     * count is the honest form; a pager for seven roles is furniture. The count
     * is returned so the screen can say how many rather than implying it showed
     * everything.
     *
     * `s_jobrole_task` IS GLOBAL - no `sub_institute_id`. The ROLE LIST is
     * therefore the same for every tenant, and only the MAP rows are
     * tenant-scoped. That asymmetry is Q-C1's pattern and is stated here because
     * a reader who assumes both are scoped will not understand the join.
     */
    public function roles(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $rows = DB::table('s_jobrole_task')
            ->select('jobrole')
            ->selectRaw('COUNT(*) as task_count')
            ->whereNotNull('jobrole')->where('jobrole', '!=', '')
            ->groupBy('jobrole')
            ->orderBy('jobrole')
            ->get();

        return response()->json([
            'status' => 1,
            'data'   => $rows->map(fn ($r) => [
                'jobrole'    => $r->jobrole,
                'task_count' => (int) $r->task_count,
            ])->values(),
        ]);
    }

    public function tasks(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), ['jobrole' => 'required|string|max:191']);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid     = (int) $context['sub_institute_id'];
        $jobrole = (string) $request->input('jobrole');

        // The catalogue side is GLOBAL; the map side is the caller's tenant only.
        // A LEFT JOIN, because an unmapped task is the normal case and must still
        // appear - it is the whole point of the screen.
        $rows = DB::table('s_jobrole_task as t')
            ->leftJoin('jobrole_task_competency_map as m', function ($j) use ($sid) {
                $j->on('m.jobrole_task_id', '=', 't.id')
                  ->where('m.sub_institute_id', '=', $sid);
            })
            ->leftJoin('competency as c', function ($j) {
                $j->on('c.id', '=', 'm.competency_id')->whereNull('c.deleted_at');
            })
            ->where('t.jobrole', $jobrole)
            ->orderBy('t.task')
            ->get(['t.id as jobrole_task_id', 't.task', 't.critical_work_function',
                   'm.id as map_id', 'm.competency_id', 'c.name as competency_name']);

        // Fold to one entry per task, each carrying its competencies.
        $byTask = [];
        foreach ($rows as $r) {
            $k = (int) $r->jobrole_task_id;
            if (!isset($byTask[$k])) {
                $byTask[$k] = [
                    'jobrole_task_id'        => $k,
                    'task'                   => $r->task,
                    'critical_work_function' => $r->critical_work_function,
                    'competencies'           => [],
                ];
            }
            if ($r->map_id !== null) {
                $byTask[$k]['competencies'][] = [
                    'map_id'          => (int) $r->map_id,
                    'competency_id'   => (int) $r->competency_id,
                    'competency_name' => $r->competency_name,
                ];
            }
        }
        $data = array_values($byTask);
        $mapped = count(array_filter($data, fn ($t) => $t['competencies'] !== []));

        return response()->json([
            'status' => 1,
            'data'   => $data,
            'counts' => [
                'tasks'    => count($data),
                'mapped'   => $mapped,
                'unmapped' => count($data) - $mapped,
            ],
            // L-14's note, kept: nothing mapped is the EXPECTED state for a
            // catalogue a customer has not authored against yet, and the payload
            // says so rather than leaving a screen to infer it from zeroes.
            'empty_is_expected' => $mapped === 0,
        ]);
    }

    /**
     * Is the catalogue bridge column actually there?
     *
     * `s_user_jobrole_task.catalogue_task_id` was created by a one-off script
     * and existed in no migration, so a freshly migrated database did not have
     * it and the read above threw - on the exact "new organisation starting
     * clean" path this feature is for. 2026_08_22_120000 now creates it
     * properly, and this check covers the window where code is deployed ahead
     * of that migration.
     *
     * CACHED FOR THE PROCESS. An information_schema lookup on every request to
     * confirm a fact that cannot change while the process runs would be a real
     * cost for no information.
     *
     * Not Schema::hasColumn(): Laravel 11 introspects with a query selecting
     * `generation_expression`, a column live's MariaDB 10.1 does not have, so
     * that helper throws on production while working on dev - it would turn a
     * guard against a 500 into a cause of one.
     */
    private static function hasCatalogueBridge(): bool
    {
        static $exists = null;

        if ($exists === null) {
            $exists = DB::select(
                'SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
                  LIMIT 1',
                ['s_user_jobrole_task', 'catalogue_task_id']
            ) !== [];
        }

        return $exists;
    }
}
