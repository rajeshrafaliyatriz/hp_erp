<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Organisation 360 menu leaf and its grant command (slice 3, D8). Same pattern as the Product Master and Signal Review leaves: the migration
 * only creates the row, the command grants the LEAF to one organisation's administrators and never an ancestor.
 */
class OrgAccountsGrantTest extends TestCase
{
    private const T = 1;

    private const T2 = 2;

    private const ROOT = '/module/crm';

    private const LEAF = '/module/crm/organisation-360';

    private const MENU = '2026_12_09_090200_create_organisation_360_menu_row';

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
        // The CRM root and two pages already under it, as on a real host.
        DB::table('tblmenumaster_g2g')->insert([
            ['id' => 199, 'menu_name' => 'Sales & Marketing Intelligence', 'parent_id' => 0, 'level' => 1, 'access_link' => self::ROOT, 'sort_order' => 1],
            ['id' => 300, 'menu_name' => 'Products', 'parent_id' => 199, 'level' => 2, 'access_link' => '/module/crm/sales/products', 'sort_order' => 2],
            ['id' => 301, 'menu_name' => 'Product Master', 'parent_id' => 199, 'level' => 2, 'access_link' => '/module/crm/product-master', 'sort_order' => 3],
        ]);
        foreach ([1, 2, 3, 4, 5] as $profile) {
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $profile === 3 ? self::T2 : self::T, 'menu_id' => 199, 'profile_id' => $profile, 'can_view' => 1]);
        }
        DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => self::T, 'menu_id' => 301, 'profile_id' => 1, 'can_view' => 1]);

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

    public function test_the_migration_creates_one_level_two_leaf_under_the_crm_root_beside_the_other_pages(): void
    {
        $leaf = $this->leaf();

        $this->assertNotNull($leaf);
        $this->assertSame('Organisation 360', $leaf->menu_name);
        $this->assertSame(199, (int) $leaf->parent_id, 'under the CRM root found by its access_link');
        $this->assertSame(2, (int) $leaf->level);
        $this->assertSame('page', $leaf->page_type);
        $this->assertSame(1, (int) $leaf->status);
        $this->assertSame(4, (int) $leaf->sort_order, 'after the pages already there');
        $this->assertSame(4, DB::table('tblmenumaster_g2g')->count(), 'the root, the two pages already there and the leaf: nothing else was added or changed');
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

    public function test_the_migration_does_nothing_without_the_crm_root_or_the_menu_table(): void
    {
        DB::table('tblmenumaster_g2g')->where('access_link', self::LEAF)->delete();
        DB::table('tblmenumaster_g2g')->where('access_link', self::ROOT)->delete();
        $this->migration(self::MENU)->up();
        $this->assertNull($this->leaf(), 'it never creates the CRM root itself');

        Schema::drop('tblmenumaster_g2g');
        $this->migration(self::MENU)->up();
        $this->migration(self::MENU)->down();
        $this->assertFalse(Schema::hasTable('tblmenumaster_g2g'));
    }

    public function test_down_removes_the_leaf_and_its_rights_and_nothing_else(): void
    {
        $this->artisan('org-accounts:grant', ['--tenant' => self::T, '--apply' => true])->assertExitCode(0);
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

        $this->artisan('org-accounts:grant', ['--tenant' => self::T])
            ->expectsOutputToContain('DRY RUN')->expectsOutputToContain('profile 1: would grant')->expectsOutputToContain('profile 4: would grant')
            ->expectsOutputToContain('Dry run: nothing was written (2 profile(s) would change)')->assertExitCode(0);

        $this->assertSame($before, DB::table('tblgroupwise_rights_g2g')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
    }

    public function test_the_grant_gives_only_that_organisations_administrators_the_leaf_and_nothing_above_or_beside_it(): void
    {
        $others = $this->rightsExceptLeaf();

        $this->artisan('org-accounts:grant', ['--tenant' => self::T, '--apply' => true])->expectsOutputToContain('granted')->assertExitCode(0);

        $this->assertSame([1, 4], $this->leafProfiles(), 'administrators by role_key and by exact legacy name; not the employee, not the assistant, not organisation 2');
        foreach (DB::table('tblgroupwise_rights_g2g')->where('menu_id', $this->leaf()->id)->get() as $row) {
            $this->assertSame([1, 1, 1, 1], [(int) $row->can_view, (int) $row->can_add, (int) $row->can_edit, (int) $row->can_delete]);
        }
        $this->assertSame($others, $this->rightsExceptLeaf(), 'neither the CRM root nor any sibling page got a single change');
    }

    public function test_an_administrator_who_cannot_see_the_root_is_left_alone_and_the_root_is_never_granted(): void
    {
        DB::table('tbluserprofilemaster')->insert(['id' => 6, 'name' => 'Admin', 'role_key' => 'administrator', 'sub_institute_id' => self::T]);
        $before = DB::table('tblgroupwise_rights_g2g')->count();

        $this->artisan('org-accounts:grant', ['--tenant' => self::T, '--apply' => true])
            ->expectsOutputToContain("profile 6: cannot see 'Sales & Marketing Intelligence'")->assertExitCode(0);

        $this->assertSame([1, 4], $this->leafProfiles(), 'profile 6 got nothing');
        $this->assertSame($before + 2, DB::table('tblgroupwise_rights_g2g')->count(), 'only the two leaf grants were added: no ancestor row was granted to make the leaf appear');
        $this->assertSame(0, DB::table('tblgroupwise_rights_g2g')->where('profile_id', 6)->count());
    }

    public function test_repeating_the_grant_adds_nothing(): void
    {
        $this->artisan('org-accounts:grant', ['--tenant' => self::T, '--apply' => true])->assertExitCode(0);
        $count = DB::table('tblgroupwise_rights_g2g')->count();

        $this->artisan('org-accounts:grant', ['--tenant' => self::T, '--apply' => true])->expectsOutputToContain('already has the screen')->assertExitCode(0);

        $this->assertSame($count, DB::table('tblgroupwise_rights_g2g')->count());
    }

    public function test_an_existing_leaf_row_is_found_by_profile_and_menu_whatever_its_organisation_column_holds(): void
    {
        DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => '9,10', 'menu_id' => $this->leaf()->id, 'profile_id' => 1, 'can_view' => 0]);

        $this->artisan('org-accounts:grant', ['--tenant' => self::T, '--apply' => true])->assertExitCode(0);

        $this->assertSame([1, 4], $this->leafProfiles(), 'profile 1 has one row, updated in place, not a second one');
        $this->assertSame(1, (int) DB::table('tblgroupwise_rights_g2g')->where('menu_id', $this->leaf()->id)->where('profile_id', 1)->value('can_view'));
    }

    public function test_revoke_removes_only_the_leaf_grants_of_that_organisation(): void
    {
        $this->artisan('org-accounts:grant', ['--tenant' => self::T, '--apply' => true])->assertExitCode(0);
        $other = DB::table('tblgroupwise_rights_g2g')->insertGetId(['sub_institute_id' => self::T2, 'menu_id' => $this->leaf()->id, 'profile_id' => 3, 'can_view' => 1]);
        $others = $this->rightsExceptLeaf();

        $this->artisan('org-accounts:grant', ['--tenant' => self::T, '--revoke' => true])->expectsOutputToContain('would remove')->assertExitCode(0);
        $this->assertSame([1, 3, 4], $this->leafProfiles(), 'a revoke dry run removes nothing');

        $this->artisan('org-accounts:grant', ['--tenant' => self::T, '--revoke' => true, '--apply' => true])->assertExitCode(0);

        $this->assertSame([3], $this->leafProfiles(), "organisation 2's grant stays");
        $this->assertSame($others, $this->rightsExceptLeaf());
        $this->assertNotNull(DB::table('tblgroupwise_rights_g2g')->where('id', $other)->first());
    }

    public function test_the_grant_command_refuses_bad_input_and_a_missing_menu_row(): void
    {
        $this->artisan('org-accounts:grant')->assertExitCode(2);
        $this->artisan('org-accounts:grant', ['--tenant' => 'abc'])->assertExitCode(2);
        $this->artisan('org-accounts:grant', ['--tenant' => '0'])->assertExitCode(2);
        $this->artisan('org-accounts:grant', ['--tenant' => self::T, '--database' => 'no_such_connection'])->assertExitCode(2);
        $this->artisan('org-accounts:grant', ['--tenant' => self::T, '--database' => 'sqlite'])->assertExitCode(0);

        $this->migration(self::MENU)->down();
        $this->artisan('org-accounts:grant', ['--tenant' => self::T, '--apply' => true])->expectsOutputToContain('menu migration first')->assertExitCode(1);
    }

    public function test_an_organisation_without_an_administrator_profile_gets_a_clear_message(): void
    {
        $this->artisan('org-accounts:grant', ['--tenant' => 99, '--apply' => true])->expectsOutputToContain('No administrator profile')->assertExitCode(0);

        $this->assertSame([], $this->leafProfiles());
    }
}
