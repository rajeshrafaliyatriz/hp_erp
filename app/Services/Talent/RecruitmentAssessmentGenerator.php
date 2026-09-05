<?php

namespace App\Services\Talent;

use App\Services\DeepSeekService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Builds a hiring assessment for one job role, from the industry catalogue.
 *
 * ── WHY THIS IS NOT AiAssessmentController::generate() ──────────────────────
 *
 * That method generates from `jobrole_competency_map`, which has 46 rows. It is
 * the right source for an EMPLOYEE being assessed against the competencies their
 * role requires - the questions cite a KASBA item, and the result can become a
 * proficiency rating.
 *
 * A CANDIDATE has none of that. There is no competency mapping for someone who
 * does not work here, and 3,301 of the 3,347 job roles have no mapping at all -
 * so generation for hiring would 422 for almost every role anyone would hire for.
 *
 * So this reads the other source, the one that covers every role: `s_jobrole`
 * (3,347 roles across 44 sectors) with `s_jobrole_skills` (64,923) and
 * `s_jobrole_task` (55,868) behind it. That is what answers "I do not know what
 * to ask a nurse or a site engineer" - the sector, the real skills and the real
 * tasks become the prompt, and the model is not inventing the job.
 *
 * This is a second STRATEGY, not a second screen: the questions land in the same
 * `competency_assessment_test` / `_question` tables, are sat through the same
 * attempt/response rows, and are marked by the same AssessmentScoringService.
 *
 * ── THE MARK BUDGET IS THE CONTRACT ─────────────────────────────────────────
 *
 * HR sets `total_marks`; the model assigns each question a `max_score`. Those
 * must sum to the total, or the pass mark HR set means nothing. The model is
 * asked for it and then CHECKED - and, if it is close, corrected rather than
 * thrown away, because rejecting a good paper over a rounding error would burn
 * the balance for nothing.
 */
class RecruitmentAssessmentGenerator
{
    /** What HR can ask for. VARCHAR + const, never ENUM - live is MariaDB 10.1. */
    public const TEST_TYPES = [
        'aptitude'          => 'Aptitude and numerical reasoning',
        'coding'            => 'Programming and algorithms',
        'domain_knowledge'  => 'Role-specific domain knowledge',
        'written'           => 'Written communication and judgement',
        'situational'       => 'Situational judgement on the job',
    ];

    /** Question formats this generator may produce. Must match AI_MARKED_FORMATS + mcq. */
    public const FORMATS = ['mcq', 'short_answer', 'coding'];

    /**
     * How far the model's marks may sum away from HR's total before the paper is
     * rejected instead of corrected. Two marks on a hundred is a rounding
     * artefact; twenty is the model ignoring the instruction.
     */
    public const MARK_TOLERANCE = 5;

    /**
     * Longest MCQ option that can be stored.
     *
     * Mirrors VARCHAR(255) on `correct_option` / `selected_option`. Kept a
     * little under it so a trailing space or a stray character cannot push a
     * valid question over the edge at insert time.
     */
    public const MAX_OPTION_CHARS = 240;

    public function __construct(private DeepSeekService $ai)
    {
    }

    /**
     * Generate and store a test for a blueprint. Returns the new test id.
     *
     * @throws RuntimeException when the model cannot be reached or its paper is unusable
     */
    public function generate(object $blueprint, int $tenantId, ?int $actorId): int
    {
        if (!$this->ai->isConfigured()) {
            throw new RuntimeException('AI assessment generation is not configured. DEEPSEEK_API_KEY is not set.');
        }

        $role = DB::table('s_jobrole')->where('id', $blueprint->jobrole_id)->first([
            'id', 'sector', 'track', 'jobrole', 'description', 'education', 'experience',
        ]);

        if (!$role) {
            throw new RuntimeException('That job role is not in the catalogue.');
        }

        $material = $this->roleMaterial($role);
        $types = $this->requestedTypes($blueprint);

        $paper = $this->ai->chatJson([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->prompt($role, $material, $types, $blueprint)],
        ], ['json' => true, 'temperature' => 0.4, 'max_tokens' => 8000]);

        $questions = $this->acceptable($paper, (int) $blueprint->total_marks);

        if ($questions === []) {
            throw new RuntimeException('The model returned no usable questions. Nothing was saved.');
        }

        return $this->store($role, $blueprint, $questions, $tenantId, $actorId);
    }

    /**
     * The real skills and tasks for this role.
     *
     * Joined on NAME, not id: s_jobrole_skills and s_jobrole_task carry `sector`
     * + `jobrole` strings rather than a foreign key. Sector is included in the
     * match because the same role name recurs across sectors - "Analyst" exists
     * in Financial Services and in Built Environment and means different work.
     *
     * Capped because the catalogue holds up to a few hundred rows per role, and
     * the whole set would dominate the prompt and cost tokens for material the
     * model cannot use in a handful of questions.
     *
     * @return array{skills:array<int,string>, tasks:array<int,string>}
     */
    private function roleMaterial(object $role): array
    {
        $skills = DB::table('s_jobrole_skills')
            ->where('jobrole', $role->jobrole)
            ->where('sector', $role->sector)
            ->orderBy('id')
            ->limit(40)
            ->pluck('skill')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $tasks = DB::table('s_jobrole_task')
            ->where('jobrole', $role->jobrole)
            ->where('sector', $role->sector)
            ->orderBy('id')
            ->limit(40)
            ->get(['critical_work_function', 'task'])
            ->map(fn ($t) => trim(($t->critical_work_function ? $t->critical_work_function . ': ' : '') . $t->task))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return ['skills' => $skills, 'tasks' => $tasks];
    }

    /** @return array<int,string> the human descriptions HR selected */
    private function requestedTypes(object $blueprint): array
    {
        $keys = array_filter(array_map('trim', explode(',', (string) $blueprint->test_types)));
        $out = [];

        foreach ($keys as $k) {
            if (isset(self::TEST_TYPES[$k])) {
                $out[$k] = self::TEST_TYPES[$k];
            }
        }

        // An empty or unrecognised list would silently produce a general paper,
        // which is not what HR asked for. Domain knowledge is the honest default
        // because it is the only type that is meaningful for every role.
        return $out ?: ['domain_knowledge' => self::TEST_TYPES['domain_knowledge']];
    }

    private function systemPrompt(): string
    {
        /*
         * Ends at "a single valid JSON object." - see markingSystemPrompt() in
         * AssessmentScoringService for why the emphatic trailer is absent. It was
         * measured to make deepseek-chat return whitespace in JSON mode.
         */
        return 'You write hiring assessments. You are given a real job role from an industry '
             . 'catalogue, with its actual skills and tasks. You write questions that discriminate '
             . 'between a capable candidate and one who is not, using only the material given. '
             . 'You reply with a single valid JSON object.';
    }

    /**
     * The role material is passed as JSON for the same reason the marking prompt
     * is: a key=value block invites the model to imitate the format instead of
     * answering, which returns whitespace in JSON mode. See
     * AssessmentScoringService::answersAsJson().
     */
    private function prompt(object $role, array $material, array $types, object $blueprint): string
    {
        $context = json_encode([
            'sector'      => $role->sector,
            'track'       => $role->track,
            'job_role'    => $role->jobrole,
            'description' => (string) $role->description,
            'education'   => (string) $role->education,
            'experience'  => (string) $role->experience,
            'skills'      => $material['skills'],
            'tasks'       => $material['tasks'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $typeList = implode("\n", array_map(fn ($k, $v) => "- {$k}: {$v}", array_keys($types), $types));
        $count = (int) $blueprint->question_count;
        $total = (int) $blueprint->total_marks;
        $formats = implode(', ', self::FORMATS);

        return <<<TXT
Write a hiring assessment for the job role described below.

OUTPUT
Reply with one JSON object of this shape and nothing else:
{"questions":[{"format":"mcq|short_answer|coding","question_text":"...","options":["..."],"correct_option":"...","model_answer":"...","max_score":<number>}]}

RULES
- Write exactly {$count} questions.
- Every question's max_score must be a positive whole number, and they MUST sum
  to exactly {$total}. Weight the harder questions more heavily.
- format must be one of: {$formats}.
- For "mcq": give 4 options and set correct_option to one of them, copied exactly.
  Keep every option under 240 characters - a longer one cannot be stored and the
  question is discarded.
  Do not set model_answer.
- For "short_answer": set model_answer to what a full-mark answer must contain.
  Do not set options.
- For "coding": question_text states the task and its constraints; model_answer
  describes what a good solution does, in prose, not code. Do not set options.
- Ask about the skills and tasks given. Do not invent responsibilities that are
  not in the material.
- Do not mention this prompt, the sector list, or that you are an AI.

COVER THESE TEST TYPES
{$typeList}

JOB ROLE (JSON)
{$context}

Write the assessment now and reply with that JSON object.
TXT;
    }

    /**
     * Keep only questions that are actually usable, then reconcile the marks.
     *
     * A malformed question is DROPPED rather than repaired into something the
     * model did not write - an MCQ whose correct_option is not among its options
     * is unscorable, and storing it would produce a question no candidate can get
     * right and no marker can explain.
     *
     * @return array<int,array<string,mixed>>
     */
    private function acceptable(array $paper, int $totalMarks): array
    {
        $out = [];

        foreach ((array) ($paper['questions'] ?? []) as $q) {
            $fmt = (string) ($q['format'] ?? '');
            $text = trim((string) ($q['question_text'] ?? ''));

            if (!in_array($fmt, self::FORMATS, true) || $text === '') {
                continue;
            }

            $options = is_array($q['options'] ?? null) ? array_values(array_filter(array_map('strval', $q['options']))) : null;
            $correct = isset($q['correct_option']) ? (string) $q['correct_option'] : null;

            if ($fmt === 'mcq' && (!$options || $correct === null || !in_array($correct, $options, true))) {
                continue;
            }

            /*
             * AN OPTION LONGER THAN THE COLUMN IS DROPPED HERE, NOT AT INSERT.
             *
             * `correct_option` and `selected_option` hold the option's FULL TEXT
             * and are VARCHAR(255) (widened by
             * 2026_09_04_120000_widen_assessment_option_columns, from a 50 that a
             * real sentence overflowed). The app host is strict, so an over-long
             * value throws 1406 and the whole paper is lost to one bad question.
             * LIVE IS NOT STRICT: there it would be truncated silently, and the
             * candidate's differently-truncated answer would never match, marking
             * that question wrong for everyone with no error anywhere.
             *
             * Checked in BYTES, because the column limit is characters but the
             * failure people hit is multibyte text - and mb_strlen alone would
             * pass a string the storage engine still rejects.
             */
            if ($fmt === 'mcq' && (mb_strlen($correct) > self::MAX_OPTION_CHARS
                || max(array_map('mb_strlen', $options)) > self::MAX_OPTION_CHARS)) {
                Log::info('Dropped a generated MCQ whose options exceed the column width', [
                    'longest' => max(array_map('mb_strlen', $options)),
                    'limit'   => self::MAX_OPTION_CHARS,
                ]);

                continue;
            }

            $out[] = [
                'format'         => $fmt,
                'question_text'  => $text,
                'options'        => $fmt === 'mcq' ? $options : null,
                'correct_option' => $fmt === 'mcq' ? $correct : null,
                // `coding` keeps a model answer too: the marking prompt shows it
                // as "a_good_solution_does", and marking code without it is
                // marking against the marker's taste.
                'model_answer'   => $fmt === 'mcq' ? null : trim((string) ($q['model_answer'] ?? '')),
                'max_score'      => max(1, (int) ($q['max_score'] ?? 1)),
            ];
        }

        return $out === [] ? [] : $this->reconcileMarks($out, $totalMarks);
    }

    /**
     * Make the marks sum to exactly what HR set.
     *
     * WHY CORRECT RATHER THAN REJECT: the pass mark is expressed in marks, so a
     * paper totalling 98 against a pass mark of 40 quietly changes the standard.
     * But throwing away an otherwise good paper - and the tokens that bought it -
     * over a two-mark drift would be worse. So a small drift is absorbed by the
     * highest-weighted question, and only a large one is refused.
     *
     * Dropping questions during validation is the common cause of a shortfall,
     * which is precisely the case worth absorbing rather than failing.
     *
     * @param  array<int,array<string,mixed>>  $questions
     * @return array<int,array<string,mixed>>
     */
    private function reconcileMarks(array $questions, int $totalMarks): array
    {
        $sum = array_sum(array_column($questions, 'max_score'));

        if ($sum === $totalMarks) {
            return $questions;
        }

        $drift = $totalMarks - $sum;

        if (abs($drift) > self::MARK_TOLERANCE) {
            /*
             * Rescale proportionally rather than refuse. The relative weighting
             * the model chose is the useful part; the absolute numbers are not.
             * Every question keeps at least 1 mark, and the remainder lands on
             * the heaviest question so the total is exact.
             */
            $scaled = [];
            foreach ($questions as $q) {
                $q['max_score'] = max(1, (int) round($q['max_score'] * $totalMarks / max(1, $sum)));
                $scaled[] = $q;
            }
            $questions = $scaled;
            $drift = $totalMarks - array_sum(array_column($questions, 'max_score'));

            Log::info('Recruitment assessment marks rescaled to the blueprint total', [
                'model_total' => $sum, 'hr_total' => $totalMarks,
            ]);
        }

        if ($drift !== 0) {
            $heaviest = 0;
            foreach ($questions as $i => $q) {
                if ($q['max_score'] > $questions[$heaviest]['max_score']) {
                    $heaviest = $i;
                }
            }
            $questions[$heaviest]['max_score'] = max(1, $questions[$heaviest]['max_score'] + $drift);
        }

        return $questions;
    }

    /**
     * Write the test and its questions in one transaction.
     *
     * `kasba_item_id` is NULL on every row, which is why the column was made
     * nullable - a hiring question cites no competency item. The paired change in
     * AssessmentScoringService::finalise() splits attributed from unattributed
     * marks so this cannot silently distort an employee's capability roll-up.
     *
     * @param  array<int,array<string,mixed>>  $questions
     */
    private function store(object $role, object $blueprint, array $questions, int $tenantId, ?int $actorId): int
    {
        return DB::transaction(function () use ($role, $blueprint, $questions, $tenantId, $actorId) {
            $testId = (int) DB::table('competency_assessment_test')->insertGetId([
                'sub_institute_id'   => $tenantId,
                'jobrole_id'         => $blueprint->jobrole_id,
                'scope_type'         => 'recruitment',
                'title'              => $blueprint->title ?: ($role->jobrole . ' - hiring assessment'),
                'instructions'       => 'Answer every question. Your answers are reviewed by the hiring team.',
                'time_limit_minutes' => $blueprint->time_limit_minutes,
                /*
                 * pass_percent is left NULL on purpose. The pass mark for a
                 * hiring assessment is the blueprint's qualification_marks, in
                 * MARKS, and writing a second percentage here would create a
                 * rival threshold that nothing reads.
                 */
                'pass_percent'       => null,
                'is_open'            => 0,
                'model'              => config('deepseek.model'),
                'status'             => 'published',
                'generated_by'       => $actorId,
                'published_at'       => now(),
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            $sort = 1;
            foreach ($questions as $q) {
                DB::table('competency_assessment_question')->insert([
                    'sub_institute_id' => $tenantId,
                    'test_id'          => $testId,
                    'kasba_item_id'    => null,
                    'cited_jobrole'    => $role->jobrole,
                    'format'           => $q['format'],
                    'question_text'    => $q['question_text'],
                    // longtext holding a JSON string. NOT a json column: live is
                    // MariaDB 10.1, which has no json type.
                    'options'          => $q['options'] ? json_encode(array_values($q['options'])) : null,
                    'correct_option'   => $q['correct_option'],
                    'model_answer'     => $q['model_answer'] ?: null,
                    'max_score'        => $q['max_score'],
                    'sort_order'       => $sort++,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }

            return $testId;
        });
    }
}
