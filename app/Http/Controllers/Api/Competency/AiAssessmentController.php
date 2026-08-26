<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Concerns\ResolvesEmployeeJobRole;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Services\Competency\AssessmentScoringService;
use App\Services\DeepSeekService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * AI-GENERATED CAPABILITY ASSESSMENT.
 *
 * HR or an admin generates ONE test per job role; every employee holding that
 * role takes the same test. Per job role rather than per person because the
 * questions are then comparable between people, and it is one generation rather
 * than one per head.
 *
 * THREE ENDPOINTS, TWO AUDIENCES:
 *
 *   POST /competency/ai-assessment/generate   admin,hr   jobrole -> questions
 *   GET  /competency/ai-assessment/mine       employee   TAKES NO SUBJECT
 *   POST /competency/ai-assessment/submit     employee   TAKES NO SUBJECT
 *
 * THE EMPLOYEE ENDPOINTS NAME NOBODY. Same design as MyCapabilityController: an
 * endpoint that accepts no user_id cannot be made to return or write somebody
 * else's data, because there is no decision to get wrong. `data_scope` is read
 * by nothing in this codebase, so self-scoping cannot be delegated to it.
 *
 * THE GUARD: NO ITEM, NO QUESTION. Questions are generated only for capability
 * items that exist for that job role, and `kasba_item_id` is NOT NULL in the
 * schema. An LLM produces plausible output by design - a test about competencies
 * nobody authored would look exactly like a test about competencies somebody
 * did. The structure refuses it rather than a rule someone must remember.
 *
 * NOTHING IS HARDCODED. Question formats, counts and the item list all come from
 * the request or the data. The model name comes from config. The five KASBA
 * dimensions are whatever the tenant has authored.
 */
class AiAssessmentController extends Controller
{
    use ResolvesEmployeeJobRole;
    use ResolvesCompetencyContext;

    /** Formats this controller knows how to store and score. */
    private const FORMATS = ['mcq', 'short_answer'];

    /**
     * POST /competency/ai-assessment/generate
     *
     * Body: jobrole_id, [formats[]], [questions_per_item], [title]
     */
    public function generate(Request $request, DeepSeekService $ai)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'jobrole_id'         => 'required|integer',
            'formats'            => 'nullable|array',
            'formats.*'          => 'string|in:' . implode(',', self::FORMATS),
            'questions_per_item' => 'nullable|integer|min:1|max:5',
            'title'              => 'nullable|string|max:191',
            /*
             * SCOPE. Generation used to take a job role and produce questions
             * for EVERY KASBA item of EVERY competency mapped to it - the only
             * test you could build was "everything this role does".
             *
             *   jobrole    - every item of every mapped competency (as before)
             *   competency - every item of ONE competency
             *   kasba_item - ONE item. The individual-KASBA case.
             *
             * The job role is still required in every mode: it is what decides
             * who the test is FOR, and the required proficiency comes from the
             * role's mapping, not from the competency in isolation.
             */
            'scope_type'         => 'nullable|string|in:jobrole,competency,kasba_item',
            'competency_id'      => 'nullable|integer|required_if:scope_type,competency',
            'kasba_item_id'      => 'nullable|integer|required_if:scope_type,kasba_item',
            'time_limit_minutes' => 'nullable|integer|min:1|max:480',
            'pass_percent'       => 'nullable|numeric|min:0|max:100',
            'is_open'            => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid       = (int) $context['sub_institute_id'];
        $actor     = (int) $context['user_id'];
        $jobroleId = $request->integer('jobrole_id');
        $formats   = $request->input('formats') ?: self::FORMATS;
        $perItem   = (int) ($request->input('questions_per_item') ?: 1);

        // REFUSED BEFORE ANY WORK, and said in words. Without this the caller
        // gets a generic failure from deep inside an HTTP client.
        if (!$ai->isConfigured()) {
            return response()->json([
                'status'  => 0,
                'message' => 'AI assessment generation is not configured. DEEPSEEK_API_KEY is not set.',
                'reason'  => 'not_configured',
            ], 503);
        }

        $jobrole = DB::table('s_user_jobrole')
            ->where('id', $jobroleId)->where('sub_institute_id', $sid)
            ->first(['id', 'jobrole']);

        if (!$jobrole) {
            return response()->json(['status' => 0, 'message' => 'Job role not found.'], 404);
        }

        $scopeType    = (string) ($request->input('scope_type') ?: 'jobrole');
        $competencyId = $request->filled('competency_id') ? $request->integer('competency_id') : null;
        $kasbaItemId  = $request->filled('kasba_item_id') ? $request->integer('kasba_item_id') : null;

        // THE GUARD, APPLIED BEFORE THE LLM IS TOLD ANYTHING.
        // Narrowed by scope, but ALWAYS still inside the role's own mapping -
        // narrowing must not become a way to assess someone on a competency
        // their role does not require.
        $items = DB::table('jobrole_competency_map as m')
            ->join('competency_kasba_item as k', 'k.competency_id', '=', 'm.competency_id')
            ->leftJoin('competency as c', 'c.id', '=', 'm.competency_id')
            ->where('m.sub_institute_id', $sid)->where('k.sub_institute_id', $sid)
            ->where('m.jobrole_id', $jobroleId)
            ->when($scopeType === 'competency', fn ($q) => $q->where('m.competency_id', $competencyId))
            ->when($scopeType === 'kasba_item', fn ($q) => $q->where('k.id', $kasbaItemId))
            ->get(['k.id', 'k.kasba_type', 'k.item_label', 'k.competency_id', 'c.name as competency_name', 'm.required_proficiency']);

        if ($items->isEmpty()) {
            // The reason differs by scope, because "nothing to assess" has a
            // different fix in each case and a single message would send people
            // to the wrong screen.
            $why = match ($scopeType) {
                'competency' => 'That competency is not mapped to this job role, so there is nothing to assess. Map it in Role Requirements first.',
                'kasba_item' => 'That KASBA item does not belong to a competency mapped to this job role, so there is nothing to assess.',
                default      => 'This job role has no competencies mapped to it, so there is nothing to assess. Add them in Role Requirements first.',
            };

            return response()->json([
                'status'  => 0,
                'message' => $why,
                'reason'  => 'no_items',
                'empty_is_expected' => true,
            ], 422);
        }

        try {
            // chatJson(), not json() - the service's real signature takes a
            // messages array and forces a JSON response format. Resolved from the
            // service rather than assumed from the name.
            $generated = $ai->chatJson([
                ['role' => 'system', 'content' => 'You write workplace capability assessments. You return only valid JSON.'],
                ['role' => 'user',   'content' => $this->prompt($jobrole->jobrole, $items, $formats, $perItem)],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 0,
                'message' => 'The assessment service did not return a usable result.',
                'detail'  => $e->getMessage(),
            ], 502);
        }

        $rows = $this->acceptable($generated, $items, $formats, $jobrole->jobrole);

        if (!$rows) {
            return response()->json([
                'status'  => 0,
                'message' => 'No question in the generated result referenced a real capability item, so nothing was saved.',
                'reason'  => 'no_valid_questions',
            ], 422);
        }

        $testId = null;
        DB::transaction(function () use (&$testId, $sid, $jobroleId, $jobrole, $actor, $ai, $request, $rows,
                                          $scopeType, $competencyId, $kasbaItemId, $items) {
            /*
             * A DEFAULT TITLE THAT SAYS WHAT THE TEST IS.
             *
             * Every generated test used to be called "Capability assessment —
             * {role}", so a role with four tests had four identically named
             * tests and nobody could tell which was which. The scope is the
             * distinguishing fact, so the scope is in the name.
             */
            $scopeLabel = match ($scopeType) {
                'competency' => $items->first()->competency_name ?? 'competency',
                'kasba_item' => $items->first()->item_label ?? 'item',
                default      => $jobrole->jobrole,
            };

            $testId = DB::table('competency_assessment_test')->insertGetId([
                'sub_institute_id' => $sid,
                'jobrole_id'       => $jobroleId,
                'scope_type'       => $scopeType,
                'competency_id'    => $scopeType === 'competency' ? $competencyId : null,
                'kasba_item_id'    => $scopeType === 'kasba_item' ? $kasbaItemId : null,
                'title'            => $request->input('title') ?: ('Capability assessment — ' . $scopeLabel),
                'time_limit_minutes' => $request->filled('time_limit_minutes')
                    ? $request->integer('time_limit_minutes') : null,
                // NULL, not 50: a test with no threshold set reports a score and
                // makes no pass/fail claim, rather than inventing one.
                'pass_percent'     => $request->filled('pass_percent') ? $request->input('pass_percent') : null,
                'is_open'          => $request->boolean('is_open') ? 1 : 0,
                'model'            => $ai->model(),
                'status'           => 'draft',
                'generated_by'     => $actor,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            foreach ($rows as $i => $r) {
                DB::table('competency_assessment_question')->insert([
                    'sub_institute_id' => $sid,
                    'test_id'          => $testId,
                    'kasba_item_id'    => $r['kasba_item_id'],
                    'cited_item_label'           => $r['cited_item_label'],
                    'cited_kasba_type'           => $r['cited_kasba_type'],
                    'cited_competency_id'        => $r['cited_competency_id'],
                    'cited_competency_name'      => $r['cited_competency_name'],
                    'cited_jobrole'              => $r['cited_jobrole'],
                    'cited_required_proficiency' => $r['cited_required_proficiency'],
                    'format'           => $r['format'],
                    'question_text'    => $r['question_text'],
                    'options'          => $r['options'] !== null ? json_encode($r['options']) : null,
                    'correct_option'   => $r['correct_option'],
                    'model_answer'     => $r['model_answer'],
                    'max_score'        => 1,
                    'sort_order'       => $i,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }
        });

        return response()->json([
            'status' => 1,
            'data'   => [
                'test_id'          => $testId,
                'jobrole_id'       => $jobroleId,
                'model'            => $ai->model(),
                'items_available'  => $items->count(),
                'questions_saved'  => count($rows),
                'status_is'        => 'draft',
            ],
            // Said rather than implied: the caller asked for N and may have got
            // fewer, because questions naming an item that does not exist are
            // dropped rather than trusted.
            'questions_requested' => $items->count() * $perItem,
            'questions_dropped'   => max(0, ($items->count() * $perItem) - count($rows)),
            'message' => 'Test generated as a draft. Publish it to make it visible to employees in this job role.',
        ], 201);
    }

    /**
     * GET /competency/ai-assessment/jobroles — the tenant's job roles.
     *
     * generate() needs a jobrole_id and NOTHING ON THE FRONTEND COULD PRODUCE ONE.
     * The two existing lists were both the wrong shape: the task-map roles are the
     * GLOBAL catalogue keyed by NAME with no tenant column, and role-requirements
     * needs an id to start from. This returns the caller's own job roles with ids.
     *
     * IT REPORTS HOW MANY COMPETENCIES EACH ROLE HAS. A role with 0 cannot be
     * assessed — generate() refuses it — so the screen can say why BEFORE the
     * button is pressed rather than after. An option that is offered and then
     * refused is worse than one that explains itself.
     *
     * Tenant from the token. Guarded profile:admin,hr like the rest of this half.
     */
    /**
     * GET /competency/ai-assessment/scope-options?jobrole_id=X
     *
     * What a job role actually contains: its competencies, and the KASBA items
     * under each. The generator needs this to offer anything narrower than
     * "everything this role does".
     *
     * ── IT IS THE SAME QUERY generate() USES, ON PURPOSE ────────────────────
     *
     * If the picker and the generator disagreed about what a role contains, the
     * form would cheerfully offer a scope that then produced nothing - and the
     * user would have paid for a model call to find out. One query, grouped two
     * ways, so the list you choose from is by construction the list that gets
     * assessed.
     *
     * Small by nature: the largest role on either database has 5 competencies
     * and 20 items. No pagination, no search - it all fits on screen.
     */
    public function scopeOptions(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), ['jobrole_id' => 'required|integer']);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid       = (int) $context['sub_institute_id'];
        $jobroleId = $request->integer('jobrole_id');

        $jobrole = DB::table('s_user_jobrole')
            ->where('id', $jobroleId)->where('sub_institute_id', $sid)->whereNull('deleted_at')->first();

        if (!$jobrole) {
            return response()->json(['status' => 0, 'message' => 'Job role not found.'], 404);
        }

        $rows = DB::table('jobrole_competency_map as m')
            ->join('competency_kasba_item as k', 'k.competency_id', '=', 'm.competency_id')
            ->leftJoin('competency as c', 'c.id', '=', 'm.competency_id')
            ->where('m.sub_institute_id', $sid)->where('k.sub_institute_id', $sid)
            ->where('m.jobrole_id', $jobroleId)
            ->orderBy('c.name')->orderBy('k.kasba_type')->orderBy('k.id')
            ->get(['k.id', 'k.kasba_type', 'k.item_label', 'k.competency_id',
                   'c.name as competency_name', 'm.required_proficiency']);

        $competencies = [];
        foreach ($rows as $row) {
            $key = (int) $row->competency_id;
            $competencies[$key] ??= [
                'id'                   => $key,
                'name'                 => $row->competency_name ?: 'Unnamed competency',
                'required_proficiency' => $row->required_proficiency,
                'items'                => [],
            ];
            $competencies[$key]['items'][] = [
                'id'         => (int) $row->id,
                // An item with no label is shown as such rather than as a blank
                // row - it is a real state in this data (66 live rows carry
                // free text and no library link) and a blank line hides it.
                'label'      => $row->item_label ?: ('Unlabelled ' . $row->kasba_type . ' item'),
                'kasba_type' => $row->kasba_type,
            ];
        }

        return response()->json([
            'status' => 1,
            'data'   => [
                'jobrole'      => $jobrole->jobrole,
                'competencies' => array_values($competencies),
                'total_items'  => $rows->count(),
            ],
            // The SAME refusal generate() gives, said before the button rather
            // than after a failed call.
            'empty_is_expected' => $rows->isEmpty(),
            'empty_reason' => $rows->isEmpty()
                ? 'This job role has no competencies mapped to it, so there is nothing to assess. Add them in Role Requirements first.'
                : null,
        ]);
    }

    public function jobroles(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];

        $roles = DB::table('s_user_jobrole as j')
            ->leftJoin('jobrole_competency_map as m', function ($join) use ($sid) {
                $join->on('m.jobrole_id', '=', 'j.id')->where('m.sub_institute_id', '=', $sid);
            })
            ->where('j.sub_institute_id', $sid)
            ->whereNull('j.deleted_at')
            ->groupBy('j.id', 'j.jobrole', 'j.department')
            ->orderBy('j.jobrole')
            ->get([
                'j.id',
                'j.jobrole as name',
                'j.department',
                DB::raw('COUNT(m.id) as competency_count'),
            ]);

        $assessable = $roles->where('competency_count', '>', 0)->count();

        return response()->json([
            'status' => 1,
            'data'   => [
                'roles'      => $roles,
                'total'      => $roles->count(),
                'assessable' => $assessable,
            ],
            // A tenant with job roles but none mapped is the NORMAL state before
            // anyone authors Role Requirements. Said here so the screen explains
            // it rather than showing a list where every option fails.
            'empty_is_expected' => $assessable === 0,
            'empty_reason'      => $assessable === 0
                ? 'None of your job roles has competencies mapped yet. Add them in Role Requirements before generating an assessment.'
                : null,
        ]);
    }

    /**
     * POST /competency/ai-assessment/publish — make a draft visible to employees.
     *
     * Body: test_id, [publish=true|false]
     *
     * WHY THIS EXISTS AS ITS OWN STEP: generate() writes a DRAFT. Without a
     * publish step a generated test can never reach anybody, which is the state
     * this feature shipped in until now. It is deliberately not automatic —
     * an LLM wrote these questions and a person should look at them before an
     * employee is assessed on them.
     *
     * ONE PUBLISHED TEST PER JOB ROLE. Publishing supersedes any other published
     * test for that role rather than adding a second, because mine() returns the
     * latest and two live tests would make which-one-you-get a matter of ordering.
     * THE SUPERSEDED COUNT IS RETURNED IN WORDS, never silently.
     *
     * Unpublishing is supported (publish=false) and is NOT a delete: responses
     * already recorded are untouched.
     */
    public function publish(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'test_id' => 'required|integer',
            'publish' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid    = (int) $context['sub_institute_id'];
        $testId = $request->integer('test_id');
        $wants  = $request->boolean('publish', true);

        $test = DB::table('competency_assessment_test')
            ->where('id', $testId)->where('sub_institute_id', $sid)->whereNull('deleted_at')
            ->first(['id', 'jobrole_id', 'status', 'title']);

        if (!$test) {
            return response()->json(['status' => 0, 'message' => 'Assessment not found.'], 404);
        }

        // A test with no questions cannot be published. It would appear to an
        // employee as an empty exam - present-looking and assessing nothing,
        // which is the failure this whole feature is built to refuse.
        $questions = DB::table('competency_assessment_question')->where('test_id', $testId)->count();
        if ($wants && $questions === 0) {
            return response()->json([
                'status'  => 0,
                'message' => 'This assessment has no questions, so it cannot be published.',
                'reason'  => 'no_questions',
            ], 422);
        }

        $superseded = 0;
        DB::transaction(function () use (&$superseded, $sid, $test, $wants) {
            if ($wants) {
                $superseded = DB::table('competency_assessment_test')
                    ->where('sub_institute_id', $sid)
                    ->where('jobrole_id', $test->jobrole_id)
                    ->where('id', '!=', $test->id)
                    ->where('status', 'published')
                    ->update(['status' => 'superseded', 'updated_at' => now()]);
            }

            DB::table('competency_assessment_test')->where('id', $test->id)->update([
                'status'       => $wants ? 'published' : 'draft',
                'published_at' => $wants ? now() : null,
                'updated_at'   => now(),
            ]);
        });

        return response()->json([
            'status' => 1,
            'data'   => [
                'test_id'    => $test->id,
                'jobrole_id' => (int) $test->jobrole_id,
                'status_is'  => $wants ? 'published' : 'draft',
                'questions'  => $questions,
                'superseded' => $superseded,
            ],
            'message' => $wants
                ? ($superseded > 0
                    ? "Published. {$superseded} previously published assessment(s) for this job role were superseded and are no longer shown to employees. Their recorded answers are untouched."
                    : 'Published. Employees in this job role can now see it.')
                : 'Unpublished. Employees can no longer see it. Answers already recorded are untouched.',
        ]);
    }

    /**
     * GET /competency/ai-assessment/mine — TAKES NO SUBJECT.
     *
     * The published test for the caller's own job role, without answers. A
     * correct_option is never sent to the person taking the test.
     */
    public function mine(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];
        $me  = (int) $context['user_id'];   // THE CALLER. Never a request field.

        $user = DB::table('tbluser')->where('id', $me)->where('sub_institute_id', $sid)
            ->first(['id', 'jobtitle_id', 'allocated_standards']);

        // Both columns, not just jobtitle_id - see ResolvesEmployeeJobRole.
        $jobroleId = $user ? $this->resolveJobRoleId($user) : null;

        if (!$user || !$jobroleId) {
            return response()->json([
                'status' => 1,
                'data'   => ['test' => null, 'questions' => []],
                'empty_is_expected' => true,
                'empty_reason' => 'You do not have a job role yet, so no assessment has been prepared for you.',
                'scope' => 'self',
            ]);
        }

        $test = DB::table('competency_assessment_test')
            ->where('sub_institute_id', $sid)->where('jobrole_id', $jobroleId)
            ->where('status', 'published')->whereNull('deleted_at')
            ->orderByDesc('published_at')->first(['id', 'title', 'instructions', 'published_at']);

        if (!$test) {
            return response()->json([
                'status' => 1,
                'data'   => ['test' => null, 'questions' => []],
                'empty_is_expected' => true,
                'empty_reason' => 'No assessment has been published for your job role yet.',
                'scope' => 'self',
            ]);
        }

        $questions = DB::table('competency_assessment_question as q')
            ->leftJoin('competency_assessment_response as r', function ($j) use ($me) {
                $j->on('r.question_id', '=', 'q.id')->where('r.user_id', '=', $me);
            })
            ->where('q.test_id', $test->id)->orderBy('q.sort_order')
            // NOTE THE ABSENT COLUMNS: correct_option and model_answer are NOT
            // selected. The person taking the test is never sent the answers.
            ->get(['q.id', 'q.format', 'q.question_text', 'q.options', 'q.max_score',
                   'r.answer_text', 'r.selected_option', 'r.answered_at']);

        $answered = $questions->whereNotNull('answered_at')->count();

        return response()->json([
            'status' => 1,
            'data'   => [
                'test'      => $test,
                'questions' => $questions->map(function ($q) {
                    $q->options = $q->options ? json_decode($q->options, true) : null;
                    return $q;
                })->values(),
                'total'      => $questions->count(),
                'answered'   => $answered,
                // UNANSWERED IS NOT ZERO. Reported as outstanding, never scored.
                'unanswered' => $questions->count() - $answered,
                'submitted'  => $questions->count() > 0 && $answered === $questions->count(),
            ],
            'empty_is_expected' => $questions->isEmpty(),
            'scope' => 'self',
        ]);
    }

    /**
     * POST /competency/ai-assessment/submit — TAKES NO SUBJECT.
     *
     * Body: answers[] of { question_id, selected_option | answer_text }
     *
     * Q-B3 HOLDS: this records answers and scores MCQs. It does NOT move anyone's
     * proficiency. A submitted rating is not a confirmed one, and proficiency
     * changes only on explicit confirmation elsewhere.
     */
    public function submit(Request $request, AssessmentScoringService $marker)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), [
            'answers'                   => 'required|array|min:1',
            'answers.*.question_id'     => 'required|integer',
            'answers.*.selected_option' => 'nullable|string|max:50',
            'answers.*.answer_text'     => 'nullable|string|max:5000',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid = (int) $context['sub_institute_id'];
        $me  = (int) $context['user_id'];

        $ids = collect($request->input('answers'))->pluck('question_id')->map('intval')->all();

        /*
         * THE CLOCK HAS TO BITE, OR IT IS DECORATION.
         *
         * start() computes `seconds_remaining` server-side and the browser
         * counts it down, but nothing refused a LATE submission - so closing
         * the tab, disabling JavaScript, or simply leaving it open overnight
         * bought unlimited time on a timed assessment. A limit the server
         * announces and does not enforce is worse than no limit: it tells
         * honest people they have thirty minutes while everyone else has as
         * long as they like.
         *
         * A GRACE PERIOD IS DELIBERATE. The clock is enforced 60 seconds late,
         * because the browser's auto-submit fires AT zero and then has to make
         * a network round trip. Refusing exactly on the boundary would reject
         * the very submission the timer just triggered.
         *
         * Late answers are REFUSED, not scored as zero - nothing already
         * recorded is touched, and whatever they saved before the deadline
         * stands.
         */
        $lateAttempt = DB::table('competency_assessment_attempt as a')
            ->join('competency_assessment_test as t', 't.id', '=', 'a.test_id')
            ->where('a.sub_institute_id', $sid)->where('a.user_id', $me)
            ->whereNull('a.submitted_at')
            ->whereNotNull('a.started_at')
            ->whereNotNull('t.time_limit_minutes')
            ->whereRaw('DATE_ADD(a.started_at, INTERVAL (t.time_limit_minutes * 60) + 60 SECOND) < NOW()')
            ->first(['a.id', 'a.started_at', 't.title', 't.time_limit_minutes']);

        if ($lateAttempt) {
            // Finalise what they DID answer in time, so a missed deadline still
            // produces a result rather than an abandoned sitting.
            $marker->finalise((int) $lateAttempt->id, $sid);

            return response()->json([
                'status'  => 0,
                'reason'  => 'time_expired',
                'message' => sprintf(
                    'Time ran out on "%s" — it allowed %d minutes. Everything you answered before the deadline has been kept and scored; these later answers were not accepted.',
                    $lateAttempt->title,
                    (int) $lateAttempt->time_limit_minutes
                ),
                'data' => ['attempt_id' => (int) $lateAttempt->id],
            ], 422);
        }

        // Only questions on a PUBLISHED test for the caller's OWN job role are
        // accepted. A question id from anywhere else is dropped, not refused -
        // and the count of dropped ones is reported rather than hidden.
        /*
         * THE SAME ROLE RESOLUTION mine() USES.
         *
         * This joined `u.jobtitle_id = t.jobrole_id` directly, which is the one
         * thing ResolvesEmployeeJobRole's docblock says never to do: an
         * employee's role lives in EITHER jobtitle_id OR allocated_standards,
         * and the two disagree. mine() resolved it properly and submit() did
         * not, so a person could be served a test and then have every answer
         * silently counted as "dropped".
         *
         * Measured today: 292 of 298 live employees resolve either way, so this
         * bites nobody right now - it bites the moment anyone is created
         * through a path that writes only allocated_standards, which is the
         * path the employee form uses.
         */
        $user = DB::table('tbluser')->where('id', $me)->where('sub_institute_id', $sid)
            ->first(['id', 'jobtitle_id', 'allocated_standards']);
        $myJobroleId = $user ? $this->resolveJobRoleId($user) : null;

        $allowed = $myJobroleId
            ? DB::table('competency_assessment_question as q')
                ->join('competency_assessment_test as t', 't.id', '=', 'q.test_id')
                ->where('q.sub_institute_id', $sid)
                ->where('t.status', 'published')
                /*
                 * A test is takeable when it targets your job role, OR it is
                 * open to everyone in the tenant. `is_open` is what makes
                 * self-serve tests possible without loosening the role rule for
                 * assigned ones.
                 */
                ->where(fn ($w) => $w->where('t.jobrole_id', $myJobroleId)->orWhere('t.is_open', 1))
                ->whereIn('q.id', $ids)
                ->get(['q.id', 'q.test_id', 'q.format', 'q.correct_option', 'q.max_score'])
                ->keyBy('id')
            : collect();

        $written = 0; $scored = 0;
        foreach ($request->input('answers') as $a) {
            $q = $allowed->get((int) ($a['question_id'] ?? 0));
            if (!$q) {
                continue;
            }

            // MCQ scores itself. SHORT ANSWER IS LEFT UNSCORED - null, not zero.
            // An unscored answer is awaiting review; a zero would be a mark.
            $score = null; $by = null;
            if ($q->format === 'mcq' && $q->correct_option !== null) {
                $score = ((string) ($a['selected_option'] ?? '') === (string) $q->correct_option) ? $q->max_score : 0;
                $by = 'auto';
                $scored++;
            }

            // created_at is NOT in the update set: it was there, so changing an
            // answer rewrote when the answer was first given.
            $exists = DB::table('competency_assessment_response')
                ->where('question_id', $q->id)->where('user_id', $me)->exists();

            $values = [
                'sub_institute_id' => $sid,
                'test_id'          => $q->test_id,
                'answer_text'      => $a['answer_text'] ?? null,
                'selected_option'  => $a['selected_option'] ?? null,
                'score'            => $score,
                'scored_by'        => $by,
                'answered_at'      => now(),
                'updated_at'       => now(),
            ];
            if (!$exists) {
                $values['created_at'] = now();
            }

            DB::table('competency_assessment_response')->updateOrInsert(
                ['question_id' => $q->id, 'user_id' => $me],
                $values
            );
            $written++;
        }

        /*
         * FINALISE, but only when the caller says they are DONE.
         *
         * `final=false` (the default) is a save: answers are recorded and the
         * person can come back. `final=true` is the submission - it totals the
         * attempt, marks the written answers and produces the rating proposals.
         *
         * Kept apart because scoring is expensive and irreversible-feeling: a
         * partial save that produced a result would show someone a mark for a
         * test they had not finished.
         */
        $testId = $allowed->first()->test_id ?? null;
        $result = null;

        if ($request->boolean('final') && $testId) {
            $attemptId = $this->attemptFor((int) $testId, $me, $sid);
            $totals    = $marker->finalise($attemptId, $sid);

            /*
             * MARKING IS NOT DONE HERE, AND THAT IS DELIBERATE.
             *
             * Marking written answers means an HTTP call to DeepSeek with a
             * 120-second timeout. Doing it inline would make a person press
             * Submit and then watch a spinner for up to two minutes with no
             * idea whether their answers were saved - and it holds the database
             * connection open across the whole call, which is how the first
             * version of this ended in "MySQL server has gone away".
             *
             * Submit therefore returns the moment the answers are safe. The
             * caller then asks for marking separately (POST .../attempts/{id}/mark),
             * so a slow or unavailable model delays a SCORE, never a SUBMISSION.
             */
            $result = [
                'attempt_id'      => $attemptId,
                'score'           => $totals['total'],
                'max_score'       => $totals['max'],
                'percent'         => $totals['percent'],
                'awaiting_review' => $totals['awaiting'],
                'proposals'       => $totals['proposals'],
                // The caller uses this to decide whether to ask for marking.
                'marking_pending' => $totals['awaiting'] > 0,
            ];
        }

        return response()->json([
            'status' => 1,
            'data'   => [
                'answers_written' => $written,
                'auto_scored'     => $scored,
                'awaiting_review' => $written - $scored,
                'dropped'         => count($ids) - $written,
                'result'          => $result,
            ],
            // Stated so nobody reads a submission as a proficiency change.
            'proficiency_unchanged' => true,
            'message' => $result
                ? 'Assessment submitted and scored. Any rating it suggests is a proposal until someone approves it - your proficiency is not changed by submitting.'
                : 'Answers saved. Nothing is scored until you submit.',
        ]);
    }

    /**
     * POST /competency/ai-assessment/start - TAKES NO SUBJECT.
     *
     * Opens the sitting and anchors the clock. The countdown is measured from
     * `started_at` ON THE SERVER, because a timer the browser owns is a timer
     * the browser can reset with a refresh.
     */
    public function start(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $validator = Validator::make($request->all(), ['test_id' => 'required|integer']);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => $validator->errors()->first()], 422);
        }

        $sid = (int) $context['sub_institute_id'];
        $me  = (int) $context['user_id'];
        $testId = (int) $request->input('test_id');

        if (!$this->mayTake($testId, $me, $sid)) {
            return response()->json([
                'status' => 0,
                'message' => 'This assessment is not open to you.',
            ], 403);
        }

        $attemptId = $this->attemptFor($testId, $me, $sid);

        // Only the FIRST open starts the clock. Re-opening a part-finished test
        // must not hand back time that has already run.
        DB::table('competency_assessment_attempt')
            ->where('id', $attemptId)->whereNull('started_at')
            ->update(['started_at' => now(), 'status' => 'in_progress', 'updated_at' => now()]);

        $attempt = DB::table('competency_assessment_attempt')->find($attemptId);
        $test = DB::table('competency_assessment_test')->find($testId);

        return response()->json([
            'status' => 1,
            'data'   => [
                'attempt_id'         => $attemptId,
                'started_at'         => $attempt->started_at,
                'time_limit_minutes' => $test->time_limit_minutes ?? null,
                // Computed here, not in the browser, for the same reason.
                'seconds_remaining'  => $this->secondsRemaining($attempt, $test),
                'submitted_at'       => $attempt->submitted_at,
            ],
        ]);
    }

    /**
     * GET /competency/ai-assessment/my-result - TAKES NO SUBJECT.
     *
     * What the person scored, and why. This is the half of the loop that never
     * existed: `mine()` deliberately does not select `r.score`, so somebody
     * could take an assessment and never learn a single thing about how they
     * did.
     *
     * Correct answers are STILL not sent. Per question the caller learns their
     * own score and the maximum, which shows where marks were lost without
     * handing out the answer key for a test that is still published.
     */
    public function myResult(Request $request)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];
        $me  = (int) $context['user_id'];

        $attempt = DB::table('competency_assessment_attempt as a')
            ->join('competency_assessment_test as t', 't.id', '=', 'a.test_id')
            ->where('a.sub_institute_id', $sid)->where('a.user_id', $me)
            ->when($request->filled('test_id'), fn ($q) => $q->where('a.test_id', $request->integer('test_id')))
            ->orderByDesc('a.submitted_at')->orderByDesc('a.id')
            ->first(['a.*', 't.title', 't.pass_percent', 't.scope_type', 't.time_limit_minutes']);

        if (!$attempt || !$attempt->submitted_at) {
            return response()->json([
                'status' => 1,
                'data'   => null,
                'empty_is_expected' => true,
                'empty_reason' => 'You have not submitted an assessment yet.',
            ]);
        }

        $questions = DB::table('competency_assessment_question as q')
            ->leftJoin('competency_assessment_response as r', function ($j) use ($me) {
                $j->on('r.question_id', '=', 'q.id')->where('r.user_id', '=', $me);
            })
            ->where('q.test_id', $attempt->test_id)->orderBy('q.sort_order')
            // correct_option and model_answer remain unselected.
            ->get(['q.id', 'q.question_text', 'q.format', 'q.max_score', 'q.cited_item_label',
                   'q.cited_kasba_type', 'q.cited_competency_name',
                   'r.score', 'r.scored_by', 'r.answered_at']);

        $proposals = DB::table('competency_assessment_rating_proposal')
            ->where('attempt_id', $attempt->id)->where('sub_institute_id', $sid)
            ->orderByDesc('scored_percent')
            ->get(['item_label', 'kasba_type', 'questions', 'scored_percent',
                   'proposed_rating', 'current_rating', 'status']);

        return response()->json([
            'status' => 1,
            'data'   => [
                'attempt'   => $attempt,
                'questions' => $questions,
                // What the result SUGGESTS. Shown to the person so they know
                // what is being proposed about them, and labelled pending so
                // nobody reads it as their new rating.
                'proposals' => $proposals,
                'passed'    => $attempt->pass_percent !== null && $attempt->percent !== null
                    ? (float) $attempt->percent >= (float) $attempt->pass_percent
                    : null,
                'bands'     => AssessmentScoringService::RATING_BANDS,
            ],
            'proficiency_unchanged' => true,
        ]);
    }

    /**
     * POST /competency/ai-assessment/attempts/{id}/mark - own attempt only.
     *
     * Marks this attempt's written answers with the model, then recomputes the
     * totals and proposals.
     *
     * Separate from submit() so a slow model delays a score, never a
     * submission - see the note in submit(). Safe to call more than once: it
     * only ever looks at answers that are still unscored, so a retry after a
     * timeout marks the remainder rather than re-marking what is done.
     *
     * A model that cannot be reached leaves the answers UNSCORED, not zero.
     * They stay in the human review queue, which is where they would have been
     * anyway before any of this existed.
     */
    public function markMine(Request $request, int $id, AssessmentScoringService $marker)
    {
        $context = $this->competencyContext($request);
        if (!is_array($context)) {
            return $context;
        }

        $sid = (int) $context['sub_institute_id'];
        $me  = (int) $context['user_id'];

        // The attempt must be the CALLER'S OWN. This route takes an id, so
        // unlike mine()/submit() it needs the check spelled out.
        $attempt = DB::table('competency_assessment_attempt')
            ->where('id', $id)->where('sub_institute_id', $sid)->where('user_id', $me)->first();

        if (!$attempt) {
            return response()->json(['status' => 0, 'message' => 'Assessment attempt not found.'], 404);
        }
        if (!$attempt->submitted_at) {
            return response()->json([
                'status' => 0,
                'message' => 'This assessment has not been submitted yet, so there is nothing to mark.',
            ], 422);
        }

        $marking = $marker->scoreShortAnswers($id, $sid);
        $totals  = $marker->finalise($id, $sid);

        return response()->json([
            'status' => 1,
            'data'   => [
                'marked'          => $marking['scored'],
                'left_for_review' => $marking['failed'],
                'unavailable'     => $marking['reason'],
                'score'           => $totals['total'],
                'max_score'       => $totals['max'],
                'percent'         => $totals['percent'],
                'awaiting_review' => $totals['awaiting'],
                'proposals'       => $totals['proposals'],
            ],
            'message' => $marking['reason']
                ? 'Your written answers could not be marked automatically, so they are waiting for a person to review them. Nothing was scored as zero.'
                : sprintf('%d written answer(s) marked.', $marking['scored']),
            'proficiency_unchanged' => true,
        ]);
    }

    /**
     * The attempt row for this person on this test, created if absent.
     *
     * An open test has no assignment step, so the row appears the first time
     * they start it. An assigned test already has one, and this finds it.
     */
    private function attemptFor(int $testId, int $userId, int $tenantId): int
    {
        $existing = DB::table('competency_assessment_attempt')
            ->where('test_id', $testId)->where('user_id', $userId)->value('id');

        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table('competency_assessment_attempt')->insertGetId([
            'sub_institute_id' => $tenantId,
            'test_id'          => $testId,
            'user_id'          => $userId,
            'status'           => 'in_progress',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    /** Published, and either aimed at your job role, open to all, or assigned to you. */
    private function mayTake(int $testId, int $userId, int $tenantId): bool
    {
        $test = DB::table('competency_assessment_test')
            ->where('id', $testId)->where('sub_institute_id', $tenantId)
            ->where('status', 'published')->whereNull('deleted_at')->first();

        if (!$test) {
            return false;
        }
        if ($test->is_open) {
            return true;
        }

        $assigned = DB::table('competency_assessment_attempt')
            ->where('test_id', $testId)->where('user_id', $userId)->exists();
        if ($assigned) {
            return true;
        }

        $user = DB::table('tbluser')->where('id', $userId)->where('sub_institute_id', $tenantId)
            ->first(['id', 'jobtitle_id', 'allocated_standards']);

        return $user && (int) $this->resolveJobRoleId($user) === (int) $test->jobrole_id;
    }

    /** NULL when the test has no limit; never negative. */
    private function secondsRemaining(?object $attempt, ?object $test): ?int
    {
        if (!$attempt || !$test || empty($test->time_limit_minutes) || !$attempt->started_at) {
            return null;
        }

        $deadline = Carbon::parse($attempt->started_at)->addMinutes((int) $test->time_limit_minutes);

        return max(0, now()->diffInSeconds($deadline, false));
    }

    /** The prompt. Built from the items, never from a fixed list of subjects. */
    private function prompt(string $jobrole, $items, array $formats, int $perItem): string
    {
        $formatList = implode(' and ', $formats);
        $lines = $items->map(fn ($i) =>
            "- id={$i->id} | type={$i->kasba_type} | item={$i->item_label} | competency=" . ($i->competency_name ?? 'unnamed')
        )->implode("\n");

        return <<<TXT
        You are writing a workplace capability assessment for the job role "{$jobrole}".

        Write {$perItem} question(s) for EACH capability item listed below.
        Permitted formats: {$formatList}.

        RULES
        - Every question MUST carry the kasba_item_id of the item it assesses.
        - Use ONLY the ids listed. Do not invent an id.
        - mcq questions need "options" (3-5 strings) and "correct_option" (the exact option text).
        - short_answer questions need "model_answer" and no options.
        - Assess the item, not general knowledge.

        ITEMS
        {$lines}

        Return JSON: {"questions":[{"kasba_item_id":123,"format":"mcq","question_text":"...","options":["..."],"correct_option":"...","model_answer":null}]}
        TXT;
    }

    /**
     * KEEP ONLY QUESTIONS THAT NAME A REAL ITEM IN THIS JOB ROLE.
     *
     * This is where a plausible answer is separated from a true one. An LLM that
     * invents an id produces a question that reads perfectly and assesses
     * nothing; it is dropped here and counted, never stored.
     */
    private function acceptable($generated, $items, array $formats, string $jobroleName): array
    {
        // KEYED, not just flipped: the item itself is needed to write the
        // CITATION beside the question. A live id says what it points at now;
        // the citation says what was asked.
        $byId = $items->keyBy(fn ($i) => (int) $i->id);
        $valid = $items->pluck('id')->map('intval')->flip();
        $out = [];

        foreach ((array) ($generated['questions'] ?? []) as $q) {
            $id = (int) ($q['kasba_item_id'] ?? 0);
            $fmt = (string) ($q['format'] ?? '');
            $text = trim((string) ($q['question_text'] ?? ''));

            if (!$valid->has($id) || !in_array($fmt, $formats, true) || $text === '') {
                continue;
            }

            $options = is_array($q['options'] ?? null) ? array_values($q['options']) : null;
            $correct = isset($q['correct_option']) ? (string) $q['correct_option'] : null;

            // An MCQ whose correct_option is not among its own options is not a
            // question, it is a broken one. Dropped rather than stored unscorable.
            if ($fmt === 'mcq' && (!$options || $correct === null || !in_array($correct, $options, true))) {
                continue;
            }

            $src = $byId->get($id);

            $out[] = [
                'kasba_item_id' => $id,
                // THE CITATION, taken at generation time and never recomputed.
                'cited_item_label'           => $src->item_label ?? null,
                'cited_kasba_type'           => $src->kasba_type ?? null,
                'cited_competency_id'        => isset($src->competency_id) ? (int) $src->competency_id : null,
                'cited_competency_name'      => $src->competency_name ?? null,
                'cited_jobrole'              => $jobroleName,
                'cited_required_proficiency' => $src->required_proficiency ?? null,
                'format'        => $fmt,
                'question_text' => $text,
                'options'       => $fmt === 'mcq' ? $options : null,
                'correct_option' => $fmt === 'mcq' ? $correct : null,
                'model_answer'  => $fmt === 'short_answer' ? (string) ($q['model_answer'] ?? '') : null,
            ];
        }

        return $out;
    }
}
