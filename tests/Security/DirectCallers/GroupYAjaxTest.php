<?php

namespace Tests\Security\DirectCallers;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A-1c, Group Y: AJAXController's changed lookups (tableDataTenant, tableDataAuthenticated, AICourseGeneration, GammaContentGeneration).
 *
 * `GET /table_data` is ANONYMOUS BY DESIGN (a documented compatibility window: the legacy frontend sends no token). Its token lookup only decides whether a
 * caller counts as "authenticated", which unlocks (1) the schema dump `all_tables=1`, (2) tables with credential / identity columns (tbluser...), and (3)
 * pins the tenant to the token OWNER's. With the plain Sanctum lookup a fabricated "Session Token", an expired token or an orphaned token counted as
 * authenticated. After the fix they get exactly the ANONYMOUS treatment: no schema dump, no sensitive table, and no pinned tenant.
 *
 * HOW THE TENANT PIN IS TESTED (reviewer finding H1): the bad tokens whose owner exists (fabricated, EXPIRED, foreign-model) belong to the TENANT-1
 * administrator, while the request names TENANT 2 on a table that has distinct rows per tenant. A caller wrongly accepted as authenticated is pinned to
 * tenant 1 and sees tenant 1's rows; the anonymous caller sees tenant 2's. The two answers differ, so skipping the expiry check (or any other check) changes
 * the result. A control test proves the legitimate token really is pinned to tenant 1 on the same request.
 *
 * HONEST LIMIT: the controller asks `information_schema` (tables, columns) and `DATABASE()`, and filters tenants with MySQL's FIND_IN_SET. SQLite has none of
 * them, so this test attaches a fake in-memory `information_schema` and registers DATABASE() and FIND_IN_SET on the connection's PDO. The SHOW TABLES schema
 * dump cannot run on SQLite at all: for a legitimate token the test therefore requires the QueryException whose SQL is exactly `SHOW TABLES` (the first
 * statement after the gate; a refused caller never reaches it).
 */
class GroupYAjaxTest extends GroupYTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSiteTables();
        $this->installFakeInformationSchema();

        // tenant 1: users 1 (administrator) and 10; tenant 2: user 2.
        $this->makeUser(10, 1, 1);
    }

    /** Fake `information_schema.tables/columns`, DATABASE() and FIND_IN_SET so the controller's real code path runs on SQLite. */
    private function installFakeInformationSchema(): void
    {
        $pdo = $this->db()->getPdo();
        $pdo->sqliteCreateFunction('DATABASE', static fn () => 'main', 0);
        $pdo->sqliteCreateFunction('FIND_IN_SET', static function ($needle, $list) {
            return in_array((string) $needle, array_map('trim', explode(',', (string) $list)), true) ? 1 : 0;
        }, 2);
        $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
        $pdo->exec('CREATE TABLE information_schema.tables (table_schema TEXT, table_name TEXT)');
        $pdo->exec('CREATE TABLE information_schema.columns (table_schema TEXT, table_name TEXT, column_name TEXT)');
        foreach (['tbluser', 'sub_std_map'] as $table) {
            $pdo->exec("INSERT INTO information_schema.tables VALUES ('main', '{$table}')");
            $pdo->exec("INSERT INTO information_schema.columns VALUES ('main', '{$table}', 'sub_institute_id')");
        }
        // `password` on tbluser is what marks it SENSITIVE (credential-bearing) for the controller; sub_std_map is an ordinary tenant table.
        $pdo->exec("INSERT INTO information_schema.columns VALUES ('main', 'tbluser', 'password')");
        $pdo->exec("INSERT INTO information_schema.columns VALUES ('main', 'tbluser', 'deleted_at')");
    }

    /** @return list<int> the ids returned by table_data */
    private function ids(\Illuminate\Testing\TestResponse $response): array
    {
        $ids = array_map(static fn ($row) => (int) $row['id'], $response->json() ?? []);
        sort($ids);

        return $ids;
    }

    private function seedTenantRows(): void
    {
        DB::table('sub_std_map')->insert([
            ['id' => 1, 'sub_institute_id' => 1, 'subject_id' => 5],
            ['id' => 2, 'sub_institute_id' => 2, 'subject_id' => 6],
        ]);
    }

    /* ── GET /table_data: bad tokens ──────────────────────────────────── */

    #[DataProvider('kinds')]
    public function test_a_bad_token_cannot_list_the_schema(string $kind): void
    {
        $response = $this->getJson('/table_data?all_tables=1&token=' . urlencode($this->badToken($kind)));
        $response->assertStatus(401);
        $this->assertSame('Authentication is required to list tables.', $response->json('error'), $kind);
    }

    #[DataProvider('kinds')]
    public function test_a_bad_token_cannot_read_a_credential_table(string $kind): void
    {
        $token = $this->badToken($kind);

        $query = $this->getJson('/table_data?table=tbluser&filters[sub_institute_id]=1&token=' . urlencode($token));
        $query->assertStatus(401);
        $this->assertSame('Authentication is required to read "tbluser".', $query->json('error'), $kind);

        // Same through the Bearer header.
        $this->getJson('/table_data?table=tbluser&filters[sub_institute_id]=1', ['Authorization' => 'Bearer ' . $token])->assertStatus(401);
    }

    #[DataProvider('kinds')]
    public function test_request_supplied_identity_cannot_rescue_a_bad_token_on_table_data(string $kind): void
    {
        $token = $this->badToken($kind);
        foreach ($this->spoofs() as $spoof) {
            $query = http_build_query(array_merge(['table' => 'tbluser', 'token' => $token, 'filters' => ['sub_institute_id' => 1]], $spoof));
            $this->getJson('/table_data?' . $query)->assertStatus(401);

            $schema = http_build_query(array_merge(['all_tables' => 1, 'token' => $token], $spoof));
            $this->getJson('/table_data?' . $schema)->assertStatus(401);
        }
    }

    /**
     * H1. The request names TENANT 2; tenant 1 and tenant 2 have different rows. An accepted token whose owner is the tenant-1 administrator would pin the
     * query to tenant 1. A bad token must get the ANONYMOUS answer (tenant 2's rows), exactly like a request with no token at all.
     */
    #[DataProvider('kinds')]
    public function test_a_bad_token_gets_exactly_the_anonymous_answer_and_is_not_pinned_to_its_owners_tenant(string $kind): void
    {
        $this->seedTenantRows();
        $token = $this->badToken($kind);

        $anonymous = $this->getJson('/table_data?table=sub_std_map&filters[sub_institute_id]=2');
        $anonymous->assertStatus(200);
        $this->assertSame([2], $this->ids($anonymous), 'the documented anonymous compatibility window: the caller names the tenant');

        $response = $this->getJson('/table_data?table=sub_std_map&filters[sub_institute_id]=2&token=' . urlencode($token));
        $this->assertSame($anonymous->status(), $response->status(), $kind);
        $this->assertSame($anonymous->json(), $response->json(), $kind . ': identical to the anonymous answer');
        $this->assertNotSame([1], $this->ids($response), $kind . ': NOT the tenant-1 rows an accepted tenant-1 owner would be pinned to');

        // Bearer header form.
        $bearer = $this->getJson('/table_data?table=sub_std_map&filters[sub_institute_id]=2', ['Authorization' => 'Bearer ' . $token]);
        $this->assertSame($anonymous->json(), $bearer->json(), $kind . ' (bearer)');
    }

    /** CONTROL for the test above: the same request with a LEGITIMATE tenant-1 token IS pinned to tenant 1, so the two answers can differ. */
    public function test_control_a_legitimate_token_naming_tenant_2_is_pinned_to_tenant_1_unlike_an_anonymous_caller(): void
    {
        $this->seedTenantRows();

        $anonymous = $this->getJson('/table_data?table=sub_std_map&filters[sub_institute_id]=2');
        $pinned = $this->getJson('/table_data?table=sub_std_map&filters[sub_institute_id]=2&token=' . urlencode($this->adminToken));

        $this->assertSame([2], $this->ids($anonymous));
        $this->assertSame([1], $this->ids($pinned), 'proven identity wins over the requested tenant');
        $this->assertNotSame($anonymous->json(), $pinned->json(), 'the anonymous and the pinned answers differ: the comparison above can detect an accepted token');
    }

    public function test_the_orphaned_token_no_longer_unlocks_the_credential_table_for_any_tenant(): void
    {
        // The scenario that was worst under the plain lookup: authenticated=true, but the owner is gone, so NO tenant was pinned and the REQUEST chose one.
        $orphan = $this->badToken('orphaned token (owner deleted)');

        $response = $this->getJson('/table_data?table=tbluser&filters[sub_institute_id]=2&token=' . urlencode($orphan));
        $response->assertStatus(401);
        $this->assertStringNotContainsString('user2', (string) $response->getContent());
    }

    /* ── GET /table_data: legitimate tokens ───────────────────────────── */

    public function test_a_legitimate_token_is_still_authenticated_and_pinned_to_its_own_tenant(): void
    {
        $own = $this->getJson('/table_data?table=tbluser&token=' . urlencode($this->adminToken));
        $own->assertStatus(200);
        $this->assertSame([1, 10], $this->ids($own), 'authenticated: the credential-bearing table is readable, for its own tenant only');

        // Naming ANOTHER tenant does not widen it (proven identity wins over the request).
        $spoof = $this->getJson('/table_data?table=tbluser&filters[sub_institute_id]=2&token=' . urlencode($this->adminToken));
        $spoof->assertStatus(200);
        $this->assertSame([1, 10], $this->ids($spoof), 'the request cannot override the pinned tenant');

        // The other tenant's own token sees its own rows.
        $theirs = $this->getJson('/table_data?table=tbluser&token=' . urlencode($this->otherToken));
        $this->assertSame([2], $this->ids($theirs));

        // Bearer header form is unchanged.
        $bearer = $this->getJson('/table_data?table=tbluser', ['Authorization' => 'Bearer ' . $this->adminToken]);
        $this->assertSame([1, 10], $this->ids($bearer));
    }

    public function test_a_legitimate_token_passes_the_gate_for_the_schema_dump_and_reaches_show_tables(): void
    {
        $this->withoutExceptionHandling();
        try {
            $this->getJson('/table_data?all_tables=1&token=' . urlencode($this->adminToken));
            $this->fail('An authenticated schema dump must reach `SHOW TABLES`, which SQLite cannot run; an answer without that exception means the gate refused the caller.');
        } catch (QueryException $e) {
            $this->assertSame('SHOW TABLES', $e->getSql(), 'the first statement after the authentication gate');
        }
    }

    /* ── GET /AICourseGeneration ──────────────────────────────────────── */

    private function courseParams(array $extra = []): array
    {
        return array_merge([
            'sub_institute_id' => 1, 'user_id' => 1, 'user_profile_name' => 'ADMIN', 'syear' => '2026', 'industry' => 'Retail',
            'department' => 'Sales', 'subject_id' => 77,
        ], $extra);
    }

    #[DataProvider('kinds')]
    public function test_ai_course_generation_refuses_a_bad_token_before_any_write(string $kind): void
    {
        $token = $this->badToken($kind);
        $before = $this->snapshotAll();

        $response = $this->getJson('/AICourseGeneration?' . http_build_query($this->courseParams(['token' => $token])));
        $response->assertStatus(401);
        $this->assertSame('Invalid token', $response->json('message'), $kind);

        // Naming another tenant / user changes nothing for an unrecognised token.
        $this->getJson('/AICourseGeneration?' . http_build_query($this->courseParams(['token' => $token, 'sub_institute_id' => 2, 'user_id' => 2])))->assertStatus(401);

        $this->assertSame($before, $this->snapshotAll(), $kind . ': nothing written (no academic_section / department / course rows)');
    }

    public function test_ai_course_generation_with_a_legitimate_token_is_owner_pinned_and_unchanged(): void
    {
        // A course already exists for tenant 1 / subject 77: the method then answers without writing or calling any external API.
        DB::table('sub_std_map')->insert(['id' => 5, 'sub_institute_id' => 1, 'subject_id' => 77]);

        $own = $this->getJson('/AICourseGeneration?' . http_build_query($this->courseParams(['token' => $this->adminToken])));
        $own->assertStatus(200);
        $this->assertSame(2, $own->json('status_code'));
        $this->assertSame(5, $own->json('course_id'));

        // The request names tenant 2 and user 2: the token's owner (tenant 1) still decides.
        $spoof = $this->getJson('/AICourseGeneration?' . http_build_query($this->courseParams(['token' => $this->adminToken, 'sub_institute_id' => 2, 'user_id' => 2])));
        $spoof->assertStatus(200);
        $this->assertSame(5, $spoof->json('course_id'), 'the owner\'s tenant (1) was used, not the requested one (2)');
    }

    /* ── GET /gammaContent ────────────────────────────────────────────── */

    private function gammaParams(array $extra = []): array
    {
        return array_merge([
            'industry' => 'Retail', 'department' => 'Sales', 'jobrole' => 'Rep', 'skill' => 'Negotiation', 'content_title' => 'T', 'slide_count' => 5,
            'sub_institute_id' => 1, 'user_id' => 1, 'user_profile_name' => 'ADMIN', 'syear' => '2026',
        ], $extra);
    }

    #[DataProvider('kinds')]
    public function test_gamma_content_generation_refuses_a_bad_token_before_any_lookup_or_external_call(string $kind): void
    {
        $token = $this->badToken($kind);
        $before = $this->snapshotAll();

        $response = $this->getJson('/gammaContent?' . http_build_query($this->gammaParams(['token' => $token])));
        $response->assertStatus(401);
        $this->assertSame('Invalid token', $response->json('message'), $kind);

        $this->getJson('/gammaContent?' . http_build_query($this->gammaParams(['token' => $token, 'sub_institute_id' => 2, 'user_id' => 1])))->assertStatus(401);

        $this->assertSame($before, $this->snapshotAll(), $kind);
    }

    public function test_gamma_content_generation_with_a_legitimate_token_gets_past_the_gate(): void
    {
        // No matching skill row: the method stops with 404 BEFORE it calls Gemini or Gamma, so no external service is touched.
        $response = $this->getJson('/gammaContent?' . http_build_query($this->gammaParams(['token' => $this->adminToken])));
        $response->assertStatus(404);
        $this->assertSame('Skill not found in database', $response->json('error'));
        $this->assertFalse($response->json('status'));
    }
}
