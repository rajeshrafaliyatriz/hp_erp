<?php

namespace App\Console\Commands;

use App\Services\Competency\ProficiencyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Leave a WORKING example of the capability loop on a tenant.
 *
 * ── WHAT IT BUILDS ──────────────────────────────────────────────────────────
 *
 * A course aimed at a capability gap a named employee actually has, with a quiz
 * whose questions cite the KASBA items they are behind on, and auto-apply on -
 * then sits that employee at it. Afterwards their gap has closed, their
 * capability history explains why, and every screen that reads either shows it.
 *
 * ── WHY IT IS A SEPARATE COMMAND FROM lms:seed-ai-demo ──────────────────────
 *
 * That one writes course and assessment content nobody's record depends on.
 * This one CHANGES A REAL PERSON'S CAPABILITY RATINGS. That deserves its own
 * decision rather than riding along with a content seed, so it is opt-in twice:
 * a named learner, and --execute.
 *
 * It is reversible. Every rating written appends to competency_rating_history
 * with the value it replaced, so what was there before is recoverable - which
 * was not true of any rating in this product until that table existed.
 *
 *   php artisan lms:seed-capability-loop --database=live --tenant=6 --learner=63
 *   php artisan lms:seed-capability-loop --database=live --tenant=6 --learner=63 --execute
 */
class LmsSeedCapabilityLoop extends Command
{
    protected $signature = 'lms:seed-capability-loop
        {--database=mysql : Connection to seed}
        {--tenant=6 : sub_institute_id}
        {--learner= : tbluser.id whose gap the course should close}
        {--execute : Actually write. Without it, nothing is changed}';

    protected $description = 'Seed a course that closes a real capability gap, and sit the learner at it';

    public function handle(): int
    {
        $connection = (string) $this->option('database');
        $tenant = (int) $this->option('tenant');
        $learnerId = (int) $this->option('learner');
        $execute = (bool) $this->option('execute');

        DB::setDefaultConnection($connection);
        $db = DB::connection($connection);

        if ($learnerId <= 0) {
            $this->error('--learner is required: this command changes that person\'s capability record.');

            return self::FAILURE;
        }

        $learner = $db->table('tbluser')->where('id', $learnerId)
            ->where('sub_institute_id', $tenant)
            ->first(['id', 'first_name', 'last_name', 'department_id', 'jobtitle_id', 'allocated_standards']);

        if (!$learner) {
            $this->error("No user {$learnerId} in tenant {$tenant}.");

            return self::FAILURE;
        }

        $name = trim(($learner->first_name ?? '') . ' ' . ($learner->last_name ?? ''));
        $this->info("Capability loop demo on '{$connection}', tenant {$tenant}, for {$name} (#{$learnerId})");
        $this->line($execute ? '  MODE: writing' : '  MODE: dry run (pass --execute to write)');
        $this->newLine();

        // ── Which gap to close ──────────────────────────────────────────────
        $jobroleId = (int) ($learner->jobtitle_id ?: 0);

        if ($jobroleId <= 0) {
            $first = trim(explode(',', (string) $learner->allocated_standards)[0] ?? '');
            $jobroleId = is_numeric($first) ? (int) $first : 0;
        }

        $requirements = $db->table('jobrole_competency_map as m')
            ->join('competency as c', 'c.id', '=', 'm.competency_id')
            ->where('m.sub_institute_id', $tenant)
            ->where('m.jobrole_id', $jobroleId)
            ->whereNotNull('m.required_proficiency')
            ->get(['m.competency_id', 'm.required_proficiency', 'c.name', 'c.code']);

        if ($requirements->isEmpty()) {
            $this->warn('  That job role has no capability requirements, so there is no gap to close.');

            return self::SUCCESS;
        }

        $levels = app(ProficiencyService::class)->rollUp(
            $tenant,
            $learnerId,
            $requirements->pluck('competency_id')->map(fn ($id) => (int) $id)->all()
        );

        $this->line('  Their capability today:');
        $gaps = [];

        foreach ($requirements as $req) {
            $cid = (int) $req->competency_id;
            $level = $levels[$cid]['level'] ?? null;
            $state = $level === null
                ? 'unmeasured'
                : ($level >= (float) $req->required_proficiency ? 'met' : 'GAP');

            $this->line(sprintf('    %-8s %-36s %-10s %s / %d',
                $req->code, mb_substr($req->name, 0, 34), $state,
                $level === null ? '  -  ' : sprintf('%5.2f', $level),
                $req->required_proficiency));

            if ($state === 'GAP') {
                $gaps[] = ['id' => $cid, 'name' => $req->name, 'level' => $level,
                           'required' => (int) $req->required_proficiency];
            }
        }

        if ($gaps === []) {
            $this->newLine();
            $this->warn('  They are already at or above every requirement - nothing to demonstrate.');

            return self::SUCCESS;
        }

        // The biggest shortfall makes the clearest demonstration.
        usort($gaps, fn ($a, $b) => ($b['required'] - $b['level']) <=> ($a['required'] - $a['level']));
        $target = $gaps[0];

        $items = $db->table('competency_kasba_item')
            ->where('sub_institute_id', $tenant)
            ->where('competency_id', $target['id'])
            ->orderBy('id')
            ->get(['id', 'kasba_type', 'item_label']);

        $this->newLine();
        $this->line(sprintf('  Closing: %s (%.2f of %d)', $target['name'], $target['level'], $target['required']));
        $this->line(sprintf('  A course with %d module(s) and a %d-question quiz citing %d capability item(s).',
            1, $items->count() * 2, $items->count()));

        if (!$execute) {
            $this->newLine();
            $this->warn('  Nothing was written. Re-run with --execute.');

            return self::SUCCESS;
        }

        $existing = $db->table('sub_std_map')
            ->where('sub_institute_id', $tenant)
            ->where('display_name', $this->courseTitle($target['name']))
            ->whereNull('deleted_at')
            ->value('id');

        if ($existing) {
            $this->line("  Course already present as #{$existing} - nothing rebuilt.");

            return self::SUCCESS;
        }

        $courseId = $this->buildCourse($db, $tenant, $learner, $target, $items);
        $this->line("  Course #{$courseId} published, quiz attached, auto-apply on.");

        $result = $this->sitTheQuiz($db, $tenant, $learnerId, $courseId);
        $this->line(sprintf('  %s scored %s%% - %s.', $name, $result['percent'],
            $result['passed'] ? 'passed' : 'did not pass'));

        // ── What actually moved ─────────────────────────────────────────────
        $after = app(ProficiencyService::class)->rollUp($tenant, $learnerId, [$target['id']]);
        $newLevel = $after[$target['id']]['level'] ?? null;

        $this->newLine();
        $this->info(sprintf('  %s: %.2f -> %.2f (needs %d) - %s',
            $target['name'], $target['level'], $newLevel ?? 0, $target['required'],
            $newLevel !== null && $newLevel >= $target['required'] ? 'GAP CLOSED' : 'still short'));

        $history = $db->table('competency_rating_history')
            ->where('user_id', $learnerId)->where('course_id', $courseId)->get();

        $this->line(sprintf('  %d capability item(s) moved, each recorded with what it replaced:', $history->count()));

        foreach ($history as $h) {
            $this->line(sprintf('    %-46s %s -> %d',
                mb_substr((string) $h->item_label, 0, 44),
                $h->old_rating === null ? 'first' : $h->old_rating,
                $h->new_rating));
        }

        $this->newLine();
        $this->line('  Visible now on: Profile -> My Capability, the employee drawer\'s Capability');
        $this->line('  Progress tab, and Talent Management -> Capability Progress.');

        return self::SUCCESS;
    }

    private function courseTitle(string $competency): string
    {
        return $competency . ' - practitioner course';
    }

    /** A course, one module, and a quiz whose questions cite the capabilities. */
    private function buildCourse($db, int $tenant, object $learner, array $target, $items): int
    {
        $now = now();
        $actor = $db->table('tbluser')->where('sub_institute_id', $tenant)
            ->whereIn('user_profile_id', function ($q) use ($tenant) {
                $q->select('id')->from('tbluserprofilemaster')
                  ->where('sub_institute_id', $tenant)->where('role_key', 'administrator');
            })->value('id');

        $courseId = $db->table('sub_std_map')->insertGetId([
            'display_name' => $this->courseTitle($target['name']),
            'standard_id' => $learner->department_id,
            'subject_category' => 'Capability development',
            'subject_type' => 'Self-paced course',
            'sort_order' => 1,
            'status' => 1,
            'sub_institute_id' => $tenant,
            'allow_grades' => 'Yes', 'allow_content' => 'Yes', 'elective_subject' => 'No',
            'add_content' => 'chapterwise',
            'created_by' => $actor, 'created_at' => $now,
        ]);

        $db->table('lms_course_settings')->insert([
            'course_id' => $courseId, 'sub_institute_id' => $tenant,
            'description' => 'Built to close the ' . $target['name'] . ' gap. Passing it updates '
                . 'the learner\'s capability record.',
            'language' => 'English', 'passing_score' => 60,
            // The flag the Course Builder can now set.
            'auto_apply_rating' => 1,
            'issue_certificate' => 1, 'visibility' => 'all', 'enrollment_rule' => 'open',
            'created_by' => $actor, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $db->table('course_competency_map')->insert([
            'sub_institute_id' => $tenant, 'course_id' => $courseId,
            'competency_id' => $target['id'], 'proficiency_level' => $target['required'],
            'is_primary' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        /*
         * A module is REQUIRED before any question can exist:
         * lms_question_master.subject_id carries an FK to chapter_master.subject_id,
         * so a course with no chapter cannot hold a question at all (errno 1452).
         */
        $chapterId = $db->table('chapter_master')->insertGetId([
            'subject_id' => $courseId, 'chapter_name' => $target['name'],
            'chapter_desc' => 'One lesson per capability this course builds.',
            'sort_order' => 1, 'sub_institute_id' => $tenant,
            'created_by' => $actor, 'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ($items as $i => $item) {
            $db->table('content_master')->insert([
                'subject_id' => $courseId, 'chapter_id' => $chapterId,
                'title' => $item->item_label,
                'description' => 'What good looks like for "' . $item->item_label . '", and the '
                    . 'two mistakes people most often make with it.',
                'file_type' => 'link', 'content_category' => 'Reading',
                'sort_order' => $i + 1, 'sub_institute_id' => $tenant,
                'created_by' => $actor, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $paperId = $db->table('question_paper')->insertGetId([
            'subject_id' => $courseId, 'standard_id' => $learner->department_id,
            'paper_name' => $target['name'] . ' check', 'exam_type' => 'quiz',
            'attempt_allowed' => 3, 'sub_institute_id' => $tenant, 'syear' => date('Y'),
            'show_hide' => 1, 'created_by' => $actor, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $ids = [];

        /*
         * Two questions per capability item, so each clears MIN_QUESTIONS_TO_RATE
         * and is rated on its OWN evidence rather than on the course average.
         */
        foreach ($items as $item) {
            foreach ([1, 2] as $n) {
                $qId = $db->table('lms_question_master')->insertGetId([
                    'question_type_id' => 1,
                    'subject_id' => $courseId,
                    'chapter_id' => $chapterId,
                    // The citation that makes per-capability rating possible.
                    'kasba_item_id' => $item->id,
                    'standard_id' => $learner->department_id,
                    'question_title' => $n === 1
                        ? 'Which of these best shows someone is capable at "' . $item->item_label . '"?'
                        : 'Working as part of a team, when does "' . $item->item_label . '" matter most?',
                    'points' => 1, 'multiple_answer' => 0,
                    'sub_institute_id' => $tenant, 'status' => 1,
                    'created_by' => $actor, 'created_on' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                $options = $n === 1
                    ? [
                        ['They do it consistently and can say where it stops being appropriate.', 1],
                        ['They can define it accurately when asked.', 0],
                        ['They have completed the training that covers it.', 0],
                    ]
                    : [
                        ['When the usual approach does not fit and somebody has to decide.', 1],
                        ['During an audit.', 0],
                        ['Only when a supervisor asks about it.', 0],
                    ];

                foreach ($options as [$text, $correct]) {
                    $db->table('answer_master')->insert([
                        'question_id' => $qId, 'answer' => $text, 'correct_answer' => $correct,
                        'sub_institute_id' => $tenant, 'created_by' => $actor,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }

                $ids[] = $qId;
            }
        }

        $db->table('question_paper')->where('id', $paperId)->update([
            'question_ids' => implode(',', $ids),
            'total_ques' => count($ids),
            'total_marks' => count($ids),
        ]);

        $db->table('lms_course_enroll')->insert([
            'user_id' => $learner->id, 'course_id' => $courseId,
            'sub_institute_id' => $tenant, 'status' => 'enrolled',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $courseId;
    }

    /**
     * Sit the quiz THROUGH THE REAL ENDPOINTS.
     *
     * Inserting an attempt row directly would demonstrate nothing: the whole
     * point is that the product's own scoring, rating and history writes run.
     */
    private function sitTheQuiz($db, int $tenant, int $learnerId, int $courseId): array
    {
        $user = \App\Models\auth\tbluserModel::find($learnerId);
        $token = $user->createToken('seed-capability-loop')->plainTextToken;

        $make = function (array $params) use ($token) {
            $r = \Illuminate\Http\Request::create('/x', 'POST', $params + ['token' => $token]);
            $r->headers->set('Authorization', 'Bearer ' . $token);

            return $r;
        };

        /*
         * ── FINISH THE LESSONS FIRST ────────────────────────────────────────
         *
         * The quiz is gated on completing every lesson, and rightly so - the
         * first run of this command was refused with "Finish all 4 lessons
         * before taking the quiz". That gate is the product working, not an
         * obstacle to route around, so the seeded learner completes the lessons
         * the same way a real one does: through POST /lms/learning/progress,
         * which validates that each content item actually belongs to the course.
         */
        $learning = app(\App\Http\Controllers\Api\LmsLearningController::class);

        $lessons = $db->table('content_master')
            ->where('subject_id', $courseId)
            ->whereNull('deleted_at')
            ->get(['id', 'chapter_id']);

        foreach ($lessons as $lesson) {
            $learning->saveProgress($make([
                'course_id' => $courseId,
                'content_id' => $lesson->id,
                'chapter_id' => $lesson->chapter_id,
                'status' => 'completed',
                'time_spent_delta' => 240,
            ]));
        }

        $controller = app(\App\Http\Controllers\Api\LmsQuizController::class);

        $start = json_decode($controller->start($make([]), $courseId)->getContent(), true);
        $attemptId = $start['data']['attempt_id'] ?? null;

        if (!$attemptId) {
            $user->tokens()->where('name', 'seed-capability-loop')->delete();

            throw new \RuntimeException('Could not start the quiz: ' . ($start['message'] ?? 'unknown'));
        }

        // Answer every question correctly, reading the key from the database -
        // the same place the scorer reads it.
        $paper = $db->table('question_paper')->where('subject_id', $courseId)
            ->orderByDesc('id')->first(['question_ids']);

        $questionIds = array_filter(array_map('intval', explode(',', (string) $paper->question_ids)));

        $answers = $db->table('answer_master')
            ->whereIn('question_id', $questionIds)
            ->where('correct_answer', 1)
            ->pluck('id', 'question_id')
            ->all();

        $body = json_decode(
            $controller->submit($make(['answers' => $answers]), $attemptId)->getContent(),
            true
        );

        $user->tokens()->where('name', 'seed-capability-loop')->delete();

        return [
            'percent' => $body['data']['percent'] ?? '?',
            'passed' => (bool) ($body['data']['passed'] ?? false),
        ];
    }
}
