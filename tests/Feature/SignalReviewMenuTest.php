<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Signal Review menu leaf and its grant command (slice 2 of the agentic CRM, Docs/agentic-crm/slices/S02-contract.md, "Menu").
 * The Signals branch comes from the real menu migration of 2026-11-29, so the leaf is placed under the rows that exist today.
 */
class SignalReviewMenuTest extends TestCase
{
    private const T = 1;

    private const T2 = 2;

    private const ROOT = '/module/crm';

    private const PARENT = '/module/crm/signals';

    private const LEAF = '/signal-review';

    private const SIGNALS_MENU = '2026_11_29_080200_add_signals_menu_branch';

    private const MENU = '2026_12_08_090300_create_signal_review_menu_row';

    private int $rightsBeforeLeaf = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('tblmenumaster_g2g', function ($t) {
            $t->id();
            $t->string('menu_name');
            $t->unsignedBigInteger('parent_id')->default(0);
            $t->integer('level')->default(1);
            $t->string('page_type')->nullable();
            $t->string('access_link')->nullable();
            $t->string('icon')->nullable();
            $t->integer('status')->default(1);
            $t->integer('sort_order')->default(0);
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('menu_type')->nullable();
            $t->timestamps();
        });
        // The real column holds an id, NULL or a comma-separated list, so it is text here too.
        Schema::create('tblgroupwise_rights_g2g', function ($t) {
            $t->id();
            $t->string('sub_institute_id')->nullable();
            $t->unsignedBigInteger('menu_id');
            $t->unsignedBigInteger('profile_id');
            $t->tinyInteger('can_view')->default(0);
            $t->tinyInteger('can_add')->default(0);
            $t->tinyInteger('can_edit')->default(0);
            $t->tinyInteger('can_delete')->default(0);
            $t->tinyInteger('dashboard_right')->default(0);
            $t->tinyInteger('is_mobile')->default(0);
            $t->timestamp('created_at')->nullable();
        });

        // Organisation 1: two administrators (one by role_key, one only by its exact legacy name, role_key NULL as on the app host), an employee, and
        // a profile whose name merely STARTS like an administrator's. Organisation 2: an administrator.
        DB::table('tbluserprofilemaster')->insert([
            ['id' => 1, 'name' => 'Admin', 'role_key' => 'administrator', 'sub_institute_id' => self::T],
            ['id' => 2, 'name' => 'Employee', 'role_key' => 'employee', 'sub_institute_id' => self::T],
            ['id' => 3, 'name' => 'Admin', 'role_key' => 'administrator', 'sub_institute_id' => self::T2],
            ['id' => 4, 'name' => 'Administrator', 'role_key' => null, 'sub_institute_id' => self::T],
            ['id' => 5, 'name' => 'Administrator Assistant', 'role_key' => null, 'sub_institute_id' => self::T],
        ]);
        DB::table('tblmenumaster_g2g')->insert(['id' => 199, 'menu_name' => 'Sales & Marketing Intelligence', 'parent_id' => 0, 'level' => 1, 'access_link' => self::ROOT, 'sort_order' => 1]);
        foreach ([1, 2, 3, 4, 5] as $profile) {
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $profile === 3 ? self::T2 : self::T, 'menu_id' => 199, 'profile_id' => $profile, 'can_view' => 1]);
        }
        $this->migration(self::SIGNALS_MENU)->up();   // the Signals branch and its four pages, granted to administrators (profiles 1, 3 and 4)

        $this->rightsBeforeLeaf = DB::table('tblgroupwise_rights_g2g')->count();
        $this->migration(self::MENU)->up();
    }

    private function migration(string $name): object
    {
        return require base_path("database/migrations/{$name}.php");
    }

    private function leaf(): ?object
    {
        return DB::table('tblmenumaster_g2g')->where('access_link', self::LEAF)->first();
    }

    private function parentId(): int
    {
        return (int) DB::table('tblmenumaster_g2g')->where('access_link', self::PARENT)->value('id');
    }

    /** @return list<int> */
    private function leafProfiles(): array
    {
        return DB::table('tblgroupwise_rights_g2g')->where('menu_id', $this->leaf()->id)->orderBy('profile_id')->pluck('profile_id')->map(fn ($p) => (int) $p)->all();
    }

    private function rightsExceptLeaf(): array
    {
        return DB::table('tblgroupwise_rights_g2g')->where('menu_id', '<>', $this->leaf()->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    // ---- the migration ----

    public function test_the_migration_creates_one_leaf_under_the_existing_signals_row_beside_the_old_screens(): void
    {
        $leaf = $this->leaf();

        $this->assertNotNull($leaf);
        $this->assertSame('Signal Review', $leaf->menu_name);
        $this->assertSame($this->parentId(), (int) $leaf->parent_id, 'under the Signals row found by its access_link');
        $this->assertSame(3, (int) $leaf->level, 'one level below the Signals row, like Company Opportunities');
        $this->assertSame('page', $leaf->page_type);
        $this->assertSame(1, (int) $leaf->status);
        $this->assertSame(5, (int) $leaf->sort_order, 'after the four existing pages (1 to 4)');
        $this->assertSame(4, DB::table('tblmenumaster_g2g')->where('parent_id', $this->parentId())->where('id', '<>', $leaf->id)->count(), 'the old screens are all still there');
        $this->assertSame(1, DB::table('tblmenumaster_g2g')->where('access_link', '/signals')->count());
    }

    public function test_the_migration_grants_nobody_anything(): void
    {
        $this->assertSame([], $this->leafProfiles());
        $this->assertSame($this->rightsBeforeLeaf, DB::table('tblgroupwise_rights_g2g')->count(), 'no rights row was added anywhere');
    }

    public function test_running_the_migration_twice_creates_no_second_row(): void
    {
        $this->migration(self::MENU)->up();
        $this->migration(self::MENU)->up();

        $this->assertSame(1, DB::table('tblmenumaster_g2g')->where('access_link', self::LEAF)->count());
    }

    public function test_the_migration_does_nothing_without_the_signals_row_or_the_menu_table(): void
    {
        DB::table('tblmenumaster_g2g')->where('access_link', self::LEAF)->delete();
        DB::table('tblmenumaster_g2g')->where('access_link', self::PARENT)->delete();
        $this->migration(self::MENU)->up();
        $this->assertNull($this->leaf(), 'it never creates the Signals row itself');

        Schema::drop('tblmenumaster_g2g');
        $this->migration(self::MENU)->up();
        $this->migration(self::MENU)->down();
        $this->assertFalse(Schema::hasTable('tblmenumaster_g2g'));
    }

    public function test_down_removes_the_leaf_and_its_rights_and_nothing_else(): void
    {
        $this->artisan('signals:grant-review', ['--tenant' => self::T, '--apply' => true])->assertExitCode(0);
        $others = $this->rightsExceptLeaf();
        $menuRows = DB::table('tblmenumaster_g2g')->count();
        $leafId = $this->leaf()->id;

        $this->migration(self::MENU)->down();

        $this->assertNull($this->leaf());
        $this->assertSame(0, DB::table('tblgroupwise_rights_g2g')->where('menu_id', $leafId)->count());
        $this->assertSame($menuRows - 1, DB::table('tblmenumaster_g2g')->count());
        $this->assertSame($others, DB::table('tblgroupwise_rights_g2g')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'every other rights row is untouched');
        $this->migration(self::MENU)->down();
    }

    // ---- the grant command ----

    public function test_the_grant_command_is_a_dry_run_by_default_and_writes_nothing(): void
    {
        $before = DB::table('tblgroupwise_rights_g2g')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->artisan('signals:grant-review', ['--tenant' => self::T])
            ->expectsOutputToContain('DRY RUN')->expectsOutputToContain('profile 1: would grant')->expectsOutputToContain('profile 4: would grant')
            ->expectsOutputToContain('Dry run: nothing was written (2 profile(s) would change)')->assertExitCode(0);

        $this->assertSame($before, DB::table('tblgroupwise_rights_g2g')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
    }

    public function test_the_grant_gives_only_that_organisations_administrators_the_leaf_and_nothing_above_it(): void
    {
        $above = $this->rightsExceptLeaf();

        $this->artisan('signals:grant-review', ['--tenant' => self::T, '--apply' => true])->expectsOutputToContain('granted')->assertExitCode(0);

        $this->assertSame([1, 4], $this->leafProfiles(), 'administrators by role_key and by exact legacy name; not the employee, not the assistant, not organisation 2');
        foreach (DB::table('tblgroupwise_rights_g2g')->where('menu_id', $this->leaf()->id)->get() as $row) {
            $this->assertSame([1, 1, 1, 1], [(int) $row->can_view, (int) $row->can_add, (int) $row->can_edit, (int) $row->can_delete]);
        }
        $this->assertSame($above, $this->rightsExceptLeaf(), 'neither the Signals row, the Sales & Marketing root nor any sibling got a single change');
    }

    public function test_repeating_the_grant_adds_nothing(): void
    {
        $this->artisan('signals:grant-review', ['--tenant' => self::T, '--apply' => true])->assertExitCode(0);
        $count = DB::table('tblgroupwise_rights_g2g')->count();

        $this->artisan('signals:grant-review', ['--tenant' => self::T, '--apply' => true])->expectsOutputToContain('already has the screen')->assertExitCode(0);

        $this->assertSame($count, DB::table('tblgroupwise_rights_g2g')->count());
    }

    public function test_an_existing_leaf_row_is_found_by_profile_and_menu_whatever_its_organisation_column_holds(): void
    {
        DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => '9,10', 'menu_id' => $this->leaf()->id, 'profile_id' => 1, 'can_view' => 0]);

        $this->artisan('signals:grant-review', ['--tenant' => self::T, '--apply' => true])->assertExitCode(0);

        $this->assertSame([1, 4], $this->leafProfiles(), 'profile 1 has one row, updated in place, not a second one');
        $this->assertSame(1, (int) DB::table('tblgroupwise_rights_g2g')->where('menu_id', $this->leaf()->id)->where('profile_id', 1)->value('can_view'));
    }

    public function test_an_administrator_who_cannot_see_everything_above_the_leaf_is_left_alone(): void
    {
        // Profile 6 sees the root but not Signals; profile 7 sees Signals but not the root.
        DB::table('tbluserprofilemaster')->insert([
            ['id' => 6, 'name' => 'Admin', 'role_key' => 'administrator', 'sub_institute_id' => self::T],
            ['id' => 7, 'name' => 'Admin', 'role_key' => 'administrator', 'sub_institute_id' => self::T],
        ]);
        DB::table('tblgroupwise_rights_g2g')->insert([
            ['sub_institute_id' => self::T, 'menu_id' => 199, 'profile_id' => 6, 'can_view' => 1],
            ['sub_institute_id' => self::T, 'menu_id' => $this->parentId(), 'profile_id' => 7, 'can_view' => 1],
        ]);
        $before = DB::table('tblgroupwise_rights_g2g')->count();

        $this->artisan('signals:grant-review', ['--tenant' => self::T, '--apply' => true])
            ->expectsOutputToContain("profile 6: cannot see 'Signals'")->expectsOutputToContain("profile 7: cannot see 'Sales & Marketing Intelligence'")->assertExitCode(0);

        $this->assertSame([1, 4], $this->leafProfiles());
        $this->assertSame($before + 2, DB::table('tblgroupwise_rights_g2g')->count(), 'only the two leaf grants were added: the missing rows above the leaf were not granted to make it appear');
        $this->assertSame(0, DB::table('tblgroupwise_rights_g2g')->where('profile_id', 6)->where('menu_id', $this->parentId())->count());
        $this->assertSame(0, DB::table('tblgroupwise_rights_g2g')->where('profile_id', 7)->where('menu_id', 199)->count());
    }

    public function test_revoke_removes_only_the_leaf_grants_of_that_organisation(): void
    {
        $this->artisan('signals:grant-review', ['--tenant' => self::T, '--apply' => true])->assertExitCode(0);
        $other = DB::table('tblgroupwise_rights_g2g')->insertGetId(['sub_institute_id' => self::T2, 'menu_id' => $this->leaf()->id, 'profile_id' => 3, 'can_view' => 1]);
        $above = $this->rightsExceptLeaf();

        $this->artisan('signals:grant-review', ['--tenant' => self::T, '--revoke' => true])->expectsOutputToContain('would remove')->assertExitCode(0);
        $this->assertSame([1, 3, 4], $this->leafProfiles(), 'a revoke dry run removes nothing');

        $this->artisan('signals:grant-review', ['--tenant' => self::T, '--revoke' => true, '--apply' => true])->assertExitCode(0);

        $this->assertSame([3], $this->leafProfiles(), "organisation 2's grant stays");
        $this->assertSame($above, $this->rightsExceptLeaf());
        $this->assertNotNull(DB::table('tblgroupwise_rights_g2g')->where('id', $other)->first());
    }

    public function test_the_grant_command_refuses_bad_input_and_a_missing_menu_row(): void
    {
        $this->artisan('signals:grant-review')->assertExitCode(2);
        $this->artisan('signals:grant-review', ['--tenant' => 'abc'])->assertExitCode(2);
        $this->artisan('signals:grant-review', ['--tenant' => '0'])->assertExitCode(2);
        $this->artisan('signals:grant-review', ['--tenant' => self::T, '--database' => 'no_such_connection'])->assertExitCode(2);
        $this->artisan('signals:grant-review', ['--tenant' => self::T, '--database' => 'sqlite'])->assertExitCode(0);

        $this->migration(self::MENU)->down();
        $this->artisan('signals:grant-review', ['--tenant' => self::T, '--apply' => true])->expectsOutputToContain('menu migration first')->assertExitCode(1);
    }

    public function test_an_organisation_without_an_administrator_profile_gets_a_clear_message(): void
    {
        $this->artisan('signals:grant-review', ['--tenant' => 99, '--apply' => true])->expectsOutputToContain('No administrator profile')->assertExitCode(0);

        $this->assertSame([], $this->leafProfiles());
    }
}
