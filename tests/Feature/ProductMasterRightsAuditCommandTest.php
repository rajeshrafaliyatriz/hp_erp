<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsCrmProductApiWorld;
use Tests\TestCase;

/**
 * product-master:rights-audit (slice 15, plan step P4.2): READ ONLY, per profile, found by access_link, with the notes the owner asked for.
 */
class ProductMasterRightsAuditCommandTest extends TestCase
{
    use BuildsCrmProductApiWorld;

    private const T = 1;

    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrmProductApiWorld();
        foreach ([[4, 'hr_manager'], [5, 'employee'], [7, 'employee'], [8, 'employee'], [9, 'employee']] as [$id, $role]) {
            DB::table('tbluserprofilemaster')->insert(['id' => $id, 'name' => "Profile {$id}", 'role_key' => $role, 'sub_institute_id' => self::T]);
        }
        $this->grant(4, 468, 1, 0, 0, 0);   // HR holds the Products view: prices visible
        $this->grant(5, 468, 1, 1, 1, 1);   // a seller: every right on Products
        $this->grant(7, 469, 1, 1, 1, 1);   // services only
        $this->grant(8, 468, 0, 1, 1, 0);   // may add and edit but not view
        Cache::flush();
    }

    private function grant(int $profile, int $menu, int $view, int $add, int $edit, int $delete): void
    {
        DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => self::T, 'menu_id' => $menu, 'profile_id' => $profile, 'can_view' => $view, 'can_add' => $add, 'can_edit' => $edit, 'can_delete' => $delete]);
    }

    private function audit(array $options = ['--tenant' => self::T]): array
    {
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $code = Artisan::call('product-master:rights-audit', $options);

        return [$code, Artisan::output(), $queries];
    }

    public function test_it_names_each_profile_and_the_four_things_to_confirm(): void
    {
        [$code, $out] = $this->audit();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Profile 5', $out);
        $this->assertStringContainsString('CHECK #4 Profile 4 (hr_manager): an HR profile that holds the Products or Services view', $out, 'HR with the Products view sees prices');
        $this->assertStringContainsString('NOTE  #5 Profile 5 (employee): holds the Products view but not the Portfolio', $out, 'a seller reaches the list through the Products row');
        $this->assertStringContainsString('NOTE  #7 Profile 7 (employee): holds the Services view and not the Products view', $out);
        $this->assertStringContainsString('CHECK #8 Profile 8 (employee): may add or edit on the Products row but cannot view it', $out);
        $this->assertStringContainsString('Profile 9', $out, 'a profile with no row at all is in the table');
        $this->assertStringNotContainsString('#9 Profile 9', $out, 'and has nothing to confirm');
    }

    public function test_it_issues_only_select_statements_even_when_the_application_cache_is_the_database(): void
    {
        // found on the first run against the dev database: the menu id is remembered through Cache::remember, which with the database store is an INSERT / DELETE on `cache`
        Schema::create('cache', function ($table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        config(['cache.default' => 'database']);

        [, , $queries] = $this->audit();

        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(select|pragma|with)\b/i', $sql, 'a statement that is not a SELECT: ' . $sql);
        }
    }

    public function test_it_refuses_a_missing_tenant_and_a_connection_that_is_not_the_default(): void
    {
        [$code, $out] = $this->audit([]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('--tenant must be an organisation number', $out);

        [$code, $out] = $this->audit(['--tenant' => self::T, '--database' => 'somewhere_else']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('default connection', $out);
    }
}
