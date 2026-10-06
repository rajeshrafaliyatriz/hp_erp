<?php

namespace App\Services\Lms;

use App\Services\Competency\AssessmentScoringService;
use App\Services\DeepSeekService;
use Illuminate\Support\Facades\DB;

/**
 * Write a course's quiz from the course's own content.
 *
 * ── WHAT DID NOT EXIST ──────────────────────────────────────────────────────
 *
 * Nothing generated questions for a course quiz. The Course Builder's step 3
 * could only take them one at a time by hand, and the three AI question
 * generators the codebase does have are all job-role shaped and cannot be
 * pointed at a course:
 *
 *   AiAssessmentController      KASBA items for a job role. No course_id
 *                               parameter; the controller never touches
 *                               sub_std_map, chapter_master or content_master.
 *   RecruitmentAssessmentGenerator  hiring, from s_jobrole_skills / _task.
 *   GenerateQuestionsController Gemini, job-role skills and tasks.
 *
 * So "generate a quiz for THIS course" had no data route at all, even though
 * the course knows its own modules, lessons and competencies.
 *
 * ── WHAT THE MODEL IS TOLD ──────────────────────────────────────────────────
 *
 * Two things, and their relationship:
 *
 *   1. THE CONTENT. Every module and, under it, every lesson's title and
 *      description. This is what the learner was actually taught, so it is what
 *      the questions must come from - a quiz asking about material the course
 *      never covered is unfair however good the question is.
 *
 *   2. THE CAPABILITIES. The KASBA items behind the competencies the course is
 *      mapped to (course_competency_map), each with its id. A question carries
 *      the id of the capability it tests, which is what lets a pass move that
 *      capability specifically - see QuizScoringService::perItemRatings.
 *
 * The prompt states explicitly that the content is the source of the questions
 * and the capabilities are the target, because a model given two lists without
 * being told how they relate will average them.
 *
 * ── THE THREE MEASURED PROMPT CONSTRAINTS ───────────────────────────────────
 *
 * All documented at length in AssessmentScoringService and obeyed here:
 *
 *   1. The system message ends on "a single valid JSON object." and NOTHING
 *      after it. Measured 2026-09-04: ending it "...and nothing else - no prose,
 *      no markdown fences." returned BLANK on every send.
 *   2. Data is passed as a JSON array, never as a `key=value` block. A readable
 *      key/value block makes the model CONTINUE the shape of the data instead of
 *      answering it, which returns pure whitespace in JSON mode. This was the
 *      measured root cause of the marking failure.
 *   3. The schema is declared BEFORE the data and the prompt ends on an
 *      imperative - never on a JSON literal, which is one more thing to
 *      continue rather than obey.
 *
 * AiAssessmentController::prompt() violates all three and predates the finding;
 * it is not touched here because it is a working, separately-verified path, but
 * this is the shape it should be moved to.
 */
class CourseQuizGenerator
{
    /** Longest MCQ option that fits the storage and stays exactly comparable. */
    public const MAX_OPTION_CHARS = 240;

    /** Fewest and most questions one call will write. */
    public const MIN_QUESTIONS = 1;
    public const MAX_QUESTIONS = 40;

    public function __construct(private readonly DeepSeekService $ai)
    {
    }

    /**
     * @return array{questions:array<int,array<string,mixed>>, dropped:int, context:array<string,mixed>}
     */
    public function generate(int $courseId, int $tenantId, int $count, array $formats): array
    {
        $context = $this->courseContext($courseId, $tenantId);

        if ($context['modules'] === []) {
            throw new \RuntimeException(
                'This course has no modules or lessons yet, so there is nothing to write questions about. '
                . 'Add content in step 2 first.'
            );
        }

        $raw = $this->ai->chatJson(
            [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $this->prompt($context, $count, $formats)],
            ],
            [
                'json' => true,
                // Lower than the 0.7 default: this is generation constrained by
                // supplied material, not open composition. The hiring generator
                // uses 0.4 for the same reason.
                'temperature' => 0.4,
                // The 4000 default truncates well before 40 questions with
                // options and explanations, and a truncated reply is billed in
                // full before failing.
                'max_tokens' => 8000,
            ]
        );

        $accepted = $this->acceptable($raw, $context, $formats);

        return [
            'questions' => $accepted,
            'dropped' => max(0, count((array) ($raw['questions'] ?? [])) - count($accepted)),
            'context' => $context,
        ];
    }

    /**
     * Ends on "a single valid JSON object." and nothing after it. See the class
     * header - this exact ending is measured, not stylistic.
     */
    private function systemPrompt(): string
    {
        return 'You write assessment questions for workplace training courses. '
            . 'You are given a course\'s own teaching material and the capabilities it is meant to build, '
            . 'and you write questions that test whether someone who studied that material can do those things. '
            . 'You reply with a single valid JSON object.';
    }

    /** Schema first, data as JSON, closing imperative. */
    private function prompt(array $context, int $count, array $formats): string
    {
        $formatList = implode(', ', $formats);

        $capabilities = json_encode(array_values($context['capabilities']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $content = json_encode(array_values($context['modules']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $hasCapabilities = $context['capabilities'] !== [];

        $capabilityRule = $hasCapabilities
            ? <<<TXT
            - Every question MUST carry the "kasba_item_id" of the capability it tests, taken from CAPABILITIES below.
            - Use ONLY the ids listed there. Never invent one.
            - Spread the questions across the capabilities rather than concentrating on one.
            TXT
            : '- Set "kasba_item_id" to null: this course is not mapped to any capability.';

        return <<<PROMPT
        You are writing the quiz for the course "{$context['course_title']}".

        OUTPUT
        Reply with one JSON object of this shape:
        {
          "questions": [
            {
              "kasba_item_id": 123,
              "chapter_id": 45,
              "format": "mcq",
              "question_text": "...",
              "options": ["...", "...", "...", "..."],
              "correct_option": "the exact text of the right option",
              "model_answer": null,
              "points": 1
            }
          ]
        }

        RULES
        - Write exactly {$count} question(s). Permitted formats: {$formatList}.
        - Base every question on the COURSE CONTENT below. Do not test anything the course does not cover.
        - Carry the "chapter_id" of the module the question comes from.
        {$capabilityRule}
        - mcq questions need 3 to 5 "options" and a "correct_option" repeating one option's text EXACTLY.
        - No option may exceed 240 characters.
        - short_answer questions need a "model_answer" describing what a good answer contains, and no options.
        - Ask about applying the material, not about recalling its wording.
        - "points" reflects how much work the question is. Whole numbers.

        COURSE CONTENT (JSON array of modules, each with its lessons)
        {$content}

        CAPABILITIES THIS COURSE BUILDS (JSON array)
        {$capabilities}

        The course content is where the questions come from. The capabilities are what each
        question must measure. Write the {$count} question(s) now and reply with that JSON object.
        PROMPT;
    }

    /**
     * The course's own modules, lessons and mapped capabilities.
     *
     * @return array{course_title:string, modules:array, capabilities:array}
     */
    public function courseContext(int $courseId, int $tenantId): array
    {
        $course = DB::table('sub_std_map')
            ->where('id', $courseId)->where('sub_institute_id', $tenantId)
            ->first(['id', 'display_name']);

        $chapters = DB::table('chapter_master')
            ->where('subject_id', $courseId)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->get(['id', 'chapter_name', 'chapter_desc']);

        $lessons = DB::table('content_master')
            ->where('subject_id', $courseId)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->get(['id', 'chapter_id', 'title', 'description', 'file_type'])
            ->groupBy('chapter_id');

        $modules = [];

        foreach ($chapters as $chapter) {
            $modules[] = [
                'chapter_id' => (int) $chapter->id,
                'module' => $chapter->chapter_name,
                'about' => $chapter->chapter_desc ?: null,
                'lessons' => ($lessons->get($chapter->id) ?? collect())->map(fn ($l) => [
                    'title' => $l->title,
                    // The slide bullets and speaker notes live here for an
                    // AI-generated course - this IS the teaching text.
                    'covers' => $l->description ? mb_substr($l->description, 0, 1200) : null,
                    'kind' => $l->file_type,
                ])->values()->all(),
            ];
        }

        // The KASBA items behind the competencies this course is mapped to.
        // Without a mapping there is nothing for a pass to move, which the
        // caller is told rather than left to discover.
        $capabilities = DB::table('competency_kasba_item as k')
            ->join('course_competency_map as m', function ($j) use ($courseId) {
                $j->on('m.competency_id', '=', 'k.competency_id')
                    ->where('m.course_id', '=', $courseId);
            })
            ->leftJoin('competency as c', 'c.id', '=', 'k.competency_id')
            ->where('k.sub_institute_id', $tenantId)
            ->where('m.sub_institute_id', $tenantId)
            ->get(['k.id', 'k.kasba_type', 'k.item_label', 'k.competency_id', 'c.name as competency_name'])
            ->map(fn ($k) => [
                'kasba_item_id' => (int) $k->id,
                'capability' => $k->item_label,
                'kind' => $k->kasba_type,
                'competency' => $k->competency_name,
            ])
            ->all();

        return [
            'course_title' => $course->display_name ?? 'this course',
            'modules' => $modules,
            'capabilities' => $capabilities,
        ];
    }

    /**
     * Keep only questions that can actually be stored and marked.
     *
     * Anything dropped is counted and reported rather than silently discarded,
     * so a caller can tell "the model wrote six and two were unusable" from
     * "the model wrote four".
     */
    private function acceptable($raw, array $context, array $formats): array
    {
        $validItems = collect($context['capabilities'])->pluck('kasba_item_id')->flip();
        $validChapters = collect($context['modules'])->pluck('chapter_id')->flip();
        $firstChapter = $context['modules'][0]['chapter_id'] ?? null;

        $out = [];

        foreach ((array) ($raw['questions'] ?? []) as $q) {
            $format = (string) ($q['format'] ?? '');
            $text = trim((string) ($q['question_text'] ?? ''));

            if (!in_array($format, $formats, true) || $text === '') {
                continue;
            }

            $options = is_array($q['options'] ?? null)
                ? array_values(array_filter(array_map(
                    fn ($o) => trim((string) $o),
                    $q['options']
                ), fn ($o) => $o !== ''))
                : [];

            $correct = isset($q['correct_option']) ? trim((string) $q['correct_option']) : null;

            if ($format === 'mcq') {
                // An MCQ whose key is not among its own options cannot be marked
                // by comparison, and storing it unscorable is worse than losing it.
                if (count($options) < 2 || $correct === null || !in_array($correct, $options, true)) {
                    continue;
                }

                // Over-length options are truncated silently by a non-strict
                // server, after which the learner's answer never matches the key
                // and the question is wrong for everyone. See
                // RecruitmentAssessmentGenerator, which has guarded this since
                // it was written.
                if (max(array_map('mb_strlen', $options)) > self::MAX_OPTION_CHARS) {
                    continue;
                }
            }

            $itemId = (int) ($q['kasba_item_id'] ?? 0);
            $chapterId = (int) ($q['chapter_id'] ?? 0);

            $out[] = [
                // A citation that names a capability this course does not build
                // is dropped to null rather than stored: a wrong citation would
                // move the wrong person's wrong rating.
                'kasba_item_id' => $validItems->has($itemId) ? $itemId : null,
                // An unknown module falls back to the first, so the question is
                // still attributable to something rather than orphaned.
                'chapter_id' => $validChapters->has($chapterId) ? $chapterId : $firstChapter,
                'format' => $format,
                'question_title' => $text,
                'options' => $format === 'mcq' ? $options : [],
                'correct_option' => $format === 'mcq' ? $correct : null,
                'model_answer' => $format === 'mcq' ? null : (
                    isset($q['model_answer']) ? trim((string) $q['model_answer']) : null
                ),
                'points' => max(1, min(100, (int) ($q['points'] ?? 1))),
            ];
        }

        return $out;
    }

    /** The bands a score maps onto, so the caller can explain the consequence. */
    public function bands(): array
    {
        return AssessmentScoringService::RATING_BANDS;
    }
}
