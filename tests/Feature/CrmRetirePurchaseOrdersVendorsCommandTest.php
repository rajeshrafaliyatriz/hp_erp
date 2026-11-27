<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `php artisan crm:retire-purchase-orders-vendors` (26 Nov 2026): the CRM Purchase Orders and Vendors menu rows, their rights, their picklist values and their reports (and the report
 * folders left empty by them) come out of the data, with a before-image, and go back with --restore.
 *
 * The safeguards proved here:
 *   - a dry run writes nothing (the default), not even a before-image, and sends only SELECT statements
 *   - --apply writes the before-image FIRST (when it cannot be written nothing is deleted), then deletes rights, menu rows, picklist values, reports and emptied folders in one transaction;
 *     a row with another address, module or primary module, or a look-alike one, is never touched; a folder that still holds another report stays
 *   - a second --apply finds nothing to do and writes no second image
 *   - a menu row with children, and a report with a schedule or a share, are REFUSED: nothing is written, the row is named, exit 1
 *   - --restore puts every row back with its own id, byte for byte, refuses (writes nothing) when an id, an address, a module or a primary module is taken, will not restore a row
 *     this command could not have deleted, and a second --restore changes nothing
 *   - a host other than the dev host is refused unless named
 */
class CrmRetirePurchaseOrdersVendorsCommandTest extends TestCase
{
    private string $storage;

    private const VENDORS = '/module/crm/sales/vendors';

    private const PURCHASE_ORDERS = '/module/crm/sales/purchase-orders';

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'retire-po-vendors-' . bin2hex(random_bytes(4));
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
        Schema::create('crm_picklist_values', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->default(0);
            $t->string('module');
            $t->string('field_key');
            $t->string('value');
            $t->string('label')->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_default')->default(false);
            $t->boolean('status')->default(true);
            $t->string('only_on_some_hosts')->nullable();
        });
        Schema::create('report_folders', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('module_scope', 30);
            $t->string('name', 191);
            $t->boolean('is_system')->default(false);
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('reports', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('module_scope', 30);
            $t->unsignedBigInteger('folder_id')->nullable();
            $t->string('report_type', 20);
            $t->string('primary_module', 80);
            $t->string('title', 191);
            $t->unsignedBigInteger('owner_id');
            $t->longText('definition');
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('report_schedules', function ($t) {
            $t->id();
            $t->unsignedBigInteger('report_id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->string('frequency', 20);
            $t->boolean('is_active')->default(true);
        });
        Schema::create('report_shares', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('report_id');
            $t->unsignedBigInteger('viewer_user_id');
        });

        DB::table('tblmenumaster_g2g')->insert([
            ['id' => 446, 'menu_name' => 'Sales', 'parent_id' => 199, 'access_link' => '/module/crm/sales', 'status' => 1, 'sort_order' => 40],
            ['id' => 452, 'menu_name' => 'Vendors', 'parent_id' => 446, 'access_link' => self::VENDORS, 'status' => 1, 'sort_order' => 60],
            ['id' => 453, 'menu_name' => 'Invoices', 'parent_id' => 446, 'access_link' => '/module/crm/sales/invoices', 'status' => 1, 'sort_order' => 59],
            ['id' => 454, 'menu_name' => 'Purchase Orders', 'parent_id' => 446, 'access_link' => self::PURCHASE_ORDERS, 'status' => 1, 'sort_order' => 61],
            ['id' => 455, 'menu_name' => 'Vendors look-alike', 'parent_id' => 446, 'access_link' => self::VENDORS . '/archive', 'status' => 1, 'sort_order' => 62],
        ]);
        DB::table('tblgroupwise_rights_g2g')->insert([
            ['id' => 1, 'sub_institute_id' => 6, 'menu_id' => 452, 'profile_id' => 10, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1],
            ['id' => 2, 'sub_institute_id' => 7, 'menu_id' => 452, 'profile_id' => 11, 'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0],
            ['id' => 3, 'sub_institute_id' => 6, 'menu_id' => 454, 'profile_id' => 10, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1],
            ['id' => 4, 'sub_institute_id' => 6, 'menu_id' => 453, 'profile_id' => 10, 'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1],
            ['id' => 5, 'sub_institute_id' => 6, 'menu_id' => 455, 'profile_id' => 10, 'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0],
        ]);
        DB::table('crm_picklist_values')->insert([
            ['id' => 1, 'sub_institute_id' => 0, 'module' => 'purchase_orders', 'field_key' => 'po_stage', 'value' => 'Created', 'label' => 'Created', 'sort_order' => 1, 'is_default' => 1, 'status' => 1, 'only_on_some_hosts' => 'x'],
            ['id' => 2, 'sub_institute_id' => 0, 'module' => 'purchase_orders', 'field_key' => 'po_stage', 'value' => 'Delivered', 'label' => 'Delivered', 'sort_order' => 2, 'is_default' => 0, 'status' => 1, 'only_on_some_hosts' => null],
            ['id' => 3, 'sub_institute_id' => 0, 'module' => 'sales_orders', 'field_key' => 'so_stage', 'value' => 'Created', 'label' => 'Created', 'sort_order' => 1, 'is_default' => 1, 'status' => 1, 'only_on_some_hosts' => null],
            ['id' => 4, 'sub_institute_id' => 6, 'module' => 'vendors', 'field_key' => 'category', 'value' => 'Hardware', 'label' => 'Hardware', 'sort_order' => 1, 'is_default' => 0, 'status' => 1, 'only_on_some_hosts' => null],
            ['id' => 5, 'sub_institute_id' => 0, 'module' => 'purchase_orders_archive', 'field_key' => 'po_stage', 'value' => 'Old', 'label' => 'Old', 'sort_order' => 1, 'is_default' => 0, 'status' => 1, 'only_on_some_hosts' => null],
        ]);
        DB::table('report_folders')->insert([
            ['id' => 15, 'sub_institute_id' => 6, 'module_scope' => 'crm', 'name' => 'Purchase Order Reports', 'is_system' => 1, 'deleted_at' => null],
            ['id' => 27, 'sub_institute_id' => 1, 'module_scope' => 'crm', 'name' => 'Purchase Order Reports', 'is_system' => 1, 'deleted_at' => null],
            ['id' => 28, 'sub_institute_id' => 1, 'module_scope' => 'crm', 'name' => 'Quote Reports', 'is_system' => 1, 'deleted_at' => null],
        ]);
        $report = fn (int $id, int $tenant, ?int $folder, string $module, string $title, ?string $deleted = null) => [
            'id' => $id, 'sub_institute_id' => $tenant, 'module_scope' => 'crm', 'folder_id' => $folder, 'report_type' => 'tabular', 'primary_module' => $module, 'title' => $title, 'owner_id' => 1,
            'definition' => json_encode(['columns' => [['module' => $module, 'field' => 'subject']]]), 'deleted_at' => $deleted,
        ];
        DB::table('reports')->insert([
            $report(33, 6, 15, 'crm.purchase_orders', 'PurchaseOrder by Contacts'),
            $report(34, 6, 15, 'crm.purchase_orders', 'PurchaseOrder Detailed Report'),
            $report(61, 1, 27, 'crm.purchase_orders', 'PurchaseOrder by Contacts'),
            $report(62, 1, 27, 'crm.purchase_orders', 'PurchaseOrder Detailed Report', '2026-11-20 10:00:00'),
            $report(70, 1, 28, 'crm.quotes', 'Quotes Detailed Report'),
            $report(71, 1, 28, 'crm.purchase_orders_archive', 'Look-alike module'),
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
        $all = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return [
            'menu' => $all('tblmenumaster_g2g'), 'rights' => $all('tblgroupwise_rights_g2g'), 'picklist' => $all('crm_picklist_values'),
            'folders' => $all('report_folders'), 'reports' => $all('reports'), 'schedules' => $all('report_schedules'), 'shares' => $all('report_shares'),
        ];
    }

    /** @return array{0: int, 1: string} */
    private function retire(array $options = []): array
    {
        $code = Artisan::call('crm:retire-purchase-orders-vendors', $options);

        return [$code, Artisan::output()];
    }

    /** @return list<string> */
    private function images(?string $dir = null): array
    {
        return glob(($dir ?? $this->storage . '/app/crm-retire-purchase-orders-vendors') . '/*.json') ?: [];
    }

    private function imagePath(): string
    {
        $files = $this->images();
        $this->assertCount(1, $files, 'one before-image was saved');

        return $files[0];
    }

    public function test_a_dry_run_lists_what_it_would_delete_with_a_count_per_kind_and_writes_nothing(): void
    {
        $before = $this->snapshot();
        $sent = [];
        DB::listen(function ($q) use (&$sent) {
            $sent[] = $q->sql;
        });

        [$code, $out] = $this->retire();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('menu row 452 "Vendors"', $out);
        $this->assertStringContainsString('menu row 454 "Purchase Orders"', $out);
        $this->assertStringContainsString('right 1 on menu row 452', $out);
        $this->assertStringContainsString('picklist value 4: module vendors', $out);
        $this->assertStringContainsString('report 62 "PurchaseOrder Detailed Report"', $out);
        $this->assertStringContainsString('in the trash', $out);
        $this->assertStringContainsString('report folder 15 "Purchase Order Reports"', $out);
        $this->assertStringContainsString('(a) 3 right(s)', $out);
        $this->assertStringContainsString('(b) 2 menu row(s)', $out);
        $this->assertStringContainsString('(c) 3 picklist value(s)', $out);
        $this->assertStringContainsString('(d) 4 report(s) (reports) and 2 emptied report folder(s)', $out);
        $this->assertStringContainsString('Nothing was written', $out);
        $this->assertStringNotContainsString('Invoices', $out);
        $this->assertStringNotContainsString('look-alike', $out);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->images(), 'a dry run saves no before-image either');
        $this->assertNotSame([], $sent);
        foreach ($sent as $sql) {
            $this->assertStringStartsWith('select', strtolower(ltrim($sql)), 'a dry run sends SELECT statements only: ' . $sql);
        }
    }

    public function test_apply_saves_the_before_image_then_deletes_the_four_kinds_of_row_and_nothing_else(): void
    {
        $before = $this->snapshot();

        [$code, $out] = $this->retire(['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('DONE', $out);
        $after = $this->snapshot();
        $this->assertSame([446, 453, 455], array_column($after['menu'], 'id'), 'only the two exact addresses are gone; Invoices and a look-alike address stay');
        $this->assertSame([4, 5], array_column($after['rights'], 'id'), 'only the rights on those two rows are gone');
        $this->assertSame([3, 5], array_column($after['picklist'], 'id'), 'only the picklist values of the two modules are gone; the Sales Orders stages and a look-alike module stay');
        $this->assertSame([70, 71], array_column($after['reports'], 'id'), 'only the reports of the two primary modules are gone, in the trash or not');
        $this->assertSame([28], array_column($after['folders'], 'id'), 'the two emptied folders are gone, the one that still holds another report stays');

        $image = json_decode((string) file_get_contents($this->imagePath()), true);
        $this->assertSame('crm-retire-purchase-orders-vendors-before-image/1', $image['format']);
        $this->assertSame([452, 454], array_column($image['menu'], 'id'));
        $this->assertSame([1, 2, 3], array_column($image['rights'], 'id'));
        $this->assertSame([1, 2, 4], array_column($image['picklist'], 'id'));
        $this->assertSame([15, 27], array_column($image['folders'], 'id'));
        $this->assertSame([33, 34, 61, 62], array_column($image['reports'], 'id'));
        $this->assertSame($before['rights'][0], $image['rights'][0], 'the before-image holds the row as it was');
        $this->assertSame($before['picklist'][0], $image['picklist'][0], 'a picklist row is kept whole, a column only some hosts have included');
        $this->assertSame($before['reports'][3], $image['reports'][3]);
    }

    public function test_a_folder_that_still_holds_a_report_stays_even_when_that_report_is_in_the_trash(): void
    {
        DB::table('reports')->insert(['id' => 80, 'sub_institute_id' => 6, 'module_scope' => 'crm', 'folder_id' => 15, 'report_type' => 'tabular', 'primary_module' => 'crm.quotes', 'title' => 'Mine',
            'owner_id' => 1, 'definition' => '{}', 'deleted_at' => '2026-11-01 00:00:00']);

        [$code, $out] = $this->retire(['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('report folder 15 "Purchase Order Reports" stays', $out);
        $this->assertSame([15, 28], array_column($this->snapshot()['folders'], 'id'));
        $this->assertSame([27], array_column(json_decode((string) file_get_contents($this->imagePath()), true)['folders'], 'id'), 'the before-image holds only the folder that went');
    }

    public function test_nothing_is_deleted_when_the_before_image_cannot_be_written(): void
    {
        $before = $this->snapshot();
        file_put_contents($this->storage . '/in-the-way', 'a file, not a folder');

        [$code, $out] = $this->retire(['--apply' => true, '--image-dir' => $this->storage . '/in-the-way/images']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('could not be saved', $out);
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

    public function test_what_is_left_over_is_still_taken_when_the_menu_rows_are_already_gone(): void
    {
        DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', [452, 454])->delete();
        DB::table('tblmenumaster_g2g')->whereIn('id', [452, 454])->delete();

        [$code, $out] = $this->retire(['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('(b) 0 menu row(s)', $out);
        $this->assertSame([3, 5], array_column($this->snapshot()['picklist'], 'id'));
        $this->assertSame([70, 71], array_column($this->snapshot()['reports'], 'id'));
    }

    public function test_a_connection_without_the_report_tables_skips_them_and_says_so(): void
    {
        Schema::drop('report_schedules');
        Schema::drop('report_shares');
        Schema::drop('reports');
        Schema::drop('report_folders');

        [$code, $out] = $this->retire(['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('has no reports; that part is skipped', $out);
        $this->assertStringContainsString('has no report_folders; that part is skipped', $out);
        $this->assertSame([446, 453, 455], DB::table('tblmenumaster_g2g')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([3, 5], DB::table('crm_picklist_values')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all());
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

    public function test_restore_refuses_and_writes_nothing_when_an_id_is_taken(): void
    {
        $this->retire(['--apply' => true]);
        $path = $this->imagePath();
        DB::table('tblmenumaster_g2g')->insert(['id' => 452, 'menu_name' => 'Something else', 'parent_id' => 446, 'access_link' => '/module/crm/sales/other', 'status' => 1, 'sort_order' => 1]);
        $held = $this->snapshot();

        [$code, $out] = $this->retire(['--restore' => $path]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED', $out);
        $this->assertSame($held, $this->snapshot());
    }

    public function test_restore_refuses_when_an_address_a_module_or_a_primary_module_was_made_again_under_another_id(): void
    {
        $this->retire(['--apply' => true]);
        $path = $this->imagePath();

        foreach ([
            fn () => DB::table('tblmenumaster_g2g')->insert(['id' => 900, 'menu_name' => 'Vendors', 'parent_id' => 446, 'access_link' => self::VENDORS, 'status' => 1, 'sort_order' => 60]),
            fn () => DB::table('crm_picklist_values')->insert(['id' => 900, 'sub_institute_id' => 0, 'module' => 'purchase_orders', 'field_key' => 'po_stage', 'value' => 'Created', 'label' => 'Created']),
            fn () => DB::table('reports')->insert(['id' => 900, 'sub_institute_id' => 6, 'module_scope' => 'crm', 'folder_id' => null, 'report_type' => 'tabular', 'primary_module' => 'crm.vendors', 'title' => 'again',
                'owner_id' => 1, 'definition' => '{}']),
        ] as $makeAgain) {
            $makeAgain();
            $held = $this->snapshot();

            [$code, $out] = $this->retire(['--restore' => $path]);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('REFUSED', $out);
            $this->assertSame($held, $this->snapshot());
            DB::table('tblmenumaster_g2g')->where('id', 900)->delete();
            DB::table('crm_picklist_values')->where('id', 900)->delete();
            DB::table('reports')->where('id', 900)->delete();
        }
    }

    public function test_restore_will_not_restore_a_row_this_command_could_not_have_deleted(): void
    {
        $write = fn (array $image) => file_put_contents($this->storage . '/forged.json', json_encode($image + ['format' => 'crm-retire-purchase-orders-vendors-before-image/1', 'rights' => [], 'menu' => [], 'picklist' => [], 'folders' => [], 'reports' => []]));
        $before = $this->snapshot();
        $cases = [
            'another address' => [['menu' => [['id' => 900, 'menu_name' => 'Invoices', 'parent_id' => 446, 'access_link' => '/module/crm/sales/invoices', 'status' => 1, 'sort_order' => 1]]], 'another address'],
            'a right on a row that is not in the file' => [['menu' => [['id' => 900, 'menu_name' => 'Vendors', 'parent_id' => 446, 'access_link' => self::VENDORS, 'status' => 1, 'sort_order' => 1]],
                'rights' => [['id' => 90, 'sub_institute_id' => 6, 'menu_id' => 453, 'profile_id' => 10]]], 'right on a menu row that is not one of its own'],
            'another picklist module' => [['picklist' => [['id' => 900, 'module' => 'sales_orders', 'field_key' => 'so_stage', 'value' => 'x']]], 'picklist value of another module'],
            'another primary module' => [['reports' => [['id' => 900, 'primary_module' => 'crm.quotes', 'folder_id' => null, 'title' => 't']]], 'report with another primary module'],
            'a folder no report sat in' => [['folders' => [['id' => 28, 'name' => 'Quote Reports']], 'reports' => [['id' => 900, 'primary_module' => 'crm.vendors', 'folder_id' => 15, 'title' => 't']]], 'folder that none of its reports sat in'],
        ];

        foreach ($cases as $name => [$image, $needle]) {
            $write($image);

            [$code, $out] = $this->retire(['--restore' => $this->storage . '/forged.json']);

            $this->assertSame(1, $code, $name);
            $this->assertStringContainsString($needle, $out, $name);
            $this->assertSame($before, $this->snapshot(), $name);
        }
    }

    public function test_restore_will_not_read_a_file_this_command_did_not_write(): void
    {
        $path = $this->storage . '/other.json';
        file_put_contents($path, json_encode(['format' => 'gtm-retire-prospecting-forecast-before-image/1', 'menu' => [], 'rights' => []]));

        [$code, $out] = $this->retire(['--restore' => $path]);
        [$missing] = $this->retire(['--restore' => $this->storage . '/no-such-file.json']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('not a before-image written by this command', $out);
        $this->assertSame(2, $missing);
    }

    public function test_a_menu_row_that_has_children_is_left_alone(): void
    {
        DB::table('tblmenumaster_g2g')->insert(['id' => 490, 'menu_name' => 'Child', 'parent_id' => 452, 'access_link' => '/module/crm/sales/vendors-child', 'status' => 1, 'sort_order' => 1]);
        $before = $this->snapshot();

        [$code, $out] = $this->retire(['--apply' => true]);
        [$dry, $dryOut] = $this->retire();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('would be orphaned', $out);
        $this->assertStringContainsString('Nothing was written', $out);
        $this->assertSame(0, $dry, 'a dry run only reports it');
        $this->assertStringContainsString('REFUSED', $dryOut);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->images());
    }

    public function test_a_report_with_a_schedule_or_a_share_is_refused_and_named_and_nothing_is_written(): void
    {
        DB::table('report_schedules')->insert(['id' => 5, 'report_id' => 61, 'sub_institute_id' => 1, 'frequency' => 'weekly', 'is_active' => 1]);
        DB::table('report_shares')->insert(['id' => 8, 'sub_institute_id' => 6, 'report_id' => 34, 'viewer_user_id' => 99]);
        $before = $this->snapshot();

        [$code, $out] = $this->retire(['--apply' => true]);

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('REFUSED: report 61 "PurchaseOrder by Contacts" has a schedule (report_schedules row 5, weekly, active)', $out);
        $this->assertStringContainsString('REFUSED: report 34 "PurchaseOrder Detailed Report" is shared (report_shares row 8, with user 99)', $out);
        $this->assertStringContainsString('Nothing was written', $out);
        $this->assertSame($before, $this->snapshot(), 'not even the menu rows go while a schedule is in the way');
        $this->assertSame([], $this->images(), 'and no before-image is written for a refused run');
    }

    public function test_a_schedule_on_another_report_is_none_of_its_business(): void
    {
        DB::table('report_schedules')->insert(['id' => 5, 'report_id' => 70, 'sub_institute_id' => 1, 'frequency' => 'weekly', 'is_active' => 1]);

        [$code, $out] = $this->retire(['--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertSame([5], array_column($this->snapshot()['schedules'], 'id'));
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
