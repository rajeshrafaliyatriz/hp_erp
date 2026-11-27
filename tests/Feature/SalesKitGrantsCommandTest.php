<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSalesKitWorld;
use Tests\TestCase;

/**
 * `php artisan crm:grant-sales-kit-rights {--tenant=6} {--apply}` (27 Nov 2026): the starting rights on the Sales Kit menu row for one organization's profiles. A dry run by default; --apply
 * writes only the LEAF rows the named profiles of THAT organization lack, never overwrites a row, saves a before-image and a summary, and a re-run changes nothing.
 */
class SalesKitGrantsCommandTest extends TestCase
{
    use BuildsSalesKitWorld;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalesKitWorld();
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sales-kit-grants-' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @return array<string, int> profile name => id, for one organization */
    private function profiles(int $tenant, array $names = ['Admin', 'HR', 'HR Executive', 'Reporting Manager', 'Employee', 'Department Head']): array
    {
        $ids = [];
        foreach ($names as $name) {
            $ids[$name] = (int) DB::table('tbluserprofilemaster')->insertGetId(['name' => $name, 'role_key' => null, 'sub_institute_id' => $tenant]);
        }

        return $ids;
    }

    private function users(int $profile, int $tenant, int $active, int $inactive = 0, int $deleted = 0): void
    {
        $row = fn (array $over) => $over + ['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile, 'status' => 1];
        for ($i = 0; $i < $active; $i++) {
            DB::table('tbluser')->insert($row([]));
        }
        for ($i = 0; $i < $inactive; $i++) {
            DB::table('tbluser')->insert($row(['status' => 0]));
        }
        for ($i = 0; $i < $deleted; $i++) {
            DB::table('tbluser')->insert($row(['deleted_at' => now()]));
        }
    }

    /** @param array<string, mixed> $options */
    private function grant(array $options = []): array
    {
        $code = Artisan::call('crm:grant-sales-kit-rights', $options + ['--image-dir' => $this->dir]);

        return [$code, Artisan::output()];
    }

    private function rightsOnKit(): array
    {
        return DB::table('tblgroupwise_rights_g2g')->where('menu_id', $this->kitMenuId())->orderBy('profile_id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function flags(int $profile): ?array
    {
        $row = DB::table('tblgroupwise_rights_g2g')->where('menu_id', $this->kitMenuId())->where('profile_id', $profile)->first();

        return $row ? [(int) $row->can_view, (int) $row->can_add, (int) $row->can_edit, (int) $row->can_delete] : null;
    }

    public function test_a_dry_run_writes_nothing_and_prints_each_profile_with_its_active_users_and_what_it_would_get(): void
    {
        $p = $this->profiles(self::T);
        $this->users($p['Admin'], self::T, 2, 1, 1);
        $this->users($p['Employee'], self::T, 9);
        $this->users($p['Department Head'], self::T, 4);
        $rightsBefore = DB::table('tblgroupwise_rights_g2g')->count();

        [$code, $out] = $this->grant(['--tenant' => self::T]);

        $this->assertSame(0, $code);
        $this->assertSame($rightsBefore, DB::table('tblgroupwise_rights_g2g')->count(), 'a dry run writes nothing');
        $this->assertDirectoryDoesNotExist($this->dir, 'and saves no file');
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('organization 6', $out);
        $this->assertStringContainsString(sprintf('profile %d "Admin": 2 active user(s): would be given view, add, edit, delete', $p['Admin']), $out, 'only status 1 and not deleted are counted');
        $this->assertStringContainsString(sprintf('profile %d "Employee": 9 active user(s): would be given view', $p['Employee']), $out);
        $this->assertStringContainsString(sprintf('profile %d "HR": 0 active user(s)', $p['HR']), $out);
        $this->assertStringContainsString('5 right(s) to write for 11 active user(s) in total', $out);
        $this->assertStringContainsString('Nothing was written', $out);
        $this->assertStringNotContainsString('Department Head', $out, 'a profile outside the proposed matrix is not mentioned and gets nothing');
    }

    public function test_apply_writes_the_proposed_matrix_on_the_leaf_only_for_the_named_organizations_profiles(): void
    {
        $six = $this->profiles(self::T);
        $seven = $this->profiles(self::T2);
        $this->users($six['Admin'], self::T, 2);
        $this->users($seven['Admin'], self::T2, 5);
        $allBefore = DB::table('tblgroupwise_rights_g2g')->count();

        [$code, $out] = $this->grant(['--tenant' => self::T, '--apply' => true]);

        $this->assertSame(0, $code, $out);
        $this->assertSame([1, 1, 1, 1], $this->flags($six['Admin']));
        $this->assertSame([1, 1, 1, 1], $this->flags($six['HR']));
        $this->assertSame([1, 1, 1, 1], $this->flags($six['HR Executive']));
        $this->assertSame([1, 0, 0, 0], $this->flags($six['Reporting Manager']));
        $this->assertSame([1, 0, 0, 0], $this->flags($six['Employee']));
        $this->assertNull($this->flags($six['Department Head']), 'every other profile gets nothing');
        foreach ($seven as $name => $id) {
            $this->assertNull($this->flags($id), "organization 7's {$name} is another organization's profile");
        }
        $this->assertSame($allBefore + 5, DB::table('tblgroupwise_rights_g2g')->count());
        $this->assertSame(5, DB::table('tblgroupwise_rights_g2g')->where('menu_id', $this->kitMenuId())->count());
        $this->assertSame(0, DB::table('tblgroupwise_rights_g2g')->whereIn('menu_id', [476, 479])->count(), 'the ancestors (Market Signals, Product Portfolio) are never granted');
        $row = DB::table('tblgroupwise_rights_g2g')->where('profile_id', $six['Admin'])->first();
        $this->assertSame(['6', 0, 0], [(string) $row->sub_institute_id, (int) $row->dashboard_right, (int) $row->is_mobile]);
        $this->assertStringContainsString('DONE: 5 right(s) written', $out);
    }

    public function test_apply_saves_a_before_image_and_a_summary_with_the_ids_it_wrote_and_how_to_undo_them(): void
    {
        $p = $this->profiles(self::T);
        $this->users($p['Employee'], self::T, 3);
        $this->users($p['Admin'], self::T, 2);

        [$code] = $this->grant(['--tenant' => self::T, '--apply' => true]);

        $this->assertSame(0, $code);
        $before = glob($this->dir . '/grant-sales-kit-rights-T6-*-before.json');
        $summary = glob($this->dir . '/grant-sales-kit-rights-T6-*-summary.json');
        $this->assertCount(1, $before);
        $this->assertCount(1, $summary);
        $image = json_decode((string) file_get_contents($before[0]), true);
        $done = json_decode((string) file_get_contents($summary[0]), true);
        $this->assertSame('crm-grant-sales-kit-rights/1', $image['format']);
        $this->assertSame([], $image['existing_rights_on_menu_row'], 'the before-image is what was there: no rights on the Sales Kit');
        $this->assertSame(self::KIT, $image['menu_row']['access_link']);
        $this->assertSame(['Admin', 'HR', 'HR Executive', 'Reporting Manager', 'Employee'], array_column($image['plan'], 'name'));
        $this->assertSame(5, $done['rows_written']);
        $this->assertSame(5, $done['active_users_reached']);
        $ids = array_column($done['rights'], 'id');
        $this->assertSame(array_map('intval', array_column($this->rightsOnKit(), 'id')), $ids);
        $this->assertSame('DELETE FROM tblgroupwise_rights_g2g WHERE menu_id = ' . $this->kitMenuId() . ' AND id IN (' . implode(', ', $ids) . ');', $done['undo']);
        // The undo statement does what it says.
        DB::statement($done['undo']);
        $this->assertSame([], $this->rightsOnKit());
    }

    public function test_an_existing_row_is_never_overwritten_whatever_it_says_and_even_when_its_tenant_column_is_blank_or_a_list(): void
    {
        $p = $this->profiles(self::T);
        $kit = $this->kitMenuId();
        // Employee was set in Role and Permissions to view AND add; the row's tenant column is NULL. Admin's row says "no view" (a deliberate revoke) with a CSV tenant column.
        DB::table('tblgroupwise_rights_g2g')->insert([
            ['menu_id' => $kit, 'profile_id' => $p['Employee'], 'can_view' => 1, 'can_add' => 1, 'sub_institute_id' => null],
            ['menu_id' => $kit, 'profile_id' => $p['Admin'], 'can_view' => 0, 'can_add' => 0, 'sub_institute_id' => '1,2,3,6,7'],
        ]);
        $before = $this->rightsOnKit();

        [, $out] = $this->grant(['--tenant' => self::T]);
        $this->assertStringContainsString('LEFT ALONE', $out);
        $this->assertStringContainsString('3 right(s) to write', $out, 'HR, HR Executive and Reporting Manager only');

        [$code] = $this->grant(['--tenant' => self::T, '--apply' => true]);

        $this->assertSame(0, $code);
        $after = $this->rightsOnKit();
        foreach ($before as $row) {
            $this->assertContains($row, $after, 'the row is exactly as it was, with its own id');
        }
        $this->assertSame([1, 1, 0, 0], $this->flags($p['Employee']));
        $this->assertSame([0, 0, 0, 0], $this->flags($p['Admin']));
        $this->assertSame(5, count($after));
        $this->assertSame(1, DB::table('tblgroupwise_rights_g2g')->where('menu_id', $kit)->where('profile_id', $p['Employee'])->count(), 'no second row for a profile that had one');
    }

    public function test_a_rerun_changes_nothing_and_writes_no_files(): void
    {
        $this->profiles(self::T);
        $this->grant(['--tenant' => self::T, '--apply' => true]);
        $first = $this->rightsOnKit();
        $files = glob($this->dir . '/*');

        [$code, $out] = $this->grant(['--tenant' => self::T, '--apply' => true]);

        $this->assertSame(0, $code);
        $this->assertSame($first, $this->rightsOnKit());
        $this->assertSame($files, glob($this->dir . '/*'), 'nothing to do, so no new before-image');
        $this->assertStringContainsString('Nothing to do', $out);
    }

    public function test_another_organization_is_granted_only_when_it_is_named_and_its_run_leaves_the_first_alone(): void
    {
        $six = $this->profiles(self::T);
        $seven = $this->profiles(self::T2, ['Admin', 'Employee']);
        $this->grant(['--tenant' => self::T, '--apply' => true]);
        $sixRows = $this->rightsOnKit();

        [$code, $out] = $this->grant(['--tenant' => self::T2, '--apply' => true]);

        $this->assertSame(0, $code);
        $this->assertSame([1, 1, 1, 1], $this->flags($seven['Admin']));
        $this->assertSame([1, 0, 0, 0], $this->flags($seven['Employee']));
        foreach ($sixRows as $row) {
            $this->assertContains($row, $this->rightsOnKit(), 'organization 6 is exactly as it was');
        }
        $this->assertStringContainsString('no profile named "HR" in organization 7', $out);
        $this->assertSame(7, count($this->rightsOnKit()));
    }

    public function test_profiles_are_found_by_name_ignoring_case_and_spaces_and_a_deleted_profile_is_not(): void
    {
        $lower = (int) DB::table('tbluserprofilemaster')->insertGetId(['name' => '  hr executive ', 'sub_institute_id' => self::T]);
        $gone = (int) DB::table('tbluserprofilemaster')->insertGetId(['name' => 'Admin', 'sub_institute_id' => self::T, 'deleted_at' => now()]);

        [$code] = $this->grant(['--tenant' => self::T, '--apply' => true]);

        $this->assertSame(0, $code);
        $this->assertSame([1, 1, 1, 1], $this->flags($lower));
        $this->assertNull($this->flags($gone), 'a deleted profile is not granted');
        $this->assertSame(1, count($this->rightsOnKit()));
    }

    public function test_it_refuses_when_the_menu_row_is_missing_when_the_tenant_is_not_a_number_and_when_the_connection_is_unknown(): void
    {
        $this->profiles(self::T);

        [$code, $out] = $this->grant(['--tenant' => 'six']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('--tenant must be a whole number', $out);
        [$code] = $this->grant(['--tenant' => '0']);
        $this->assertSame(2, $code);
        [$code, $out] = $this->grant(['--tenant' => self::T, '--connection' => 'nowhere']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString("no database connection named 'nowhere'", $out);

        DB::table('tblmenumaster_g2g')->where('access_link', self::KIT)->delete();
        [$code, $out] = $this->grant(['--tenant' => self::T, '--apply' => true]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('Run the migration 2026_12_30_090100_add_sales_kit_menu_row first', $out);
        $this->assertSame(0, DB::table('tblgroupwise_rights_g2g')->count());
        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function test_it_warns_when_the_menu_row_is_hidden_because_a_role_and_permissions_save_would_delete_its_rights(): void
    {
        $this->profiles(self::T);
        DB::table('tblmenumaster_g2g')->where('access_link', self::KIT)->update(['status' => 0]);

        [$code, $out] = $this->grant(['--tenant' => self::T]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('WARNING: the row is not status 1', $out);
    }

    public function test_an_organization_with_none_of_the_profiles_has_nothing_to_do(): void
    {
        [$code, $out] = $this->grant(['--tenant' => 99, '--apply' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Nothing to do', $out);
        $this->assertSame(0, DB::table('tblgroupwise_rights_g2g')->count());
    }

    public function test_the_granted_profiles_really_reach_the_routes_view_for_an_employee_and_everything_for_an_admin(): void
    {
        // End to end with the middleware: the rows the command writes are exactly what platformright reads.
        $p = $this->profiles(self::T);
        $this->grant(['--tenant' => self::T, '--apply' => true]);
        $product = $this->kitProduct(self::T);
        $mint = function (int $profile) {
            $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => self::T, 'user_profile_id' => $profile, 'status' => 1]);

            return ['Authorization' => 'Bearer ' . \App\Models\auth\tbluserModel::find($id)->createToken('t')->plainTextToken];
        };

        $this->getJson("/api/product-master/{$product}/kit", $mint($p['Employee']))->assertOk()->assertJsonPath('data.can.add', false);
        $this->postJson("/api/product-master/{$product}/kit/links", ['title' => 'T', 'url' => 'https://example.com', 'kind' => 'video'], $mint($p['Employee']))->assertStatus(403);
        $this->postJson("/api/product-master/{$product}/kit/links", ['title' => 'T', 'url' => 'https://example.com', 'kind' => 'video'], $mint($p['HR']))->assertCreated();
        $this->getJson("/api/product-master/{$product}/kit", $mint($p['Department Head']))->assertStatus(403);
    }
}
