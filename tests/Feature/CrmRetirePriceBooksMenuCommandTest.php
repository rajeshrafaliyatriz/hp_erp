<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `php artisan crm:retire-price-books-menu` (S16, contract section 4): the Price Books menu row and its rights come out of the menu, with a before-image, and go back with --rollback.
 *
 * The safeguards proved here, each with the mutation that breaks it (listed in docs/agentic-crm/_evidence/s16 by the coordinator):
 *   - a dry run writes nothing (the default)
 *   - --apply saves the before-image BEFORE the first delete, deletes the menu row and every right on it in one transaction, and a row with another address is never touched
 *   - --rollback puts the rows back with their own ids, byte for byte, and refuses (writes nothing) when an id is taken
 *   - a host other than the dev host is refused unless named; a row that has children is left alone
 */
class CrmRetirePriceBooksMenuCommandTest extends TestCase
{
    private string $storage;

    private const LINK = '/module/crm/sales/price-books';

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'retire-pb-' . bin2hex(random_bytes(4));
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
            ['id' => 199, 'menu_name' => 'Sales', 'parent_id' => 0, 'access_link' => '/module/crm', 'status' => 1, 'sort_order' => 1],
            ['id' => 480, 'menu_name' => 'Price Books', 'parent_id' => 199, 'access_link' => self::LINK, 'status' => 1, 'sort_order' => 7],
            ['id' => 481, 'menu_name' => 'Quotes', 'parent_id' => 199, 'access_link' => '/module/crm/sales/quotes', 'status' => 1, 'sort_order' => 8],
            ['id' => 482, 'menu_name' => 'Price Books look-alike', 'parent_id' => 199, 'access_link' => self::LINK . '/archive', 'status' => 1, 'sort_order' => 9],
        ]);
        DB::table('tblgroupwise_rights_g2g')->insert([
            ['id' => 1, 'sub_institute_id' => 6, 'menu_id' => 480, 'profile_id' => 10, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1],
            ['id' => 2, 'sub_institute_id' => 7, 'menu_id' => 480, 'profile_id' => 11, 'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0],
            ['id' => 3, 'sub_institute_id' => 6, 'menu_id' => 481, 'profile_id' => 10, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1],
            ['id' => 4, 'sub_institute_id' => 6, 'menu_id' => 482, 'profile_id' => 10, 'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0],
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
        $code = Artisan::call('crm:retire-price-books-menu', $options);

        return [$code, Artisan::output()];
    }

    private function imagePath(): string
    {
        $files = glob($this->storage . '/app/crm-price-books/*.json') ?: [];
        $this->assertCount(1, $files, 'one before-image was saved');

        return $files[0];
    }

    public function test_a_dry_run_lists_what_it_would_delete_and_writes_nothing(): void
    {
        $before = $this->snapshot();

        [$code, $out] = $this->retire();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('menu row 480 "Price Books"', $out);
        $this->assertStringContainsString('2 right(s) granted on it', $out);
        $this->assertStringContainsString('Nothing was written', $out);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], glob($this->storage . '/app/crm-price-books/*.json') ?: [], 'a dry run saves no before-image either');
    }

    public function test_apply_saves_the_before_image_then_deletes_the_row_and_its_rights_and_nothing_else(): void
    {
        $before = $this->snapshot();

        [$code, $out] = $this->retire(['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('DONE', $out);
        $after = $this->snapshot();
        $this->assertSame([199, 481, 482], array_column($after['menu'], 'id'), 'only the row with the exact address is gone; a look-alike address stays');
        $this->assertSame([3, 4], array_column($after['rights'], 'id'), 'only the rights on that row are gone');
        $this->assertSame(array_values(array_filter($before['menu'], fn ($r) => $r['id'] !== 480)), $after['menu']);

        $image = json_decode((string) file_get_contents($this->imagePath()), true);
        $this->assertSame([480], array_column($image['menu'], 'id'));
        $this->assertSame([1, 2], array_column($image['rights'], 'id'));
        $this->assertSame($before['rights'][0], $image['rights'][0], 'the before-image holds the row as it was');
    }

    public function test_a_second_apply_finds_nothing_to_do(): void
    {
        $this->retire(['--apply' => true]);
        $after = $this->snapshot();

        [$code, $out] = $this->retire(['--apply' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Nothing to do', $out);
        $this->assertSame($after, $this->snapshot());
        $this->assertCount(1, glob($this->storage . '/app/crm-price-books/*.json'), 'no second before-image for a run that deleted nothing');
    }

    public function test_rollback_puts_every_row_back_with_its_own_id_byte_for_byte(): void
    {
        $before = $this->snapshot();
        $this->retire(['--apply' => true]);
        $path = $this->imagePath();

        [$code, $out] = $this->retire(['--rollback' => $path]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('UNDONE', $out);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_rollback_refuses_and_writes_nothing_when_an_id_or_the_address_is_taken(): void
    {
        $this->retire(['--apply' => true]);
        $path = $this->imagePath();
        DB::table('tblmenumaster_g2g')->insert(['id' => 480, 'menu_name' => 'Something else', 'parent_id' => 199, 'access_link' => '/module/crm/sales/other', 'status' => 1, 'sort_order' => 1]);
        $held = $this->snapshot();

        [$code, $out] = $this->retire(['--rollback' => $path]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED', $out);
        $this->assertSame($held, $this->snapshot());
    }

    public function test_rollback_will_not_restore_a_row_with_another_address(): void
    {
        $path = $this->storage . '/forged.json';
        file_put_contents($path, json_encode(['format' => 'crm-price-books-menu-before-image/1', 'menu' => [['id' => 900, 'menu_name' => 'Quotes', 'parent_id' => 199, 'access_link' => '/module/crm/sales/quotes', 'status' => 1, 'sort_order' => 1]], 'rights' => []]));
        $before = $this->snapshot();

        [$code, $out] = $this->retire(['--rollback' => $path]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('another address', $out);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_row_that_has_children_is_left_alone(): void
    {
        DB::table('tblmenumaster_g2g')->insert(['id' => 490, 'menu_name' => 'Child', 'parent_id' => 480, 'access_link' => '/module/crm/sales/price-books/child', 'status' => 1, 'sort_order' => 1]);
        $before = $this->snapshot();

        [$code, $out] = $this->retire(['--apply' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('would be orphaned', $out);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], glob($this->storage . '/app/crm-price-books/*.json') ?: []);
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

    public function test_apply_and_rollback_together_are_refused(): void
    {
        [$code, $out] = $this->retire(['--apply' => true, '--rollback' => 'x.json']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('two different runs', $out);
    }
}
