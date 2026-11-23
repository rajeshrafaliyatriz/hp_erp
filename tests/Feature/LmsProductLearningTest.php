<?php

namespace Tests\Feature;

use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The two product-website cards on My Learning ("G2G Sales", "ScholarClone Sales").
 *
 * A card, not a course: it opens the original product page as a normal browser page, so there are no lessons, quiz or progress.
 * What is pinned here is that seeding writes exactly the card and the enrolment - nothing else - to the tenant it was told to, that a
 * re-run changes nothing and never deletes, that the existing course list serves the cards to an enrolled learner and to nobody else,
 * and that the two pages the cards open are the unmodified originals.
 */
class LmsProductLearningTest extends TestCase
{
    private const T1 = 1;
    private const T2 = 2;

    private int $learner;
    private int $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('personal_access_tokens', function ($t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tenant_setting', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('setting_key');
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
        });
        Schema::create('hrms_departments', function ($t) {
            $t->id();
            $t->string('department')->nullable();
        });
        Schema::create('sub_std_map', function ($t) {
            $t->id();
            $t->string('display_name')->nullable();
            $t->string('display_image')->nullable();
            $t->unsignedBigInteger('standard_id')->nullable();
            $t->string('subject_category')->nullable();
            $t->string('subject_type')->nullable();
            $t->integer('sort_order')->nullable();
            $t->integer('status')->default(1);
            $t->string('allow_grades')->nullable();
            $t->string('allow_content')->nullable();
            $t->string('elective_subject')->nullable();
            $t->string('add_content')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('chapter_master', function ($t) {
            $t->id();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('chapter_name');
            $t->softDeletes();
        });
        Schema::create('content_master', function ($t) {
            $t->id();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->unsignedBigInteger('chapter_id')->nullable();
            $t->string('title')->nullable();
            $t->softDeletes();
        });
        Schema::create('question_paper', function ($t) {
            $t->id();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('lms_course_enroll', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('course_id');
            $t->string('status')->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('lms_content_progress', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('course_id');
            $t->unsignedBigInteger('content_id');
            $t->string('status')->nullable();
            $t->softDeletes();
        });
        Schema::create('lms_virtual_classroom', function ($t) {
            $t->id();
            $t->unsignedBigInteger('course_id')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('lms_session_registrations', function ($t) {
            $t->id();
            $t->unsignedBigInteger('session_id');
            $t->unsignedBigInteger('user_id');
            $t->string('status')->nullable();
            $t->softDeletes();
        });

        DB::table('tbluserprofilemaster')->insert(['id' => 1, 'name' => 'Employee', 'role_key' => 'employee']);
        $this->learner = DB::table('tbluser')->insertGetId(['user_name' => 'learner', 'sub_institute_id' => self::T1, 'user_profile_id' => 1]);
        $this->outsider = DB::table('tbluser')->insertGetId(['user_name' => 'outsider', 'sub_institute_id' => self::T2, 'user_profile_id' => 1]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seedCards(array $options = []): int
    {
        return Artisan::call('lms:seed-product-learning', ['--database' => config('database.default')] + $options);
    }

    private function as(int $userId): array
    {
        return ['Authorization' => 'Bearer ' . tbluserModel::find($userId)->createToken('t')->plainTextToken];
    }

    private function cardId(string $name, int $tenant = self::T1): int
    {
        return (int) DB::table('sub_std_map')->where('display_name', $name)->where('sub_institute_id', $tenant)->value('id');
    }

    private function page(string $file): string
    {
        return file_get_contents(base_path("../g2gf/public/learning/product-learning/{$file}"));
    }

    // ── safety ───────────────────────────────────────────────────────────────

    public function test_a_tenant_is_required_and_nothing_is_guessed(): void
    {
        $this->assertSame(1, $this->seedCards(['--execute' => true]));
        $this->assertSame(0, DB::table('sub_std_map')->count());
    }

    public function test_an_unknown_product_or_tenant_is_refused(): void
    {
        $this->assertSame(1, $this->seedCards(['--tenant' => self::T1, '--product' => 'nope', '--execute' => true]));
        $this->assertSame(1, $this->seedCards(['--tenant' => 999, '--execute' => true]));
        $this->assertSame(0, DB::table('sub_std_map')->count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->assertSame(0, $this->seedCards(['--tenant' => self::T1, '--learner' => (string) $this->learner]));

        foreach (['sub_std_map', 'lms_course_enroll'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must stay empty on a dry run");
        }
    }

    // ── what gets built: the card and the enrolment, nothing else ───────────

    public function test_it_writes_two_cards_and_no_lessons_quiz_or_progress(): void
    {
        $this->assertSame(0, $this->seedCards(['--tenant' => self::T1, '--learner' => (string) $this->learner, '--execute' => true]));

        $this->assertSame(['G2G Sales', 'ScholarClone Sales'], DB::table('sub_std_map')->orderBy('sort_order')->pluck('display_name')->all());
        $this->assertSame(['Product Learning', 'Product Learning'], DB::table('sub_std_map')->pluck('subject_category')->all());
        $this->assertSame(2, DB::table('lms_course_enroll')->where('user_id', $this->learner)->count());

        foreach (['chapter_master', 'content_master', 'question_paper', 'lms_content_progress'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "a website card has no {$table}");
        }
        $this->assertSame(0, $this->cardId('G2G Sales', self::T2), 'the other organisation must not get the card');
    }

    public function test_each_product_names_only_itself(): void
    {
        foreach (['g2g' => ['G2G', 'ScholarClone'], 'scholarclone' => ['ScholarClone', 'G2G']] as $key => [$own, $other]) {
            $def = require base_path("database/data/product-learning/{$key}.php");
            $names = json_encode([$def['name'], $def['legacy_names']]);
            $this->assertStringContainsStringIgnoringCase($own, $def['name']);
            $this->assertStringNotContainsStringIgnoringCase($other, $names, 'the frontend picks the page from the product in the name');
        }
    }

    // ── idempotence ──────────────────────────────────────────────────────────

    public function test_running_it_again_changes_nothing(): void
    {
        $options = ['--tenant' => self::T1, '--learner' => (string) $this->learner, '--execute' => true];
        $this->seedCards($options);
        $before = [DB::table('sub_std_map')->pluck('id')->all(), DB::table('lms_course_enroll')->count()];

        $this->seedCards($options);

        $this->assertSame($before, [DB::table('sub_std_map')->pluck('id')->all(), DB::table('lms_course_enroll')->count()]);
    }

    public function test_a_legacy_named_course_is_renamed_in_place_and_nothing_is_deleted(): void
    {
        // what an earlier version of this command left behind: a course with lessons
        $id = DB::table('sub_std_map')->insertGetId(['display_name' => 'G2G Product Learning', 'sub_institute_id' => self::T1, 'subject_category' => null]);
        $lesson = DB::table('content_master')->insertGetId(['subject_id' => $id, 'title' => 'Welcome']);

        $this->seedCards(['--tenant' => self::T1, '--product' => 'g2g', '--execute' => true]);

        $this->assertSame(1, DB::table('sub_std_map')->count(), 'renamed, not duplicated');
        $row = DB::table('sub_std_map')->where('id', $id)->first();
        $this->assertSame('G2G Sales', $row->display_name);
        $this->assertSame('Product Learning', $row->subject_category);
        $this->assertSame(1, DB::table('content_master')->where('id', $lesson)->count(), 'earlier lessons are never deleted');
    }

    // ── enrolment ────────────────────────────────────────────────────────────

    public function test_only_the_named_learner_is_enrolled_and_only_in_their_own_tenant(): void
    {
        $this->seedCards(['--tenant' => self::T1, '--learner' => $this->learner . ',' . $this->outsider, '--execute' => true]);

        $this->assertSame(2, DB::table('lms_course_enroll')->where('user_id', $this->learner)->count());
        $this->assertSame(0, DB::table('lms_course_enroll')->where('user_id', $this->outsider)->count(), 'a user of another organisation is never enrolled');
    }

    public function test_all_learners_enrols_active_users_of_the_tenant_only(): void
    {
        $inactive = DB::table('tbluser')->insertGetId(['user_name' => 'gone', 'sub_institute_id' => self::T1, 'user_profile_id' => 1, 'status' => 0]);

        $this->seedCards(['--tenant' => self::T1, '--all-learners' => true, '--execute' => true]);

        $this->assertSame(2, DB::table('lms_course_enroll')->where('user_id', $this->learner)->count());
        $this->assertSame(0, DB::table('lms_course_enroll')->where('user_id', $inactive)->count());
        $this->assertSame(0, DB::table('lms_course_enroll')->where('user_id', $this->outsider)->count());
    }

    // ── through the real endpoint ────────────────────────────────────────────

    public function test_my_learning_serves_the_two_cards_to_the_enrolled_learner_only(): void
    {
        $this->seedCards(['--tenant' => self::T1, '--learner' => (string) $this->learner, '--execute' => true]);

        $list = $this->getJson('/api/lms/learning/courses', $this->as($this->learner))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['G2G Sales', 'ScholarClone Sales'], array_column($list, 'display_name'));
        $this->assertSame(['Product Learning', 'Product Learning'], array_column($list, 'subject_category'));
    }

    public function test_another_organisations_user_sees_neither_card(): void
    {
        $this->seedCards(['--tenant' => self::T1, '--learner' => (string) $this->learner, '--execute' => true]);

        $this->assertSame([], $this->getJson('/api/lms/learning/courses', $this->as($this->outsider))->assertOk()->json('data'));
    }

    // ── the two pages are the unmodified originals ───────────────────────────

    public function test_the_pages_are_the_original_websites_with_no_lms_wrapper(): void
    {
        foreach (['g2g.html' => ['Gaps to Growth', 'KASBA'], 'scholarclone.html' => ['ScholarClone', 'Scholar CCB']] as $file => $mustContain) {
            $html = $this->page($file);

            foreach ($mustContain as $text) {
                $this->assertStringContainsString($text, $html, "{$file} should be the original page");
            }
            // Everything the lesson wrapper used to inject, and the pieces of it. None may remain.
            foreach (['learning-bridge', 'learning-mode', '__LM_SECTIONS', 'data-lm-hide', 'lm-wrap', 'lm-status', 'noindex', 'g2g-course'] as $marker) {
                $this->assertStringNotContainsString($marker, $html, "{$file} must not carry the lesson wrapper ({$marker})");
            }
            // and the original is whole: its own nav, hero, FAQ and footer, and its own script
            foreach (['<nav id="nav">', '<header id="hero">', 'id="faq"', '<footer>', '</html>'] as $part) {
                $this->assertStringContainsString($part, $html, "{$file} is missing {$part}");
            }
        }
    }

    public function test_no_lesson_wrapper_files_are_left_behind(): void
    {
        $files = array_map('basename', glob(base_path('../g2gf/public/learning/product-learning/*')));
        sort($files);

        $this->assertSame(['g2g.html', 'scholarclone.html'], $files);
    }
}
