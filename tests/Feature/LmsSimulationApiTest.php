<?php

namespace Tests\Feature;

use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use App\Services\Lms\SimulationStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Simulation Learning: the simulations of an organisation as data. These pin what matters most about it - that nothing is hard-coded (a
 * gallery is whatever rows the organisation has), that one organisation can never see or touch another's, that the rights matrix on the
 * menu row decides who may add, edit and delete, that a specification is kept in one canonical form whichever way it arrived, and that
 * versions, deletes, platform simulations and progress behave.
 */
class LmsSimulationApiTest extends TestCase
{
    private const T1 = 1;
    private const T2 = 2;
    private const ADMIN = 1;
    private const EMPLOYEE = 2;
    private const MANAGER = 3;
    private const MENU = '/module/lms/simulation-learning';

    private int $menuId;

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
        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('tenant_setting', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('setting_key');
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
        });
        Schema::create('tblmenumaster_g2g', function ($t) {
            $t->id();
            $t->string('menu_name');
            $t->unsignedBigInteger('parent_id')->nullable()->default(0);
            $t->integer('level')->nullable()->default(1);
            $t->string('page_type')->nullable();
            $t->string('access_link')->nullable();
            $t->string('icon')->nullable();
            $t->tinyInteger('status')->default(1);
            $t->integer('sort_order')->default(1);
            $t->text('sub_institute_id')->nullable();
            $t->string('menu_type')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tblgroupwise_rights_g2g', function ($t) {
            $t->id();
            $t->unsignedBigInteger('menu_id');
            $t->unsignedBigInteger('profile_id');
            $t->tinyInteger('can_view')->default(0);
            $t->tinyInteger('can_add')->default(0);
            $t->tinyInteger('can_edit')->default(0);
            $t->tinyInteger('can_delete')->default(0);
            $t->tinyInteger('dashboard_right')->default(0);
            $t->tinyInteger('is_mobile')->default(0);
            $t->text('sub_institute_id')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->string('right_view')->nullable();
            $t->string('right_add')->nullable();
            $t->string('right_edit')->nullable();
            $t->string('right_delete')->nullable();
            $t->string('right_dashboard')->nullable();
        });

        (require base_path('database/migrations/2026_12_03_090000_create_g2g_simulation_tables.php'))->up();

        DB::table('tbluserprofilemaster')->insert([
            ['id' => self::ADMIN, 'name' => 'Administrator', 'role_key' => 'administrator'],
            ['id' => self::EMPLOYEE, 'name' => 'Employee', 'role_key' => 'employee'],
            ['id' => self::MANAGER, 'name' => 'Reporting Manager', 'role_key' => 'reporting_manager'],
        ]);

        // the LMS branch the menu migration hangs the new row on, and the Course Builder / My Learning rows it copies rights from
        $lms = DB::table('tblmenumaster_g2g')->insertGetId(['menu_name' => 'LMS', 'parent_id' => 0, 'level' => 1, 'access_link' => '/module/lms', 'sort_order' => 4]);
        $builder = DB::table('tblmenumaster_g2g')->insertGetId(['menu_name' => 'Course Builder', 'parent_id' => 76, 'level' => 3, 'access_link' => '/module/lms/administration/course-builder']);
        $learning = DB::table('tblmenumaster_g2g')->insertGetId(['menu_name' => 'My Learning', 'parent_id' => 74, 'level' => 3, 'access_link' => '/module/lms/learning/my-learning']);
        DB::table('tblmenumaster_g2g')->insert(['menu_name' => 'Assessments', 'parent_id' => $lms, 'level' => 2, 'access_link' => '/module/lms/assessments', 'sort_order' => 7]);
        $none = ['can_view' => 0, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0, 'sub_institute_id' => '1'];
        DB::table('tblgroupwise_rights_g2g')->insert([
            ['menu_id' => $builder, 'profile_id' => self::ADMIN, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1, 'sub_institute_id' => '1'],
            // a profile that can only LOOK at Course Builder (on live, the Employee profiles of several organisations are like this)
            ['menu_id' => $builder, 'profile_id' => self::MANAGER] + ['can_view' => 1] + $none,
            ['menu_id' => $learning, 'profile_id' => self::ADMIN] + ['can_view' => 1] + $none,
            ['menu_id' => $learning, 'profile_id' => self::EMPLOYEE] + ['can_view' => 1] + $none,
        ]);
        (require base_path('database/migrations/2026_12_03_090100_create_simulation_learning_menu_row.php'))->up();
        $this->menuId = (int) DB::table('tblmenumaster_g2g')->where('access_link', self::MENU)->value('id');

        RoleKey::flushCache();
        Cache::flush();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function h(int $tenant = self::T1, int $profile = self::ADMIN): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    /** A valid specification: the small example from the runtime, with a different id and title. */
    private function spec(string $id = 'tiny-api', string $title = 'Tiny API', array $meta = []): string
    {
        $spec = json_decode((string) file_get_contents(base_path('tests/Fixtures/simulations/tiny-api.json')));
        $spec->id = $id;
        $spec->title = $title;
        if ($meta !== []) {
            $spec->meta = (object) $meta;
        }

        return json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function add(array $headers, string $id = 'tiny-api', string $title = 'Tiny API', array $extra = [], array $meta = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/lms/simulations', ['spec' => json_decode($this->spec($id, $title, $meta))] + $extra, $headers);
    }

    private function allow(int $profile, array $bits): void
    {
        DB::table('tblgroupwise_rights_g2g')->insert(array_merge(['menu_id' => $this->menuId, 'profile_id' => $profile, 'sub_institute_id' => '1', 'can_view' => 0, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0], $bits));
        Cache::flush();
    }

    /** A specification file on disk, as the import command takes it. */
    private function specFile(string $text): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'sim');
        file_put_contents($file, $text);

        return $file;
    }

    // ── access ───────────────────────────────────────────────────────────────

    public function test_the_menu_migration_grants_authors_only_not_everyone_who_can_look_at_course_builder(): void
    {
        $rights = DB::table('tblgroupwise_rights_g2g')->where('menu_id', $this->menuId)->get()->keyBy('profile_id');

        $this->assertTrue($rights->has(self::ADMIN), 'the authoring profile is granted the row');
        $admin = $rights[self::ADMIN];
        $this->assertSame([1, 1, 1, 1], [(int) $admin->can_view, (int) $admin->can_add, (int) $admin->can_edit, (int) $admin->can_delete], 'with the bits Course Builder gives it');
        $this->assertFalse($rights->has(self::MANAGER), 'a profile that can only view Course Builder is not granted (live has employee profiles like that)');
        $this->assertFalse($rights->has(self::EMPLOYEE), 'learners are granted per organisation by simulations:grant, never by the migration');
        $this->assertCount(1, $rights);
    }

    public function test_every_route_needs_a_token(): void
    {
        foreach ([['GET', '/api/lms/simulations'], ['GET', '/api/lms/simulations/1'], ['POST', '/api/lms/simulations'], ['PUT', '/api/lms/simulations/1'], ['DELETE', '/api/lms/simulations/1'], ['POST', '/api/lms/simulations/1/progress'], ['GET', '/api/lms/simulations/catalogue']] as [$m, $u]) {
            $this->json($m, $u)->assertStatus(401);
        }
    }

    public function test_an_employee_without_the_right_gets_nothing_and_with_it_can_only_look(): void
    {
        $this->add($this->h())->assertCreated();

        $this->getJson('/api/lms/simulations', $this->h(self::T1, self::EMPLOYEE))->assertStatus(403);

        $this->allow(self::EMPLOYEE, ['can_view' => 1]);
        $learner = $this->h(self::T1, self::EMPLOYEE);
        $this->getJson('/api/lms/simulations', $learner)->assertOk()->assertJsonPath('data.can', ['view' => true, 'add' => false, 'edit' => false, 'delete' => false]);
        $this->add($learner, 'another', 'Another')->assertForbidden();
        $this->putJson('/api/lms/simulations/1', ['category' => 'x'], $learner)->assertForbidden();
        $this->deleteJson('/api/lms/simulations/1', [], $learner)->assertForbidden();
    }

    public function test_the_menu_row_gives_authors_add_edit_and_delete_through_the_rights_matrix(): void
    {
        $this->allow(self::MANAGER, ['can_view' => 1, 'can_add' => 1, 'can_edit' => 0, 'can_delete' => 0]);
        $manager = $this->h(self::T1, self::MANAGER);

        $this->add($manager)->assertCreated();
        $this->putJson('/api/lms/simulations/1', ['category' => 'x'], $manager)->assertForbidden();
        $this->deleteJson('/api/lms/simulations/1', [], $manager)->assertForbidden();
    }

    public function test_a_profile_named_administrator_without_a_role_key_is_an_administrator_here_as_at_the_route_gate(): void
    {
        // RoleKey reads a profile literally named "Administrator" as the administrator role while its role_key is unset (true of most live
        // organisations) and the route gate lets it through on that; the rights this screen reports must agree, or the user is let in and then
        // shown nothing they can do.
        DB::table('tbluserprofilemaster')->insert(['id' => 4, 'name' => 'Administrator', 'role_key' => null]);
        $this->add($this->h(), 'wip', 'Work in progress', ['status' => 'draft'])->assertCreated();

        $named = $this->h(self::T1, 4);
        $this->getJson('/api/lms/simulations', $named)->assertOk()
            ->assertJsonPath('data.can', ['view' => true, 'add' => true, 'edit' => true, 'delete' => true])
            ->assertJsonPath('data.total', 1);   // the draft is listed: managers see drafts
        $this->add($named, 'mine', 'Mine')->assertCreated();
        $this->putJson('/api/lms/simulations/2', ['category' => 'x'], $named)->assertOk();
        $this->deleteJson('/api/lms/simulations/2', [], $named)->assertOk();
    }

    public function test_a_caller_with_no_organisation_gets_nothing_even_when_the_request_names_one(): void
    {
        // the shared identity resolver falls back to the request's sub_institute_id for an account that has none; an administrator account
        // without an organisation must not be able to use that to act inside somebody else's
        $this->add($this->h(self::T1))->assertCreated();

        foreach ([null, 0] as $none) {
            $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $none, 'user_profile_id' => self::ADMIN]);
            $drifter = ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];

            $this->getJson('/api/lms/simulations?sub_institute_id=' . self::T1, $drifter)->assertForbidden()->assertJsonPath('status', false);
            $this->postJson('/api/lms/simulations', ['spec' => json_decode($this->spec('other', 'Other')), 'sub_institute_id' => self::T1], $drifter)->assertForbidden();
            $this->postJson('/api/lms/simulations/1/progress', ['percent' => 10, 'sub_institute_id' => self::T1], $drifter)->assertForbidden();
        }
        $this->assertSame(1, DB::table('g2g_simulation')->count());
        $this->assertSame(0, DB::table('g2g_simulation_progress')->count());
    }

    public function test_whoever_may_add_or_delete_can_also_play_the_drafts_they_are_shown(): void
    {
        $this->add($this->h(), 'wip', 'Work in progress', ['status' => 'draft'])->assertCreated();
        $this->allow(self::MANAGER, ['can_view' => 1, 'can_add' => 1]);
        $this->allow(self::EMPLOYEE, ['can_view' => 1, 'can_delete' => 1]);

        foreach ([self::MANAGER, self::EMPLOYEE] as $n => $profile) {
            $person = $this->h(self::T1, $profile);
            $this->assertCount(1, $this->getJson('/api/lms/simulations', $person)->json('data.simulations'), 'the draft is listed');
            $this->getJson('/api/lms/simulations/1', $person)->assertOk();
            $this->postJson('/api/lms/simulations/1/progress', ['percent' => 20 + $n, 'step' => 1], $person)->assertOk()->assertJsonPath('data.percent', 20 + $n);
        }
        $this->assertSame(2, DB::table('g2g_simulation_progress')->count(), 'and what they played was saved');
    }

    // ── the gallery ──────────────────────────────────────────────────────────

    public function test_an_administrator_adds_a_simulation_and_the_gallery_lists_its_card(): void
    {
        $admin = $this->h();
        $this->getJson('/api/lms/simulations', $admin)->assertOk()->assertJsonPath('data.total', 0)->assertJsonPath('data.simulations', []);

        $created = $this->add($admin, 'tiny-api', 'Tiny API', ['tags' => ['queues', 'caching']], ['minutes' => 12, 'audience' => 'Engineers', 'category' => 'Engineering', 'difficulty' => 'intro'])
            ->assertCreated()->json('data');
        $this->assertSame('Engineering', $created['category']);

        $card = $this->getJson('/api/lms/simulations', $admin)->assertOk()->json('data.simulations.0');
        $this->assertSame(['tiny-api', 'Tiny API', 12, 'Engineers', 'intro', 'published', 'tenant', 1], [$card['slug'], $card['title'], $card['minutes'], $card['audience'], $card['difficulty'], $card['status'], $card['scope'], $card['version']]);
        $this->assertSame(['queues', 'caching'], $card['tags']);
        $this->assertArrayNotHasKey('spec', $card, 'the gallery never carries specifications');
        $this->assertGreaterThan(0, $card['missions']);
    }

    public function test_the_filter_chips_are_made_from_the_simulations_that_exist(): void
    {
        $admin = $this->h();
        $this->add($admin, 'one', 'One', [], ['category' => 'Engineering'])->assertCreated();
        $this->add($admin, 'two', 'Two', [], ['category' => 'engineering'])->assertCreated();
        $this->add($admin, 'three', 'Three', ['category' => 'Data & ML'])->assertCreated();

        $chips = $this->getJson('/api/lms/simulations', $admin)->json('data.categories');
        $this->assertSame([['key' => 'engineering', 'label' => 'Engineering', 'count' => 2], ['key' => 'data & ml', 'label' => 'Data & ML', 'count' => 1]], $chips);

        $this->deleteJson('/api/lms/simulations/3', [], $admin)->assertOk();
        $this->assertCount(1, $this->getJson('/api/lms/simulations', $admin)->json('data.categories'));
    }

    public function test_search_narrows_the_gallery(): void
    {
        $admin = $this->h();
        $this->add($admin, 'one', 'Traffic control')->assertCreated();
        $this->add($admin, 'two', 'Git dojo')->assertCreated();
        $this->assertSame(['Git dojo'], array_column($this->getJson('/api/lms/simulations?search=git', $admin)->json('data.simulations'), 'title'));
    }

    public function test_a_search_that_is_not_text_is_ignored_not_a_crash(): void
    {
        $admin = $this->h();
        $this->add($admin, 'one', 'Traffic control')->assertCreated();
        $this->add($admin, 'two', 'Git dojo')->assertCreated();

        $this->getJson('/api/lms/simulations?search[]=git', $admin)->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson('/api/lms/simulations?search[a][b]=git', $admin)->assertOk()->assertJsonPath('data.total', 2);
    }

    public function test_every_card_carries_the_key_its_chip_was_counted_under(): void
    {
        // PHP and JavaScript disagree on what to trim and how to lower-case a few characters, so the screen filters on the key the server
        // counted instead of working one out; whatever the characters, a chip that says N must find N cards
        $admin = $this->h();
        $this->add($admin, 'one', 'One', [], ['category' => 'Engineering'])->assertCreated();
        $this->add($admin, 'two', 'Two', [], ['category' => "Engineering\u{00A0}"])->assertCreated();   // a non-breaking space
        $this->add($admin, 'three', 'Three', [], ['category' => 'İstanbul Ops'])->assertCreated();       // a capital I with a dot
        $this->add($admin, 'four', 'Four')->assertCreated();
        $this->add($admin, 'five', 'Five', [], ['category' => 'general'])->assertCreated();
        DB::table('g2g_simulation')->where('slug', 'four')->update(['category' => '  ']);                   // an old row with a blank one

        $data = $this->getJson('/api/lms/simulations', $admin)->assertOk()->json('data');
        $cards = $data['simulations'];
        $this->assertCount(5, $cards);
        foreach ($cards as $card) {
            $this->assertArrayHasKey('category_key', $card, 'every card carries one');
        }
        $this->assertSame(5, array_sum(array_column($data['categories'], 'count')));
        foreach ($data['categories'] as $chip) {
            $this->assertCount($chip['count'], array_filter($cards, fn ($c) => $c['category_key'] === $chip['key']), 'the chip "' . $chip['label'] . '" finds its cards');
        }
        $byslug = array_column($cards, 'category_key', 'slug');
        $this->assertSame(['general', 'general'], [$byslug['four'], $byslug['five']], 'blank and "general" are one category');
    }

    // ── one organisation never sees another's ────────────────────────────────

    public function test_a_simulation_belongs_to_one_organisation(): void
    {
        $this->add($this->h(self::T1))->assertCreated();
        $other = $this->h(self::T2);

        $this->getJson('/api/lms/simulations', $other)->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/lms/simulations/1', $other)->assertNotFound();
        $this->getJson('/api/lms/simulations/1/versions', $other)->assertNotFound();
        $this->putJson('/api/lms/simulations/1', ['status' => 'archived'], $other)->assertNotFound();
        $this->deleteJson('/api/lms/simulations/1', [], $other)->assertNotFound();
        $this->postJson('/api/lms/simulations/1/restore', [], $other)->assertNotFound();
        $this->postJson('/api/lms/simulations/1/progress', ['percent' => 50], $other)->assertNotFound();

        // the same id may exist in both organisations
        $this->add($other)->assertCreated();
        $this->assertSame(2, DB::table('g2g_simulation')->count());
        $this->assertSame('published', DB::table('g2g_simulation')->where('id', 1)->value('status'), 'the other organisation changed nothing');
    }

    public function test_the_organisation_in_the_request_is_ignored(): void
    {
        $admin = $this->h(self::T1);
        $this->postJson('/api/lms/simulations', ['spec' => json_decode($this->spec()), 'sub_institute_id' => self::T2], $admin)->assertCreated();
        $this->assertSame(self::T1, (int) DB::table('g2g_simulation')->value('sub_institute_id'));
    }

    // ── drafts, status, learners ─────────────────────────────────────────────

    public function test_learners_see_published_simulations_only_and_managers_see_drafts_too(): void
    {
        $admin = $this->h();
        $this->add($admin, 'live', 'Live one')->assertCreated();
        $this->add($admin, 'wip', 'Work in progress', ['status' => 'draft'])->assertCreated();
        $this->allow(self::EMPLOYEE, ['can_view' => 1]);
        $learner = $this->h(self::T1, self::EMPLOYEE);

        $this->assertSame(['Live one'], array_column($this->getJson('/api/lms/simulations', $learner)->json('data.simulations'), 'title'));
        $this->getJson('/api/lms/simulations/2', $learner)->assertNotFound();
        $this->assertCount(2, $this->getJson('/api/lms/simulations', $admin)->json('data.simulations'));

        $this->putJson('/api/lms/simulations/2', ['status' => 'published'], $admin)->assertOk();
        $this->assertCount(2, $this->getJson('/api/lms/simulations', $learner)->json('data.simulations'));
    }

    // ── the specification ────────────────────────────────────────────────────

    /** A specification as an author keeps it on disk: indented, a whole number spelled "3.0", and an empty object in it. */
    private function prettySpec(): array
    {
        $spec = json_decode($this->spec());
        $spec->scenarios[0]->mix = new \stdClass();           // an empty object must stay an empty object
        $pretty = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $this->assertStringContainsString("\n    ", $pretty);
        $this->assertStringContainsString('3.0', $pretty);

        return [$spec, $pretty];
    }

    public function test_a_specification_is_stored_in_one_canonical_form_whichever_door_it_came_through(): void
    {
        [$spec, $pretty] = $this->prettySpec();

        // the screen: the browser parses the file and sends it compact, and JavaScript has no "3.0"
        $this->postJson('/api/lms/simulations', ['spec' => $spec], $this->h(self::T1))->assertCreated();
        // the command line: the file as it is on disk
        $file = $this->specFile($pretty);
        $this->assertSame(0, Artisan::call('simulations:import', ['file' => $file, '--tenant' => 2]));
        // a script posting the file's own text
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => $this->h(3)['Authorization']];
        $this->call('POST', '/api/lms/simulations', [], [], [], $server, '{"spec":' . $pretty . '}')->assertCreated();

        $rows = DB::table('g2g_simulation_version')->orderBy('simulation_id')->get();
        $this->assertCount(3, $rows);
        $stored = (string) $rows[0]->spec;
        $this->assertNotSame($pretty, $stored);
        $this->assertStringNotContainsString("\n", $stored, 'compact');
        $this->assertStringNotContainsString('3.0', $stored, 'a whole number is spelled the same however the file spelled it');
        $this->assertStringContainsString('"mix":{}', $stored, 'and an empty object is still an empty object');
        foreach ($rows as $row) {
            $this->assertSame($stored, $row->spec, 'one text for the one document, from every door');
            $this->assertSame(hash('sha256', $stored), $row->sha256, 'and so one sha256');
        }

        $shown = $this->getJson('/api/lms/simulations/2', $this->h(2))->assertOk();
        $this->assertSame('tiny-api', $shown->json('data.spec.id'));
        $this->assertStringContainsString('"mix":{}', $shown->getContent(), 'and played back with the empty object intact');
        @unlink($file);
    }

    public function test_importing_the_same_document_again_through_another_door_adds_no_version(): void
    {
        [$spec, $pretty] = $this->prettySpec();
        $file = $this->specFile($pretty);

        $this->postJson('/api/lms/simulations', ['spec' => $spec], $this->h(self::T1))->assertCreated();           // from the screen
        $this->assertSame(0, Artisan::call('simulations:import', ['file' => $file, '--tenant' => 2]));              // from a file
        $this->assertSame(0, Artisan::call('simulations:import', ['file' => $file, '--tenant' => 1, '--update' => true]));
        $this->putJson('/api/lms/simulations/2', ['spec' => $spec], $this->h(self::T2))->assertOk()->assertJsonPath('data.version', 1);

        $this->assertSame([1, 1], [DB::table('g2g_simulation_version')->where('simulation_id', 1)->count(), DB::table('g2g_simulation_version')->where('simulation_id', 2)->count()]);
        $this->assertSame([1, 1], [(int) DB::table('g2g_simulation')->where('id', 1)->value('current_version'), (int) DB::table('g2g_simulation')->where('id', 2)->value('current_version')]);
        @unlink($file);
    }

    public function test_a_version_stored_before_the_canonical_form_is_not_given_a_twin_by_the_same_document(): void
    {
        // organisation 6 on production was imported from a pretty-printed file, so its stored text is the file's own
        [, $pretty] = $this->prettySpec();
        $file = $this->specFile($pretty);
        $this->assertSame(0, Artisan::call('simulations:import', ['file' => $file, '--tenant' => 6]));
        DB::table('g2g_simulation_version')->where('simulation_id', 1)->update(['spec' => $pretty, 'sha256' => hash('sha256', $pretty)]);

        $this->assertSame(0, Artisan::call('simulations:import', ['file' => $file, '--tenant' => 6, '--update' => true]));
        $this->assertSame(1, DB::table('g2g_simulation_version')->count(), 'the same document is not a new version');
        $this->assertSame($pretty, DB::table('g2g_simulation_version')->value('spec'), 'and what was stored is left as it was');

        file_put_contents($file, str_replace('"Tiny API"', '"Tiny API, revised"', $pretty));
        $this->assertSame(0, Artisan::call('simulations:import', ['file' => $file, '--tenant' => 6, '--update' => true]));
        $this->assertSame(2, DB::table('g2g_simulation_version')->count(), 'a real change still is');
        $this->assertStringNotContainsString("\n", (string) DB::table('g2g_simulation_version')->where('version', 2)->value('spec'), 'and is stored in the canonical form');
        @unlink($file);
    }

    public function test_wrong_types_in_the_specification_are_refused_with_the_field_named(): void
    {
        $admin = $this->h();
        $cases = [
            ['"lesson.intro"', fn (object $s) => $s->lesson->intro = 5],
            ['"lesson.intro"', fn (object $s) => $s->lesson->intro = str_repeat('x', 4001)],
            ['"lesson.missions.0"', fn (object $s) => $s->lesson->missions[0] = 'm1'],
            ['"lesson.missions.0.id"', fn (object $s) => $s->lesson->missions[0]->id = ['m1']],
            ['"lesson.missions.0.kind"', fn (object $s) => $s->lesson->missions[0]->kind = true],
            ['"lesson.missions.0.title"', fn (object $s) => $s->lesson->missions[0]->title = 7],
            ['"lesson.missions.0.brief"', fn (object $s) => $s->lesson->missions[0]->brief = []],
            ['"lesson.missions.0.brief"', fn (object $s) => $s->lesson->missions[0]->brief = str_repeat('b', 1501)],
            ['"meta.authors"', fn (object $s) => $s->meta->authors = 'G2G simulation team'],
            ['"meta.authors"', fn (object $s) => $s->meta->authors = [1]],
            ['"meta.authors"', fn (object $s) => $s->meta->authors = array_fill(0, 11, 'A. Author')],
            ['"meta.authors"', fn (object $s) => $s->meta->authors = [str_repeat('a', 81)]],
            ['"meta.tags"', fn (object $s) => $s->meta->tags = [1]],
            ['"meta.category"', fn (object $s) => $s->meta->category = []],
            ['"meta.difficulty"', fn (object $s) => $s->meta->difficulty = 2],
            ['"meta.minutes"', fn (object $s) => $s->meta->minutes = '5'],
            ['"meta.minutes"', fn (object $s) => $s->meta->minutes = 121],
        ];
        foreach ($cases as $n => [$field, $mutate]) {
            $spec = json_decode($this->spec());
            $mutate($spec);
            $r = $this->postJson('/api/lms/simulations', ['spec' => $spec], $admin)->assertStatus(422);
            $this->assertStringContainsString($field, implode(' | ', $r->json('problems')), "case {$n}");
        }
        $this->assertSame(0, DB::table('g2g_simulation')->count());

        // the edge of what is allowed is allowed
        $spec = json_decode($this->spec());
        $spec->lesson->intro = str_repeat('x', 4000);
        $spec->lesson->missions[0]->brief = str_repeat('b', 1500);
        $spec->meta->authors = array_fill(0, 10, str_repeat('a', 80));
        $this->postJson('/api/lms/simulations', ['spec' => $spec], $admin)->assertCreated();
    }

    public function test_a_whole_number_written_with_a_fraction_is_still_a_whole_number(): void
    {
        // JavaScript cannot tell 5.0 from 5, so the runtime accepts both; a file spelled "5.0" must not be refused where the screen's "5" is not
        $text = str_replace(['"minutes":5,', '"version":1,'], ['"minutes":5.0,', '"version":1.0,'], $this->spec());
        $this->assertStringContainsString('"minutes":5.0,', $text);
        $this->assertStringContainsString('"version":1.0,', $text);

        $parsed = (new SimulationStore())->parse($text);
        $this->assertSame([5, 'tiny-api'], [$parsed['facts']['minutes'], $parsed['facts']['slug']]);
        $this->assertStringContainsString('"minutes":5,', $parsed['json']);
    }

    public function test_the_shipped_example_still_passes_the_shape_check(): void
    {
        $parsed = (new SimulationStore())->parse((string) file_get_contents(base_path('tests/Fixtures/simulations/tiny-api.json')));
        $this->assertSame(['tiny-api', 1, 5], [$parsed['facts']['slug'], $parsed['facts']['missions'], $parsed['facts']['minutes']]);
    }

    public function test_bad_specifications_are_refused_with_every_problem_listed(): void
    {
        $admin = $this->h();
        $r = $this->postJson('/api/lms/simulations', ['spec' => ['schema' => 'nope', 'id' => 'Bad Id', 'title' => '']], $admin)->assertStatus(422);
        $problems = implode(' | ', $r->json('problems'));
        foreach (['"schema"', '"id"', '"title"', '"family"', '"knobs"', '"model"', '"lesson"'] as $needle) {
            $this->assertStringContainsString($needle, $problems);
        }
        $this->assertSame(0, DB::table('g2g_simulation')->count());

        $this->postJson('/api/lms/simulations', [], $admin)->assertStatus(422)->assertJsonPath('problems.0', '"spec" is required: the simulation specification.');
        $this->postJson('/api/lms/simulations', ['spec' => '{not json'], $admin)->assertStatus(422);
        $this->postJson('/api/lms/simulations', ['spec' => json_decode($this->spec()), 'status' => 'bogus'], $admin)->assertStatus(422);
        $this->postJson('/api/lms/simulations', ['spec' => json_decode($this->spec('x', 'T', ['difficulty' => 'hard']))], $admin)->assertStatus(422);

        $big = json_decode($this->spec());
        $big->summary = str_repeat('x', 300);
        $big->padding = str_repeat('y', SimulationStore::MAX_SPEC_BYTES);
        $this->postJson('/api/lms/simulations', ['spec' => $big], $admin)->assertStatus(413);
    }

    public function test_the_same_id_cannot_be_added_twice_to_one_organisation(): void
    {
        $admin = $this->h();
        $this->add($admin)->assertCreated();
        $this->add($admin)->assertStatus(409);
    }

    // ── versions ─────────────────────────────────────────────────────────────

    public function test_a_new_version_is_kept_and_the_id_cannot_change(): void
    {
        $admin = $this->h();
        $this->add($admin)->assertCreated();

        $same = $this->putJson('/api/lms/simulations/1', ['spec' => json_decode($this->spec())], $admin)->assertOk();
        $this->assertSame(1, $same->json('data.version'), 'saving what is already current makes no new version');

        $changed = $this->putJson('/api/lms/simulations/1', ['spec' => json_decode($this->spec('tiny-api', 'Tiny API, second edition')), 'note' => 'new title'], $admin)->assertOk();
        $this->assertSame(2, $changed->json('data.version'));
        $this->assertSame('Tiny API, second edition', $changed->json('data.title'));

        $this->putJson('/api/lms/simulations/1', ['spec' => json_decode($this->spec('renamed', 'Tiny API'))], $admin)->assertStatus(422);

        $versions = $this->getJson('/api/lms/simulations/1/versions', $admin)->assertOk();
        $this->assertSame([2, 1], array_column($versions->json('data.versions'), 'version'));
        $this->assertSame('new title', $versions->json('data.versions.0.note'));

        $this->assertSame('Tiny API', $this->getJson('/api/lms/simulations/1?version=1', $admin)->json('data.spec.title'), 'an author can open an older version');
        $this->assertSame('Tiny API, second edition', $this->getJson('/api/lms/simulations/1', $admin)->json('data.spec.title'));
    }

    public function test_the_card_can_be_edited_without_touching_the_specification(): void
    {
        $admin = $this->h();
        $this->add($admin)->assertCreated();
        $r = $this->putJson('/api/lms/simulations/1', ['category' => 'Operations', 'tags' => 'a, b', 'difficulty' => 'advanced', 'sort_order' => 3, 'status' => 'archived'], $admin)->assertOk();
        $this->assertSame(['Operations', ['a', 'b'], 'advanced', 'archived', 1], [$r->json('data.category'), $r->json('data.tags'), $r->json('data.difficulty'), $r->json('data.status'), $r->json('data.version')]);
        $this->putJson('/api/lms/simulations/1', ['difficulty' => 'impossible'], $admin)->assertStatus(422);
    }

    public function test_the_cover_address_is_checked(): void
    {
        $admin = $this->h();
        foreach (['javascript:alert(1)', 'data:text/html,x', 'http://insecure.example/x.png', '//evil.example/x.png', '/ok path.png'] as $bad) {
            $this->add($admin, 'x' . substr(md5($bad), 0, 6), 'X', ['cover_url' => $bad])->assertStatus(422);
        }
        $this->add($admin, 'good-one', 'Good', ['cover_url' => 'https://cdn.example.com/a.png'])->assertCreated();
        $this->add($admin, 'good-two', 'Good two', ['cover_url' => '/sim-runtime/covers/a.png'])->assertCreated();
    }

    public function test_options_of_the_wrong_type_are_refused_with_a_message_not_a_server_error(): void
    {
        $admin = $this->h();
        $this->add($admin)->assertCreated();

        $bad = [
            ['cover_url', []], ['cover_url', 5], ['category', []], ['category', ['a' => 1]], ['note', []], ['status', []], ['status', 3],
            ['difficulty', []], ['difficulty', 'hard'], ['sort_order', []], ['sort_order', 'abc'], ['sort_order', true],
            ['tags', [[1]]], ['tags', [['a' => 1]]], ['tags', 5], ['tags', ['a' => 1]],
        ];
        foreach ($bad as $n => [$key, $value]) {
            $created = $this->add($admin, 'o' . $n, 'Option ' . $n, [$key => $value])->assertStatus(422);
            $this->assertStringContainsString('"' . $key . '"', implode(' | ', $created->json('problems')), "create {$key}");
            $changed = $this->putJson('/api/lms/simulations/1', [$key => $value], $admin)->assertStatus(422);
            $this->assertStringContainsString('"' . $key . '"', implode(' | ', $changed->json('problems')), "update {$key}");
        }
        $this->assertSame(1, DB::table('g2g_simulation')->count(), 'nothing was saved');
        $this->assertSame('published', DB::table('g2g_simulation')->value('status'));
    }

    public function test_a_new_simulation_follows_the_same_card_rules_as_an_edit(): void
    {
        $admin = $this->h();
        $this->add($admin, 'one', 'One', ['difficulty' => 'advanced', 'sort_order' => 70000])->assertCreated();
        $this->add($admin, 'two', 'Two', ['sort_order' => -5])->assertCreated();
        $this->add($admin, 'three', 'Three', ['sort_order' => '7'])->assertCreated();
        $this->add($admin, 'four', 'Four', ['difficulty' => null, 'sort_order' => null])->assertCreated();

        $rows = DB::table('g2g_simulation')->orderBy('id')->get();
        $this->assertSame(['advanced', 65000], [$rows[0]->difficulty, (int) $rows[0]->sort_order], 'the order is held to the column, as an edit holds it');
        $this->assertSame([0, 7, 0], [(int) $rows[1]->sort_order, (int) $rows[2]->sort_order, (int) $rows[3]->sort_order]);

        $this->putJson('/api/lms/simulations/1', ['sort_order' => 70000], $admin)->assertOk();
        $this->assertSame(65000, (int) DB::table('g2g_simulation')->where('id', 1)->value('sort_order'));
    }

    // ── delete and restore ───────────────────────────────────────────────────

    public function test_delete_removes_it_from_the_gallery_and_restore_brings_it_back(): void
    {
        $admin = $this->h();
        $this->add($admin)->assertCreated();

        $this->deleteJson('/api/lms/simulations/1', [], $admin)->assertOk()->assertJsonPath('data.deleted', true);
        $this->getJson('/api/lms/simulations', $admin)->assertJsonPath('data.total', 0);
        $this->getJson('/api/lms/simulations/1', $admin)->assertNotFound();
        $this->assertNotNull(DB::table('g2g_simulation')->value('deleted_at'), 'soft deleted, not gone');

        $this->postJson('/api/lms/simulations/1/restore', [], $admin)->assertOk();
        $this->getJson('/api/lms/simulations', $admin)->assertJsonPath('data.total', 1);
        $this->assertSame(1, DB::table('g2g_simulation_version')->count(), 'its versions were never touched');
    }

    // ── platform simulations ─────────────────────────────────────────────────

    public function test_a_platform_simulation_is_offered_to_every_organisation_and_switched_on_per_organisation(): void
    {
        (new SimulationStore())->create(null, null, $this->spec('shared', 'Shared by the platform'), ['status' => 'published']);
        $t1 = $this->h(self::T1);
        $t2 = $this->h(self::T2);

        $this->assertSame(['Shared by the platform'], array_column($this->getJson('/api/lms/simulations/catalogue', $t1)->assertOk()->json('data.simulations'), 'title'));
        $this->getJson('/api/lms/simulations', $t1)->assertJsonPath('data.total', 0);
        $this->getJson('/api/lms/simulations/1', $t1)->assertNotFound();

        $this->postJson('/api/lms/simulations/1/enable', [], $t1)->assertOk()->assertJsonPath('data.enabled', true);
        $card = $this->getJson('/api/lms/simulations', $t1)->json('data.simulations.0');
        $this->assertSame(['Shared by the platform', 'platform'], [$card['title'], $card['scope']]);
        $this->getJson('/api/lms/simulations/1', $t1)->assertOk();
        $this->assertCount(0, $this->getJson('/api/lms/simulations/catalogue', $t1)->json('data.simulations'), 'no longer on offer once switched on');

        $this->getJson('/api/lms/simulations', $t2)->assertJsonPath('data.total', 0);   // organisation 2 has not switched it on
        $this->putJson('/api/lms/simulations/1', ['status' => 'archived'], $t1)->assertNotFound();   // and may not edit the platform's copy
        $this->deleteJson('/api/lms/simulations/1', [], $t1)->assertNotFound();

        $this->postJson('/api/lms/simulations/1/disable', [], $t1)->assertOk();
        $this->getJson('/api/lms/simulations', $t1)->assertJsonPath('data.total', 0);
    }

    public function test_switching_a_simulation_on_needs_the_add_right_and_a_platform_simulation(): void
    {
        (new SimulationStore())->create(null, null, $this->spec('shared', 'Shared'), []);
        $this->allow(self::EMPLOYEE, ['can_view' => 1]);
        $this->postJson('/api/lms/simulations/1/enable', [], $this->h(self::T1, self::EMPLOYEE))->assertForbidden();

        $admin = $this->h();
        $this->add($admin, 'mine', 'Mine')->assertCreated();
        $this->postJson('/api/lms/simulations/2/enable', [], $admin)->assertNotFound();   // an organisation's own simulation is not "platform"
    }

    public function test_a_platform_simulation_the_platform_has_withdrawn_can_still_be_switched_off_but_not_on(): void
    {
        (new SimulationStore())->create(null, null, $this->spec('shared', 'Shared'), []);
        $admin = $this->h();
        $this->postJson('/api/lms/simulations/1/enable', [], $admin)->assertOk();

        foreach (['draft', 'archived'] as $status) {
            DB::table('g2g_simulation')->where('id', 1)->update(['status' => $status]);
            $this->assertCount(1, $this->getJson('/api/lms/simulations', $admin)->json('data.simulations'), "a manager still sees it when it is {$status}");
            $this->postJson('/api/lms/simulations/1/disable', [], $admin)->assertOk()->assertJsonPath('data.enabled', false);
            $this->getJson('/api/lms/simulations', $admin)->assertJsonPath('data.total', 0);

            $this->postJson('/api/lms/simulations/1/enable', [], $admin)->assertNotFound();   // only a published one can be switched on
            DB::table('g2g_simulation')->where('id', 1)->update(['status' => 'published']);
            $this->postJson('/api/lms/simulations/1/enable', [], $admin)->assertOk();
        }
    }

    // ── progress ─────────────────────────────────────────────────────────────

    public function test_progress_is_recorded_per_person_and_only_moves_forward(): void
    {
        $admin = $this->h();
        $this->add($admin)->assertCreated();
        $this->allow(self::EMPLOYEE, ['can_view' => 1]);
        $learner = $this->h(self::T1, self::EMPLOYEE);

        $this->postJson('/api/lms/simulations/1/progress', ['percent' => 33, 'step' => 2], $learner)->assertOk()->assertJsonPath('data.percent', 33);
        $this->postJson('/api/lms/simulations/1/progress', ['percent' => 17, 'step' => 1], $learner)->assertOk()->assertJsonPath('data.percent', 33)->assertJsonPath('data.step', 2);
        $this->assertSame('in_progress', $this->getJson('/api/lms/simulations', $learner)->json('data.simulations.0.progress.status'));

        $done = $this->postJson('/api/lms/simulations/1/progress', ['percent' => 100, 'status' => 'completed', 'result' => ['stars' => ['m1' => 3]]], $learner)->assertOk();
        $this->assertSame(['completed', 100], [$done->json('data.status'), $done->json('data.percent')]);
        $this->assertNotNull($done->json('data.completed_at'));
        $this->postJson('/api/lms/simulations/1/progress', ['percent' => 10], $learner)->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertNull($this->getJson('/api/lms/simulations', $admin)->json('data.simulations.0.progress'), 'someone else\'s progress is not yours');
        $this->assertSame(1, DB::table('g2g_simulation_progress')->count());
        $this->assertSame('{"stars":{"m1":3}}', DB::table('g2g_simulation_progress')->value('result'));
    }

    public function test_the_first_report_creates_the_row_and_a_later_one_changes_that_same_row(): void
    {
        $this->add($this->h())->assertCreated();
        $this->allow(self::EMPLOYEE, ['can_view' => 1]);
        $learner = $this->h(self::T1, self::EMPLOYEE);
        $this->assertSame(0, DB::table('g2g_simulation_progress')->count());

        $this->postJson('/api/lms/simulations/1/progress', ['percent' => 25, 'step' => 1, 'result' => ['stars' => ['m1' => 2]]], $learner)->assertOk()
            ->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.percent', 25)->assertJsonPath('data.step', 1)->assertJsonPath('data.completed_at', null);
        $row = DB::table('g2g_simulation_progress')->first();
        $this->assertSame([self::T1, 1, 'in_progress', '{"stars":{"m1":2}}'], [(int) $row->sub_institute_id, (int) $row->version, $row->status, $row->result]);
        $this->assertNotNull($row->started_at);
        $this->assertNull($row->completed_at);

        $this->travelTo(now()->addMinutes(5));
        $this->postJson('/api/lms/simulations/1/progress', ['percent' => 50, 'step' => 2], $learner)->assertOk()->assertJsonPath('data.percent', 50);
        $later = DB::table('g2g_simulation_progress')->first();
        $this->assertSame(1, DB::table('g2g_simulation_progress')->count(), 'the same row, not a second one');
        $this->assertSame([$row->id, $row->started_at], [$later->id, $later->started_at], 'started once');
        $this->assertNotSame($row->last_seen_at, $later->last_seen_at);
        $this->assertSame('{"stars":{"m1":2}}', $later->result, 'a report that sends no result keeps the one held');

        // a first report that is already the completion
        $this->postJson('/api/lms/simulations/1/progress', ['status' => 'completed', 'step' => 4], $this->h())->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.percent', 100)->assertJsonPath('data.step', 4);
        $this->assertNotNull(DB::table('g2g_simulation_progress')->where('user_id', '!=', $row->user_id)->value('completed_at'));
    }

    public function test_reports_that_arrive_out_of_order_never_move_percent_or_step_backwards(): void
    {
        $this->add($this->h())->assertCreated();
        $this->allow(self::EMPLOYEE, ['can_view' => 1]);
        $learner = $this->h(self::T1, self::EMPLOYEE);

        // (percent, step) as they arrive, and what is held after each
        foreach ([[60, 5, 60, 5], [40, 3, 60, 5], [80, 4, 80, 5], [80, 9, 80, 9], [10, 0, 80, 9]] as [$percent, $step, $heldPercent, $heldStep]) {
            $this->postJson('/api/lms/simulations/1/progress', ['percent' => $percent, 'step' => $step], $learner)->assertOk()
                ->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.percent', $heldPercent)->assertJsonPath('data.step', $heldStep)->assertJsonPath('data.completed_at', null);
        }

        // out-of-range figures are held to the range
        $other = $this->h(self::T1, self::ADMIN);
        $this->postJson('/api/lms/simulations/1/progress', ['percent' => -5, 'step' => -1], $other)->assertOk()->assertJsonPath('data.percent', 0)->assertJsonPath('data.step', 0);
        $this->postJson('/api/lms/simulations/1/progress', ['percent' => 250, 'step' => 5000], $other)->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.percent', 100)->assertJsonPath('data.step', 1000);
    }

    public function test_a_completed_simulation_stays_completed_and_keeps_its_first_completion_time(): void
    {
        $this->add($this->h())->assertCreated();
        $this->allow(self::EMPLOYEE, ['can_view' => 1]);
        $learner = $this->h(self::T1, self::EMPLOYEE);

        $this->postJson('/api/lms/simulations/1/progress', ['percent' => 100, 'status' => 'completed', 'step' => 7, 'result' => ['stars' => ['m1' => 3]]], $learner)->assertOk();
        $done = DB::table('g2g_simulation_progress')->first();
        $this->assertNotNull($done->completed_at);

        // a timed save that was slow on the wire and lands after the completion, a replay from the start, and a second completion
        $this->travelTo(now()->addHour());
        foreach ([['percent' => 40, 'step' => 3, 'status' => 'in_progress'], ['percent' => 0, 'step' => 0], ['percent' => 100, 'status' => 'completed', 'step' => 9]] as $late) {
            $r = $this->postJson('/api/lms/simulations/1/progress', $late, $learner)->assertOk();
            $this->assertSame(['completed', 100, 7], [$r->json('data.status'), $r->json('data.percent'), $r->json('data.step')]);
        }
        $row = DB::table('g2g_simulation_progress')->first();
        $this->assertSame($done->completed_at, $row->completed_at, 'the first completion time is kept');
        $this->assertSame([$done->result, $done->started_at], [$row->result, $row->started_at]);
        $this->assertNotSame($done->last_seen_at, $row->last_seen_at, 'the later reports were applied, they just changed nothing that matters');
        $this->assertSame(1, DB::table('g2g_simulation_progress')->count());
    }

    // ── the menu row and the commands ────────────────────────────────────────

    public function test_the_menu_migration_adds_one_row_for_the_authors_and_takes_it_out_again(): void
    {
        $row = DB::table('tblmenumaster_g2g')->where('access_link', self::MENU)->first();
        $lms = DB::table('tblmenumaster_g2g')->where('access_link', '/module/lms')->value('id');
        $this->assertSame([$lms, 2, 'page', 'Simulation Learning', 1], [$row->parent_id, $row->level, $row->page_type, $row->menu_name, $row->status]);
        $this->assertSame(8, (int) $row->sort_order, 'one past the highest of its siblings');

        $rights = DB::table('tblgroupwise_rights_g2g')->where('menu_id', $this->menuId)->get();
        $this->assertSame([self::ADMIN], $rights->pluck('profile_id')->map(fn ($i) => (int) $i)->all(), 'only the people who author in the LMS');
        $this->assertSame([1, 1, 1, 1], [(int) $rights[0]->can_view, (int) $rights[0]->can_add, (int) $rights[0]->can_edit, (int) $rights[0]->can_delete]);

        $migration = require base_path('database/migrations/2026_12_03_090100_create_simulation_learning_menu_row.php');
        $migration->up();
        $this->assertSame(1, DB::table('tblmenumaster_g2g')->where('access_link', self::MENU)->count(), 'running it twice adds nothing');
        $this->assertSame(1, DB::table('tblgroupwise_rights_g2g')->where('menu_id', $this->menuId)->count());

        $migration->down();
        $this->assertSame(0, DB::table('tblmenumaster_g2g')->where('access_link', self::MENU)->count());
        $this->assertSame(0, DB::table('tblgroupwise_rights_g2g')->where('menu_id', $this->menuId)->count());
    }

    public function test_the_import_command_adds_and_updates_a_simulation_for_one_organisation(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sim') . '.json';
        file_put_contents($file, $this->spec('from-file', 'From a file', ['category' => 'Engineering']));

        $this->assertSame(0, Artisan::call('simulations:import', ['file' => $file, '--tenant' => 6]));
        $row = DB::table('g2g_simulation')->first();
        $this->assertSame([6, 'from-file', 'Engineering', 'published', 1], [(int) $row->sub_institute_id, $row->slug, $row->category, $row->status, (int) $row->current_version]);

        $this->assertSame(1, Artisan::call('simulations:import', ['file' => $file, '--tenant' => 6]), 'the same id again is refused');
        file_put_contents($file, $this->spec('from-file', 'From a file, edited', ['category' => 'Engineering']));
        $this->assertSame(0, Artisan::call('simulations:import', ['file' => $file, '--tenant' => 6, '--update' => true]));
        $this->assertSame(2, (int) DB::table('g2g_simulation')->value('current_version'));

        $this->assertSame(0, Artisan::call('simulations:import', ['file' => $file, '--platform' => true]));
        $this->assertNull(DB::table('g2g_simulation')->where('id', 2)->value('sub_institute_id'));
        $this->assertSame(2, Artisan::call('simulations:import', ['file' => $file]), 'an owner is required');
        @unlink($file);
    }

    public function test_re_importing_with_update_leaves_the_status_alone_unless_it_is_asked_to_change_it(): void
    {
        $file = $this->specFile($this->spec('from-file', 'From a file'));

        $this->assertSame(0, Artisan::call('simulations:import', ['file' => $file, '--tenant' => 6, '--status' => 'draft']));
        $this->assertSame('draft', DB::table('g2g_simulation')->value('status'));

        file_put_contents($file, $this->spec('from-file', 'From a file, edited'));
        $this->assertSame(0, Artisan::call('simulations:import', ['file' => $file, '--tenant' => 6, '--update' => true]));
        $row = DB::table('g2g_simulation')->first();
        $this->assertSame(['draft', 2], [$row->status, (int) $row->current_version], 'a new version, and still a draft');

        $this->assertSame(0, Artisan::call('simulations:import', ['file' => $file, '--tenant' => 6, '--update' => true, '--status' => 'archived']));
        $this->assertSame('archived', DB::table('g2g_simulation')->value('status'), 'asked for, so changed');

        $this->assertSame(0, Artisan::call('simulations:import', ['file' => $file, '--tenant' => 7]));
        $this->assertSame('published', DB::table('g2g_simulation')->where('sub_institute_id', 7)->value('status'), 'a new one is still published unless it is said otherwise');
        @unlink($file);
    }

    public function test_the_grant_command_gives_the_learners_of_one_organisation_view_and_can_undo_it(): void
    {
        DB::table('tbluserprofilemaster')->where('id', self::EMPLOYEE)->update(['sub_institute_id' => self::T1]);
        DB::table('tbluserprofilemaster')->where('id', self::ADMIN)->update(['sub_institute_id' => self::T1]);

        $this->assertSame(0, Artisan::call('simulations:grant', ['--tenant' => self::T1, '--dry-run' => true]));
        $this->assertSame(1, DB::table('tblgroupwise_rights_g2g')->where('menu_id', $this->menuId)->count(), 'a dry run changes nothing');

        $this->assertSame(0, Artisan::call('simulations:grant', ['--tenant' => self::T1]));
        $employee = DB::table('tblgroupwise_rights_g2g')->where('menu_id', $this->menuId)->where('profile_id', self::EMPLOYEE)->first();
        $this->assertSame([1, 0, 0, 0], [(int) $employee->can_view, (int) $employee->can_add, (int) $employee->can_edit, (int) $employee->can_delete], 'view only');
        $this->assertSame(2, DB::table('tblgroupwise_rights_g2g')->where('menu_id', $this->menuId)->count(), 'the administrator already had it and is not duplicated');

        $this->assertSame(0, Artisan::call('simulations:grant', ['--tenant' => self::T1]));
        $this->assertSame(2, DB::table('tblgroupwise_rights_g2g')->where('menu_id', $this->menuId)->count(), 'running it again adds nothing');

        $this->assertSame(0, Artisan::call('simulations:grant', ['--tenant' => self::T1, '--revoke' => true]));
        $this->assertSame([self::ADMIN], DB::table('tblgroupwise_rights_g2g')->where('menu_id', $this->menuId)->pluck('profile_id')->map(fn ($i) => (int) $i)->all(), 'the author grant is untouched');
        $this->assertSame(2, Artisan::call('simulations:grant', []), 'a tenant is required');
    }
}
