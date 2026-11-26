<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `php artisan crm:retire-master-fields-menu` (26 Nov 2026): the Master Fields menu row and its rights come out of the menu (Role and Permissions too), with a before-image, and go back with --restore.
 * The Agent Run History row is NEVER selected: the agent API checks a right on it, so its row and its rights must survive every run.
 *
 * The safeguards proved here:
 *   - a dry run writes nothing (the default), not even a before-image
 *   - --apply writes the before-image FIRST (when it cannot be written nothing is deleted), then deletes the rights and the menu row in one transaction; a row with another address, or a
 *     look-alike address (the same address with something after it), is never touched
 *   - a second --apply finds nothing to do and writes no second image
 *   - --restore puts the row back with its own id, byte for byte, refuses (writes nothing) when the id or the address is taken, and a second --restore changes nothing
 *   - a host other than the dev host is refused unless named; a row that has children is left alone
 */
class CrmRetireMasterFieldsMenuCommandTest extends TestCase
{
    private string $storage;

    private const FIELDS = '/module/crm/master-fields';

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'retire-master-fields-' . bin2hex(random_bytes(4));
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
            ['id' => 200, 'menu_name' => 'Marketing', 'parent_id' => 199, 'access_link' => '/module/crm/marketing', 'status' => 1, 'sort_order' => 1],
            ['id' => 202, 'menu_name' => 'Master Fields', 'parent_id' => 200, 'access_link' => self::FIELDS, 'status' => 1, 'sort_order' => 4],
            ['id' => 486, 'menu_name' => 'Agent Run History', 'parent_id' => 200, 'access_link' => '/module/crm/marketing/agent-runs', 'status' => 1, 'sort_order' => 5],
            ['id' => 489, 'menu_name' => 'Fields look-alike', 'parent_id' => 200, 'access_link' => self::FIELDS . '/archive', 'status' => 1, 'sort_order' => 6],
        ]);
        DB::table('tblgroupwise_rights_g2g')->insert([
            ['id' => 1, 'sub_institute_id' => 6, 'menu_id' => 202, 'profile_id' => 10, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1],
            ['id' => 2, 'sub_institute_id' => 7, 'menu_id' => 202, 'profile_id' => 11, 'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0],
            ['id' => 3, 'sub_institute_id' => 6, 'menu_id' => 202, 'profile_id' => 12, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 0, 'can_delete' => 0],
            ['id' => 4, 'sub_institute_id' => 6, 'menu_id' => 486, 'profile_id' => 10, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1],
            ['id' => 5, 'sub_institute_id' => 6, 'menu_id' => 489, 'profile_id' => 10, 'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
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
    private function retire(array $options = []): array
    {
        $code = Artisan::call('crm:retire-master-fields-menu', $options);

        return [$code, Artisan::output()];
    }

    /** @return list<string> */
    private function images(?string $dir = null): array
    {
        return glob(($dir ?? $this->storage . '/app/crm-retire-master-fields-menu') . '/*.json') ?: [];
    }

    private function imagePath(): string
    {
        $files = $this->images();
        $this->assertCount(1, $files, 'one before-image was saved');

        return $files[0];
    }

    public function test_a_dry_run_lists_what_it_would_delete_and_writes_nothing(): void
    {
        $before = $this->snapshot();

        [$code, $out] = $this->retire();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('menu row 202 "Master Fields"', $out);
        $this->assertStringContainsString('right 1 on menu row 202', $out);
        $this->assertStringContainsString('3 right(s) granted on them', $out);
        $this->assertStringContainsString('Nothing was written', $out);
        $this->assertStringNotContainsString('Agent Run History', $out);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->images(), 'a dry run saves no before-image either');
    }

    public function test_apply_saves_the_before_image_then_deletes_the_row_and_its_rights_and_nothing_else(): void
    {
        $before = $this->snapshot();

        [$code, $out] = $this->retire(['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('DONE', $out);
        $after = $this->snapshot();
        $this->assertSame([200, 486, 489], array_column($after['menu'], 'id'), 'only the exact address is gone; Agent Run History and a look-alike address stay');
        $this->assertSame([4, 5], array_column($after['rights'], 'id'), 'only the rights on that row are gone');
        $this->assertSame(array_values(array_filter($before['menu'], fn ($r) => ! in_array($r['id'], [202], true))), $after['menu']);

        $image = json_decode((string) file_get_contents($this->imagePath()), true);
        $this->assertSame('crm-retire-master-fields-menu-before-image/1', $image['format']);
        $this->assertSame([202], array_column($image['menu'], 'id'));
        $this->assertSame([1, 2, 3], array_column($image['rights'], 'id'));
        $this->assertSame($before['rights'][0], $image['rights'][0], 'the before-image holds the row as it was');
        $this->assertSame($before['menu'][1], $image['menu'][0]);
    }

    public function test_the_agent_run_history_row_and_its_rights_survive_apply_and_restore_untouched(): void
    {
        $history = fn () => [
            DB::table('tblmenumaster_g2g')->where('id', 486)->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('tblgroupwise_rights_g2g')->where('menu_id', 486)->get()->map(fn ($r) => (array) $r)->all(),
        ];
        $before = $history();
        $this->assertCount(1, $before[0]);
        $this->assertCount(1, $before[1]);

        $this->retire(['--apply' => true]);
        $this->assertSame($before, $history(), 'the agent API still finds its row and its right after the Master Fields row is gone');

        $this->retire(['--restore' => $this->imagePath()]);
        $this->assertSame($before, $history());
    }

    public function test_nothing_is_deleted_when_the_before_image_cannot_be_written(): void
    {
        $before = $this->snapshot();
        file_put_contents($this->storage . '/in-the-way', 'a file, not a folder');

        [$code, $out] = $this->retire(['--apply' => true, '--image-dir' => $this->storage . '/in-the-way/images']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('could not be saved', $out . '');
        $this->assertSame($before, $this->snapshot(), 'no image, no delete');
    }

    public function test_the_before_image_goes_where_image_dir_says(): void
    {
        $dir = $this->storage . '/chosen/place';

        [$code, $out] = $this->retire(['--apply' => true, '--image-dir' => $dir]);

        $this->assertSame(0, $code, $out);
        $this->assertCount(1, $this->images($dir));
        $this->assertSame([], $this->images(), 'and not in the default folder');
    }

    public function test_a_second_apply_finds_nothing_to_do_and_writes_no_second_image(): void
    {
        $this->retire(['--apply' => true]);
        $after = $this->snapshot();

        [$code, $out] = $this->retire(['--apply' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Nothing to do', $out);
        $this->assertSame($after, $this->snapshot());
        $this->assertCount(1, $this->images(), 'no second before-image for a run that deleted nothing');
    }

    public function test_restore_puts_every_row_back_with_its_own_id_byte_for_byte(): void
    {
        $before = $this->snapshot();
        $this->retire(['--apply' => true]);
        $path = $this->imagePath();

        [$code, $out] = $this->retire(['--restore' => $path]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('RESTORED', $out);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_second_restore_changes_nothing(): void
    {
        $this->retire(['--apply' => true]);
        $path = $this->imagePath();
        $this->retire(['--restore' => $path]);
        $restored = $this->snapshot();

        [$code, $out] = $this->retire(['--restore' => $path]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Nothing to do', $out);
        $this->assertSame($restored, $this->snapshot());
    }

    public function test_restore_refuses_and_writes_nothing_when_an_id_or_the_address_is_taken(): void
    {
        $this->retire(['--apply' => true]);
        $path = $this->imagePath();
        DB::table('tblmenumaster_g2g')->insert(['id' => 202, 'menu_name' => 'Something else', 'parent_id' => 200, 'access_link' => '/module/crm/marketing/other', 'status' => 1, 'sort_order' => 1]);
        $held = $this->snapshot();

        [$code, $out] = $this->retire(['--restore' => $path]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED', $out);
        $this->assertSame($held, $this->snapshot());
    }

    public function test_restore_refuses_when_the_address_was_made_again_under_another_id(): void
    {
        $this->retire(['--apply' => true]);
        $path = $this->imagePath();
        DB::table('tblmenumaster_g2g')->insert(['id' => 900, 'menu_name' => 'Master Fields', 'parent_id' => 200, 'access_link' => self::FIELDS, 'status' => 1, 'sort_order' => 4]);
        $held = $this->snapshot();

        [$code, $out] = $this->retire(['--restore' => $path]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED', $out);
        $this->assertSame($held, $this->snapshot());
    }

    public function test_restore_will_not_restore_a_row_with_another_address(): void
    {
        $path = $this->storage . '/forged.json';
        file_put_contents($path, json_encode(['format' => 'crm-retire-master-fields-menu-before-image/1', 'menu' => [['id' => 900, 'menu_name' => 'Agent Run History', 'parent_id' => 200, 'access_link' => '/module/crm/marketing/agent-runs', 'status' => 1, 'sort_order' => 1]], 'rights' => []]));
        $before = $this->snapshot();

        [$code, $out] = $this->retire(['--restore' => $path]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('another address', $out);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_restore_will_not_read_a_file_this_command_did_not_write(): void
    {
        $path = $this->storage . '/other.json';
        file_put_contents($path, json_encode(['format' => 'crm-contacts-menu-before-image/1', 'menu' => [], 'rights' => []]));

        [$code, $out] = $this->retire(['--restore' => $path]);
        [$missing] = $this->retire(['--restore' => $this->storage . '/no-such-file.json']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('not a before-image written by this command', $out);
        $this->assertSame(2, $missing);
    }

    public function test_a_row_that_has_children_is_left_alone(): void
    {
        DB::table('tblmenumaster_g2g')->insert(['id' => 490, 'menu_name' => 'Child', 'parent_id' => 202, 'access_link' => '/module/crm/master-fields-child', 'status' => 1, 'sort_order' => 1]);
        $before = $this->snapshot();

        [$code, $out] = $this->retire(['--apply' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('would be orphaned', $out);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->images());
    }

    public function test_a_host_that_is_not_the_dev_host_is_refused_unless_it_is_named(): void
    {
        $before = $this->snapshot();
        $original = config('database.default');
        config(['database.connections.prod' => ['driver' => 'mysql', 'host' => '128.199.17.97', 'database' => 'x']]);
        config(['database.default' => 'prod']);

        [$code, $out] = $this->retire(['--apply' => true]);
        config(['database.default' => $original]);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('Refusing to run on host', $out);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_apply_and_restore_together_are_refused(): void
    {
        [$code, $out] = $this->retire(['--apply' => true, '--restore' => 'x.json']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('two different runs', $out);
    }
}
