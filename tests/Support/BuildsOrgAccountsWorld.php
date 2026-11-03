<?php

namespace Tests\Support;

use App\Domain\OrgAccounts\OrgAccount;
use Illuminate\Support\Facades\DB;

/**
 * The database the Organisation 360 tests (slice 3) run on: the three source tables and the two identity tables come from the REAL migration files,
 * run on the in-memory sqlite database, plus one-line helpers to put a row in each source. Use it from a test class and call buildOrgAccountsWorld()
 * in setUp().
 */
trait BuildsOrgAccountsWorld
{
    /** @var list<string> */
    private array $worldMigrations = [
        '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables',   // g2g_companies
        '2026_10_08_100000_extend_g2g_signals_for_market_import',         // g2g_companies.state
        '2026_10_10_100000_create_gtm_foundation_tables',                 // gtm_accounts
        '2026_11_20_090100_create_crm_organizations_table',               // crm_organizations
        '2026_11_24_081100_add_crm_organization_id_to_gtm_accounts_table', // gtm_accounts.crm_organization_id
        '2026_12_09_090000_create_g2g_org_accounts_tables',               // g2g_org_accounts, g2g_org_link_rejections
    ];

    protected function buildOrgAccountsWorld(): void
    {
        foreach ($this->worldMigrations as $name) {
            (require base_path("database/migrations/{$name}.php"))->up();
        }
    }

    /** @param array<string, mixed> $o */
    protected function crm(string $name, int $tenant = 1, array $o = []): int
    {
        static $seq = 0;
        $seq++;

        return (int) DB::table('crm_organizations')->insertGetId($o + [
            'sub_institute_id' => $tenant, 'name' => $name, 'account_no' => 'ACC' . $seq . '-' . bin2hex(random_bytes(3)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $o */
    protected function gtm(string $name, int $tenant = 1, array $o = []): int
    {
        return (int) DB::table('gtm_accounts')->insertGetId($o + ['sub_institute_id' => $tenant, 'name' => $name, 'stage' => 'target', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $o */
    protected function company(string $name, int $tenant = 1, array $o = []): int
    {
        return (int) DB::table('g2g_companies')->insertGetId($o + [
            'sub_institute_id' => $tenant, 'name' => $name, 'normalized_name' => strtolower($name), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, array<string, mixed>> identity pointers keyed by "type:id" => the identity row (live ones only) */
    protected function identityByRow(int $tenant = 1): array
    {
        $out = [];
        foreach (OrgAccount::query()->where('sub_institute_id', $tenant)->get() as $identity) {
            foreach (OrgAccount::POINTERS as $type => $column) {
                if ($identity->{$column} !== null) {
                    $out[$type . ':' . $identity->{$column}] = $identity->getAttributes();
                }
            }
        }

        return $out;
    }

    /** One string that changes when anything in the table changes (rows in id order, every column). */
    protected function checksum(string $table): string
    {
        return md5(json_encode(DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()));
    }

    /** @return array<string, string> checksum of each source table */
    protected function sourceChecksums(): array
    {
        return array_combine(['crm_organizations', 'gtm_accounts', 'g2g_companies'], array_map(fn ($t) => $this->checksum($t), ['crm_organizations', 'gtm_accounts', 'g2g_companies']));
    }
}
