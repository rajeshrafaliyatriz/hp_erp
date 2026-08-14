<?php

namespace App\Http\Controllers\Api\Competency;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Competency\Concerns\ResolvesCompetencyContext;
use App\Services\DeepSeekService;
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

        // THE GUARD, APPLIED BEFORE THE LLM IS TOLD ANYTHING.
        $items = DB::table('jobrole_competency_map as m')
            ->join('competency_kasba_item as k', 'k.competency_id', '=', 'm.competency_id')
            ->leftJoin('competency as c', 'c.id', '=', 'm.competency_id')
            ->where('m.sub_institute_id', $sid)->where('k.sub_institute_id', $sid)
            ->where('m.jobrole_id', $jobroleId)
            ->get(['k.id', 'k.kasba_type', 'k.item_label', 'k.competency_id', 'c.name as competency_name', 'm.required_proficiency']);

        if ($items->isEmpty()) {
            return response()->json([
                'status'  => 0,
                'message' => 'This job role has no competencies mapped to it, so there is nothing to assess. Add them in Role Requirements first.',
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
        DB::transaction(function () use (&$testId, $sid, $jobroleId, $jobrole, $actor, $ai, $request, $rows) {
            $testId = DB::table('competency_assessment_test')->insertGetId([
                'sub_institute_id' => $sid,
                'jobrole_id'       => $jobroleId,
                'title'            => $request->input('title') ?: ('Capability assessment — ' . $jobrole->jobrole),
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
            ->first(['id', 'jobtitle_id']);

        if (!$user || !$user->jobtitle_id) {
            return response()->json([
                'status' => 1,
                'data'   => ['test' => null, 'questions' => []],
                'empty_is_expected' => true,
                'empty_reason' => 'You do not have a job role yet, so no assessment has been prepared for you.',
                'scope' => 'self',
            ]);
        }

        $test = DB::table('competency_assessment_test')
            ->where('sub_institute_id', $sid)->where('jobrole_id', $user->jobtitle_id)
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
    public function submit(Request $request)
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

        // Only questions on a PUBLISHED test for the caller's OWN job role are
        // accepted. A question id from anywhere else is dropped, not refused -
        // and the count of dropped ones is reported rather than hidden.
        $allowed = DB::table('competency_assessment_question as q')
            ->join('competency_assessment_test as t', 't.id', '=', 'q.test_id')
            ->join('tbluser as u', 'u.jobtitle_id', '=', 't.jobrole_id')
            ->where('u.id', $me)->where('u.sub_institute_id', $sid)
            ->where('q.sub_institute_id', $sid)->where('t.status', 'published')
            ->whereIn('q.id', $ids)
            ->get(['q.id', 'q.test_id', 'q.format', 'q.correct_option', 'q.max_score'])
            ->keyBy('id');

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

            DB::table('competency_assessment_response')->updateOrInsert(
                ['question_id' => $q->id, 'user_id' => $me],
                [
                    'sub_institute_id' => $sid,
                    'test_id'          => $q->test_id,
                    'answer_text'      => $a['answer_text'] ?? null,
                    'selected_option'  => $a['selected_option'] ?? null,
                    'score'            => $score,
                    'scored_by'        => $by,
                    'answered_at'      => now(),
                    'updated_at'       => now(),
                    'created_at'       => now(),
                ]
            );
            $written++;
        }

        return response()->json([
            'status' => 1,
            'data'   => [
                'answers_written' => $written,
                'auto_scored'     => $scored,
                'awaiting_review' => $written - $scored,
                'dropped'         => count($ids) - $written,
            ],
            // Stated so nobody reads a submission as a proficiency change.
            'proficiency_unchanged' => true,
            'message' => 'Answers recorded. Multiple-choice answers are scored automatically; written answers await review. Your proficiency is not changed by submitting.',
        ]);
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
