<?php

namespace Tests\Feature;

use App\Domain\AI\Templates\TemplatePreviewData;
use App\Services\Ai\AiRequestScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The AI Stack shows records people made or the system measured - never a developer's worked example.
 *
 * Pins two things: the command that removes the seeded example rows only ever selects those rows (and can put
 * them back), and a template preview with nothing to read says so instead of inventing competencies.
 */
class AiStackNoFabricatedDataTest extends TestCase
{
    /** @var array<int, string> Backups that existed before the test (real ones, never deleted here). */
    private array $preexisting = [];

    protected function tearDown(): void
    {
        // The command writes its backup under storage; remove only the ones this test created.
        foreach (File::glob(storage_path('app/ai-seed-removal-*.json')) as $file) {
            if (! in_array($file, $this->preexisting, true)) {
                File::delete($file);
            }
        }

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->preexisting = File::glob(storage_path('app/ai-seed-removal-*.json'));

        Schema::create('ai_policies', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->string('policy_type')->default('ai_assisted');
            $t->integer('status')->default(1);
            $t->integer('sub_institute_id')->nullable();
            $t->integer('created_by')->nullable();
        });
        Schema::create('ai_policy_rules', function ($t) {
            $t->increments('id');
            $t->integer('policy_id');
            $t->string('rule_key');
            $t->integer('rule_value')->default(1);
        });
        Schema::create('ai_policy_assignments', function ($t) {
            $t->increments('id');
            $t->integer('policy_id');
            $t->string('scope_type');
            $t->integer('scope_id');
        });
        Schema::create('ai_templates', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->string('kind')->default('prompt');
            $t->string('template_key')->nullable();
            $t->integer('sub_institute_id')->nullable();
            $t->integer('created_by')->nullable();
        });
        Schema::create('ai_suggestions', function ($t) {
            $t->increments('id');
            $t->string('label');
            $t->string('action_ref')->nullable();
            $t->integer('sub_institute_id')->nullable();
        });
        Schema::create('ai_generated_reports', function ($t) {
            $t->increments('id');
            $t->integer('layout_template_id')->nullable();
            $t->text('html_content')->nullable();
        });

        // Seeded examples (platform scope, no author, example wording)...
        DB::table('ai_policies')->insert([
            ['id' => 1, 'name' => 'Course content generation (example)', 'sub_institute_id' => null, 'created_by' => null],
            // ...and things that must NOT be touched: an organisation's own policy, a person's policy that merely says "(example)".
            ['id' => 2, 'name' => 'Our real policy', 'sub_institute_id' => 3, 'created_by' => 6],
            ['id' => 3, 'name' => 'Written by an admin (example)', 'sub_institute_id' => null, 'created_by' => 6],
        ]);
        DB::table('ai_policy_rules')->insert([['policy_id' => 1, 'rule_key' => 'a'], ['policy_id' => 2, 'rule_key' => 'b']]);
        DB::table('ai_policy_assignments')->insert([['policy_id' => 1, 'scope_type' => 'module', 'scope_id' => 9], ['policy_id' => 2, 'scope_type' => 'module', 'scope_id' => 9]]);
        $tpl = fn (int $id, string $name, string $key, ?int $institute = null, ?int $author = null) => [
            'id' => $id, 'name' => $name, 'template_key' => $key, 'sub_institute_id' => $institute, 'created_by' => $author,
        ];
        DB::table('ai_templates')->insert([
            $tpl(1, 'Course outline draft (example)', 'g2g.lms.outline'),
            $tpl(2, 'Leave register (starter example)', 'g2g.example.hrit.report'),
            $tpl(3, 'Field edit assistant', 'g2g.field_edit'),
            $tpl(4, 'Team report', 'org.team', 3, 6),
        ]);
        DB::table('ai_suggestions')->insert([['label' => 'Draft', 'action_ref' => 'g2g.lms.outline'], ['label' => 'Mine', 'action_ref' => 'org.team']]);
        DB::table('ai_generated_reports')->insert([['layout_template_id' => 2, 'html_content' => '<p>real output</p>']]);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->artisan('ai:remove-seeded-examples')->assertSuccessful();

        $this->assertSame(3, DB::table('ai_policies')->count());
        $this->assertSame(4, DB::table('ai_templates')->count());
    }

    public function test_execute_removes_only_seeded_examples_and_keeps_what_people_made(): void
    {
        $this->artisan('ai:remove-seeded-examples --execute')->assertSuccessful();

        $this->assertSame(['Our real policy', 'Written by an admin (example)'], DB::table('ai_policies')->orderBy('id')->pluck('name')->all());
        $this->assertSame(1, DB::table('ai_policy_rules')->count(), 'only the removed policy\'s rules go');
        $this->assertSame(1, DB::table('ai_policy_assignments')->count());
        $this->assertSame(['Field edit assistant', 'Team report'], DB::table('ai_templates')->orderBy('id')->pluck('name')->all(), 'the real field-edit template stays');
        $this->assertSame(['Mine'], DB::table('ai_suggestions')->pluck('label')->all());

        // A generated report is real output: its text stays, only the pointer to the deleted layout is cleared.
        $report = DB::table('ai_generated_reports')->first();
        $this->assertSame('<p>real output</p>', $report->html_content);
        $this->assertNull($report->layout_template_id);
    }

    public function test_the_removal_is_backed_up_and_can_be_restored(): void
    {
        $this->artisan('ai:remove-seeded-examples --execute')->assertSuccessful();
        $backup = collect(File::glob(storage_path('app/ai-seed-removal-*.json')))->sort()->last();
        $this->assertNotNull($backup);

        $this->artisan('ai:remove-seeded-examples', ['--restore' => $backup])->assertSuccessful();

        $this->assertSame(3, DB::table('ai_policies')->count());
        $this->assertSame(4, DB::table('ai_templates')->count());
        $this->assertSame(2, DB::table('ai_generated_reports')->first()->layout_template_id);

    }

    public function test_a_preview_with_nothing_to_read_says_so_instead_of_inventing_competencies(): void
    {
        $values = app(TemplatePreviewData::class)->forInstitute(3);

        $this->assertSame('(no records found)', $values['records']);
        $this->assertSame('0', $values['record_count']);
        $this->assertStringContainsString('no records', $values['data_source']);
        $this->assertStringNotContainsString('EXAMPLE', json_encode($values));
        $this->assertStringNotContainsString('Root Cause Analysis', json_encode($values));
    }

    public function test_a_preview_for_an_unregistered_module_never_borrows_another_modules_records(): void
    {
        $scope = new AiRequestScope(1, 'admin', 3, [3], 7, null, true, false);
        $values = app(TemplatePreviewData::class)->forInstitute(3, $scope, 'no_such_module');

        $this->assertSame('(no records found)', $values['records']);
        $this->assertStringContainsString('no records', $values['data_source']);
    }
}
