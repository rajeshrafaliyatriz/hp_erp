<?php

namespace Tests\Feature;

use App\Domain\Signals\Ingestion\DataConnector;
use App\Domain\Signals\Ingestion\DataConnectorSync;
use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Domain\Signals\Support\UnsafeUrlException;
use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DataConnectorControllerTest extends TestCase
{
    private const T1 = 1;
    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        config(['signals.legacy_product_icp' => true]);

        Schema::create('personal_access_tokens', function ($t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('tenant_setting', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('setting_key');
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
        });

        (require base_path('database/migrations/2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables.php'))->up();
        (require base_path('database/migrations/2026_09_30_180000_add_signal_fields_to_ingestion_findings.php'))->up();
        (require base_path('database/migrations/2026_09_30_200000_add_research_sources_and_signal_kind.php'))->up();
        (require base_path('database/migrations/2026_09_30_230000_add_claude_feed_and_linking_fields.php'))->up();
        (require base_path('database/migrations/2026_10_08_100000_extend_g2g_signals_for_market_import.php'))->up();
        (require base_path('database/migrations/2026_11_29_090000_add_assessment_to_g2g_company_opportunities.php'))->up();
        (require base_path('database/migrations/2026_10_14_100000_create_g2g_icp_profiles_tables.php'))->up();
        (require base_path('database/migrations/2026_12_01_090000_add_product_key_to_signals_runs_and_sources.php'))->up();
        (require base_path('database/migrations/2026_12_07_090000_add_scope_to_g2g_company_opportunities.php'))->up();

        DB::table('tbluserprofilemaster')->insert([
            ['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator'],
            ['id' => 2, 'name' => 'Employee', 'role_key' => 'employee'],
        ]);

        $this->app->instance(SafeUrlFetcher::class, new SafeUrlFetcher(function (string $host) {
            if ($host === 'localhost' || $host === '127.0.0.1') {
                return ['127.0.0.1'];
            }
            return ['93.184.216.34'];
        }));

        $this->seedProduct(self::T1);
        $this->seedProduct(self::T2);
    }

    private function h(int $tenant = self::T1, int $profile = 1): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile]);
        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    private function seedProduct(int $tenant): void
    {
        ProductProfile::create([
            'sub_institute_id' => $tenant,
            'product_name' => 'ScholarClone',
            'description' => 'School ERP system',
            'problems_solved' => 'Manual school operations',
            'features' => 'Fee collection, LMS, Attendance',
            'target_industries' => ['Education'],
            'target_markets' => ['India'],
            'keywords' => ['school erp'],
            'excluded' => [],
            'research_enabled' => true,
            'research_frequency' => 'daily',
            'recency_days' => 30,
        ]);
    }

    private function applyConnectorMigrations(): void
    {
        (require base_path('database/migrations/2026_11_04_120000_create_g2g_data_connector_tables.php'))->up();
        (require base_path('database/migrations/2026_11_04_140000_extend_g2g_data_connectors_for_social_sync.php'))->up();
    }

    public function test_index_handles_missing_tables_gracefully_without_sql_error(): void
    {
        // Tables are NOT migrated yet
        $this->assertFalse(Schema::hasTable('g2g_data_connectors'));

        $headers = $this->h(self::T1);
        $response = $this->getJson('/api/signals/ingestion/connectors', $headers);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 1,
            'tables_migrated' => false,
            'data' => [],
        ]);
        $this->assertArrayHasKey('available', $response->json());
        $this->assertArrayHasKey('notice', $response->json());
        $this->assertStringContainsString('migrate', $response->json('notice'));
    }

    public function test_mutations_return_503_when_tables_are_missing(): void
    {
        $headers = $this->h(self::T1);

        $response = $this->postJson('/api/signals/ingestion/connectors', [
            'type' => 'public_web_page',
            'name' => 'Edu News',
            'url' => 'https://example.com/news',
            'product_key' => 'scholarclone',
        ], $headers);

        $response->assertStatus(503);
        $response->assertJson(['status' => 0]);
        $this->assertStringContainsString('migrate', $response->json('message'));
    }

    public function test_full_connector_crud_and_lifecycle(): void
    {
        $this->applyConnectorMigrations();
        $headers = $this->h(self::T1);

        // 1. Create connector
        $created = $this->postJson('/api/signals/ingestion/connectors', [
            'type' => 'public_web_page',
            'name' => 'CBSE Affiliations',
            'url' => 'https://example.com/cbse-news',
            'product_key' => 'scholarclone',
            'customer_model' => 'b2b',
            'regions' => [['country' => 'India', 'state' => 'Maharashtra', 'city' => 'Mumbai']],
        ], $headers);

        $created->assertStatus(201);
        $connectorId = (int) $created->json('data.id');
        $this->assertGreaterThan(0, $connectorId);
        $this->assertSame('CBSE Affiliations', $created->json('data.name'));
        $this->assertSame('public_web_page', $created->json('data.type'));
        $this->assertSame('not_configured', $created->json('data.connection_status'));

        // 2. Read / List
        $list = $this->getJson('/api/signals/ingestion/connectors', $headers);
        $list->assertOk();
        $this->assertTrue($list->json('tables_migrated'));
        $this->assertCount(1, $list->json('data'));
        $this->assertSame($connectorId, $list->json('data.0.id'));

        // 3. Update connector
        $updated = $this->putJson("/api/signals/ingestion/connectors/{$connectorId}", [
            'name' => 'CBSE Official Circulars',
            'url' => 'https://example.com/cbse-circulars',
            'product_key' => 'scholarclone',
            'customer_model' => 'b2b',
            'regions' => [['country' => 'India', 'state' => 'Delhi', 'city' => 'New Delhi']],
        ], $headers);

        $updated->assertOk();
        $this->assertSame('CBSE Official Circulars', $updated->json('data.name'));
        $this->assertSame('https://example.com/cbse-circulars', $updated->json('data.url'));

        // 4. Schedule settings
        $scheduled = $this->postJson("/api/signals/ingestion/connectors/{$connectorId}/schedule", [
            'sync_enabled' => true,
            'schedule_enabled' => true,
            'schedule_interval_minutes' => 120,
            'schedule_timezone' => 'Asia/Kolkata',
        ], $headers);

        $scheduled->assertOk();
        $this->assertTrue($scheduled->json('data.schedule_enabled'));
        $this->assertSame(120, $scheduled->json('data.schedule_interval_minutes'));
        $this->assertSame('Asia/Kolkata', $scheduled->json('data.schedule_timezone'));

        // 5. Connect and Disconnect
        $disconnected = $this->postJson("/api/signals/ingestion/connectors/{$connectorId}/disconnect", [], $headers);
        $disconnected->assertOk();
        $this->assertSame('disconnected', $disconnected->json('data.connection_status'));

        $connected = $this->postJson("/api/signals/ingestion/connectors/{$connectorId}/connect", [], $headers);
        $connected->assertOk();
        $this->assertSame('not_configured', $connected->json('data.connection_status'));

        // 6. Delete connector
        $deleted = $this->deleteJson("/api/signals/ingestion/connectors/{$connectorId}", [], $headers);
        $deleted->assertOk();
        $deleted->assertJson(['status' => 1]);

        $this->assertDatabaseMissing('g2g_data_connectors', ['id' => $connectorId]);

        // List should now be empty
        $afterDelete = $this->getJson('/api/signals/ingestion/connectors', $headers);
        $afterDelete->assertOk();
        $this->assertCount(0, $afterDelete->json('data'));
    }

    public function test_tenant_isolation_on_crud_and_delete(): void
    {
        $this->applyConnectorMigrations();
        $h1 = $this->h(self::T1);
        $h2 = $this->h(self::T2);

        $res1 = $this->postJson('/api/signals/ingestion/connectors', [
            'type' => 'public_web_page',
            'name' => 'T1 Connector',
            'url' => 'https://example.com/t1',
            'product_key' => 'scholarclone',
        ], $h1);
        $res1->assertStatus(201);
        $id1 = (int) $res1->json('data.id');

        // Tenant 2 cannot see Tenant 1's connector
        $list2 = $this->getJson('/api/signals/ingestion/connectors', $h2);
        $list2->assertOk();
        $this->assertCount(0, $list2->json('data'));

        // Tenant 2 cannot update Tenant 1's connector
        $this->putJson("/api/signals/ingestion/connectors/{$id1}", [
            'name' => 'Hijacked',
            'product_key' => 'scholarclone',
        ], $h2)->assertStatus(404);

        // Tenant 2 cannot delete Tenant 1's connector
        $this->deleteJson("/api/signals/ingestion/connectors/{$id1}", [], $h2)->assertStatus(404);

        // Connector is still in database for Tenant 1
        $this->assertDatabaseHas('g2g_data_connectors', ['id' => $id1, 'sub_institute_id' => self::T1]);
    }

    public function test_url_and_account_validation(): void
    {
        $this->applyConnectorMigrations();
        $headers = $this->h(self::T1);

        // Public web page requires URL
        $this->postJson('/api/signals/ingestion/connectors', [
            'type' => 'public_web_page',
            'name' => 'Missing URL',
            'product_key' => 'scholarclone',
        ], $headers)->assertStatus(422);

        // LinkedIn requires numeric account ID
        $this->postJson('/api/signals/ingestion/connectors', [
            'type' => 'linkedin',
            'name' => 'Non Numeric LinkedIn',
            'external_account_id' => 'abc-company',
            'product_key' => 'scholarclone',
        ], $headers)->assertStatus(422);

        // SSRF protection prevents loopback URL
        $ssrf = $this->postJson('/api/signals/ingestion/connectors', [
            'type' => 'public_web_page',
            'name' => 'SSRF Attack',
            'url' => 'http://127.0.0.1/admin',
            'product_key' => 'scholarclone',
        ], $headers);
        $ssrf->assertStatus(422);
    }
}
