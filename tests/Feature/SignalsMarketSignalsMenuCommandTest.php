<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `php artisan signals:market-signals-menu` (26 Nov 2026): the Partner Network menu row and its rights come out of the menu (Role and Permissions too) and the tab "Company Opportunities"
 * is renamed "Market Signals", with a before-image, and both go back with --restore.
 *
 * The safeguards proved here:
 *   - a dry run writes nothing (the default), not even a before-image
 *   - --apply writes the before-image FIRST (when it cannot be written nothing changes), then deletes the rights and the row and renames the tab in one transaction; a row with another
 *     address, another name or another parent (a look-alike) is never touched
 *   - a second --apply finds nothing to do and writes no second image
 *   - --restore puts the row back with its own id and the old name back, byte for byte; it refuses (writes nothing) when an id or the address is taken, or when the renamed row no longer has
 *     the new name; a second --restore changes nothing
 *   - a host other than the dev host is refused unless named; a row that has children is left alone, and then nothing at all is changed
 */
class SignalsMarketSignalsMenuCommandTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'market-signals-menu-' . bin2hex(random_bytes(4));
        File::makeDirectory($this->storage . '/app', 0775, true);
        $this->app->useStoragePath($this->storage);

        Schema::create('tblmenumaster_g2g', function ($t) {
            $t->id();
            $t->string('menu_name');
            $t->unsignedBigInteger('parent_id')->default(0);
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
        });
        DB::table('tblmenumaster_g2g')->insert([
            ['id' => 199, 'menu_name' => 'Sales & Marketing', 'parent_id' => 0, 'access_link' => '/module/crm', 'status' => 1, 'sort_order' => 1],
            ['id' => 476, 'menu_name' => 'Market Signals', 'parent_id' => 199, 'access_link' => '/module/crm/signals', 'status' => 1, 'sort_order' => 1],
            ['id' => 477, 'menu_name' => 'Company Opportunities', 'parent_id' => 476, 'access_link' => '/signals', 'status' => 1, 'sort_order' => 1],
            ['id' => 478, 'menu_name' => 'Ingestion Engine', 'parent_id' => 476, 'access_link' => '/ingestion', 'status' => 1, 'sort_order' => 2],
            ['id' => 479, 'menu_name' => 'Product Portfolio', 'parent_id' => 476, 'access_link' => '/portfolio', 'status' => 1, 'sort_order' => 3],
            ['id' => 480, 'menu_name' => 'Partner Network', 'parent_id' => 476, 'access_link' => '/partners', 'status' => 1, 'sort_order' => 4],
            // look-alikes: each differs from the real row in exactly one of address, name, parent
            ['id' => 490, 'menu_name' => 'Partner Network', 'parent_id' => 476, 'access_link' => '/partners/archive', 'status' => 1, 'sort_order' => 5],
            ['id' => 493, 'menu_name' => 'Company Opportunities', 'parent_id' => 99, 'access_link' => '/signals', 'status' => 1, 'sort_order' => 8],
            ['id' => 494, 'menu_name' => 'Company Opportunities', 'parent_id' => 476, 'access_link' => '/signals/archive', 'status' => 1, 'sort_order' => 9],
            ['id' => 495, 'menu_name' => 'Company Opportunities Archive', 'parent_id' => 476, 'access_link' => '/signals', 'status' => 1, 'sort_order' => 10],
        ]);
        DB::table('tblgroupwise_rights_g2g')->insert([
            ['id' => 1, 'sub_institute_id' => 6, 'menu_id' => 480, 'profile_id' => 10, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1],
            ['id' => 2, 'sub_institute_id' => 7, 'menu_id' => 480, 'profile_id' => 11, 'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0],
            ['id' => 3, 'sub_institute_id' => 6, 'menu_id' => 480, 'profile_id' => 12, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 0, 'can_delete' => 0],
            ['id' => 4, 'sub_institute_id' => 6, 'menu_id' => 477, 'profile_id' => 10, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1],
            ['id' => 5, 'sub_institute_id' => 6, 'menu_id' => 478, 'profile_id' => 10, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    /**
     * Two more look-alikes at EXACTLY the address /partners: one with another name, one under another parent. They are not the row this command means, so apply never touches them;
     * a restore, which refuses while another row holds the address, is tested without them.
     */
    private function sameAddressLookAlikes(): void
    {
        DB::table('tblmenumaster_g2g')->insert([
            ['id' => 491, 'menu_name' => 'Partners', 'parent_id' => 476, 'access_link' => '/partners', 'status' => 1, 'sort_order' => 6],
            ['id' => 492, 'menu_name' => 'Partner Network', 'parent_id' => 99, 'access_link' => '/partners', 'status' => 1, 'sort_order' => 7],
        ]);
        DB::table('tblgroupwise_rights_g2g')->insert([
            ['id' => 6, 'sub_institute_id' => 6, 'menu_id' => 491, 'profile_id' => 10, 'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0],
            ['id' => 7, 'sub_institute_id' => 6, 'menu_id' => 492, 'profile_id' => 10, 'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0],
        ]);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'menu' => DB::table('tblmenumaster_g2g')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'rights' => DB::table('tblgroupwise_rights_g2g')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    /** @return array{0: int, 1: string} */
    private function menuCmd(array $options = []): array
    {
        $code = Artisan::call('signals:market-signals-menu', $options);

        return [$code, Artisan::output()];
    }

    /** @return list<string> */
    private function images(?string $dir = null): array
    {
        return glob(($dir ?? $this->storage . '/app/signals-market-signals-menu') . '/*.json') ?: [];
    }

    private function imagePath(): string
    {
        $files = $this->images();
        $this->assertCount(1, $files, 'one before-image was saved');

        return $files[0];
    }

    private function menuName(int $id): ?string
    {
        return DB::table('tblmenumaster_g2g')->where('id', $id)->value('menu_name');
    }

    public function test_a_dry_run_lists_what_it_would_change_and_writes_nothing(): void
    {
        $this->sameAddressLookAlikes();
        $before = $this->snapshot();

        [$code, $out] = $this->menuCmd();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('delete menu row 480 "Partner Network"', $out);
        $this->assertStringContainsString('right 1 on menu row 480', $out);
        $this->assertStringContainsString('3 right(s) granted on the row to delete', $out);
        $this->assertStringContainsString('rename menu row 477 "Company Opportunities" -> "Market Signals"', $out);
        $this->assertStringContainsString('left alone: row 491 "Partners"', $out);
        $this->assertStringContainsString('left alone: row 492 "Partner Network" (parent 99)', $out);
        $this->assertStringContainsString('left alone: row 493 "Company Opportunities" (parent 99)', $out);
        $this->assertStringContainsString('left alone: row 495 "Company Opportunities Archive"', $out);
        $this->assertStringContainsString('Nothing was written', $out);
        $this->assertStringNotContainsString('menu row 478', $out);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->images(), 'a dry run saves no before-image either');
    }

    public function test_apply_saves_the_before_image_then_deletes_the_row_and_its_rights_renames_the_tab_and_changes_nothing_else(): void
    {
        $this->sameAddressLookAlikes();
        $before = $this->snapshot();

        [$code, $out] = $this->menuCmd(['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('DONE', $out);
        $after = $this->snapshot();
        $this->assertSame([199, 476, 477, 478, 479, 490, 491, 492, 493, 494, 495], array_column($after['menu'], 'id'), 'only row 480 is gone; every look-alike stays');
        $this->assertSame([4, 5, 6, 7], array_column($after['rights'], 'id'), 'only the rights on that row are gone');
        $this->assertSame('Market Signals', $this->menuName(477), 'the tab is renamed');
        $expected = array_values(array_filter($before['menu'], fn ($r) => $r['id'] !== 480));
        $expected[array_search(477, array_column($expected, 'id'), true)]['menu_name'] = 'Market Signals';
        $this->assertSame($expected, $after['menu'], 'the rename changes the name and nothing else: id, address, parent, status and order stay');
        $this->assertSame($before['rights'][3], $after['rights'][0], 'the rights on the renamed row are untouched');

        $image = json_decode((string) file_get_contents($this->imagePath()), true);
        $this->assertSame('signals-market-signals-menu-before-image/1', $image['format']);
        $this->assertSame([480], array_column($image['menu'], 'id'));
        $this->assertSame([1, 2, 3], array_column($image['rights'], 'id'));
        $this->assertSame($before['rights'][0], $image['rights'][0], 'the before-image holds the row as it was');
        $this->assertSame($before['menu'][5], $image['menu'][0]);
        $this->assertSame([['id' => 477, 'access_link' => '/signals', 'parent_id' => 476, 'old_name' => 'Company Opportunities', 'new_name' => 'Market Signals']], $image['renamed']);
    }

    public function test_apply_forgets_the_remembered_menu_ids(): void
    {
        Cache::put('menu_id_for_link:/partners', 480, 300);
        Cache::put('menu_id_for_link:/signals', 477, 300);

        $this->menuCmd(['--apply' => true]);

        $this->assertNull(Cache::get('menu_id_for_link:/partners'));
        $this->assertNull(Cache::get('menu_id_for_link:/signals'));
    }

    public function test_nothing_changes_when_the_before_image_cannot_be_written(): void
    {
        $before = $this->snapshot();
        file_put_contents($this->storage . '/in-the-way', 'a file, not a folder');

        [$code, $out] = $this->menuCmd(['--apply' => true, '--image-dir' => $this->storage . '/in-the-way/images']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('could not be saved', $out);
        $this->assertSame($before, $this->snapshot(), 'no image, no change: not the delete, not the rename');
    }

    public function test_the_before_image_goes_where_image_dir_says(): void
    {
        $dir = $this->storage . '/chosen/place';

        [$code, $out] = $this->menuCmd(['--apply' => true, '--image-dir' => $dir]);

        $this->assertSame(0, $code, $out);
        $this->assertCount(1, $this->images($dir));
        $this->assertSame([], $this->images(), 'and not in the default folder');
    }

    public function test_a_second_apply_finds_nothing_to_do_and_writes_no_second_image(): void
    {
        $this->menuCmd(['--apply' => true]);
        $after = $this->snapshot();

        [$code, $out] = $this->menuCmd(['--apply' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Nothing to do', $out);
        $this->assertSame($after, $this->snapshot());
        $this->assertCount(1, $this->images(), 'no second before-image for a run that changed nothing');
    }

    public function test_when_the_tab_is_already_renamed_only_the_row_is_deleted(): void
    {
        DB::table('tblmenumaster_g2g')->where('id', 477)->update(['menu_name' => 'Market Signals']);

        [$code, $out] = $this->menuCmd(['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('rename menu row', $out);
        $this->assertNull(DB::table('tblmenumaster_g2g')->where('id', 480)->first());
        $image = json_decode((string) file_get_contents($this->imagePath()), true);
        $this->assertSame([], $image['renamed']);
        $this->assertSame([480], array_column($image['menu'], 'id'));
    }

    public function test_when_the_row_is_already_gone_only_the_tab_is_renamed(): void
    {
        DB::table('tblmenumaster_g2g')->where('id', 480)->delete();
        DB::table('tblgroupwise_rights_g2g')->where('menu_id', 480)->delete();

        [$code, $out] = $this->menuCmd(['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertSame('Market Signals', $this->menuName(477));
        $image = json_decode((string) file_get_contents($this->imagePath()), true);
        $this->assertSame([], $image['menu']);
        $this->assertSame([477], array_column($image['renamed'], 'id'));
    }

    public function test_restore_puts_the_row_the_rights_and_the_old_name_back_byte_for_byte(): void
    {
        $before = $this->snapshot();
        $this->menuCmd(['--apply' => true]);
        $path = $this->imagePath();

        [$code, $out] = $this->menuCmd(['--restore' => $path]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('RESTORED', $out);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('Company Opportunities', $this->menuName(477));
    }

    public function test_a_second_restore_changes_nothing(): void
    {
        $this->menuCmd(['--apply' => true]);
        $path = $this->imagePath();
        $this->menuCmd(['--restore' => $path]);
        $restored = $this->snapshot();

        [$code, $out] = $this->menuCmd(['--restore' => $path]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Nothing to do', $out);
        $this->assertSame($restored, $this->snapshot());
    }

    public function test_restore_finishes_the_half_that_is_missing_when_the_other_half_is_already_back(): void
    {
        $before = $this->snapshot();
        $this->menuCmd(['--apply' => true]);
        $path = $this->imagePath();
        DB::table('tblmenumaster_g2g')->where('id', 477)->update(['menu_name' => 'Company Opportunities']);   // the name was put back by hand

        [$code, $out] = $this->menuCmd(['--restore' => $path]);

        $this->assertSame(0, $code, $out);
        $this->assertSame($before, $this->snapshot(), 'the row and its rights come back; the name was already right');
    }

    public function test_restore_refuses_and_writes_nothing_when_an_id_is_taken(): void
    {
        $this->menuCmd(['--apply' => true]);
        $path = $this->imagePath();
        DB::table('tblmenumaster_g2g')->insert(['id' => 480, 'menu_name' => 'Something else', 'parent_id' => 476, 'access_link' => '/module/other', 'status' => 1, 'sort_order' => 1]);
        $held = $this->snapshot();

        [$code, $out] = $this->menuCmd(['--restore' => $path]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED', $out);
        $this->assertSame($held, $this->snapshot(), 'not even the old name went back');
    }

    public function test_restore_refuses_when_the_address_was_made_again_under_another_id(): void
    {
        $this->menuCmd(['--apply' => true]);
        $path = $this->imagePath();
        DB::table('tblmenumaster_g2g')->insert(['id' => 900, 'menu_name' => 'Partner Network', 'parent_id' => 476, 'access_link' => '/partners', 'status' => 1, 'sort_order' => 4]);
        $held = $this->snapshot();

        [$code, $out] = $this->menuCmd(['--restore' => $path]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED', $out);
        $this->assertSame($held, $this->snapshot());
    }

    public function test_restore_refuses_when_a_right_id_is_taken(): void
    {
        $this->menuCmd(['--apply' => true]);
        $path = $this->imagePath();
        DB::table('tblgroupwise_rights_g2g')->insert(['id' => 2, 'sub_institute_id' => 9, 'menu_id' => 478, 'profile_id' => 99, 'can_view' => 1]);
        $held = $this->snapshot();

        [$code, $out] = $this->menuCmd(['--restore' => $path]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED', $out);
        $this->assertSame($held, $this->snapshot());
    }

    public function test_restore_refuses_when_the_renamed_row_no_longer_has_the_new_name_and_restores_nothing(): void
    {
        $this->menuCmd(['--apply' => true]);
        $path = $this->imagePath();
        DB::table('tblmenumaster_g2g')->where('id', 477)->update(['menu_name' => 'Signals Home']);
        $held = $this->snapshot();

        [$code, $out] = $this->menuCmd(['--restore' => $path]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED', $out);
        $this->assertStringContainsString('is now named "Signals Home"', $out);
        $this->assertSame($held, $this->snapshot(), 'the deleted row was not put back either: all or nothing');
    }

    public function test_restore_refuses_when_the_renamed_row_is_not_at_its_address_any_more(): void
    {
        $this->menuCmd(['--apply' => true]);
        $path = $this->imagePath();
        DB::table('tblmenumaster_g2g')->where('id', 477)->update(['access_link' => '/elsewhere']);
        $held = $this->snapshot();

        [$code, $out] = $this->menuCmd(['--restore' => $path]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('is not at /signals any more', $out);
        $this->assertSame($held, $this->snapshot());
    }

    public function test_restore_will_not_restore_or_rename_a_row_the_command_does_not_mean(): void
    {
        $before = $this->snapshot();
        $format = 'signals-market-signals-menu-before-image/1';
        $forgedMenu = $this->storage . '/forged-menu.json';
        file_put_contents($forgedMenu, json_encode(['format' => $format, 'menu' => [['id' => 900, 'menu_name' => 'Agent Run History', 'parent_id' => 476, 'access_link' => '/module/crm/marketing/agent-runs', 'status' => 1, 'sort_order' => 1]], 'rights' => [], 'renamed' => []]));
        $forgedRename = $this->storage . '/forged-rename.json';
        file_put_contents($forgedRename, json_encode(['format' => $format, 'menu' => [], 'rights' => [], 'renamed' => [['id' => 478, 'access_link' => '/ingestion', 'parent_id' => 476, 'old_name' => 'Hacked', 'new_name' => 'Ingestion Engine']]]));
        $forgedRight = $this->storage . '/forged-right.json';
        file_put_contents($forgedRight, json_encode(['format' => $format, 'menu' => [], 'rights' => [['id' => 900, 'menu_id' => 478, 'profile_id' => 1]], 'renamed' => []]));

        [$menuCode, $menuOut] = $this->menuCmd(['--restore' => $forgedMenu]);
        [$renameCode, $renameOut] = $this->menuCmd(['--restore' => $forgedRename]);
        [$rightCode, $rightOut] = $this->menuCmd(['--restore' => $forgedRight]);

        $this->assertSame(1, $menuCode);
        $this->assertStringContainsString('another address, name or parent', $menuOut);
        $this->assertSame(1, $renameCode);
        $this->assertStringContainsString('another address or name', $renameOut);
        $this->assertSame(1, $rightCode);
        $this->assertStringContainsString('a right on a menu row it does not restore', $rightOut);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_restore_will_not_read_a_file_this_command_did_not_write(): void
    {
        $path = $this->storage . '/other.json';
        file_put_contents($path, json_encode(['format' => 'crm-retire-automation-rules-menu-before-image/1', 'menu' => [], 'rights' => []]));

        [$code, $out] = $this->menuCmd(['--restore' => $path]);
        [$missing] = $this->menuCmd(['--restore' => $this->storage . '/no-such-file.json']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('not a before-image written by this command', $out);
        $this->assertSame(2, $missing);
    }

    public function test_a_row_that_has_children_is_left_alone_and_so_is_the_rename(): void
    {
        DB::table('tblmenumaster_g2g')->insert(['id' => 500, 'menu_name' => 'Child', 'parent_id' => 480, 'access_link' => '/partners/child', 'status' => 1, 'sort_order' => 1]);
        $before = $this->snapshot();

        [$code, $out] = $this->menuCmd(['--apply' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('would be orphaned', $out);
        $this->assertSame($before, $this->snapshot(), 'a run is both changes or neither');
        $this->assertSame([], $this->images());
    }

    public function test_look_alike_rows_alone_are_not_touched_and_there_is_nothing_to_do(): void
    {
        DB::table('tblmenumaster_g2g')->whereIn('id', [477, 480])->delete();
        $before = $this->snapshot();

        [$code, $out] = $this->menuCmd(['--apply' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Nothing to do', $out);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->images());
    }

    public function test_a_host_that_is_not_the_dev_host_is_refused_unless_it_is_named(): void
    {
        $before = $this->snapshot();
        $original = config('database.default');
        config(['database.connections.prod' => ['driver' => 'mysql', 'host' => '128.199.17.97', 'database' => 'x']]);
        config(['database.default' => 'prod']);

        [$code, $out] = $this->menuCmd(['--apply' => true]);
        config(['database.default' => $original]);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('Refusing to run on host', $out);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_and_restore_together_are_refused(): void
    {
        [$code, $out] = $this->menuCmd(['--apply' => true, '--restore' => 'x.json']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('two different runs', $out);
    }
}
