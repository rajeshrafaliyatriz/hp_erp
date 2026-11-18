<?php

namespace Tests\Feature;

use App\Support\ColumnExists;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Slice 3 schema: the organisation identity table, the rejected-suggestion table and the email-width migration. Runs the real up() and down() on sqlite. */
class OrgAccountsSchemaTest extends TestCase
{
    private const TABLES = '2026_12_09_090000_create_g2g_org_accounts_tables';

    private const EMAILS = '2026_12_09_090100_widen_crm_email_columns_to_191';

    private function migration(string $name): object
    {
        return require base_path("database/migrations/{$name}.php");
    }

    /** @param array<string, mixed> $o */
    private function insert(array $o = []): int
    {
        return (int) DB::table('g2g_org_accounts')->insertGetId($o + ['sub_institute_id' => 6, 'name' => 'Acme School', 'normalized_name' => 'acme', 'lifecycle' => 'discovered']);
    }

    public function test_up_creates_both_tables_with_their_columns_and_is_idempotent(): void
    {
        $this->assertFalse(Schema::hasTable('g2g_org_accounts'));
        $this->migration(self::TABLES)->up();
        $this->migration(self::TABLES)->up();

        foreach (['id', 'sub_institute_id', 'name', 'normalized_name', 'domain', 'lifecycle', 'crm_organization_id', 'gtm_account_id', 'g2g_company_id', 'crm_link_basis', 'gtm_link_basis',
            'company_link_basis', 'owner_user_id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'] as $c) {
            $this->assertTrue(ColumnExists::has('g2g_org_accounts', $c), "g2g_org_accounts.{$c}");
        }
        foreach (['id', 'sub_institute_id', 'left_type', 'left_id', 'right_type', 'right_id', 'decided_by', 'created_at'] as $c) {
            $this->assertTrue(ColumnExists::has('g2g_org_link_rejections', $c), "g2g_org_link_rejections.{$c}");
        }
        $names = array_map(fn ($i) => $i['name'], Schema::getIndexes('g2g_org_accounts'));
        foreach (['g2g_oa_tenant_crm', 'g2g_oa_tenant_gtm', 'g2g_oa_tenant_company', 'g2g_oa_tenant_name', 'g2g_oa_tenant_lifecycle', 'g2g_oa_tenant_domain'] as $i) {
            $this->assertContains($i, $names, $i);
        }
    }

    public function test_one_source_row_can_belong_to_only_one_identity_in_a_tenant(): void
    {
        $this->migration(self::TABLES)->up();
        $this->insert(['crm_organization_id' => 11, 'gtm_account_id' => 21, 'g2g_company_id' => 31]);

        foreach (['crm_organization_id' => 11, 'gtm_account_id' => 21, 'g2g_company_id' => 31] as $column => $id) {
            try {
                $this->insert([$column => $id]);
                $this->fail("a second identity pointing at the same {$column} must be refused");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        // The same ids in another organisation are a different row of the world.
        $this->insert(['sub_institute_id' => 7, 'crm_organization_id' => 11, 'gtm_account_id' => 21, 'g2g_company_id' => 31]);
        $this->assertSame(2, DB::table('g2g_org_accounts')->count());
    }

    public function test_many_identities_may_have_no_pointer_at_all(): void
    {
        $this->migration(self::TABLES)->up();
        $this->insert();
        $this->insert();
        $this->insert(['g2g_company_id' => 5]);
        $this->insert(['g2g_company_id' => null]);

        $this->assertSame(4, DB::table('g2g_org_accounts')->count(), 'NULL pointers never collide');
    }

    public function test_a_rejected_suggestion_is_recorded_once(): void
    {
        $this->migration(self::TABLES)->up();
        $row = ['sub_institute_id' => 6, 'left_type' => 'gtm_account', 'left_id' => 1, 'right_type' => 'g2g_company', 'right_id' => 2, 'decided_by' => 3];
        DB::table('g2g_org_link_rejections')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('g2g_org_link_rejections')->insert($row);
    }

    public function test_down_removes_both_tables_and_a_second_down_changes_nothing(): void
    {
        $this->migration(self::TABLES)->up();
        $this->migration(self::TABLES)->down();
        $this->migration(self::TABLES)->down();

        $this->assertFalse(Schema::hasTable('g2g_org_accounts'));
        $this->assertFalse(Schema::hasTable('g2g_org_link_rejections'));
    }

    public function test_the_email_widening_is_a_harmless_no_op_on_sqlite_and_down_does_nothing(): void
    {
        Schema::create('crm_organizations', function ($t) {
            $t->id();
            $t->string('email', 100)->nullable();
        });
        $before = ColumnExists::all('crm_organizations');

        $this->migration(self::EMAILS)->up();
        $this->migration(self::EMAILS)->down();

        $this->assertSame($before, ColumnExists::all('crm_organizations'));
    }

    public function test_the_email_widening_names_the_four_columns_and_only_widens(): void
    {
        $source = (string) file_get_contents(base_path('database/migrations/' . self::EMAILS . '.php'));

        $this->assertStringContainsString("'crm_organizations' => ['email', 'secondary_email']", $source);
        $this->assertStringContainsString("'crm_contacts' => ['email', 'secondary_email']", $source);
        $this->assertStringContainsString('VARCHAR(191)', $source);
        $this->assertStringContainsString('(int) $row->len >= 191', $source, 'a column already wide enough is left alone');
        $this->assertStringNotContainsString('DROP', strtoupper(preg_replace('#/\*.*?\*/#s', '', $source) ?? ''), 'it only widens');
    }
}
