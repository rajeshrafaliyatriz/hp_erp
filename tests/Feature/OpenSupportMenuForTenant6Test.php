<?php

namespace Tests\Feature;

use App\Models\tblmenumaster_g2gModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_12_25_090000_open_support_menu_for_tenant_6: the hidden Support root is opened for organisation 6 only, and only organisation 6's
 * Administrator is granted. Runs the migration itself on in-memory sqlite; nothing touches a real host.
 */
class OpenSupportMenuForTenant6Test extends TestCase
{
    private const FILE = 'database/migrations/2026_12_25_090000_open_support_menu_for_tenant_6.php';

    private const ROOT = 456;

    private const TICKETS = 457;

    private const FAQ = 458;

    protected function setUp(): void
    {
        parent::setUp();

        // visibleToTenant() uses MySQL's FIND_IN_SET: 1-based position of the whole item in a comma list, 0 when absent, NULL for a NULL list.
        DB::connection()->getPdo()->sqliteCreateFunction(
            'FIND_IN_SET',
            fn ($needle, $list) => $list === null ? null : (($i = array_search((string) $needle, explode(',', (string) $list), true)) === false ? 0 : $i + 1),
            2
        );

        Schema::create('tblmenumaster_g2g', function ($t) {
            $t->id();
            $t->string('menu_name');
            $t->unsignedBigInteger('parent_id')->default(0);
            $t->integer('level')->default(1);
            $t->string('access_link')->nullable();
            $t->tinyInteger('status')->default(1);
            $t->integer('sort_order')->default(0);
            $t->text('sub_institute_id')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
        });
        Schema::create('tblgroupwise_rights_g2g', function ($t) {
            $t->id();
            $t->text('sub_institute_id')->nullable();
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

        DB::table('tblmenumaster_g2g')->insert([
            ['id' => self::ROOT, 'menu_name' => 'Support', 'parent_id' => 0, 'level' => 1, 'access_link' => '/module/crm/support', 'status' => 0, 'sub_institute_id' => ''],
            ['id' => self::TICKETS, 'menu_name' => 'Tickets', 'parent_id' => self::ROOT, 'level' => 2, 'access_link' => '/module/crm/support/tickets', 'status' => 1, 'sub_institute_id' => ''],
            ['id' => self::FAQ, 'menu_name' => 'FAQ', 'parent_id' => self::ROOT, 'level' => 2, 'access_link' => '/module/crm/support/faq', 'status' => 1, 'sub_institute_id' => ''],
            ['id' => 460, 'menu_name' => 'Hidden leaf', 'parent_id' => self::ROOT, 'level' => 2, 'access_link' => '/module/crm/support/hidden', 'status' => 0, 'sub_institute_id' => ''],
        ]);
        DB::table('tbluserprofilemaster')->insert([
            ['id' => 1, 'name' => 'Admin', 'role_key' => 'administrator', 'sub_institute_id' => 1],
            ['id' => 16, 'name' => 'Admin', 'role_key' => 'administrator', 'sub_institute_id' => 6],
            ['id' => 17, 'name' => 'Employee', 'role_key' => 'employee', 'sub_institute_id' => 6],
            ['id' => 60, 'name' => 'Admin', 'role_key' => null, 'sub_institute_id' => 6],   // legacy profile: the name says administrator
        ]);
        // Organisation 1's Administrator already holds the Support rights, as on the real hosts.
        foreach ([self::ROOT, self::TICKETS, self::FAQ] as $menuId) {
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => '1', 'menu_id' => $menuId, 'profile_id' => 1, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1]);
        }
    }

    private function migration(): object
    {
        return require base_path(self::FILE);
    }

    private function rightsFor(int $profileId): array
    {
        return DB::table('tblgroupwise_rights_g2g')->where('profile_id', $profileId)->orderBy('menu_id')->pluck('menu_id')->map(fn ($id) => (int) $id)->all();
    }

    private function visibleRootIds(int $tenant): array
    {
        return tblmenumaster_g2gModel::where('status', 1)->where('parent_id', 0)->visibleToTenant($tenant)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_the_hidden_root_opens_for_organisation_6_only_and_only_its_administrators_are_granted(): void
    {
        $this->assertSame([], $this->visibleRootIds(6));

        $this->migration()->up();

        $root = DB::table('tblmenumaster_g2g')->where('id', self::ROOT)->first();
        $this->assertSame(1, (int) $root->status);
        $this->assertSame('6', (string) $root->sub_institute_id);

        $this->assertSame([self::ROOT], $this->visibleRootIds(6), 'organisation 6 sees Support');
        $this->assertSame([], $this->visibleRootIds(1), 'organisation 1 holds the rights but must not see the root');
        $this->assertSame([], $this->visibleRootIds(16), 'FIND_IN_SET is a list match, not a prefix match: tenant 16 is not tenant 6');

        // The root and the two visible leaves, for both tenant-6 administrators; the hidden leaf is skipped.
        $this->assertSame([self::ROOT, self::TICKETS, self::FAQ], $this->rightsFor(16));
        $this->assertSame([self::ROOT, self::TICKETS, self::FAQ], $this->rightsFor(60));
        $this->assertSame([], $this->rightsFor(17), 'the Employee profile is not granted');
        $this->assertSame([self::ROOT, self::TICKETS, self::FAQ], $this->rightsFor(1), 'organisation 1 keeps what it had, nothing more');

        $grant = DB::table('tblgroupwise_rights_g2g')->where('profile_id', 16)->where('menu_id', self::TICKETS)->first();
        $this->assertSame([1, 1, 1, 1], [(int) $grant->can_view, (int) $grant->can_add, (int) $grant->can_edit, (int) $grant->can_delete]);
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->migration()->up();
        $rights = DB::table('tblgroupwise_rights_g2g')->count();
        $root = (array) DB::table('tblmenumaster_g2g')->where('id', self::ROOT)->first();

        $this->migration()->up();

        $this->assertSame($rights, DB::table('tblgroupwise_rights_g2g')->count());
        $this->assertSame($root['sub_institute_id'], DB::table('tblmenumaster_g2g')->where('id', self::ROOT)->value('sub_institute_id'));
    }

    public function test_a_root_that_is_already_open_for_everyone_is_never_narrowed(): void
    {
        DB::table('tblmenumaster_g2g')->where('id', self::ROOT)->update(['status' => 1, 'sub_institute_id' => '']);

        $this->migration()->up();

        $this->assertSame('', (string) DB::table('tblmenumaster_g2g')->where('id', self::ROOT)->value('sub_institute_id'));
        $this->assertSame([self::ROOT], $this->visibleRootIds(1), 'still open for organisation 1');
        $this->assertSame([self::ROOT, self::TICKETS, self::FAQ], $this->rightsFor(16), 'organisation 6 is still granted');
    }

    public function test_down_hides_the_root_again_and_removes_only_what_up_granted(): void
    {
        $this->migration()->up();
        $this->migration()->down();

        $root = DB::table('tblmenumaster_g2g')->where('id', self::ROOT)->first();
        $this->assertSame(0, (int) $root->status);
        $this->assertSame('', (string) $root->sub_institute_id);
        $this->assertSame([], $this->rightsFor(16));
        $this->assertSame([], $this->rightsFor(60));
        $this->assertSame([self::ROOT, self::TICKETS, self::FAQ], $this->rightsFor(1), 'organisation 1 is untouched');
    }

    public function test_a_host_without_the_support_branch_is_left_alone(): void
    {
        DB::table('tblmenumaster_g2g')->delete();

        $this->migration()->up();

        $this->assertSame(0, DB::table('tblmenumaster_g2g')->count());
        $this->assertSame([], $this->rightsFor(16));
    }
}
