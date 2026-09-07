<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\AiCourseController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seed the two AI-generated artefacts, WITHOUT calling the AI.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * Both AI features refuse to run right now, and correctly so:
 *
 *   Refusing to call DeepSeek: the balance is USD -0.24, at or below the
 *   USD 1.00 floor set by DEEPSEEK_MIN_BALANCE_USD.
 *
 * The guard is doing its job and lowering the floor would just spend money
 * silently. But a demo should not be blocked on a billing state, so this writes
 * what the model WOULD have produced, in exactly the shape each generator
 * writes it.
 *
 * ── IT USES THE REAL CODE PATHS ─────────────────────────────────────────────
 *
 * The course is published through AiCourseController::publish() rather than by
 * inserting rows, so the demo exercises the real module/lesson/deck builder -
 * the half that had never once run (0 of 61 outlines had ever produced a
 * course). Only the model call itself is substituted.
 *
 * The assessment matches the insert in AiAssessmentController::generate()
 * field for field, including `model`, so it is indistinguishable from a
 * generated one except that a person wrote the questions.
 *
 * Idempotent and dry-run by default: this writes to a live tenant.
 *
 *   php artisan lms:seed-ai-demo --database=live --tenant=6
 *   php artisan lms:seed-ai-demo --database=live --tenant=6 --execute
 */
class LmsSeedAiDemo extends Command
{
    protected $signature = 'lms:seed-ai-demo
        {--database=mysql : Connection to seed}
        {--tenant=6 : sub_institute_id}
        {--learner= : tbluser.id to build for, so THAT person can sit the assessment}
        {--execute : Actually write. Without it, nothing is changed}';

    protected $description = 'Seed an AI-style course and capability assessment without calling the AI';

    /** Marks everything this command creates. */
    private const TAG = 'AI demo';

    /**
     * One question shape per KASBA type.
     *
     * `competency_kasba_item.kasba_type` is one of skill / knowledge / ability /
     * attitude / behaviour, and those are not five words for the same thing:
     * knowledge is recalled, a skill is performed, an ability is judged, an
     * attitude is held, a behaviour is chosen repeatedly. Each therefore fails in
     * its own way, and its distractors are the specific things people mistake for
     * it - which is what makes a wrong answer diagnostic instead of filler.
     *
     * `correct` is placed at a rotating letter per question; `wrong` fills the
     * rest in order. `written` is the short-answer stem, with {label} and {role}
     * substituted.
     */
    private const MCQ_BANK = [
        'knowledge' => [
            'correct' => 'They can say why it works the way it does, and what follows when a premise changes.',
            'wrong' => [
                'They can recite the standard definition without hesitating.',
                'They know which document to look it up in.',
                'They attended the session where it was covered.',
            ],
            'written' => 'Explain {label} in your own words to someone new to the team, as {role} would. '
                . 'What would you want them to understand first, and why that first?',
        ],
        'skill' => [
            'correct' => 'They do it reliably under time pressure, and their work does not come back for rework.',
            'wrong' => [
                'They can describe each step of it from memory.',
                'They have used the relevant tools at least once.',
                'They ask someone to check it before every submission.',
            ],
            'written' => 'Describe a piece of work where {label} was the difference between a good and a poor '
                . 'result, as {role}. What did you actually do?',
        ],
        'ability' => [
            'correct' => 'They reach a workable answer in a situation the procedure does not cover.',
            'wrong' => [
                'They follow the documented procedure closely every time.',
                'They escalate anything that is not covered by the procedure.',
                'They can list the factors that ought to be considered.',
            ],
            'written' => 'Describe a situation where {label} mattered because the usual approach did not fit, '
                . 'as {role}. How did you decide what to do?',
        ],
        'attitude' => [
            'correct' => 'They hold to it when it costs them something - time, credit, or being right.',
            'wrong' => [
                'They agree with it when it is raised in a meeting.',
                'They can explain why the organisation values it.',
                'They demonstrate it when their work is being reviewed.',
            ],
            'written' => 'Describe a time when {label} was inconvenient for you as {role}, and you acted on it '
                . 'anyway. What did it cost, and why was it worth it?',
        ],
        'behaviour' => [
            'correct' => 'Colleagues can predict they will do it, because they do it every time and not only when watched.',
            'wrong' => [
                'They do it whenever they are reminded to.',
                'They did it consistently during their probation period.',
                'They can point to the policy that requires it.',
            ],
            'written' => 'Give an example of {label} in your own routine as {role}. How would a colleague know '
                . 'you do it consistently rather than occasionally?',
        ],
    ];

    public function handle(): int
    {
        $connection = (string) $this->option('database');
        $tenant = (int) $this->option('tenant');
        $execute = (bool) $this->option('execute');

        // The controllers resolve DB::connection() by default, so the whole
        // command runs against whichever connection is asked for.
        DB::setDefaultConnection($connection);
        $db = DB::connection($connection);

        $this->info("Seeding AI demo data on '{$connection}', tenant {$tenant}");
        $this->line($execute ? '  MODE: writing' : '  MODE: dry run (pass --execute to write)');
        $this->newLine();

        $balance = $this->reportBalance();
        $this->newLine();

        $courseId = $this->seedAiCourse($db, $tenant, $execute);
        $this->newLine();
        $testId = $this->seedAssessment($db, $tenant, $execute);

        $this->newLine();

        if (!$execute) {
            $this->warn('Nothing was written. Re-run with --execute.');

            return self::SUCCESS;
        }

        $this->info('Done.');
        $this->line('  AI course:   ' . ($courseId ? "#{$courseId}" : 'already present'));
        $this->line('  Assessment:  ' . ($testId ? "#{$testId}" : 'already present'));
        $this->line('  Neither needed the DeepSeek account' . ($balance !== null ? " (balance USD {$balance})" : ''));

        return self::SUCCESS;
    }

    /**
     * The job role both artefacts are built for.
     *
     * ── WHY NOT JUST THE FIRST MAPPED ROLE ──────────────────────────────────
     *
     * A first cut took the first row of jobrole_competency_map. On tenant 6 that
     * is "Artificial Intelligence / Machine Learning Engineer" - a real role with
     * real competencies, and the assessment published cleanly. But mine() scopes
     * a learner to their OWN job role, resolved from tbluser.jobtitle_id, and the
     * demo learner is a Full Stack Developer. The endpoint answered, correctly,
     * "No assessment has been published for your job role yet."
     *
     * So the role is now chosen by the thing that actually decides whether the
     * demo works: how many employees hold it. Ties break on how many competencies
     * it has, since a role with more mapped capabilities makes a richer test.
     * Course and assessment share the role, so the demo tells one story rather
     * than two unrelated ones.
     *
     * `--learner` makes this exact: an assessment is only sittable by someone
     * whose own jobtitle_id matches it, so when a demo will be given as a
     * particular person, name them and their role is used.
     *
     * @return object|null {jobrole_id, jobrole, competency_id, holders}
     */
    private function targetRole($db, int $tenant)
    {
        $learnerId = (int) $this->option('learner');

        if ($learnerId > 0) {
            $rows = $db->select('
                SELECT  u.jobtitle_id            AS jobrole_id,
                        r.jobrole                AS jobrole,
                        (SELECT COUNT(*) FROM tbluser h
                          WHERE h.jobtitle_id = u.jobtitle_id
                            AND h.sub_institute_id = ?) AS holders
                FROM tbluser u
                JOIN s_user_jobrole r         ON r.id = u.jobtitle_id
                JOIN jobrole_competency_map m ON m.jobrole_id = u.jobtitle_id
                                             AND m.sub_institute_id = ?
                WHERE u.id = ? AND u.sub_institute_id = ?
                LIMIT 1', [$tenant, $tenant, $learnerId, $tenant]);

            if (!isset($rows[0])) {
                $this->warn("  Learner #{$learnerId} has no job role with competencies mapped"
                    . ' - falling back to the most widely held role.');
            }
        } else {
            $rows = [];
        }

        $rows = $rows ?: $db->select('
            SELECT  u.jobtitle_id            AS jobrole_id,
                    r.jobrole                AS jobrole,
                    COUNT(DISTINCT u.id)     AS holders,
                    COUNT(DISTINCT m.competency_id) AS competencies
            FROM tbluser u
            JOIN s_user_jobrole r        ON r.id = u.jobtitle_id
            JOIN jobrole_competency_map m ON m.jobrole_id = u.jobtitle_id
                                         AND m.sub_institute_id = ?
            WHERE u.sub_institute_id = ? AND u.jobtitle_id > 0
            GROUP BY u.jobtitle_id, r.jobrole
            ORDER BY holders DESC, competencies DESC
            LIMIT 1', [$tenant, $tenant]);

        $role = $rows[0] ?? null;

        if (!$role) {
            return null;
        }

        // The competency with the most KASBA items behind it - those items are
        // what the questions cite, so the richest one makes the fullest test.
        $competency = $db->select('
            SELECT m.competency_id, m.required_proficiency, COUNT(k.id) AS items
            FROM jobrole_competency_map m
            LEFT JOIN competency_kasba_item k ON k.competency_id = m.competency_id
                                             AND k.sub_institute_id = ?
            WHERE m.jobrole_id = ? AND m.sub_institute_id = ?
            GROUP BY m.competency_id, m.required_proficiency
            ORDER BY items DESC
            LIMIT 1', [$tenant, $role->jobrole_id, $tenant]);

        if (!isset($competency[0])) {
            return null;
        }

        $role->competency_id = (int) $competency[0]->competency_id;
        $role->required_proficiency = $competency[0]->required_proficiency;

        return $role;
    }

    /** Say plainly why this command is needed, from the live account state. */
    private function reportBalance(): ?string
    {
        try {
            $balance = app(\App\Services\DeepSeekService::class)->balance();

            $total = $balance['total'] ?? null;
            $this->line('  DeepSeek balance: USD ' . var_export($total, true)
                . ' (floor USD ' . config('deepseek.min_balance_usd') . ')');

            return $total === null ? null : (string) $total;
        } catch (\Throwable $e) {
            $this->line('  DeepSeek balance: unavailable');

            return null;
        }
    }

    /**
     * A published AI course, built through the real publish() path.
     *
     * The outline is stored in the same JSON shape generateOutline() produces,
     * so buildCourseFromOutline() reads it exactly as it would a generated one:
     * a title, a summary, learning objectives, and slides carrying bullets and
     * speaker notes.
     */
    private function seedAiCourse($db, int $tenant, bool $execute): ?int
    {
        $existing = $db->table('ai_course_outlines')
            ->where('sub_institute_id', $tenant)
            ->whereNotNull('course_id')
            ->where('course_type', self::TAG)
            ->value('course_id');

        if ($existing) {
            $this->line("  AI course         already published as #{$existing}");

            return null;
        }

        $department = $db->table('hrms_departments')
            ->where('sub_institute_id', $tenant)->whereNull('deleted_at')
            ->first(['id', 'department']);

        if (!$department) {
            $this->warn('  AI course         SKIPPED - this tenant has no departments');

            return null;
        }

        // The role real employees hold - see targetRole(). The course and the
        // assessment are built for the same one, so they read as one story.
        $role = $this->targetRole($db, $tenant);
        $outline = $this->courseOutline($role->jobrole ?? 'Team member');

        $this->line('  AI course         CREATE "' . $outline['title'] . '"');
        $this->line('                    ' . count($outline['slides']) . ' slides -> 1 module + '
            . (count($outline['slides']) + 1) . ' lessons (including the deck)');
        $this->line('                    department: ' . $department->department
            . ($role ? ', role: ' . $role->jobrole . ' (' . $role->holders . ' employee(s) hold it)' : ''));

        if (!$execute) {
            return null;
        }

        $outlineId = $db->table('ai_course_outlines')->insertGetId([
            'course_type' => self::TAG,
            'input_fields' => json_encode([
                'industry' => 'Information Technology',
                'department_ids' => [$department->id],
                'jobrole_ids' => $role ? [(int) $role->jobrole_id] : [],
                'scope_mode' => 'competency',
                'competency_ids' => $role ? [$role->competency_id] : [],
            ]),
            /*
             * NOT NULL with no default on both databases. Omitting it worked on
             * live only because that server does not run STRICT_TRANS_TABLES -
             * it silently stored an empty string, which json_decode() then reads
             * back as null when the outline list renders. Dev, being strict,
             * refused the insert outright and is what caught this.
             */
            'configure_fields' => json_encode([
                'scope' => 'competency',
                'AI model' => 'deepseek-chat',
                'seeded' => self::TAG,
            ]),
            'outline' => json_encode($outline),
            'presentation_platform' => 'gamma',
            'ai_model' => 'deepseek-chat',
            'slide_count' => count($outline['slides']),
            /*
             * A real Gamma export URL is not available without the account, so
             * this points at a public sample deck. The POINT of the demo is
             * that the deck becomes a pptx lesson the player opens in place -
             * which it does with any reachable pptx.
             */
            'export_url' => 'https://scholar.harvard.edu/files/torman_personal/files/samplepptx.pptx',
            'status' => 'completed',
            'sub_institute_id' => $tenant,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * Published through the CONTROLLER, not by inserting rows.
         *
         * This is the path that had never produced a course, so seeding around
         * it would demo something the product cannot actually do.
         */
        $admin = $db->table('tbluser')
            ->where('sub_institute_id', $tenant)
            ->whereIn('user_profile_id', function ($q) use ($tenant) {
                $q->select('id')->from('tbluserprofilemaster')
                  ->where('sub_institute_id', $tenant)
                  ->where('role_key', 'administrator');
            })
            ->value('id');

        $user = \App\Models\auth\tbluserModel::find($admin);
        $token = $user->createToken('seed-ai-demo')->plainTextToken;

        $request = \Illuminate\Http\Request::create('/x', 'POST', [
            'token' => $token,
            'display_name' => $outline['title'],
            'standard_id' => $department->id,
            'subject_category' => 'Information Technology',
            'subject_type' => 'Self-paced course',
            'jobrole' => $role->jobrole ?? null,
            'department_ids' => [$department->id],
            'jobrole_ids' => $role ? [(int) $role->jobrole_id] : [],
            'competency_ids' => $role ? [$role->competency_id] : [],
            'status' => 1,
        ]);
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $response = app(AiCourseController::class)->publish($request, $outlineId);
        $body = json_decode($response->getContent(), true);

        $user->tokens()->where('name', 'seed-ai-demo')->delete();

        if (($body['status'] ?? false) !== true) {
            $this->error('  publish failed: ' . ($body['message'] ?? 'unknown'));

            return null;
        }

        $this->line('                    -> ' . $body['message']);

        return (int) ($body['data']['course_id'] ?? 0);
    }

    /**
     * A capability assessment, in the exact shape generate() writes.
     *
     * Questions are drawn from the job role's real KASBA items so they cite
     * genuine capabilities, which is what makes the review screen meaningful:
     * every question names the item it is testing.
     */
    private function seedAssessment($db, int $tenant, bool $execute): ?int
    {
        $role = $this->targetRole($db, $tenant);

        if (!$role) {
            $this->warn('  Assessment        SKIPPED - no job role that employees hold has competencies mapped');

            return null;
        }

        $competency = $db->table('competency')->where('id', $role->competency_id)->first(['id', 'name']);

        $existing = $db->table('competency_assessment_test')
            ->where('sub_institute_id', $tenant)
            ->where('jobrole_id', $role->jobrole_id)
            ->where('competency_id', $role->competency_id)
            ->whereNull('deleted_at')
            ->value('id');

        if ($existing) {
            $this->line("  Assessment        already present as #{$existing}");

            return null;
        }

        $items = $db->table('competency_kasba_item')
            ->where('sub_institute_id', $tenant)
            ->where('competency_id', $role->competency_id)
            ->get(['id', 'kasba_type', 'item_label']);

        if ($items->isEmpty()) {
            $this->warn('  Assessment        SKIPPED - that competency has no KASBA items to cite');

            return null;
        }

        $questions = $this->questionsFor($items, $competency->name ?? '', $role->jobrole ?? '');

        $this->line('  Assessment        CREATE "Capability assessment — ' . ($competency->name ?? '') . '"');
        $this->line('                    role: ' . $role->jobrole . ' (' . $role->holders . ' employee(s) hold it)');
        $this->line('                    ' . count($questions) . ' questions from '
            . $items->count() . ' real KASBA item(s)');

        if (!$execute) {
            return null;
        }

        $actor = $db->table('tbluser')->where('sub_institute_id', $tenant)->value('id');

        $testId = $db->table('competency_assessment_test')->insertGetId([
            'sub_institute_id' => $tenant,
            'jobrole_id' => $role->jobrole_id,
            'scope_type' => 'competency',
            'competency_id' => $role->competency_id,
            'kasba_item_id' => null,
            'title' => 'Capability assessment — ' . ($competency->name ?? 'competency'),
            'time_limit_minutes' => 20,
            'pass_percent' => 60,
            'is_open' => 0,
            // The model that WOULD have written these, so the provenance column
            // is not silently blank.
            'model' => 'deepseek-chat',
            'status' => 'draft',
            'generated_by' => $actor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($questions as $i => $q) {
            $db->table('competency_assessment_question')->insert([
                'sub_institute_id' => $tenant,
                'test_id' => $testId,
                'kasba_item_id' => $q['kasba_item_id'],
                'cited_item_label' => $q['cited_item_label'],
                'cited_kasba_type' => $q['cited_kasba_type'],
                'cited_competency_id' => $role->competency_id,
                'cited_competency_name' => $competency->name ?? null,
                'cited_jobrole' => $role->jobrole,
                'cited_required_proficiency' => $role->required_proficiency,
                'format' => $q['format'],
                'question_text' => $q['question_text'],
                'options' => $q['options'] !== null ? json_encode($q['options']) : null,
                'correct_option' => $q['correct_option'],
                'model_answer' => $q['model_answer'],
                'max_score' => $q['max_score'],
                'sort_order' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        /*
         * PUBLISHED, THROUGH THE REAL ENDPOINT.
         *
         * The generator leaves a test in `draft` for a human to review, which is
         * right - but `mine()` filters on `status = 'published'`, so a draft is
         * invisible to every learner and the demo would show an assessment
         * nobody can sit.
         *
         * publish() is called rather than an UPDATE so the demo also exercises
         * the supersede rule: publishing a test for a job role retires any
         * earlier published one, leaving recorded answers intact.
         */
        $this->publishAssessment($tenant, $testId, $actor);

        return $testId;
    }

    /** Publish the seeded test via AiAssessmentController, reporting what it said. */
    private function publishAssessment(int $tenant, int $testId, $actor): void
    {
        $user = \App\Models\auth\tbluserModel::find(
            DB::table('tbluser')
                ->where('sub_institute_id', $tenant)
                ->whereIn('user_profile_id', function ($q) use ($tenant) {
                    $q->select('id')->from('tbluserprofilemaster')
                      ->where('sub_institute_id', $tenant)
                      ->where('role_key', 'administrator');
                })
                ->value('id')
        );

        if (!$user) {
            $this->warn('                    could not publish: no administrator on this tenant');

            return;
        }

        $token = $user->createToken('seed-ai-demo')->plainTextToken;

        $request = \Illuminate\Http\Request::create('/x', 'POST', [
            'token' => $token,
            'test_id' => $testId,
            'publish' => true,
        ]);
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $response = app(\App\Http\Controllers\Api\Competency\AiAssessmentController::class)->publish($request);
        $body = json_decode($response->getContent(), true);

        $user->tokens()->where('name', 'seed-ai-demo')->delete();

        $this->line('                    -> ' . ($body['message'] ?? 'publish failed'));
    }

    /** The course outline, in generateOutline()'s response shape. */
    private function courseOutline(string $jobrole): array
    {
        $slides = [
            ['Why this course exists', [
                'Understand the capabilities this role is expected to demonstrate.',
                'See how each module maps to a competency your organisation has defined.',
                'Know what "complete" means: every lesson, then the quiz.',
            ], 'Open by connecting the course to the learner\'s own role rather than to a syllabus.'],
            ['How the learning is structured', [
                'Each lesson covers one capability and takes under fifteen minutes.',
                'Material is self-paced; return to any lesson at any time.',
                'Progress is recorded as you open each lesson.',
            ], 'Set the expectation that this is short, revisitable and tracked.'],
            ['Core concepts', [
                'The vocabulary the rest of the course assumes.',
                'Where these ideas show up in day-to-day work.',
                'The two mistakes people most often make early on.',
            ], 'Name the misconceptions explicitly; correcting them later costs more.'],
            ['Applying it in practice', [
                'Work through a realistic scenario end to end.',
                'Identify the decision points and what informs each one.',
                'Compare your approach with the worked example.',
            ], 'The scenario should resemble something the learner has actually faced.'],
            ['Assessment and what happens next', [
                'The quiz unlocks once every lesson is complete.',
                'Passing it updates your competency rating and issues your certificate.',
                'Your manager sees the result on your record.',
            ], 'Be explicit that the result is recorded - it changes how seriously it is taken.'],
        ];

        return [
            'title' => 'Foundations for ' . $jobrole,
            'summary' => 'A short, self-paced course covering the core capabilities this role is '
                . 'expected to demonstrate, ending in an assessment that updates the learner\'s '
                . 'competency record.',
            'learning_objectives' => [
                'Explain the core concepts this role depends on.',
                'Apply them to a realistic scenario and justify the decisions taken.',
                'Recognise the common early mistakes and avoid them.',
                'Meet the proficiency level the role requires.',
            ],
            'slides' => array_map(fn ($s, $i) => [
                'slide_number' => $i + 1,
                'title' => $s[0],
                'bullets' => $s[1],
                'speaker_notes' => $s[2],
            ], $slides, array_keys($slides)),
        ];
    }

    /**
     * Questions that cite REAL capabilities.
     *
     * Every question names the KASBA item it tests, which is what the review
     * and rating-proposal screens read - a question citing nothing produces a
     * result that cannot move any rating.
     *
     * @param  \Illuminate\Support\Collection  $items
     */
    private function questionsFor($items, string $competency, string $jobrole): array
    {
        $mcq = [];
        $written = [];

        /*
         * Two questions per capability, not one.
         *
         * A competency here carries only three or four KASBA items, so one
         * question each produces a three-question test - too short to separate a
         * pass from a lucky guess at a 60% pass mark, where two right out of
         * three passes. Each item gets a recall question and an applied one,
         * which is also how the generator's own prompt is written: recognise it,
         * then show you can do it.
         *
         * MCQs are listed before the written answers so a learner meets the
         * quick ones first and the marker has all the auto-scored questions
         * together.
         */
        /*
         * The capability is QUOTED rather than spliced into the sentence.
         *
         * KASBA labels are noun phrases written by whoever defined the
         * competency - "Building scalable ETL and feature pipelines" - so
         * dropping one into "someone has ..." produces "someone has building
         * scalable ETL pipelines". Quoting it reads correctly whatever shape the
         * label takes, and it puts the cited capability in front of the learner,
         * which is the point of citing it.
         */
        foreach ($items->take(4) as $index => $item) {
            $label = $item->item_label;
            $role = $this->withArticle($jobrole);
            $type = strtolower(trim((string) $item->kasba_type));
            $bank = self::MCQ_BANK[$type] ?? self::MCQ_BANK['skill'];

            /*
             * Distractors vary by KASBA type, and the correct letter moves.
             *
             * A first cut gave every MCQ the same four options with the answer
             * always at A - so a learner who worked out the first one had the
             * other two for free, and the auto-scored half of the test measured
             * nothing. The five KASBA types fail in genuinely different ways
             * (knowing a thing, doing it, judging when to, choosing to), so each
             * gets distractors drawn from its own confusions.
             */
            $letters = ['A', 'B', 'C', 'D'];
            $correctAt = $index % 4;
            $wrong = $bank['wrong'];
            $options = [];

            foreach ($letters as $position => $letter) {
                $options[$letter] = $position === $correctAt
                    ? $bank['correct']
                    : array_shift($wrong);
            }

            $mcq[] = [
                'kasba_item_id' => $item->id,
                'cited_item_label' => $item->item_label,
                'cited_kasba_type' => $item->kasba_type,
                'format' => 'mcq',
                'question_text' => 'In the context of ' . $competency . ', which of these best shows '
                    . 'that someone is genuinely capable at "' . $label . '"?',
                'options' => $options,
                'correct_option' => $letters[$correctAt],
                'model_answer' => null,
                'max_score' => 2,
            ];

            $written[] = [
                'kasba_item_id' => $item->id,
                'cited_item_label' => $item->item_label,
                'cited_kasba_type' => $item->kasba_type,
                'format' => 'short_answer',
                'question_text' => str_replace(
                    ['{label}', '{role}'],
                    ['"' . $label . '"', $role],
                    $bank['written']
                ),
                'options' => null,
                'correct_option' => null,
                'model_answer' => 'A strong answer names a specific situation, the action taken, and '
                    . 'the reasoning behind it. An answer that only restates what "' . $label
                    . '" means does not demonstrate it and should not score full marks.',
                'max_score' => 3,
            ];
        }

        return array_merge($mcq, $written);
    }

    /**
     * "an Artificial Intelligence engineer", not "a Artificial Intelligence
     * engineer". Vowel-initial is the whole rule that matters here - job role
     * names in this data are plain English noun phrases.
     */
    private function withArticle(string $noun): string
    {
        $noun = trim($noun);

        if ($noun === '') {
            return 'someone in this role';
        }

        return (str_contains('aeiouAEIOU', $noun[0]) ? 'an ' : 'a ') . $noun;
    }
}
