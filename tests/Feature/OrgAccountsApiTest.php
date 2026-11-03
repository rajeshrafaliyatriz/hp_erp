<?php

namespace Tests\Feature;

use App\Domain\OrgAccounts\OrgLinkConflict;
use App\Domain\OrgAccounts\OrgLinker;
use App\Domain\OrgAccounts\OrgLinkNotFound;
use App\Domain\OrgAccounts\OrgResolver;
use App\Models\auth\tbluserModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Organisation 360 API (slice 3, task 3, Docs/agentic-crm/slices/S03-organisation-360.md): the list, the 360, the edits, the link answers and the
 * one-step "create the CRM organisation". Tables come from the real migration files on in-memory sqlite; the resolver and the linker (another track's
 * classes) are replaced by fakes except in the last test, which runs the real linker through the API. The cross-tenant sweep is its own file.
 *
 * The key lists below are the field names of g2gv0 services/org-accounts/types.ts: a renamed or dropped field fails here before the screen breaks.
 */
class OrgAccountsApiTest extends TestCase
{
    private const T = 1;

    private const T2 = 2;

    private const SCREEN = '/module/crm/organisation-360';

    private const CRM_MARKETING = '/module/crm/marketing/organizations';

    private const CRM_SUPPORT = '/module/crm/support/organizations';

    private const P = '/api/org-accounts';

    private const ITEM_KEYS = ['id', 'name', 'domain', 'lifecycle', 'owner_user_id', 'owner_name', 'sources', 'updated_at'];

    private const SUMMARY_KEYS = ['total', 'discovered', 'target', 'engaged', 'customer', 'archived', 'with_crm', 'with_gtm', 'with_signals', 'suggestions'];

    /** @var object a stand-in for OrgResolver that remembers how it was called */
    private object $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
        $this->resolver = $this->fakeResolver();
    }

    private function buildSchema(): void
    {
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
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
        });
        Schema::create('tblmenumaster_g2g', function ($t) {
            $t->id();
            $t->string('menu_name');
            $t->unsignedBigInteger('parent_id')->default(0);
            $t->integer('level')->default(1);
            $t->string('access_link')->nullable();
            $t->integer('status')->default(1);
        });
        Schema::create('tblgroupwise_rights_g2g', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('menu_id');
            $t->unsignedBigInteger('profile_id');
            $t->tinyInteger('can_view')->default(0);
            $t->tinyInteger('can_add')->default(0);
            $t->tinyInteger('can_edit')->default(0);
            $t->tinyInteger('can_delete')->default(0);
            $t->string('right_view')->nullable();
            $t->string('right_add')->nullable();
            $t->string('right_edit')->nullable();
            $t->string('right_delete')->nullable();
        });
        // The leads a signal was made into: only the columns the 360 reads (the real table is created by a migration that needs MySQL).
        Schema::create('crm_leads', function ($t) {
            $t->id();
            $t->string('lead_no', 30)->nullable();
            $t->string('lead_status')->nullable();
            $t->unsignedBigInteger('source_company_opportunity_id')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        foreach ([
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables', '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind', '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields', '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_11_29_090000_add_assessment_to_g2g_company_opportunities',
            '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', '2026_10_14_100000_create_g2g_icp_profiles_tables',
            '2026_10_31_090000_add_research_run_configuration_scope', '2026_12_07_090000_add_scope_to_g2g_company_opportunities',
            '2026_12_07_090000_create_g2g_product_master_table',
            '2026_12_08_090000_create_g2g_signal_reviews_table', '2026_12_08_090100_add_review_and_product_columns_to_g2g_company_opportunities',
            '2026_12_08_090200_add_cost_and_product_columns_to_g2g_research_runs',
            '2026_10_10_100000_create_gtm_foundation_tables', '2026_10_10_400000_create_gtm_deals_tables', '2026_11_20_090100_create_crm_organizations_table',
            '2026_11_20_090200_create_crm_contacts_table', '2026_11_20_090400_create_crm_campaigns_table', '2026_11_24_080300_create_crm_opportunities_table',
            '2026_11_24_081100_add_crm_organization_id_to_gtm_accounts_table', '2026_11_24_084000_create_crm_entity_comments_tables',
            '2026_10_10_090000_create_task_management_calendar_events_table', '2026_12_09_090000_create_g2g_org_accounts_tables',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
        // The columns 2026_11_21_080000 adds on the real hosts (that migration reads information_schema, which sqlite has not).
        Schema::table('crm_organizations', function ($t) {
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->unsignedBigInteger('deleted_by')->nullable();
        });

        Cache::flush();
        DB::table('tblmenumaster_g2g')->insert([
            ['id' => 301, 'menu_name' => 'Organisation 360', 'access_link' => self::SCREEN],
            ['id' => 302, 'menu_name' => 'Organizations', 'access_link' => self::CRM_MARKETING],
            ['id' => 303, 'menu_name' => 'Organizations (support)', 'access_link' => self::CRM_SUPPORT],
        ]);
    }

    // ---- people and rights ----

    /**
     * A user of a tenant on a profile of their own, holding exactly the given rights: link => actions.
     *
     * @param  array<string, list<string>>  $rights
     * @return array{id: int, h: array<string, string>}
     */
    private function user(array $rights = [], int $tenant = self::T, string $first = 'Asha', string $last = 'Rao', int $status = 1): array
    {
        $profile = DB::table('tbluserprofilemaster')->insertGetId(['name' => 'P' . uniqid(), 'role_key' => 'employee', 'sub_institute_id' => $tenant]);
        foreach ($rights as $link => $actions) {
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $tenant, 'menu_id' => (int) DB::table('tblmenumaster_g2g')->where('access_link', $link)->value('id'), 'profile_id' => $profile]
                + array_combine(array_map(fn ($a) => "can_{$a}", $actions), array_fill(0, count($actions), 1)));
        }
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'first_name' => $first, 'last_name' => $last, 'sub_institute_id' => $tenant, 'user_profile_id' => $profile, 'status' => $status]);

        return ['id' => $id, 'h' => ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken]];
    }

    /** Everything the screen offers: its own view, add and edit, and the CRM's add right for an organization. @return array{id: int, h: array<string, string>} */
    private function full(int $tenant = self::T, string $first = 'Asha', string $last = 'Rao'): array
    {
        return $this->user([self::SCREEN => ['view', 'add', 'edit'], self::CRM_MARKETING => ['view', 'add']], $tenant, $first, $last);
    }

    // ---- fakes for the other track's classes ----

    /** @param list<array<string, mixed>> $suggestions */
    private function fakeResolver(array $suggestions = []): object
    {
        $fake = new class($suggestions)
        {
            /** @var list<array{0: int, 1: int}> */
            public array $calls = [];

            public function __construct(private array $suggestions) {}

            public function suggestions(int $tenantId, int $limit = 100): array
            {
                $this->calls[] = [$tenantId, $limit];

                return array_slice($this->suggestions, 0, $limit);
            }
        };
        app()->instance(OrgResolver::class, $fake);

        return $fake;
    }

    private function fakeLinker(?\Throwable $throws = null): object
    {
        $fake = new class($throws)
        {
            /** @var list<array<int, mixed>> */
            public array $calls = [];

            public function __construct(public ?\Throwable $throws) {}

            public function confirm(...$args): array
            {
                $this->calls[] = ['confirm', ...$args];
                if ($this->throws) {
                    throw $this->throws;
                }

                return [];
            }

            public function reject(...$args): void
            {
                $this->calls[] = ['reject', ...$args];
                if ($this->throws) {
                    throw $this->throws;
                }
            }
        };
        app()->instance(OrgLinker::class, $fake);

        return $fake;
    }

    // ---- data ----

    /** @param array<string, mixed> $o */
    private function identity(int $tenant, string $name, array $o = []): int
    {
        return DB::table('g2g_org_accounts')->insertGetId($o + ['sub_institute_id' => $tenant, 'name' => $name, 'normalized_name' => strtolower($name), 'lifecycle' => 'discovered',
            'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-01 09:00:00']);
    }

    /** @param array<string, mixed> $o */
    private function crmOrg(int $tenant, string $name, array $o = []): int
    {
        return DB::table('crm_organizations')->insertGetId($o + ['account_no' => 'ORG-' . strtoupper(substr(uniqid(), -8)), 'name' => $name, 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $o */
    private function gtmAccount(int $tenant, string $name, array $o = []): int
    {
        return DB::table('gtm_accounts')->insertGetId($o + ['sub_institute_id' => $tenant, 'name' => $name, 'stage' => 'target', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $o */
    private function company(int $tenant, string $name, array $o = []): int
    {
        return DB::table('g2g_companies')->insertGetId($o + ['sub_institute_id' => $tenant, 'name' => $name, 'normalized_name' => strtolower($name), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $o */
    private function signal(int $tenant, int $company, array $o = []): int
    {
        return DB::table('g2g_company_opportunities')->insertGetId($o + [
            'sub_institute_id' => $tenant, 'company_id' => $company, 'research_run_id' => 1, 'title' => 'Signal ' . uniqid(), 'category' => 'hiring', 'observed_event' => 'e',
            'why_indicates_need' => 'w', 'product_fit' => 'f', 'recommended_action' => 'a', 'priority' => 'Medium', 'qualification' => 'Monitoring', 'confidence' => 'Medium',
            'review_status' => 'New', 'sources' => '[]', 'fingerprint' => uniqid(), 'first_discovered_at' => '2026-10-01 10:00:00', 'last_verified_at' => '2026-10-01 10:00:00',
            'is_sample' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> everything the create action could touch, to prove a refusal wrote nothing */
    private function footprint(): array
    {
        return [
            'crm' => DB::table('crm_organizations')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'identities' => DB::table('g2g_org_accounts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    /** @param list<string> $expected */
    private function assertKeys(array $expected, array $actual, string $what): void
    {
        sort($expected);
        $keys = array_keys($actual);
        sort($keys);
        $this->assertSame($expected, $keys, "the keys of {$what}");
    }

    /** One identity with a row in every source and something in every section of the 360. @return array<string, int> */
    private function populated(int $t = self::T): array
    {
        $asha = $this->user([], $t, 'Asha', 'Rao');
        $ravi = $this->user([], $t, 'Ravi', 'Shah');

        $crm = $this->crmOrg($t, 'Greenfield Academy', ['industry' => 'Education', 'phone' => '0120-111', 'email' => 'office@greenfield.example', 'website' => 'https://greenfield.example',
            'billing_city' => 'Noida', 'billing_state' => 'Uttar Pradesh', 'assigned_to' => $ravi['id']]);
        $gtm = $this->gtmAccount($t, 'Greenfield Academy', ['stage' => 'engaged', 'domain' => 'greenfield.example', 'icp_fit_score' => 82, 'owner_user_id' => $asha['id']]);
        $company = $this->company($t, 'Greenfield Academy', ['website' => 'https://greenfield.example', 'industry' => 'K-12', 'location' => 'Noida', 'state' => 'Uttar Pradesh', 'type' => 'private',
            'first_seen_at' => '2026-09-15 08:00:00']);
        $id = $this->identity($t, 'Greenfield Academy', [
            'domain' => 'greenfield.example', 'lifecycle' => 'engaged', 'owner_user_id' => $asha['id'], 'crm_organization_id' => $crm, 'gtm_account_id' => $gtm, 'g2g_company_id' => $company,
            'crm_link_basis' => 'manual', 'gtm_link_basis' => 'exact_name', 'company_link_basis' => 'existing_link',
        ]);

        // Signals: a dated event, a source-date-only one with a product, one undated and dismissed, a demo row and another organisation's signal on the same company id.
        DB::table('g2g_product_master')->insert([
            ['id' => 10, 'sub_institute_id' => 0, 'product_key' => 'scholar', 'kind' => 'product', 'name' => 'Scholar', 'status' => 'active', 'origin' => 'seed'],
            ['id' => 11, 'sub_institute_id' => $t + 1, 'product_key' => 'secret', 'kind' => 'product', 'name' => "Another organisation's product", 'status' => 'active', 'origin' => 'manual'],
        ]);
        $s1 = $this->signal($t, $company, ['title' => 'Hiring a principal', 'event_date' => '2026-09-01', 'business_fit' => 'scholar', 'first_discovered_at' => '2026-10-03 10:00:00']);
        $s2 = $this->signal($t, $company, ['title' => 'New campus', 'source_published_at' => '2026-08-20', 'product_master_id' => 10, 'review_status' => 'Reviewed', 'first_discovered_at' => '2026-10-02 10:00:00']);
        $s3 = $this->signal($t, $company, ['title' => 'Old news', 'review_status' => 'Dismissed', 'first_discovered_at' => '2026-10-01 10:00:00', 'product_master_id' => 11]);
        $this->signal($t, $company, ['title' => 'Demo row', 'is_sample' => 1]);
        $this->signal($t + 1, $company, ['title' => "Another organisation's signal"]);

        // People.
        $contact = fn (array $o) => $o + ['organization_id' => $crm, 'first_name' => null, 'title' => null, 'email' => null, 'phone' => null, 'mobile' => null, 'sub_institute_id' => $t, 'deleted_at' => null];
        DB::table('crm_contacts')->insert([
            $contact(['contact_no' => 'C-1', 'first_name' => 'Meera', 'last_name' => 'Iyer', 'title' => 'Principal', 'email' => 'meera@greenfield.example', 'mobile' => '98100']),
            $contact(['contact_no' => 'C-2', 'first_name' => 'Deleted', 'last_name' => 'Person', 'deleted_at' => now()]),
            $contact(['contact_no' => 'C-3', 'first_name' => 'Stranger', 'last_name' => 'Elsewhere', 'sub_institute_id' => $t + 1]),
        ]);
        DB::table('gtm_contacts')->insert([
            ['sub_institute_id' => $t, 'account_id' => $gtm, 'full_name' => 'Anil Bose', 'title' => null, 'role_in_deal' => 'champion', 'email' => 'anil@greenfield.example', 'phone' => '777', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Deals.
        $deal = fn (array $o) => $o + ['organization_id' => $crm, 'amount' => null, 'currency' => null, 'closing_date' => null, 'sales_stage' => null, 'sub_institute_id' => $t];
        DB::table('crm_opportunities')->insert([
            $deal(['opportunity_no' => 'O-1', 'name' => 'Scholar licence', 'amount' => 250000, 'currency' => 'INR', 'closing_date' => '2026-12-31', 'sales_stage' => 'Proposal/Price Quote']),
            $deal(['opportunity_no' => 'O-2', 'name' => 'Another organisation deal', 'sub_institute_id' => $t + 1]),
        ]);
        DB::table('gtm_deals')->insert([
            ['sub_institute_id' => $t, 'account_id' => $gtm, 'name' => 'Pilot', 'stage' => 'discovery', 'amount' => 5000.5, 'currency' => 'INR', 'expected_close_date' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Activity: a note, a task, a decision on a signal and a lead made from a signal.
        DB::table('crm_organization_comments')->insert([
            ['organization_id' => $crm, 'sub_institute_id' => $t, 'comment' => 'Called the principal', 'created_by' => $asha['id'], 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00'],
            ['organization_id' => $crm, 'sub_institute_id' => $t + 1, 'comment' => "Another organisation's note", 'created_by' => $asha['id'], 'created_at' => '2026-11-02 10:00:00', 'updated_at' => '2026-11-02 10:00:00'],
        ]);
        DB::table('task_management_calendar_events')->insert([
            ['sub_institute_id' => $t, 'syear' => '2026', 'title' => 'Demo at the school', 'start_at' => '2026-11-02 09:00:00', 'end_at' => '2026-11-02 10:00:00', 'owner_id' => $ravi['id'], 'linked_type' => 'ORGANIZATION', 'linked_id' => $crm, 'created_by' => $ravi['id'], 'created_at' => now(), 'updated_at' => now()],
            ['sub_institute_id' => $t, 'syear' => '2026', 'title' => 'Linked to a lead, not shown', 'start_at' => '2026-11-02 11:00:00', 'end_at' => '2026-11-02 12:00:00', 'owner_id' => $ravi['id'], 'linked_type' => 'LEAD', 'linked_id' => $crm, 'created_by' => $ravi['id'], 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('g2g_signal_reviews')->insert([
            ['sub_institute_id' => $t, 'opportunity_id' => $s1, 'decision' => 'follow_up', 'note' => 'Sent an intro', 'from_status' => 'New', 'to_status' => 'Follow-up', 'decided_by' => $asha['id'], 'decided_at' => '2026-11-03 08:00:00'],
            ['sub_institute_id' => $t + 1, 'opportunity_id' => $s1, 'decision' => 'dismissed', 'note' => null, 'from_status' => 'New', 'to_status' => 'Dismissed', 'decided_by' => null, 'decided_at' => '2026-11-03 09:00:00'],
        ]);
        DB::table('crm_leads')->insert([
            ['lead_no' => 'LD-1', 'lead_status' => 'New', 'source_company_opportunity_id' => $s2, 'sub_institute_id' => $t, 'created_by' => $asha['id'], 'created_at' => '2026-10-30 12:00:00'],
        ]);

        return ['identity' => $id, 'crm' => $crm, 'gtm' => $gtm, 'company' => $company, 'asha' => $asha['id'], 'ravi' => $ravi['id'], 's1' => $s1, 's2' => $s2, 's3' => $s3];
    }

    // ---- rights ----

    public function test_every_route_needs_a_token_and_the_right_for_its_action_and_there_is_no_administrator_bypass(): void
    {
        $id = $this->identity(self::T, 'Acme School');
        $anon = [];
        $none = $this->user();
        $admin = $this->user([], self::T);
        DB::table('tbluserprofilemaster')->where('id', DB::table('tbluser')->where('id', $admin['id'])->value('user_profile_id'))->update(['role_key' => 'administrator']);
        $viewer = $this->user([self::SCREEN => ['view']]);
        $editor = $this->user([self::SCREEN => ['edit']]);
        $adder = $this->user([self::SCREEN => ['add'], self::CRM_MARKETING => ['add']]);

        $routes = [
            ['GET', self::P, 'view'], ['GET', self::P . '/meta', 'view'], ['GET', self::P . '/suggestions', 'view'], ['GET', self::P . "/{$id}", 'view'],
            ['PATCH', self::P . "/{$id}", 'edit', ['name' => 'X']],
            ['POST', self::P . '/links', 'edit', ['left_type' => 'crm_organization', 'left_id' => 1, 'right_type' => 'gtm_account', 'right_id' => 1, 'action' => 'reject']],
            ['POST', self::P . "/{$id}/create-crm-organisation", 'add', []],
        ];
        $people = ['view' => $viewer, 'edit' => $editor, 'add' => $adder];
        $this->fakeLinker();

        foreach ($routes as $route) {
            [$method, $uri, $needs] = $route;
            $body = $route[3] ?? [];
            $this->json($method, $uri, $body, $anon)->assertStatus(401);
            $this->json($method, $uri, $body, $none['h'])->assertStatus(403);
            $this->json($method, $uri, $body, $admin['h'])->assertStatus(403);
            foreach ($people as $has => $person) {
                $res = $this->json($method, $uri, $body, $person['h']);
                $has === $needs ? $this->assertNotContains($res->getStatusCode(), [401, 403], "{$method} {$uri} with the {$has} right") : $res->assertStatus(403);
            }
        }
    }

    public function test_the_crm_add_right_is_taken_from_either_organization_menu_row_and_never_from_the_screen_alone(): void
    {
        $id = $this->identity(self::T, 'Acme School');
        $before = $this->footprint();

        $screenOnly = $this->user([self::SCREEN => ['view', 'add']]);
        $this->postJson(self::P . "/{$id}/create-crm-organisation", [], $screenOnly['h'])->assertStatus(403);
        $crmViewOnly = $this->user([self::SCREEN => ['add'], self::CRM_MARKETING => ['view'], self::CRM_SUPPORT => ['view', 'edit']]);
        $this->postJson(self::P . "/{$id}/create-crm-organisation", [], $crmViewOnly['h'])->assertStatus(403);
        $crmOnly = $this->user([self::CRM_MARKETING => ['add']]);
        $this->postJson(self::P . "/{$id}/create-crm-organisation", [], $crmOnly['h'])->assertStatus(403);
        $this->assertSame($before, $this->footprint(), 'a refusal writes nothing');

        $support = $this->user([self::SCREEN => ['add'], self::CRM_SUPPORT => ['add']]);
        $this->postJson(self::P . "/{$id}/create-crm-organisation", [], $support['h'])->assertStatus(201);
    }

    // ---- the list ----

    public function test_the_list_has_the_documented_envelope_and_item_shape(): void
    {
        $p = $this->populated();
        $this->identity(self::T, 'Bare School');
        $res = $this->getJson(self::P, $this->full()['h'])->assertOk();

        $this->assertKeys(['status', 'data', 'meta', 'summary'], $res->json(), 'the list');
        $this->assertSame(1, $res->json('status'));
        $this->assertKeys(['page', 'per_page', 'total', 'last_page'], $res->json('meta'), 'meta');
        $this->assertKeys(self::SUMMARY_KEYS, $res->json('summary'), 'summary');

        $items = collect($res->json('data'))->keyBy('id');
        $this->assertKeys(self::ITEM_KEYS, $items[$p['identity']], 'a list item');
        $this->assertKeys(['crm', 'gtm', 'signals'], $items[$p['identity']]['sources'], 'sources');
        $this->assertKeys(['id', 'account_no'], $items[$p['identity']]['sources']['crm'], 'sources.crm');
        $this->assertKeys(['id', 'stage'], $items[$p['identity']]['sources']['gtm'], 'sources.gtm');
        $this->assertKeys(['company_id', 'open_signals', 'last_signal_at'], $items[$p['identity']]['sources']['signals'], 'sources.signals');
    }

    public function test_a_list_item_shows_the_owner_and_what_each_source_holds(): void
    {
        $p = $this->populated();
        $bare = $this->identity(self::T, 'Bare School');

        $items = collect($this->getJson(self::P, $this->full()['h'])->assertOk()->json('data'))->keyBy('id');
        $item = $items[$p['identity']];
        $this->assertSame(['Greenfield Academy', 'greenfield.example', 'engaged', $p['asha'], 'Asha Rao'], [$item['name'], $item['domain'], $item['lifecycle'], $item['owner_user_id'], $item['owner_name']]);
        $this->assertSame($p['crm'], $item['sources']['crm']['id']);
        $this->assertSame(DB::table('crm_organizations')->where('id', $p['crm'])->value('account_no'), $item['sources']['crm']['account_no']);
        $this->assertSame(['id' => $p['gtm'], 'stage' => 'engaged'], $item['sources']['gtm']);
        // Three real signals, one dismissed: two are open. The demo row and the other organisation's signal on the same company id do not count.
        $this->assertSame($p['company'], $item['sources']['signals']['company_id']);
        $this->assertSame(2, $item['sources']['signals']['open_signals']);
        $this->assertSame(Carbon::parse('2026-10-03 10:00:00')->toIso8601String(), $item['sources']['signals']['last_signal_at']);

        $this->assertSame(['crm' => null, 'gtm' => null, 'signals' => null], $items[$bare]['sources']);
        $this->assertNull($items[$bare]['owner_user_id']);
        $this->assertNull($items[$bare]['owner_name']);
    }

    public function test_the_filters_narrow_the_list_and_the_summary_ignores_them(): void
    {
        $h = $this->full()['h'];
        $a = $this->identity(self::T, 'Alpha School', ['domain' => 'alpha.example', 'lifecycle' => 'engaged', 'crm_organization_id' => 1, 'gtm_account_id' => 1, 'g2g_company_id' => 1]);
        $b = $this->identity(self::T, 'Beta College', ['domain' => 'beta.example', 'lifecycle' => 'target', 'gtm_account_id' => 2]);
        $c = $this->identity(self::T, 'Gamma Trust', ['lifecycle' => 'discovered', 'g2g_company_id' => 3]);
        $d = $this->identity(self::T, 'Delta Group', ['lifecycle' => 'customer', 'crm_organization_id' => 4]);
        $this->identity(self::T, 'Deleted School', ['deleted_at' => now(), 'lifecycle' => 'archived']);
        $this->identity(self::T2, 'Other Tenant School', ['lifecycle' => 'engaged', 'crm_organization_id' => 9]);
        $ids = fn (string $query) => collect($this->getJson(self::P . $query, $h)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$a, $b, $d, $c], $ids(''), 'by name, A to Z; not the deleted one, not the other organisation');
        $this->assertSame([$b], $ids('?lifecycle=target'));
        $this->assertSame([$a, $d], $ids('?has_crm=1'));
        $this->assertSame([$b, $c], $ids('?has_crm=0'));
        $this->assertSame([$a, $b], $ids('?has_gtm=1'));
        $this->assertSame([$a, $c], $ids('?has_signals=1'));
        $this->assertSame([$d], $ids('?has_signals=0&has_gtm=0'));
        $this->assertSame([$a], $ids('?q=alpha'), 'name');
        $this->assertSame([$b], $ids('?q=beta.example'), 'domain');
        $this->assertSame([$a, $b], $ids('?q=.example'));
        $this->assertSame([$c, $d, $b, $a], $ids('?sort=name&dir=desc'));

        $summary = $this->getJson(self::P . '?lifecycle=target&q=beta', $h)->json('summary');
        $this->assertSame(['total' => 4, 'discovered' => 1, 'target' => 1, 'engaged' => 1, 'customer' => 1, 'archived' => 0, 'with_crm' => 2, 'with_gtm' => 2, 'with_signals' => 2, 'suggestions' => 0], $summary,
            "counts over this organisation's non-deleted identities, whatever the filters say");
    }

    public function test_the_search_treats_percent_and_underscore_as_ordinary_characters(): void
    {
        $h = $this->full()['h'];
        $percent = $this->identity(self::T, 'Acme 100% School');
        $this->identity(self::T, 'Acme 100 School');
        $under = $this->identity(self::T, 'Under_score Academy');
        $this->identity(self::T, 'UnderXscore Academy');
        $bang = $this->identity(self::T, 'Wow! Academy');
        $ids = fn (string $q) => collect($this->getJson(self::P . '?q=' . rawurlencode($q), $h)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$percent], $ids('100%'));
        $this->assertSame([$under], $ids('r_s'));
        $this->assertSame([$bang], $ids('w!'));
        $this->assertCount(5, array_unique(array_merge($ids('academy'), $ids('school'))), 'ordinary words still match');
    }

    public function test_paging_sorting_and_the_page_size_cap(): void
    {
        $h = $this->full()['h'];
        foreach (range(1, 55) as $i) {
            $this->identity(self::T, sprintf('School %02d', $i), ['updated_at' => Carbon::parse('2026-11-01')->addMinutes($i)->toDateTimeString()]);
        }

        $page = $this->getJson(self::P . '?per_page=500', $h)->assertOk();
        $this->assertCount(50, $page->json('data'), 'per_page is capped at 50');
        $this->assertSame(['page' => 1, 'per_page' => 50, 'total' => 55, 'last_page' => 2], $page->json('meta'));

        $second = $this->getJson(self::P . '?per_page=50&page=2', $h)->json();
        $this->assertSame(['School 51', 'School 52', 'School 53', 'School 54', 'School 55'], array_column($second['data'], 'name'));

        $recent = $this->getJson(self::P . '?sort=updated_at&per_page=3', $h)->json('data');
        $this->assertSame(['School 55', 'School 54', 'School 53'], array_column($recent, 'name'), 'by last change, newest first');
        $oldest = $this->getJson(self::P . '?sort=updated_at&dir=asc&per_page=2', $h)->json('data');
        $this->assertSame(['School 01', 'School 02'], array_column($oldest, 'name'));

        $this->assertSame(['page' => 1, 'per_page' => 25, 'total' => 55, 'last_page' => 3], $this->getJson(self::P, $h)->json('meta'), 'the default page size');
        $this->assertSame([], $this->getJson(self::P . '?page=9', $h)->json('data'));
    }

    public function test_a_bad_filter_is_a_field_keyed_422(): void
    {
        $h = $this->full()['h'];

        foreach (['lifecycle=gone' => 'lifecycle', 'has_crm=yes' => 'has_crm', 'sort=owner' => 'sort', 'page=0' => 'page', 'per_page=x' => 'per_page', 'dir=up' => 'dir'] as $query => $field) {
            $res = $this->getJson(self::P . '?' . $query, $h)->assertStatus(422);
            $this->assertSame(0, $res->json('status'));
            $this->assertIsString($res->json('message'));
            $this->assertArrayHasKey($field, $res->json('errors'), $query);
            $this->assertIsArray($res->json("errors.{$field}"));
        }
    }

    public function test_the_summary_suggestions_is_the_resolvers_count_not_capped_at_the_tab_size(): void
    {
        $many = array_map(fn ($i) => ['key' => "k{$i}", 'left' => ['type' => 'g2g_company', 'id' => $i, 'name' => 'A'], 'right' => ['type' => 'g2g_company', 'id' => $i + 1000, 'name' => 'A'], 'basis' => 'name_overlap', 'score' => 0.9], range(1, 130));
        $this->resolver = $this->fakeResolver($many);

        $this->assertSame(130, $this->getJson(self::P, $this->full()['h'])->json('summary.suggestions'));
        $this->assertSame(self::T, $this->resolver->calls[0][0], "the caller's organisation");
    }

    // ---- meta and suggestions ----

    public function test_meta_lists_the_lifecycles_and_what_the_caller_may_do(): void
    {
        $lifecycles = ['discovered', 'target', 'engaged', 'customer', 'archived'];
        $cases = [
            'everything' => [$this->full(), true, true, true],
            'view only' => [$this->user([self::SCREEN => ['view']]), false, false, false],
            'can edit, cannot add' => [$this->user([self::SCREEN => ['view', 'edit']]), false, true, true],
            'screen add without the CRM add right' => [$this->user([self::SCREEN => ['view', 'add']]), false, false, false],
            'CRM add without the screen add right' => [$this->user([self::SCREEN => ['view'], self::CRM_MARKETING => ['add']]), false, false, false],
            'the support menu row counts too' => [$this->user([self::SCREEN => ['view', 'add'], self::CRM_SUPPORT => ['add']]), true, false, false],
        ];

        foreach ($cases as $what => [$user, $create, $edit, $confirm]) {
            $res = $this->getJson(self::P . '/meta', $user['h'])->assertOk();
            $this->assertSame(1, $res->json('status'));
            $this->assertSame($lifecycles, $res->json('data.lifecycles'), $what);
            $this->assertSame(['create_crm_organization' => $create, 'edit' => $edit, 'confirm_links' => $confirm], $res->json('data.can'), $what);
        }
    }

    public function test_suggestions_are_the_resolvers_for_the_callers_organisation_limited_to_the_tab(): void
    {
        $suggestion = fn ($i) => ['key' => "crm_organization:{$i}|g2g_company:{$i}", 'left' => ['type' => 'crm_organization', 'id' => $i, 'name' => 'Acme'], 'right' => ['type' => 'g2g_company', 'id' => $i, 'name' => 'Acme School'], 'basis' => 'name_overlap', 'score' => 0.85];
        $this->resolver = $this->fakeResolver(array_map($suggestion, range(1, 120)));

        $res = $this->getJson(self::P . '/suggestions', $this->full()['h'])->assertOk();
        $this->assertSame(1, $res->json('status'));
        $this->assertCount(100, $res->json('data'));
        $this->assertKeys(['key', 'left', 'right', 'basis', 'score'], $res->json('data.0'), 'a suggestion');
        $this->assertSame([[self::T, 100]], $this->resolver->calls);

        $this->resolver = $this->fakeResolver([]);
        $this->assertSame([], $this->getJson(self::P . '/suggestions', $this->full()['h'])->json('data'));
    }

    // ---- the 360 ----

    public function test_the_360_has_the_documented_shape_and_content(): void
    {
        $p = $this->populated();
        $res = $this->getJson(self::P . "/{$p['identity']}", $this->full()['h'])->assertOk();
        $d = $res->json('data');

        $this->assertKeys(['status', 'data'], $res->json(), 'the envelope');
        $this->assertKeys(['identity', 'crm', 'gtm', 'company', 'signals', 'contacts', 'deals', 'activity', 'totals'], $d, 'the 360');
        $this->assertKeys(['id', 'name', 'domain', 'lifecycle', 'owner_user_id', 'owner_name', 'links', 'created_at', 'updated_at'], $d['identity'], 'identity');
        $this->assertKeys(['crm', 'gtm', 'company'], $d['identity']['links'], 'links');
        $this->assertKeys(['id', 'basis'], $d['identity']['links']['crm'], 'a link');
        $this->assertKeys(['id', 'account_no', 'name', 'industry', 'phone', 'email', 'website', 'billing_city', 'billing_state', 'assigned_to_name'], $d['crm'], 'crm');
        $this->assertKeys(['id', 'name', 'stage', 'domain', 'icp_fit_score', 'owner_name'], $d['gtm'], 'gtm');
        $this->assertKeys(['id', 'name', 'website', 'industry', 'location', 'state', 'type', 'first_seen_at'], $d['company'], 'company');
        $this->assertKeys(['total', 'needing_decision', 'decided', 'latest'], $d['signals'], 'signals');
        $this->assertKeys(['id', 'title', 'review_status', 'date_info', 'business_fit', 'product_master_name'], $d['signals']['latest'][0], 'a signal line');
        $this->assertKeys(['status', 'date'], $d['signals']['latest'][0]['date_info'], 'date_info');
        $this->assertKeys(['source', 'id', 'name', 'email', 'phone', 'role'], $d['contacts'][0], 'a contact line');
        $this->assertKeys(['source', 'id', 'name', 'stage', 'amount', 'currency', 'closing_date'], $d['deals'][0], 'a deal line');
        $this->assertKeys(['kind', 'at', 'by_name', 'text', 'source'], $d['activity'][0], 'an activity line');
        $this->assertKeys(['contacts', 'deals', 'activity'], $d['totals'], 'totals');

        $this->assertSame(['Greenfield Academy', 'greenfield.example', 'engaged', $p['asha'], 'Asha Rao'], [$d['identity']['name'], $d['identity']['domain'], $d['identity']['lifecycle'], $d['identity']['owner_user_id'], $d['identity']['owner_name']]);
        $this->assertSame(['crm' => ['id' => $p['crm'], 'basis' => 'manual'], 'gtm' => ['id' => $p['gtm'], 'basis' => 'exact_name'], 'company' => ['id' => $p['company'], 'basis' => 'existing_link']], $d['identity']['links']);
        $this->assertSame(Carbon::parse('2026-11-01 09:00:00')->toIso8601String(), $d['identity']['created_at']);

        $this->assertSame(['Greenfield Academy', 'Education', '0120-111', 'office@greenfield.example', 'https://greenfield.example', 'Noida', 'Uttar Pradesh', 'Ravi Shah'],
            [$d['crm']['name'], $d['crm']['industry'], $d['crm']['phone'], $d['crm']['email'], $d['crm']['website'], $d['crm']['billing_city'], $d['crm']['billing_state'], $d['crm']['assigned_to_name']]);
        $this->assertSame(['engaged', 'greenfield.example', 82, 'Asha Rao'], [$d['gtm']['stage'], $d['gtm']['domain'], $d['gtm']['icp_fit_score'], $d['gtm']['owner_name']]);
        $this->assertSame(['K-12', 'Noida', 'Uttar Pradesh', 'private', Carbon::parse('2026-09-15 08:00:00')->toIso8601String()],
            [$d['company']['industry'], $d['company']['location'], $d['company']['state'], $d['company']['type'], $d['company']['first_seen_at']]);
    }

    public function test_the_360_signals_count_real_signals_of_this_organisation_and_date_and_name_them(): void
    {
        $p = $this->populated();
        $s = $this->getJson(self::P . "/{$p['identity']}", $this->full()['h'])->assertOk()->json('data.signals');

        $this->assertSame([3, 1, 2], [$s['total'], $s['needing_decision'], $s['decided']], 'three real signals; no demo row, no other organisation');
        $this->assertSame(['Hiring a principal', 'New campus', 'Old news'], array_column($s['latest'], 'title'), 'newest first');
        $this->assertSame(['New', 'Reviewed', 'Dismissed'], array_column($s['latest'], 'review_status'));
        $this->assertSame(['status' => 'event_date_stated', 'date' => '2026-09-01'], $s['latest'][0]['date_info']);
        $this->assertSame(['status' => 'source_date_only', 'date' => '2026-08-20'], $s['latest'][1]['date_info']);
        $this->assertSame(['status' => 'undated', 'date' => null], $s['latest'][2]['date_info']);
        $this->assertSame(['scholar', null, null], array_column($s['latest'], 'business_fit'));
        $this->assertSame([null, 'Scholar', null], array_column($s['latest'], 'product_master_name'), "a shared product is named; another organisation's product is not");
    }

    public function test_the_360_lists_contacts_deals_and_activity_from_both_systems(): void
    {
        $p = $this->populated();
        $d = $this->getJson(self::P . "/{$p['identity']}", $this->full()['h'])->assertOk()->json('data');

        $this->assertSame([
            ['source' => 'gtm', 'id' => 1, 'name' => 'Anil Bose', 'email' => 'anil@greenfield.example', 'phone' => '777', 'role' => 'champion'],
            ['source' => 'crm', 'id' => 1, 'name' => 'Meera Iyer', 'email' => 'meera@greenfield.example', 'phone' => '98100', 'role' => 'Principal'],
        ], $d['contacts'], 'one CRM and one GTM contact, by name; not the deleted one, not the other organisation\'s');
        $this->assertSame(
            [['source' => 'crm', 'id' => 1, 'name' => 'Scholar licence', 'stage' => 'Proposal/Price Quote', 'amount' => 250000, 'currency' => 'INR', 'closing_date' => '2026-12-31'],
                ['source' => 'gtm', 'id' => 1, 'name' => 'Pilot', 'stage' => 'discovery', 'amount' => 5000.5, 'currency' => 'INR', 'closing_date' => null]],
            $d['deals'], 'the later closing date first, none last'
        );

        $this->assertSame(['decision', 'task', 'note', 'lead'], array_column($d['activity'], 'kind'), 'newest first');
        $this->assertSame([Carbon::parse('2026-11-03 08:00:00')->toIso8601String(), Carbon::parse('2026-11-02 09:00:00')->toIso8601String(), Carbon::parse('2026-11-01 10:00:00')->toIso8601String(),
            Carbon::parse('2026-10-30 12:00:00')->toIso8601String()], array_column($d['activity'], 'at'));
        $this->assertSame(['Asha Rao', 'Ravi Shah', 'Asha Rao', 'Asha Rao'], array_column($d['activity'], 'by_name'));
        $this->assertSame(['Contacted: Hiring a principal - Sent an intro', 'Demo at the school', 'Called the principal', 'Lead LD-1 (New) made from the signal: New campus'], array_column($d['activity'], 'text'));
        $this->assertSame(['g2g_signal_reviews', 'task_management_calendar_events', 'crm_organization_comments', 'crm_leads'], array_column($d['activity'], 'source'));
        $this->assertSame(['contacts' => 2, 'deals' => 2, 'activity' => 4], $d['totals']);
    }

    public function test_an_identity_with_no_source_rows_has_an_honest_empty_360(): void
    {
        $id = $this->identity(self::T, 'Lonely School');
        $d = $this->getJson(self::P . "/{$id}", $this->full()['h'])->assertOk()->json('data');

        $this->assertSame([null, null, null], [$d['crm'], $d['gtm'], $d['company']]);
        $this->assertSame(['crm' => null, 'gtm' => null, 'company' => null], $d['identity']['links']);
        $this->assertSame(['total' => 0, 'needing_decision' => 0, 'decided' => 0, 'latest' => []], $d['signals']);
        $this->assertSame([[], [], []], [$d['contacts'], $d['deals'], $d['activity']]);
        $this->assertSame(['contacts' => 0, 'deals' => 0, 'activity' => 0], $d['totals']);
        $this->assertNull($d['identity']['owner_name']);
    }

    public function test_a_host_without_some_tables_and_columns_still_answers_with_what_it_has(): void
    {
        $p = $this->populated();
        $h = $this->full()['h'];

        // The live database lacks tables and columns the dev one has: the Product Master, signal decisions, tasks, leads, GTM people and deals, and newer columns.
        Schema::disableForeignKeyConstraints();
        foreach (['g2g_product_master', 'g2g_signal_reviews', 'task_management_calendar_events', 'crm_leads', 'gtm_contacts', 'gtm_deals'] as $table) {
            Schema::drop($table);
        }
        Schema::table('g2g_companies', fn ($t) => $t->dropColumn(['type', 'state']));
        $d = $this->getJson(self::P . "/{$p['identity']}", $h)->assertOk()->json('data');

        $this->assertSame(3, $d['signals']['total']);
        $this->assertSame([null, null, null], array_column($d['signals']['latest'], 'product_master_name'));
        $this->assertSame([null, null], [$d['company']['type'], $d['company']['state']]);
        $this->assertSame(['crm'], array_values(array_unique(array_column($d['contacts'], 'source'))), 'only the CRM contacts exist here');
        $this->assertSame(['crm'], array_values(array_unique(array_column($d['deals'], 'source'))));
        $this->assertSame(['note'], array_values(array_unique(array_column($d['activity'], 'kind'))), 'no tasks, decisions or leads to show');
        $this->getJson(self::P, $h)->assertOk();

        // And a host with no Signals tables at all.
        Schema::drop('g2g_company_opportunities');
        Schema::drop('crm_organization_comments');
        $d = $this->getJson(self::P . "/{$p['identity']}", $h)->assertOk()->json('data');
        $this->assertSame(['total' => 0, 'needing_decision' => 0, 'decided' => 0, 'latest' => []], $d['signals']);
        $this->assertSame([], $d['activity']);
        $this->assertSame(0, $this->getJson(self::P, $h)->assertOk()->json('data.0.sources.signals.open_signals'));
    }

    public function test_a_pointer_into_another_organisations_rows_shows_nothing_of_them(): void
    {
        $crm = $this->crmOrg(self::T2, 'Other CRM');
        $gtm = $this->gtmAccount(self::T2, 'Other GTM');
        $company = $this->company(self::T2, 'Other Company');
        $this->signal(self::T2, $company, ['title' => 'Secret signal']);
        DB::table('crm_contacts')->insert(['contact_no' => 'X-1', 'organization_id' => $crm, 'last_name' => 'Secret', 'sub_institute_id' => self::T2]);
        $id = $this->identity(self::T, 'Mis-set pointers', ['crm_organization_id' => $crm, 'gtm_account_id' => $gtm, 'g2g_company_id' => $company]);

        $d = $this->getJson(self::P . "/{$id}", $this->full()['h'])->assertOk()->json('data');
        $this->assertSame([null, null, null], [$d['crm'], $d['gtm'], $d['company']]);
        $this->assertSame(0, $d['signals']['total']);
        $this->assertSame(['contacts' => 0, 'deals' => 0, 'activity' => 0], $d['totals']);
        $this->assertStringNotContainsString('Secret', json_encode($d));
        $this->assertStringNotContainsString('Other', json_encode($d));
    }

    public function test_every_list_of_the_360_is_capped_at_twenty_and_the_totals_say_how_many_exist(): void
    {
        $crm = $this->crmOrg(self::T, 'Big School');
        $gtm = $this->gtmAccount(self::T, 'Big School');
        $company = $this->company(self::T, 'Big School');
        $id = $this->identity(self::T, 'Big School', ['crm_organization_id' => $crm, 'gtm_account_id' => $gtm, 'g2g_company_id' => $company]);
        $user = $this->user();
        $signal = $this->signal(self::T, $company);
        foreach (range(1, 25) as $i) {
            DB::table('crm_contacts')->insert(['contact_no' => "C-{$i}", 'organization_id' => $crm, 'last_name' => sprintf('Person %02d', $i), 'sub_institute_id' => self::T]);
            DB::table('crm_opportunities')->insert(['opportunity_no' => "O-{$i}", 'name' => "Deal {$i}", 'organization_id' => $crm, 'sub_institute_id' => self::T]);
            DB::table('crm_organization_comments')->insert(['organization_id' => $crm, 'sub_institute_id' => self::T, 'comment' => "Note {$i}", 'created_by' => $user['id'], 'created_at' => now()->subMinutes($i), 'updated_at' => now()]);
            $this->signal(self::T, $company, ['first_discovered_at' => now()->subDays($i)->toDateTimeString()]);
        }
        DB::table('gtm_contacts')->insert(['sub_institute_id' => self::T, 'account_id' => $gtm, 'full_name' => 'Zed Gtm', 'created_at' => now(), 'updated_at' => now()]);

        $d = $this->getJson(self::P . "/{$id}", $this->full()['h'])->assertOk()->json('data');
        $this->assertCount(20, $d['contacts']);
        $this->assertCount(20, $d['deals']);
        $this->assertCount(20, $d['activity']);
        $this->assertCount(20, $d['signals']['latest']);
        $this->assertSame(['contacts' => 26, 'deals' => 25, 'activity' => 25], $d['totals']);
        $this->assertSame(26, $d['signals']['total'], 'the signal total counts them all');
        $this->assertSame('Note 1', $d['activity'][0]['text'], 'newest first');
        $this->assertNotNull($signal);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_rows(): void
    {
        $h = $this->full()['h'];
        $first = $this->populated();
        $user = $this->user();
        $this->getJson(self::P . "/{$first['identity']}", $h)->assertOk();   // warm: the rights lookup is cached after the first request
        $this->getJson(self::P, $h)->assertOk();

        $count = function (string $uri) use ($h): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($uri, $h)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        // More rows in every section of this identity, and more identities with sources.
        $grow = function (int $from, int $to) use ($first, $user) {
            foreach (range($from, $to) as $i) {
                DB::table('crm_contacts')->insert(['contact_no' => "K-{$i}", 'organization_id' => $first['crm'], 'last_name' => "Many {$i}", 'sub_institute_id' => self::T]);
                DB::table('gtm_contacts')->insert(['sub_institute_id' => self::T, 'account_id' => $first['gtm'], 'full_name' => "Gtm many {$i}", 'created_at' => now(), 'updated_at' => now()]);
                DB::table('crm_opportunities')->insert(['opportunity_no' => "P-{$i}", 'name' => "Many deal {$i}", 'organization_id' => $first['crm'], 'sub_institute_id' => self::T]);
                DB::table('gtm_deals')->insert(['sub_institute_id' => self::T, 'account_id' => $first['gtm'], 'name' => "Gtm deal {$i}", 'created_at' => now(), 'updated_at' => now()]);
                DB::table('crm_organization_comments')->insert(['organization_id' => $first['crm'], 'sub_institute_id' => self::T, 'comment' => "Many {$i}", 'created_by' => $user['id'], 'created_at' => now()->subMinutes($i), 'updated_at' => now()]);
                DB::table('task_management_calendar_events')->insert(['sub_institute_id' => self::T, 'syear' => '2026', 'title' => "Task {$i}", 'start_at' => now()->subMinutes($i), 'end_at' => now(), 'owner_id' => $user['id'],
                    'linked_type' => 'ORGANIZATION', 'linked_id' => $first['crm'], 'created_by' => $user['id'], 'created_at' => now(), 'updated_at' => now()]);
                $this->signal(self::T, $first['company'], ['first_discovered_at' => now()->subHours($i)->toDateTimeString()]);
                $crm = $this->crmOrg(self::T, "Listed {$i}");
                $gtm = $this->gtmAccount(self::T, "Listed {$i}");
                $company = $this->company(self::T, "Listed {$i}");
                $this->signal(self::T, $company);
                $this->identity(self::T, "Listed {$i}", ['crm_organization_id' => $crm, 'gtm_account_id' => $gtm, 'g2g_company_id' => $company, 'owner_user_id' => $user['id']]);
            }
        };
        $show = self::P . "/{$first['identity']}";

        $showSmall = $count($show);
        $listSmall = $count(self::P);
        $grow(1, 25);
        $showMid = $count($show);
        $listMid = $count(self::P . '?per_page=50');
        $grow(26, 85);
        $showBig = $count($show);
        $listBig = $count(self::P . '?per_page=50');

        // A section past its cap costs one extra query to count what exists (so a small organisation pays less); beyond that the cost is flat.
        $this->assertSame($showMid, $showBig, 'the 360 makes the same number of queries for 85 rows in every section as for 25');
        $this->assertLessThanOrEqual($showSmall + 8, $showMid, 'and what a larger organisation adds is one count per section');
        $this->assertLessThanOrEqual(70, $showBig, 'and the number is small');
        $this->assertSame($listSmall, $listMid, 'a page of 25 identities costs the same queries as a page of 1');
        $this->assertSame($listSmall, $listBig, 'and so does a page of 50');
        $this->assertLessThanOrEqual(25, $listBig);
    }

    // ---- editing ----

    public function test_patch_changes_lifecycle_owner_and_name_and_answers_with_the_360(): void
    {
        $owner = $this->user([], self::T, 'Neha', 'Joshi');
        $id = $this->identity(self::T, 'Old Name School', ['normalized_name' => 'old name school']);
        $editor = $this->full();

        $res = $this->patchJson(self::P . "/{$id}", ['lifecycle' => 'customer', 'owner_user_id' => $owner['id'], 'name' => '  The New Name Pvt Ltd  '], $editor['h'])->assertOk();
        $this->assertSame(1, $res->json('status'));
        $this->assertSame(['The New Name Pvt Ltd', 'customer', $owner['id'], 'Neha Joshi'], [$res->json('data.identity.name'), $res->json('data.identity.lifecycle'), $res->json('data.identity.owner_user_id'), $res->json('data.identity.owner_name')]);

        $row = DB::table('g2g_org_accounts')->find($id);
        $this->assertSame('The New Name Pvt Ltd', $row->name);
        $this->assertSame(\App\Domain\OrgAccounts\OrgNormalizer::name('The New Name Pvt Ltd'), $row->normalized_name, 'the matching key follows the name');
        $this->assertSame($editor['id'], (int) $row->updated_by);

        $this->patchJson(self::P . "/{$id}", ['owner_user_id' => null], $editor['h'])->assertOk()->assertJsonPath('data.identity.owner_user_id', null);
        $this->assertNull(DB::table('g2g_org_accounts')->where('id', $id)->value('owner_user_id'));
        $this->assertSame('customer', DB::table('g2g_org_accounts')->where('id', $id)->value('lifecycle'), 'a field that was not sent is left alone');
    }

    public function test_patch_cannot_change_anything_but_its_three_fields(): void
    {
        $id = $this->identity(self::T, 'Fixed School', ['crm_organization_id' => 5]);
        $before = (array) DB::table('g2g_org_accounts')->find($id);

        $this->patchJson(self::P . "/{$id}", ['lifecycle' => 'target', 'sub_institute_id' => self::T2, 'crm_organization_id' => 99, 'normalized_name' => 'hacked', 'id' => 777], $this->full()['h'])->assertOk();
        $after = (array) DB::table('g2g_org_accounts')->find($id);

        $this->assertSame(self::T, (int) $after['sub_institute_id']);
        $this->assertSame([5, 'fixed school', $id], [(int) $after['crm_organization_id'], $after['normalized_name'], (int) $after['id']]);
        $this->assertSame('target', $after['lifecycle']);
        $this->assertNotSame($before['lifecycle'], $after['lifecycle']);
    }

    public function test_patch_refusals_are_field_keyed_422s_that_write_nothing(): void
    {
        $id = $this->identity(self::T, 'Steady School', ['lifecycle' => 'target']);
        $mine = $this->user([], self::T, 'Mine', 'Person');
        $foreign = $this->user([], self::T2, 'Foreign', 'Person');
        $disabled = $this->user([], self::T, 'Gone', 'Person', 0);
        $h = $this->full()['h'];
        $before = $this->footprint();

        $cases = [
            'lifecycle' => [['lifecycle' => 'prospect'], 'lifecycle'],
            'lifecycle empty' => [['lifecycle' => ''], 'lifecycle'],
            'owner of another organisation' => [['owner_user_id' => $foreign['id']], 'owner_user_id'],
            'owner that does not exist' => [['owner_user_id' => 99999], 'owner_user_id'],
            'owner that is disabled' => [['owner_user_id' => $disabled['id']], 'owner_user_id'],
            'owner not a number' => [['owner_user_id' => 'abc'], 'owner_user_id'],
            'name empty' => [['name' => ''], 'name'],
            'name blank' => [['name' => '   '], 'name'],
            'name too long' => [['name' => str_repeat('x', 192)], 'name'],
            'a valid field next to an invalid one' => [['lifecycle' => 'customer', 'name' => str_repeat('y', 192)], 'name'],
            'nothing to change' => [['unrelated' => 1], 'body'],
        ];
        foreach ($cases as $what => [$body, $field]) {
            $res = $this->patchJson(self::P . "/{$id}", $body, $h)->assertStatus(422);
            $this->assertSame(0, $res->json('status'), $what);
            $this->assertIsString($res->json('message'));
            $this->assertArrayHasKey($field, $res->json('errors'), $what);
            $this->assertNotEmpty($res->json("errors.{$field}.0"));
            $this->assertSame($before, $this->footprint(), "{$what}: nothing was written");
        }

        $this->patchJson(self::P . "/{$id}", ['name' => str_repeat('n', 191), 'owner_user_id' => $mine['id']], $h)->assertOk();
    }

    // ---- links ----

    private function linkBody(array $o = []): array
    {
        return $o + ['left_type' => 'crm_organization', 'left_id' => 0, 'right_type' => 'g2g_company', 'right_id' => 0, 'action' => 'confirm'];
    }

    public function test_confirm_hands_the_pair_to_the_linker_for_the_callers_organisation_and_answers_with_the_identity(): void
    {
        $linker = $this->fakeLinker();
        $crm = $this->crmOrg(self::T, 'Acme');
        $company = $this->company(self::T, 'Acme School');
        $this->identity(self::T, 'Acme', ['crm_organization_id' => $crm, 'g2g_company_id' => $company, 'lifecycle' => 'engaged']);
        $user = $this->full();

        $res = $this->postJson(self::P . '/links', $this->linkBody(['left_id' => $crm, 'right_id' => $company]), $user['h'])->assertOk();

        $this->assertSame([['confirm', self::T, $user['id'], 'crm_organization', $crm, 'g2g_company', $company]], $linker->calls);
        $this->assertSame(1, $res->json('status'));
        $this->assertKeys(self::ITEM_KEYS, $res->json('data'), 'the identity answered');
        $this->assertSame(['Acme', $crm, $company], [$res->json('data.name'), $res->json('data.sources.crm.id'), $res->json('data.sources.signals.company_id')]);
    }

    public function test_reject_hands_the_pair_to_the_linker_and_answers_null(): void
    {
        $linker = $this->fakeLinker();
        $gtm = $this->gtmAccount(self::T, 'Acme');
        $company = $this->company(self::T, 'Acme School');
        $user = $this->full();

        $res = $this->postJson(self::P . '/links', $this->linkBody(['left_type' => 'gtm_account', 'left_id' => $gtm, 'right_id' => $company, 'action' => 'reject']), $user['h'])->assertOk();

        $this->assertSame(['status' => 1, 'data' => null], $res->json());
        $this->assertSame([['reject', self::T, $user['id'], 'gtm_account', $gtm, 'g2g_company', $company]], $linker->calls);
    }

    public function test_a_link_answer_with_a_bad_body_is_a_field_keyed_422_and_never_reaches_the_linker(): void
    {
        $linker = $this->fakeLinker();
        $crm = $this->crmOrg(self::T, 'Acme');
        $h = $this->full()['h'];

        $cases = [
            'left_type' => [$this->linkBody(['left_type' => 'lead', 'left_id' => $crm, 'right_id' => 1]), 'left_type'],
            'right_type missing' => [['left_type' => 'crm_organization', 'left_id' => $crm, 'right_id' => 1, 'action' => 'confirm'], 'right_type'],
            'left_id' => [$this->linkBody(['left_id' => 0, 'right_id' => 1]), 'left_id'],
            'right_id not a number' => [$this->linkBody(['left_id' => $crm, 'right_id' => 'x']), 'right_id'],
            'action' => [$this->linkBody(['left_id' => $crm, 'right_id' => 1, 'action' => 'merge']), 'action'],
            'the same row twice' => [$this->linkBody(['left_id' => $crm, 'right_type' => 'crm_organization', 'right_id' => $crm]), 'right_id'],
        ];
        foreach ($cases as $what => [$body, $field]) {
            $res = $this->postJson(self::P . '/links', $body, $h)->assertStatus(422);
            $this->assertSame(0, $res->json('status'), $what);
            $this->assertArrayHasKey($field, $res->json('errors'), $what);
        }
        $this->assertSame([], $linker->calls);
    }

    public function test_a_link_answer_naming_a_row_of_another_organisation_or_a_deleted_one_is_a_404_and_never_reaches_the_linker(): void
    {
        $linker = $this->fakeLinker();
        $mineCrm = $this->crmOrg(self::T, 'Mine');
        $mineCompany = $this->company(self::T, 'Mine School');
        $theirCrm = $this->crmOrg(self::T2, 'Theirs');
        $theirCompany = $this->company(self::T2, 'Theirs School');
        $deletedCrm = $this->crmOrg(self::T, 'Deleted', ['deleted_at' => now()]);
        $h = $this->full()['h'];

        foreach ([['left_id' => $theirCrm, 'right_id' => $mineCompany], ['left_id' => $mineCrm, 'right_id' => $theirCompany], ['left_id' => $theirCrm, 'right_id' => $theirCompany],
            ['left_id' => $deletedCrm, 'right_id' => $mineCompany], ['left_id' => $mineCrm, 'right_id' => 99999]] as $ids) {
            foreach (['confirm', 'reject'] as $action) {
                $this->postJson(self::P . '/links', $this->linkBody($ids + ['action' => $action]), $h)->assertStatus(404);
            }
        }
        $this->assertSame([], $linker->calls);
    }

    public function test_the_linkers_own_refusals_become_a_404_and_a_409(): void
    {
        $crm = $this->crmOrg(self::T, 'Acme');
        $company = $this->company(self::T, 'Acme School');
        $h = $this->full()['h'];
        $body = $this->linkBody(['left_id' => $crm, 'right_id' => $company]);

        $this->fakeLinker(new OrgLinkNotFound('gone'));
        $this->postJson(self::P . '/links', $body, $h)->assertStatus(404);

        $this->fakeLinker(new OrgLinkConflict(OrgLinkConflict::SAME_SOURCE, 'Both already have a record from the same source.'));
        $res = $this->postJson(self::P . '/links', $body, $h)->assertStatus(409);
        $this->assertSame([0, 'same_source', 'Both already have a record from the same source.'], [$res->json('status'), $res->json('code'), $res->json('message')]);
    }

    // ---- create the CRM organisation ----

    public function test_create_makes_the_crm_organization_links_it_and_moves_the_lifecycle_up(): void
    {
        $owner = $this->user([], self::T, 'Owen', 'Owner');
        $user = $this->full();
        $id = $this->identity(self::T, 'Fresh School', ['domain' => 'fresh.example', 'owner_user_id' => $owner['id'], 'lifecycle' => 'target']);

        $res = $this->postJson(self::P . "/{$id}/create-crm-organisation", [], $user['h'])->assertStatus(201);
        $crmId = $res->json('data.crm_organization_id');
        $this->assertSame(['status' => 1, 'data' => ['created' => true, 'crm_organization_id' => $crmId, 'restored' => false]], $res->json());

        $crm = DB::table('crm_organizations')->find($crmId);
        $this->assertSame(['Fresh School', 'fresh.example', self::T, $user['id'], $owner['id']], [$crm->name, $crm->website, (int) $crm->sub_institute_id, (int) $crm->created_by, (int) $crm->assigned_to]);
        $this->assertMatchesRegularExpression('/^ORG-[A-Z0-9]{8}$/', $crm->account_no);
        $this->assertNull($crm->deleted_at);

        $row = DB::table('g2g_org_accounts')->find($id);
        $this->assertSame([$crmId, 'manual', 'engaged', $user['id']], [(int) $row->crm_organization_id, $row->crm_link_basis, $row->lifecycle, (int) $row->updated_by]);
    }

    public function test_create_moves_discovered_up_but_never_a_customer_or_an_archived_organisation_down(): void
    {
        $h = $this->full()['h'];
        $expect = ['discovered' => 'engaged', 'target' => 'engaged', 'engaged' => 'engaged', 'customer' => 'customer', 'archived' => 'archived'];

        foreach ($expect as $from => $to) {
            $id = $this->identity(self::T, "Lifecycle {$from}", ['lifecycle' => $from]);
            $this->postJson(self::P . "/{$id}/create-crm-organisation", [], $h)->assertStatus(201);
            $this->assertSame($to, DB::table('g2g_org_accounts')->where('id', $id)->value('lifecycle'), "from {$from}");
        }
    }

    public function test_create_is_idempotent(): void
    {
        $h = $this->full()['h'];
        $id = $this->identity(self::T, 'Once School');

        $first = $this->postJson(self::P . "/{$id}/create-crm-organisation", [], $h)->assertStatus(201)->json('data');
        $second = $this->postJson(self::P . "/{$id}/create-crm-organisation", [], $h)->assertOk()->json('data');
        $third = $this->postJson(self::P . "/{$id}/create-crm-organisation", ['restore_deleted' => true], $h)->assertOk()->json('data');

        $this->assertSame(['created' => false, 'crm_organization_id' => $first['crm_organization_id'], 'restored' => false], $second);
        $this->assertSame($second, $third);
        $this->assertSame(1, DB::table('crm_organizations')->count(), 'one CRM organization, however many times it is asked for');
    }

    public function test_an_identity_that_already_has_a_live_crm_organization_gets_it_back_and_nothing_is_written(): void
    {
        $crm = $this->crmOrg(self::T, 'Existing Name');
        $id = $this->identity(self::T, 'Different Name', ['crm_organization_id' => $crm, 'lifecycle' => 'engaged']);
        $before = $this->footprint();

        $res = $this->postJson(self::P . "/{$id}/create-crm-organisation", [], $this->full()['h'])->assertOk();

        $this->assertSame(['created' => false, 'crm_organization_id' => $crm, 'restored' => false], $res->json('data'));
        $this->assertSame($before, $this->footprint());
    }

    public function test_a_deleted_crm_organization_with_the_same_name_is_a_409_and_is_left_exactly_as_it_was(): void
    {
        $deleted = $this->crmOrg(self::T, 'Old Friend School', ['deleted_at' => '2026-10-01 10:00:00', 'deleted_by' => 7, 'industry' => 'Education']);
        DB::table('crm_contacts')->insert(['contact_no' => 'C-OLD', 'organization_id' => $deleted, 'last_name' => 'History', 'sub_institute_id' => self::T]);
        $id = $this->identity(self::T, 'Old Friend School', ['lifecycle' => 'target']);
        $before = $this->footprint();

        foreach ([[], ['restore_deleted' => false]] as $body) {
            $res = $this->postJson(self::P . "/{$id}/create-crm-organisation", $body, $this->full()['h'])->assertStatus(409);
            $this->assertSame(['status' => 0, 'code' => 'deleted_name_exists', 'deleted_organization_id' => $deleted], array_intersect_key($res->json(), array_flip(['status', 'code', 'deleted_organization_id'])));
            $this->assertNotEmpty($res->json('message'));
        }
        $this->assertSame($before, $this->footprint(), 'the deleted organization was not overwritten, restored or duplicated, and the identity was not touched');
    }

    public function test_restore_deleted_brings_the_organization_back_with_its_history_and_links_it(): void
    {
        $deleted = $this->crmOrg(self::T, 'Old Friend School', ['deleted_at' => '2026-10-01 10:00:00', 'deleted_by' => 7, 'industry' => 'Education', 'account_no' => 'ORG-KEEPME1']);
        DB::table('crm_contacts')->insert(['contact_no' => 'C-OLD', 'organization_id' => $deleted, 'last_name' => 'History', 'sub_institute_id' => self::T]);
        $id = $this->identity(self::T, 'Old Friend School', ['lifecycle' => 'discovered']);
        $user = $this->full();

        $res = $this->postJson(self::P . "/{$id}/create-crm-organisation", ['restore_deleted' => true], $user['h'])->assertOk();

        $this->assertSame(['created' => true, 'crm_organization_id' => $deleted, 'restored' => true], $res->json('data'));
        $crm = DB::table('crm_organizations')->find($deleted);
        $this->assertNull($crm->deleted_at);
        $this->assertNull($crm->deleted_by);
        $this->assertSame(['Education', 'ORG-KEEPME1', $user['id']], [$crm->industry, $crm->account_no, (int) $crm->updated_by], 'it is the same row: nothing else about it changed');
        $this->assertSame(1, DB::table('crm_organizations')->count());
        $this->assertSame(1, DB::table('crm_contacts')->where('organization_id', $deleted)->count(), 'its history is still attached');
        $row = DB::table('g2g_org_accounts')->find($id);
        $this->assertSame([$deleted, 'manual', 'engaged'], [(int) $row->crm_organization_id, $row->crm_link_basis, $row->lifecycle]);
    }

    public function test_restore_is_refused_when_the_deleted_organization_belongs_to_another_identity(): void
    {
        $deleted = $this->crmOrg(self::T, 'Shared Name', ['deleted_at' => now()]);
        $this->identity(self::T, 'Other holder', ['crm_organization_id' => $deleted]);
        $this->identity(self::T, 'Gone holder', ['crm_organization_id' => null, 'deleted_at' => now()]);
        $id = $this->identity(self::T, 'Shared Name');
        $before = $this->footprint();

        $res = $this->postJson(self::P . "/{$id}/create-crm-organisation", ['restore_deleted' => true], $this->full()['h'])->assertStatus(422);
        $this->assertArrayHasKey('name', $res->json('errors'));
        $this->assertSame($before, $this->footprint());
    }

    public function test_a_live_crm_organization_with_the_same_name_is_a_422_and_is_not_duplicated(): void
    {
        $this->crmOrg(self::T, 'Same Name School');
        $id = $this->identity(self::T, 'Same Name School');
        $before = $this->footprint();

        $res = $this->postJson(self::P . "/{$id}/create-crm-organisation", ['restore_deleted' => true], $this->full()['h'])->assertStatus(422);
        $this->assertSame(0, $res->json('status'));
        $this->assertArrayHasKey('name', $res->json('errors'));
        $this->assertSame($before, $this->footprint());
    }

    public function test_the_same_name_in_another_organisation_is_no_obstacle(): void
    {
        $this->crmOrg(self::T2, 'Shared Name', ['deleted_at' => now()]);
        $this->crmOrg(self::T2, 'Live Elsewhere');
        $id = $this->identity(self::T, 'Shared Name');
        $other = $this->identity(self::T, 'Live Elsewhere');

        $this->postJson(self::P . "/{$id}/create-crm-organisation", [], $this->full()['h'])->assertStatus(201);
        $this->postJson(self::P . "/{$other}/create-crm-organisation", [], $this->full()['h'])->assertStatus(201);
        $this->assertSame(2, DB::table('crm_organizations')->where('sub_institute_id', self::T)->count());
        $this->assertSame(2, DB::table('crm_organizations')->where('sub_institute_id', self::T2)->count(), "the other organisation's rows were not touched");
        $this->assertNotNull(DB::table('crm_organizations')->where('sub_institute_id', self::T2)->where('name', 'Shared Name')->value('deleted_at'));
    }

    public function test_an_organization_made_in_the_same_instant_by_someone_else_is_a_422_not_a_500(): void
    {
        $id = $this->identity(self::T, 'Race School');
        $raced = false;
        // The CRM screen creates the same name between our check and our insert: the unique index (organization, name) refuses the insert.
        DB::beforeExecuting(function (string $query) use (&$raced) {
            if (! $raced && str_starts_with($query, 'insert into "crm_organizations"')) {
                $raced = true;
                $this->crmOrg(self::T, 'Race School');
            }
        });

        $res = $this->postJson(self::P . "/{$id}/create-crm-organisation", [], $this->full()['h'])->assertStatus(422);

        $this->assertArrayHasKey('name', $res->json('errors'));
        $this->assertSame(1, DB::table('crm_organizations')->count(), 'only the one made by the other screen');
        $this->assertNull(DB::table('g2g_org_accounts')->find($id)->crm_organization_id, 'and ours is not linked to anything');
    }

    public function test_a_bad_restore_flag_is_a_422(): void
    {
        $id = $this->identity(self::T, 'Flag School');

        $res = $this->postJson(self::P . "/{$id}/create-crm-organisation", ['restore_deleted' => 'maybe'], $this->full()['h'])->assertStatus(422);
        $this->assertArrayHasKey('restore_deleted', $res->json('errors'));
        $this->assertSame(0, DB::table('crm_organizations')->count());
    }

    // ---- not found ----

    public function test_a_missing_deleted_or_other_organisations_identity_is_the_same_404_everywhere(): void
    {
        $h = $this->full()['h'];
        $theirs = $this->identity(self::T2, 'Their School');
        $deleted = $this->identity(self::T, 'Deleted School', ['deleted_at' => now()]);

        foreach ([['GET', ''], ['PATCH', ''], ['POST', '/create-crm-organisation']] as [$method, $suffix]) {
            $bodies = [];
            foreach ([$theirs, $deleted, 99999] as $id) {
                $res = $this->json($method, self::P . "/{$id}{$suffix}", ['name' => 'X'], $h)->assertStatus(404);
                $bodies[] = $res->json();
            }
            $this->assertSame($bodies[0], $bodies[1], "{$method}: another organisation's identity and a deleted one answer alike");
            $this->assertSame($bodies[0], $bodies[2], "{$method}: and so does one that never existed");
            $this->assertSame(0, $bodies[0]['status']);
        }
        $this->getJson(self::P . '/abc', $h)->assertStatus(404);
    }

    // ---- the real resolver and linker, through the API ----

    public function test_the_real_resolver_feeds_the_suggestions_route_and_the_lists_badge(): void
    {
        app()->forgetInstance(OrgResolver::class);
        $crm = $this->crmOrg(self::T, 'Sunrise Valley International Academy Noida Uttar Pradesh');
        $company = $this->company(self::T, 'Sunrise Valley International Academy Noida Uttar Pradesh Campus');
        $this->crmOrg(self::T2, 'Sunrise Valley International Academy Noida Uttar Pradesh');
        $this->company(self::T2, 'Sunrise Valley International Academy Noida Uttar Pradesh Campus');
        $h = $this->full()['h'];

        $suggestions = $this->getJson(self::P . '/suggestions', $h)->assertOk()->json('data');

        $this->assertCount(1, $suggestions, "this organisation's pair only");
        $this->assertKeys(['key', 'left', 'right', 'basis', 'score'], $suggestions[0], 'a suggestion');
        $this->assertSame('name_overlap', $suggestions[0]['basis']);
        $this->assertEqualsCanonicalizing([['crm_organization', $crm], ['g2g_company', $company]], array_map(fn ($side) => [$side['type'], $side['id']], [$suggestions[0]['left'], $suggestions[0]['right']]));
        $this->assertSame(1, $this->getJson(self::P, $h)->assertOk()->json('summary.suggestions'));
    }

    public function test_the_real_linker_confirms_two_rows_into_one_identity_through_the_api(): void
    {
        app()->forgetInstance(OrgLinker::class);
        $crm = $this->crmOrg(self::T, 'Real Link School');
        $company = $this->company(self::T, 'Real Link School');
        $user = $this->full();

        $res = $this->postJson(self::P . '/links', $this->linkBody(['left_id' => $crm, 'right_id' => $company]), $user['h'])->assertOk();

        $this->assertSame([$crm, $company], [$res->json('data.sources.crm.id'), $res->json('data.sources.signals.company_id')], 'one identity holds both rows');
        $row = DB::table('g2g_org_accounts')->where('crm_organization_id', $crm)->first();
        $this->assertSame([(int) $company, 'manual'], [(int) $row->g2g_company_id, $row->crm_link_basis]);
        $this->assertSame(1, DB::table('g2g_org_accounts')->count());

        $this->getJson(self::P . "/{$row->id}", $user['h'])->assertOk()->assertJsonPath('data.identity.links.company.basis', 'manual');
    }
}
