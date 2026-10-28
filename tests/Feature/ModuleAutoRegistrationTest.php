<?php

namespace Tests\Feature;

use App\Domain\AI\Discovery\DiscoveredSources;
use App\Domain\AI\Discovery\ModuleSchemaDiscovery;
use App\Domain\AI\Modules\ModuleRegistrar;
use App\Domain\AI\Reports\ModuleDataSourceCatalog;
use App\Services\Ai\AiRequestScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Adding a module is a menu change: the AI Stack registers it and reads its tables without code.
 *
 * Fixture: a made-up module "Zz Demo" (menu row + three tables), built the way a developer would add one.
 * The tests pin the rules that keep that safe - what is read, what is withheld, who may read it and how
 * the organisation boundary holds - because discovery that is merely convenient is a data leak waiting.
 */
class ModuleAutoRegistrationTest extends TestCase
{
    private const MODULE_MENU = 900;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tblmenumaster_g2g', function ($t) {
            $t->integer('id')->primary();
            $t->string('menu_name');
            $t->integer('parent_id')->default(0);
            $t->integer('level')->default(1);
            $t->string('access_link')->nullable();
            $t->string('icon')->nullable();
            $t->integer('status')->default(1);
            $t->integer('sort_order')->default(0);
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('ai_modules', function ($t) {
            $t->increments('id');
            $t->string('module_key');
            $t->string('label');
            $t->string('domain')->default('g2g');
            $t->text('description')->nullable();
            $t->text('capabilities')->nullable();
            $t->text('registry_keys')->nullable();
            $t->integer('menu_id')->nullable();
            $t->string('icon')->nullable();
            $t->integer('sort_order')->default(0);
            $t->integer('status')->default(1);
            $t->integer('sub_institute_id')->nullable();
            $t->integer('client_id')->nullable();
            $t->timestamps();
        });
        Schema::create('tblgroupwise_rights_g2g', function ($t) {
            $t->increments('id');
            $t->integer('menu_id');
            $t->integer('profile_id');
            $t->integer('can_view')->default(0);
            $t->integer('can_add')->default(0);
            $t->integer('can_edit')->default(0);
            $t->integer('can_delete')->default(0);
            $t->string('sub_institute_id')->nullable();
            $t->string('right_view')->nullable();
            $t->string('right_add')->nullable();
            $t->string('right_edit')->nullable();
            $t->string('right_delete')->nullable();
        });

        // The module as a developer adds it: a level-1 menu row, two screens, plus an unrelated inactive module.
        DB::table('tblmenumaster_g2g')->insert([
            ['id' => self::MODULE_MENU, 'menu_name' => 'Zz Demo Projects', 'parent_id' => 0, 'level' => 1, 'access_link' => '/module/zz-demo', 'status' => 1],
            ['id' => 901, 'menu_name' => 'Projects', 'parent_id' => self::MODULE_MENU, 'level' => 2, 'access_link' => '/module/zz-demo/projects', 'status' => 1],
            ['id' => 902, 'menu_name' => 'Deals', 'parent_id' => self::MODULE_MENU, 'level' => 2, 'access_link' => '/gtm/deals', 'status' => 1],
            ['id' => 903, 'menu_name' => 'Platform Services', 'parent_id' => self::MODULE_MENU, 'level' => 2, 'access_link' => '/platform-services/workflow?module=zz', 'status' => 1],
            ['id' => 910, 'menu_name' => 'Switched Off', 'parent_id' => 0, 'level' => 1, 'access_link' => '/module/off', 'status' => 0],
        ]);

        Schema::create('zz_demo_projects', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('name');
            $t->string('status')->nullable();
            $t->decimal('budget', 10, 2)->default(0);
            $t->text('notes')->nullable();
            $t->string('contact_email')->nullable();   // contact detail: withheld
            $t->string('api_token')->nullable();       // secret: withheld
            $t->binary('attachment')->nullable();      // blob: never selected
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('zz_demo_milestones', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->integer('project_id');
            $t->string('title');
            $t->string('status')->nullable();
        });
        Schema::create('zz_demo_audit_lines', function ($t) {   // no organisation column: scoped through its project
            $t->increments('id');
            $t->integer('project_id');
            $t->string('note');
            $t->decimal('amount', 10, 2)->default(0);
        });
        Schema::create('zz_demo_orphan_notes', function ($t) { // no organisation column and no parent
            $t->increments('id');
            $t->string('note');
        });
        Schema::create('zz_demo_api_tokens', function ($t) {   // looks like credentials: never read
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('label');
        });

        $project = fn (int $id, int $institute, string $name, string $status, float $budget, ?string $deleted = null) => [
            'id' => $id, 'sub_institute_id' => $institute, 'name' => $name, 'status' => $status, 'budget' => $budget, 'notes' => 'n',
            'contact_email' => "p{$id}@example.com", 'api_token' => "SECRET{$id}", 'attachment' => 'x', 'deleted_at' => $deleted,
        ];
        DB::table('zz_demo_projects')->insert([
            $project(1, 3, 'Alpha', 'active', 100),
            $project(2, 3, 'Beta', 'done', 50),
            $project(3, 3, 'Gone', 'active', 7, now()->toDateTimeString()),
            $project(4, 1, 'Other org', 'active', 999),
        ]);
        DB::table('zz_demo_milestones')->insert([
            ['sub_institute_id' => 3, 'project_id' => 1, 'title' => 'M1', 'status' => 'done'],
            ['sub_institute_id' => 3, 'project_id' => 1, 'title' => 'M2', 'status' => 'pending'],
            ['sub_institute_id' => 1, 'project_id' => 4, 'title' => 'Foreign', 'status' => 'pending'],
        ]);
        DB::table('zz_demo_audit_lines')->insert([
            ['project_id' => 1, 'note' => 'mine', 'amount' => 10],
            ['project_id' => 3, 'note' => 'parent soft-deleted', 'amount' => 1],
            ['project_id' => 4, 'note' => 'other org', 'amount' => 5],
        ]);

        Cache::flush();
    }

    private function scope(int $institute, bool $admin = true, int $profile = 7): AiRequestScope
    {
        return new AiRequestScope(1, $admin ? 'admin' : 'employee', $institute, [$institute], $profile, null, $admin, false);
    }

    private function catalog(): ModuleDataSourceCatalog
    {
        app()->forgetInstance(DiscoveredSources::class);

        return app(ModuleDataSourceCatalog::class);
    }

    // ---------------------------------------------------------------- registration

    public function test_a_level_one_menu_registers_itself_and_only_once(): void
    {
        $registrar = app(ModuleRegistrar::class);

        $first = $registrar->ensureForMenu(self::MODULE_MENU);
        $second = $registrar->ensureForMenu(self::MODULE_MENU);

        $this->assertTrue($first['created']);
        $this->assertSame('zz_demo', $first['row']->module_key);
        $this->assertSame('Zz Demo Projects', $first['row']->label);
        $this->assertFalse($second['created']);
        $this->assertSame(1, DB::table('ai_modules')->where('menu_id', self::MODULE_MENU)->count());

        $flags = json_decode($first['row']->capabilities, true);
        $this->assertTrue($flags['conversational']);
        $this->assertFalse($flags['generative'], 'nothing wires generation for a new module, so it is not advertised');
    }

    public function test_a_screen_or_a_switched_off_module_is_not_registered(): void
    {
        $registrar = app(ModuleRegistrar::class);

        $this->assertNull($registrar->ensureForMenu(901)['row']);
        $this->assertNull($registrar->ensureForMenu(910)['row']);
        $this->assertNull($registrar->ensureForMenu(12345)['row']);
        $this->assertSame(0, DB::table('ai_modules')->count());
    }

    public function test_sync_registers_every_active_module_and_a_dry_run_writes_nothing(): void
    {
        $registrar = app(ModuleRegistrar::class);

        $dry = $registrar->syncAll(true);
        $this->assertSame('would register', $dry[0]['action']);
        $this->assertSame(0, DB::table('ai_modules')->count());

        $registrar->syncAll();
        $this->assertSame(['zz_demo'], DB::table('ai_modules')->pluck('module_key')->all());
    }

    public function test_a_dormant_module_with_data_is_switched_on_but_a_live_one_is_left_alone(): void
    {
        $registrar = app(ModuleRegistrar::class);
        DB::table('ai_modules')->insert(['module_key' => 'old', 'label' => 'Old', 'capabilities' => json_encode(['conversational' => false, 'agent' => false])]);
        DB::table('ai_modules')->insert(['module_key' => 'live', 'label' => 'Live', 'capabilities' => json_encode(['conversational' => true, 'agent' => false, 'generative' => true])]);

        $this->assertTrue($registrar->isDormant(DB::table('ai_modules')->where('module_key', 'old')->first()));
        $this->assertTrue($registrar->enable(DB::table('ai_modules')->where('module_key', 'old')->first()));
        $this->assertFalse($registrar->enable(DB::table('ai_modules')->where('module_key', 'live')->first()));
        $this->assertTrue(json_decode(DB::table('ai_modules')->where('module_key', 'live')->value('capabilities'), true)['generative']);
    }

    public function test_table_prefixes_come_from_the_menu_links_and_never_from_platform_paths(): void
    {
        $prefixes = app(ModuleRegistrar::class)->prefixesFor('zz_demo', self::MODULE_MENU);

        $this->assertEqualsCanonicalizing(['zz_demo_', 'gtm_'], $prefixes);
    }

    // ---------------------------------------------------------------- discovery

    public function test_discovery_reads_what_is_safe_and_says_why_it_skipped_the_rest(): void
    {
        $found = app(ModuleSchemaDiscovery::class)->discover('zz_demo', ['zz_demo_']);
        $byTable = array_column($found['tables'], null, 'table');
        $skipped = array_column($found['skipped'], 'reason', 'table');

        $this->assertEqualsCanonicalizing(['zz_demo_projects', 'zz_demo_milestones', 'zz_demo_audit_lines'], array_keys($byTable));

        $projects = array_column($byTable['zz_demo_projects']['columns'], 'name');
        $this->assertNotContains('contact_email', $projects);
        $this->assertNotContains('api_token', $projects);
        $this->assertNotContains('attachment', $projects);
        $this->assertNotContains('sub_institute_id', $projects, 'the organisation column filters, it is not data');
        $this->assertEqualsCanonicalizing(['contact_email', 'api_token', 'attachment'], $byTable['zz_demo_projects']['dropped_columns']);

        $this->assertArrayHasKey('zz_demo_orphan_notes', $skipped);
        $this->assertStringContainsString('sub_institute_id', $skipped['zz_demo_orphan_notes']);
        $this->assertArrayHasKey('zz_demo_api_tokens', $skipped);
        $this->assertStringContainsString('credentials', $skipped['zz_demo_api_tokens']);
    }

    public function test_a_child_table_is_scoped_through_its_parent_and_a_foreign_key_names_the_parent_record(): void
    {
        $found = app(ModuleSchemaDiscovery::class)->discover('zz_demo', ['zz_demo_']);
        $byTable = array_column($found['tables'], null, 'table');

        $this->assertSame('zz_demo_projects', $byTable['zz_demo_audit_lines']['tenant_via']['parent']);
        $this->assertNull($byTable['zz_demo_projects']['tenant_via']);
        $this->assertSame('project', $byTable['zz_demo_milestones']['joins'][0]['alias']);
        $this->assertSame('name', $byTable['zz_demo_milestones']['joins'][0]['label']);
    }

    public function test_a_child_with_two_equally_plausible_parents_is_skipped_not_guessed(): void
    {
        Schema::create('zz_demo_links', function ($t) {
            $t->increments('id');
            $t->integer('project_id');
            $t->integer('milestone_id');
        });

        $found = app(ModuleSchemaDiscovery::class)->discover('zz_demo', ['zz_demo_']);
        $skipped = array_column($found['skipped'], 'reason', 'table');

        $this->assertArrayHasKey('zz_demo_links', $skipped);
        $this->assertStringContainsString('guess', $skipped['zz_demo_links']);
    }

    // ---------------------------------------------------------------- reading

    public function test_discovered_sources_are_the_modules_own_and_stay_inside_the_organisation(): void
    {
        app(ModuleRegistrar::class)->ensureForMenu(self::MODULE_MENU);
        $catalog = $this->catalog();

        $names = array_column($catalog->forModule('zz_demo'), 'name');
        $this->assertEqualsCanonicalizing(['zz_demo.projects', 'zz_demo.milestones', 'zz_demo.audit_lines'], $names);

        $mine = $catalog->run('zz_demo.projects', $this->scope(3), ['limit' => 50]);
        $this->assertSame(2, $mine['total'], 'the soft-deleted row and the other organisation are not read');
        $this->assertEqualsCanonicalizing(['Alpha', 'Beta'], array_column($mine['rows'], 'name'));
        $this->assertStringNotContainsString('SECRET', json_encode($mine['rows']));
        $this->assertStringNotContainsString('@', json_encode($mine['rows']));

        $other = $catalog->run('zz_demo.projects', $this->scope(1), ['limit' => 50]);
        $this->assertSame(['Other org'], array_column($other['rows'], 'name'));

        // Reached through the parent: only the caller's own project's lines, and none of a deleted parent's.
        $lines = $catalog->run('zz_demo.audit_lines', $this->scope(3), ['limit' => 50]);
        $this->assertSame(['mine'], array_column($lines['rows'], 'note'));
        $this->assertSame(['other org'], array_column($catalog->run('zz_demo.audit_lines', $this->scope(1), ['limit' => 50])['rows'], 'note'));

        // The parent's own name is shown, in the caller's organisation only.
        $milestones = $catalog->run('zz_demo.milestones', $this->scope(3), ['limit' => 50]);
        $this->assertSame(['Alpha'], array_values(array_unique(array_column($milestones['rows'], 'project'))));
    }

    public function test_a_hand_written_module_is_never_widened_by_discovery(): void
    {
        DB::table('ai_modules')->insert(['module_key' => 'lms', 'label' => 'LMS', 'menu_id' => self::MODULE_MENU, 'capabilities' => json_encode(['conversational' => true])]);
        Schema::create('lms_extra', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('name');
        });

        $names = array_column($this->catalog()->forModule('lms'), 'name');

        $this->assertNotContains('lms.extra', $names);
    }

    public function test_the_chat_status_filter_only_applies_to_a_column_the_source_has(): void
    {
        app(ModuleRegistrar::class)->ensureForMenu(self::MODULE_MENU);
        $catalog = $this->catalog();

        $active = $catalog->run('zz_demo.projects', $this->scope(3), ['status' => 'active']);
        $this->assertSame(['Alpha'], array_column($active['rows'], 'name'));

        // milestones has a status too; audit_lines has none, so the argument is ignored there rather than breaking the query.
        $this->assertSame(1, $catalog->run('zz_demo.audit_lines', $this->scope(3), ['status' => 'active'])['total']);
    }

    // ---------------------------------------------------------------- permission

    public function test_a_non_administrator_needs_the_view_right_on_the_modules_menu(): void
    {
        app(ModuleRegistrar::class)->ensureForMenu(self::MODULE_MENU);

        try {
            $this->catalog()->run('zz_demo.projects', $this->scope(3, false, 9));
            $this->fail('A caller with no right on the module read its records.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('view rights', $e->getMessage());
        }

        DB::table('tblgroupwise_rights_g2g')->insert(['menu_id' => self::MODULE_MENU, 'profile_id' => 9, 'can_view' => 1, 'sub_institute_id' => '3']);
        $this->assertSame(2, $this->catalog()->run('zz_demo.projects', $this->scope(3, false, 9))['total']);

        // An individual DENY outranks the group's allow.
        DB::table('tblgroupwise_rights_g2g')->where('profile_id', 9)->update(['right_view' => 'deny']);
        $this->expectException(RuntimeException::class);
        $this->catalog()->run('zz_demo.projects', $this->scope(3, false, 9));
    }

    // ---------------------------------------------------------------- staying current

    public function test_a_schema_change_reaches_the_sources_without_anyone_refreshing_anything(): void
    {
        app(ModuleRegistrar::class)->ensureForMenu(self::MODULE_MENU);
        $before = array_column($this->catalog()->run('zz_demo.projects', $this->scope(3))['rows'], null);
        $this->assertArrayNotHasKey('priority', $before[0]);
        $fingerprint = app(ModuleSchemaDiscovery::class)->discover('zz_demo', ['zz_demo_'])['fingerprint'];

        Schema::table('zz_demo_projects', fn ($t) => $t->string('priority')->nullable());
        DB::table('zz_demo_projects')->where('id', 1)->update(['priority' => 'high']);

        // Inside the trust window the old listing is still used; once it lapses the new column is read.
        $this->travel(2)->minutes();
        $after = $this->catalog()->run('zz_demo.projects', $this->scope(3));

        $this->assertNotSame($fingerprint, app(ModuleSchemaDiscovery::class)->discover('zz_demo', ['zz_demo_'])['fingerprint']);
        $this->assertContains('high', array_column($after['rows'], 'priority'));
    }

    public function test_a_page_is_mapped_to_a_source_only_when_exactly_one_matches_its_name(): void
    {
        app(ModuleRegistrar::class)->ensureForMenu(self::MODULE_MENU);
        $sources = app(DiscoveredSources::class);

        $this->assertSame(['zz_demo.projects'], $sources->pageEntry('zz_demo', '/module/zz-demo/projects', 'Projects')['sources']);
        $this->assertNull($sources->pageEntry('zz_demo', '/module/zz-demo/settings', 'Settings'));
    }
}
