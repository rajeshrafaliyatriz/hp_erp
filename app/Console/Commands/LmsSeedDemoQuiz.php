<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seed the demo course with everything the quiz lifecycle needs.
 *
 * The demo course already has six lessons, one of each media type, and an
 * assigned learner. What it has never had is the second half of the lifecycle:
 * settings (so there is a pass mark and an attempt limit), a competency mapping
 * (so a result has somewhere to go), a quiz paper with real questions, and a
 * session linked to the course (so attendance has something to count toward).
 *
 * Idempotent: every insert is guarded, so running it twice changes nothing.
 * Dry-run by default — nothing is written without --execute, because this
 * writes to a live tenant.
 *
 *   php artisan lms:seed-demo-quiz --database=live --course=174 --learner=63
 *   php artisan lms:seed-demo-quiz --database=live --course=174 --learner=63 --execute
 */
class LmsSeedDemoQuiz extends Command
{
    protected $signature = 'lms:seed-demo-quiz
        {--database=mysql : Connection to seed}
        {--course= : Course id (sub_std_map.id)}
        {--learner= : The demo learner (tbluser.id)}
        {--tenant=6 : sub_institute_id}
        {--session= : Session id to link to the course}
        {--execute : Actually write. Without it, nothing is changed}';

    protected $description = 'Seed the LMS demo course with settings, a competency map, a quiz and a linked session';

    /** Marks every row this command creates, so a cleanup can find them. */
    private const DEMO_TAG = 'LMS demo';

    public function handle(): int
    {
        $connection = (string) $this->option('database');
        $courseId = (int) $this->option('course');
        $learnerId = (int) $this->option('learner');
        $tenantId = (int) $this->option('tenant');
        $execute = (bool) $this->option('execute');

        if ($courseId <= 0 || $learnerId <= 0) {
            $this->error('--course and --learner are both required.');

            return self::FAILURE;
        }

        $db = DB::connection($connection);

        $course = $db->table('sub_std_map')
            ->where('id', $courseId)
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->first(['id', 'display_name']);

        if (!$course) {
            $this->error("Course {$courseId} is not in tenant {$tenantId} on '{$connection}'.");

            return self::FAILURE;
        }

        $this->info("Seeding \"{$course->display_name}\" (#{$courseId}) on '{$connection}', tenant {$tenantId}");
        $this->line($execute ? '  MODE: writing' : '  MODE: dry run (pass --execute to write)');
        $this->newLine();

        $this->seedSettings($db, $courseId, $tenantId, $execute);
        $competencyId = $this->seedCompetencyMap($db, $courseId, $tenantId, $execute);
        $this->seedQuiz($db, $courseId, $tenantId, $execute);
        $this->seedSession($db, $courseId, $tenantId, $learnerId, $execute);

        $this->newLine();

        if (!$execute) {
            $this->warn('Nothing was written. Re-run with --execute.');

            return self::SUCCESS;
        }

        $this->info('Done. The demo course now has:');
        $this->line('  - a pass mark, an attempt limit and auto-apply on');
        $this->line('  - a competency to move' . ($competencyId ? " (#{$competencyId})" : ''));
        $this->line('  - a quiz the learner can sit once every lesson is complete');
        $this->line('  - a session linked to the course, with the learner registered');

        return self::SUCCESS;
    }

    /** Settings: the pass mark, the attempt limit, and the auto-apply choice. */
    private function seedSettings($db, int $courseId, int $tenantId, bool $execute): void
    {
        if ($db->table('lms_course_settings')->where('course_id', $courseId)->exists()) {
            $this->line('  settings          already present, left alone');

            return;
        }

        $this->line('  settings          CREATE passing_score=60 max_attempts=3 auto_apply_rating=1');

        if (!$execute) {
            return;
        }

        $db->table('lms_course_settings')->insert([
            'course_id' => $courseId,
            'sub_institute_id' => $tenantId,
            'sequential_unlock' => 0,
            'description' => 'Demonstration course covering every supported content type, '
                . 'with a quiz that moves a competency rating on completion.',
            'duration_minutes' => 60,
            'language' => 'English',
            'is_mandatory' => 0,
            'discussion_enabled' => 0,
            'visibility' => 'all',
            'passing_score' => 60,
            'max_attempts' => 3,
            // The product decision: this course writes the rating without human
            // review. Per course, so it is a choice somebody made and not a
            // rule that applies everywhere.
            'auto_apply_rating' => 1,
            'issue_certificate' => 1,
            'recert_alerts' => 0,
            'enrollment_rule' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** One competency, so a quiz result has a rating to move. */
    private function seedCompetencyMap($db, int $courseId, int $tenantId, bool $execute): ?int
    {
        $existing = $db->table('course_competency_map')
            ->where('course_id', $courseId)
            ->where('sub_institute_id', $tenantId)
            ->value('competency_id');

        if ($existing) {
            $this->line("  competency map    already mapped to competency #{$existing}");

            return (int) $existing;
        }

        $competency = $db->table('competency')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first(['id', 'name']);

        if (!$competency) {
            $this->warn('  competency map    SKIPPED - this tenant has no competencies');

            return null;
        }

        $this->line("  competency map    CREATE -> #{$competency->id} \"{$competency->name}\" target level 3");

        if ($execute) {
            $db->table('course_competency_map')->insert([
                'sub_institute_id' => $tenantId,
                'course_id' => $courseId,
                'competency_id' => $competency->id,
                // The TARGET the course aims at. What learners actually reach
                // is measured separately, in
                // lms_course_competency_effectiveness - see that migration for
                // why the two must not share a column.
                'proficiency_level' => 3,
                'is_primary' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return (int) $competency->id;
    }

    /**
     * Six questions with real options, and exactly one correct answer each.
     *
     * `correct_answer` lives only in answer_master and is never rendered — the
     * scorer reads it from the database at submit time. See
     * QuizScoringService for why that is stated so emphatically.
     */
    private function seedQuiz($db, int $courseId, int $tenantId, bool $execute): void
    {
        if ($db->table('question_paper')
            ->where('subject_id', $courseId)
            ->whereNull('deleted_at')
            ->exists()) {
            $this->line('  quiz              already present, left alone');

            return;
        }

        $bank = $this->questionBank();

        $this->line('  quiz              CREATE paper + ' . count($bank) . ' questions with options');

        if (!$execute) {
            return;
        }

        $questionIds = [];
        $now = now();

        foreach ($bank as $item) {
            $questionId = $db->table('lms_question_master')->insertGetId([
                // 1 = 'multiple' in question_type_master; the only type this
                // installation has.
                'question_type_id' => 1,
                'course_id' => $courseId,
                'question_title' => $item['question'],
                'points' => $item['points'],
                'multiple_answer' => 0,
                'sub_institute_id' => $tenantId,
                'status' => 1,
                'paper_category' => self::DEMO_TAG,
                'created_on' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $questionIds[] = $questionId;

            foreach ($item['options'] as $index => $option) {
                $db->table('answer_master')->insert([
                    'question_id' => $questionId,
                    'answer' => $option,
                    'correct_answer' => $index === $item['correct'] ? 1 : 0,
                    'sub_institute_id' => $tenantId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $db->table('question_paper')->insert([
            'course_id' => $courseId,
            'paper_name' => 'Course quiz — every content type',
            'paper_desc' => 'Six questions on the material in this course. '
                . 'Pass to earn the certificate.',
            'timelimit_enable' => 1,
            'time_allowed' => 15,
            'total_marks' => array_sum(array_column($bank, 'points')),
            'total_ques' => count($bank),
            'question_ids' => implode(',', $questionIds),
            'shuffle_question' => 0,
            'attempt_allowed' => '3',
            'show_feedback' => 1,
            'show_hide' => 1,
            // Answers stay hidden: this quiz allows retries, and revealing them
            // would make attempt two a transcription exercise.
            'result_show_ans' => 0,
            'exam_type' => 'quiz',
            'sub_institute_id' => $tenantId,
            'created_on' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** A session linked to the course, with the learner on it to be marked. */
    private function seedSession($db, int $courseId, int $tenantId, int $learnerId, bool $execute): void
    {
        $sessionId = (int) $this->option('session');

        if ($sessionId <= 0) {
            // Prefer an existing unlinked session in this tenant over creating
            // another - the demo tenant already has one, and a second identical
            // session in the calendar is noise.
            $sessionId = (int) ($db->table('lms_virtual_classroom')
                ->where('sub_institute_id', $tenantId)
                ->whereNull('deleted_at')
                ->whereNull('course_id')
                ->orderBy('id')
                ->value('id') ?? 0);
        }

        if ($sessionId <= 0) {
            $this->line('  session           none available to link, skipped');

            return;
        }

        $this->line("  session           LINK #{$sessionId} -> course {$courseId}");

        if ($execute) {
            $db->table('lms_virtual_classroom')->where('id', $sessionId)->update([
                'course_id' => $courseId,
                'updated_at' => now(),
            ]);
        }

        $registered = $db->table('lms_session_registrations')
            ->where('session_id', $sessionId)
            ->where('user_id', $learnerId)
            ->whereNull('deleted_at')
            ->exists();

        if ($registered) {
            $this->line("  registration      learner {$learnerId} already registered");

            return;
        }

        $this->line("  registration      CREATE learner {$learnerId} on session {$sessionId}");

        if ($execute) {
            $db->table('lms_session_registrations')->insert([
                'session_id' => $sessionId,
                'user_id' => $learnerId,
                'status' => 'registered',
                'registered_at' => now(),
                'sub_institute_id' => $tenantId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * The questions.
     *
     * About the course's own subject — how the LMS handles content — so the
     * demo reads as a real quiz rather than filler, and so a wrong answer is
     * plainly wrong to anyone watching.
     *
     * @return array<int,array{question:string, options:array<int,string>, correct:int, points:int}>
     */
    private function questionBank(): array
    {
        return [
            [
                'question' => 'Which lesson type does this course NOT contain?',
                'options' => ['A video (MP4)', 'A slide deck (PPT)', 'A SCORM package', 'A PDF'],
                'correct' => 2,
                'points' => 1,
            ],
            [
                'question' => 'What has to happen before the course quiz unlocks?',
                'options' => [
                    'Every lesson in the course is marked complete',
                    'An administrator approves the request',
                    'The course end date passes',
                    'Nothing — the quiz is always open',
                ],
                'correct' => 0,
                'points' => 1,
            ],
            [
                'question' => 'What does passing this quiz do, besides unlocking the certificate?',
                'options' => [
                    'Nothing else',
                    'It updates the learner\'s competency rating',
                    'It enrols them on the next course',
                    'It emails their manager',
                ],
                'correct' => 1,
                'points' => 2,
            ],
            [
                'question' => 'A live session is counted toward a learner\'s course progress when:',
                'options' => [
                    'The session is scheduled',
                    'The learner registers for it',
                    'The learner is marked as attended',
                    'The session date passes',
                ],
                'correct' => 2,
                'points' => 2,
            ],
            [
                'question' => 'Where is the correct answer to a quiz question decided?',
                'options' => [
                    'In the browser, from the option that was rendered',
                    'On the server, from answer_master at submission',
                    'By the learner\'s own confidence rating',
                    'By whichever option most learners picked',
                ],
                'correct' => 1,
                'points' => 2,
            ],
            [
                'question' => 'If the AI marker cannot mark a written answer, what happens to it?',
                'options' => [
                    'It scores zero',
                    'It scores full marks',
                    'It stays unscored and waits for a human',
                    'The whole attempt is discarded',
                ],
                'correct' => 2,
                'points' => 2,
            ],
        ];
    }
}
