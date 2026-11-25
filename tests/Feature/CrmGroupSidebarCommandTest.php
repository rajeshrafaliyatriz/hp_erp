<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `php artisan crm:group-sidebar` (25 Nov 2026): the CRM sidebar is grouped into GTM, Marketing and Sales folders, with a before-image and a way back.
 *
 * Proved here: a dry run writes nothing; --apply moves each listed leaf under its folder (level, order), enables the three folders, adds the folder right ONLY for a profile that can
 * view the root and a leaf of the folder, never touches the Support rows or a row it does not list, is repeatable (a second run changes nothing); --rollback puts the menu rows back
 * and removes exactly the rights it added.
 */
class CrmGroupSidebarCommandTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'group-sb-' . bin2hex(random_bytes(4));
        File::makeDirectory($this->storage . '/app', 0775, true);
        $this->app->useStoragePath($this->storage);

        Schema::create('tblmenumaster_g2g', function ($t) {
            $t->id();
            $t->string('menu_name');
            $t->unsignedBigInteger('parent_id')->default(0);
            $t->integer('level')->default(1);
            $t->string('access_link')->nullable();
            $t->integer('status')->default(1);
            $t->integer('sort_order')->default(0);
        });
        Schema::create('tblgroupwise_rights_g2g', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('menu_id');
            $t->unsignedBigInteger('profile_id');
            $t->integer('can_view')->default(0);
            $t->integer('can_add')->default(0);
            $t->integer('can_edit')->default(0);
            $t->integer('can_delete')->default(0);
            $t->integer('dashboard_right')->default(0);
            $t->string('created_at')->nullable();
        });
        DB::table('tblmenumaster_g2g')->insert([
            ['id' => 199, 'menu_name' => 'Sales & Marketing Intelligence', 'parent_id' => 0, 'level' => 1, 'access_link' => '/module/crm', 'status' => 1, 'sort_order' => 1],
            ['id' => 438, 'menu_name' => 'GTM Agents', 'parent_id' => 199, 'level' => 2, 'access_link' => '/gtm', 'status' => 0, 'sort_order' => 3],
            ['id' => 200, 'menu_name' => 'Marketing', 'parent_id' => 199, 'level' => 2, 'access_link' => '/module/crm/marketing', 'status' => 0, 'sort_order' => 1],
            ['id' => 446, 'menu_name' => 'Sales', 'parent_id' => 199, 'level' => 2, 'access_link' => '/module/crm/sales', 'status' => 0, 'sort_order' => 2],
            ['id' => 439, 'menu_name' => 'Command Center', 'parent_id' => 199, 'level' => 2, 'access_link' => '/gtm/command-center', 'status' => 1, 'sort_order' => 10],
            ['id' => 476, 'menu_name' => 'Market Signals', 'parent_id' => 199, 'level' => 2, 'access_link' => '/signals', 'status' => 1, 'sort_order' => 11],
            ['id' => 464, 'menu_name' => 'Forecast', 'parent_id' => 199, 'level' => 2, 'access_link' => '/gtm/forecast', 'status' => 1, 'sort_order' => 31],
            ['id' => 201, 'menu_name' => 'Leads', 'parent_id' => 199, 'level' => 2, 'access_link' => '/module/crm/marketing/leads', 'status' => 1, 'sort_order' => 20],
            ['id' => 447, 'menu_name' => 'Opportunities & Deals', 'parent_id' => 199, 'level' => 2, 'access_link' => '/module/crm/sales/opportunities', 'status' => 1, 'sort_order' => 30],
            ['id' => 456, 'menu_name' => 'Support', 'parent_id' => 0, 'level' => 1, 'access_link' => '/module/crm/support', 'status' => 1, 'sort_order' => 8],
            ['id' => 463, 'menu_name' => 'Organizations', 'parent_id' => 456, 'level' => 2, 'access_link' => '/module/crm/support/organizations', 'status' => 1, 'sort_order' => 7],
            ['id' => 443, 'menu_name' => 'Agents', 'parent_id' => 199, 'level' => 2, 'access_link' => '/gtm/agents', 'status' => 0, 'sort_order' => 23],
        ]);
        // Profile 10 can view the root and leaves of all three groups; profile 11 only the root and Leads; profile 12 has a leaf right but NO right on the root; profile 13 already holds the Sales folder.
        $r = fn (int $id, int $menu, int $profile, int $view = 1) => ['id' => $id, 'sub_institute_id' => 6, 'menu_id' => $menu, 'profile_id' => $profile, 'can_view' => $view, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1, 'dashboard_right' => 0, 'created_at' => '2026-10-09 12:00:00'];
        DB::table('tblgroupwise_rights_g2g')->insert([
            $r(1, 199, 10), $r(2, 476, 10), $r(3, 201, 10), $r(4, 447, 10),
            $r(5, 199, 11), $r(6, 201, 11),
            $r(7, 476, 12),
            $r(8, 199, 13), $r(9, 447, 13), $r(10, 446, 13),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function menu(): array
    {
        return DB::table('tblmenumaster_g2g')->orderBy('id')->get()->map(fn ($x) => (array) $x)->all();
    }

    private function rights(): array
    {
        return DB::table('tblgroupwise_rights_g2g')->orderBy('id')->get()->map(fn ($x) => (array) $x)->all();
    }

    private function groupSidebar(array $options = []): string
    {
        Artisan::call('crm:group-sidebar', $options);

        return Artisan::output();
    }

    private function folderViewers(int $folder): array
    {
        return DB::table('tblgroupwise_rights_g2g')->where('menu_id', $folder)->where('can_view', 1)->orderBy('profile_id')->pluck('profile_id')->map(fn ($p) => (int) $p)->all();
    }

    public function test_a_dry_run_prints_the_plan_and_writes_nothing(): void
    {
        $menu = $this->menu();
        $rights = $this->rights();

        $out = $this->groupSidebar();

        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('Nothing was written', $out);
        $this->assertSame($menu, $this->menu());
        $this->assertSame($rights, $this->rights());
        $this->assertSame([], glob($this->storage . '/app/crm-sidebar/*.json') ?: [], 'no before-image for a dry run');
    }

    public function test_apply_groups_the_leaves_enables_the_folders_and_gives_the_folder_right_only_to_who_can_see_the_root_and_a_leaf(): void
    {
        $before = collect($this->menu())->keyBy('id');
        $this->groupSidebar(['--apply' => true]);
        $after = collect($this->menu())->keyBy('id');

        foreach ([438 => 'GTM', 200 => 'Marketing', 446 => 'Sales'] as $id => $label) {
            $this->assertSame([$label, 1, 2, 199], [$after[$id]['menu_name'], (int) $after[$id]['status'], (int) $after[$id]['level'], (int) $after[$id]['parent_id']], "folder {$id}");
        }
        $this->assertSame([20, 30, 40], [(int) $after[438]['sort_order'], (int) $after[200]['sort_order'], (int) $after[446]['sort_order']]);
        // Leaves: under their folder, level 3, in the listed order.
        $this->assertSame([438, 3, 1], [(int) $after[476]['parent_id'], (int) $after[476]['level'], (int) $after[476]['sort_order']]);
        $this->assertSame([438, 3, 5], [(int) $after[464]['parent_id'], (int) $after[464]['level'], (int) $after[464]['sort_order']]);
        $this->assertSame([200, 3, 1], [(int) $after[201]['parent_id'], (int) $after[201]['level'], (int) $after[201]['sort_order']]);
        $this->assertSame([446, 3, 3], [(int) $after[447]['parent_id'], (int) $after[447]['level'], (int) $after[447]['sort_order']]);
        $this->assertSame([199, 2, 5], [(int) $after[439]['parent_id'], (int) $after[439]['level'], (int) $after[439]['sort_order']], 'Command Center stays under the root, first');
        // Never touched: Support, the disabled GTM row that is not listed, the root.
        foreach ([199, 456, 463, 443] as $id) {
            $this->assertSame($before[$id], $after[$id], "row {$id} is not touched");
        }
        // The folder right: profile 10 (root + leaves of all three) gets all three; 11 (root + Leads) only Marketing; 12 (no root right) none; 13 already had Sales.
        $this->assertSame([10], $this->folderViewers(438));
        $this->assertSame([10, 11], $this->folderViewers(200));
        $this->assertSame([10, 13], $this->folderViewers(446));
        $added = DB::table('tblgroupwise_rights_g2g')->where('menu_id', 438)->first();
        $this->assertSame([1, 0, 0, 0], [(int) $added->can_view, (int) $added->can_add, (int) $added->can_edit, (int) $added->can_delete], 'a folder carries no add, edit or delete');
        $this->assertSame(6, (int) $added->sub_institute_id, "the stamp is copied from the profile's root row");
        $this->assertCount(1, glob($this->storage . '/app/crm-sidebar/*.json'), 'one before-image');
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->groupSidebar(['--apply' => true]);
        $menu = $this->menu();
        $rights = $this->rights();

        $out = $this->groupSidebar(['--apply' => true]);

        $this->assertStringContainsString('Nothing to do', $out);
        $this->assertSame($menu, $this->menu());
        $this->assertSame($rights, $this->rights());
    }

    public function test_rollback_puts_the_menu_rows_back_and_removes_exactly_the_rights_it_added(): void
    {
        $menu = $this->menu();
        $rights = $this->rights();
        $this->groupSidebar(['--apply' => true]);
        $file = glob($this->storage . '/app/crm-sidebar/*.json')[0];

        $out = $this->groupSidebar(['--rollback' => $file]);

        $this->assertStringContainsString('UNDONE', $out);
        $this->assertSame($menu, $this->menu());
        $this->assertSame($rights, $this->rights());
    }
}
