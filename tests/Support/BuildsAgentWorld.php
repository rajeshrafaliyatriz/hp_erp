<?php

namespace Tests\Support;

use App\Domain\AgentGateway\AgentModel;
use App\Domain\OrgAccounts\OrgLinker;
use App\Domain\OrgAccounts\OrgResolver;
use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The database and the people the agent gateway tests (slice 7, thin) run on: every table comes from the REAL migration files on the in-memory sqlite
 * database (the agent's own five included), the users and their rights are real rows, and the two classes of the Organisation 360 that need the whole
 * tenant's data (the suggestions and the linker) are replaced by fakes that remember how they were called. The model is a FakeAgentModel; its calls are
 * metered by the real AiUsageMeter against a real rate card, so the cost the tests read is the cost the product would show.
 *
 * Call buildAgentWorld() in setUp().
 */
trait BuildsAgentWorld
{
    protected const T = 1;

    protected const T2 = 2;

    protected const ORG_LINK = '/module/crm/marketing/organizations';

    protected const ORG_LINK_SUPPORT = '/module/crm/support/organizations';

    protected const HISTORY_LINK = '/module/crm/marketing/agent-runs';

    /** @var list<string> */
    private array $agentMigrations = [
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
        '2026_08_10_140000_create_g2g_event_store',
        '2026_09_19_120000_create_ai_intelligence_tables', '2026_09_21_150000_create_ai_usage_tables', '2026_10_09_160000_add_product_to_ai_usage_events',
        '2026_12_08_100000_add_currency_to_ai_models', '2026_12_08_100100_add_cost_columns_to_ai_usage_events',
        '2026_12_10_090000_create_g2g_agent_runs_table', '2026_12_10_090100_create_g2g_agent_proposals_table', '2026_12_10_090200_create_g2g_agent_tool_calls_table',
        '2026_12_10_090300_create_g2g_agent_autonomy_table', '2026_12_10_090400_add_created_via_to_crm_organization_comments',
    ];

    protected FakeAgentModel $model;

    /** @var object the stand-in for OrgResolver */
    protected object $resolver;

    /** @var object the stand-in for OrgLinker */
    protected object $linker;

    protected function buildAgentWorld(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

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

        foreach ($this->agentMigrations as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
        Schema::table('crm_organizations', function ($t) {
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->unsignedBigInteger('deleted_by')->nullable();
        });
        DB::table('ai_models')->delete();   // the table migration seeds models; the tests set their own rates

        Cache::flush();
        DB::table('tblmenumaster_g2g')->insert([
            ['id' => 302, 'menu_name' => 'Organizations', 'access_link' => self::ORG_LINK],
            ['id' => 303, 'menu_name' => 'Organizations (support)', 'access_link' => self::ORG_LINK_SUPPORT],
            ['id' => 304, 'menu_name' => 'Agent Run History', 'access_link' => self::HISTORY_LINK],
        ]);

        $this->rate(self::T);
        $this->rate(self::T2);
        $this->model = new FakeAgentModel();
        app()->instance(AgentModel::class, $this->model);
        $this->resolver = $this->fakeResolver();
        $this->linker = $this->fakeLinker();
    }

    /** The rate card row the fake model's calls are priced from: deepseek-flash, rupees per 1,000 tokens. */
    protected function rate(int $tenant, float $input = 0.0288, float $output = 0.1152): void
    {
        DB::table('ai_models')->insert([
            'provider' => 'deepseek', 'model_id' => 'deepseek-flash', 'label' => 'deepseek-flash', 'input_cost_per_1k' => $input, 'output_cost_per_1k' => $output,
            'currency' => 'INR', 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---- people and rights ----

    /**
     * A user of a tenant on a profile of their own, holding exactly the given rights: link => actions.
     *
     * @param  array<string, list<string>>  $rights
     * @return array{id: int, h: array<string, string>}
     */
    protected function user(array $rights = [], int $tenant = self::T, string $first = 'Asha', string $last = 'Rao'): array
    {
        $profile = DB::table('tbluserprofilemaster')->insertGetId(['name' => 'P' . uniqid(), 'role_key' => 'employee', 'sub_institute_id' => $tenant]);
        foreach ($rights as $link => $actions) {
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $tenant, 'menu_id' => (int) DB::table('tblmenumaster_g2g')->where('access_link', $link)->value('id'), 'profile_id' => $profile]
                + array_combine(array_map(fn ($a) => "can_{$a}", $actions), array_fill(0, count($actions), 1)));
        }
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'first_name' => $first, 'last_name' => $last, 'sub_institute_id' => $tenant, 'user_profile_id' => $profile, 'status' => 1]);

        return ['id' => $id, 'h' => ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken]];
    }

    /** Edit (and view) on Organizations and view on Agent Run History: everything the agent needs. @return array{id: int, h: array<string, string>} */
    protected function editor(int $tenant = self::T, string $first = 'Asha', string $last = 'Rao'): array
    {
        return $this->user([self::ORG_LINK => ['view', 'edit'], self::HISTORY_LINK => ['view']], $tenant, $first, $last);
    }

    // ---- the fakes of the other track's classes ----

    /** @param list<array<string, mixed>> $suggestions */
    protected function fakeResolver(array $suggestions = []): object
    {
        $fake = new class($suggestions)
        {
            /** @var list<array{0: int, 1: int}> */
            public array $calls = [];

            public function __construct(public array $suggestions) {}

            public function suggestions(int $tenantId, int $limit = 100): array
            {
                $this->calls[] = [$tenantId, $limit];

                return array_slice($this->suggestions, 0, $limit);
            }
        };
        app()->instance(OrgResolver::class, $fake);

        return $fake;
    }

    protected function fakeLinker(?\Throwable $throws = null): object
    {
        $fake = new class($throws)
        {
            /** @var list<array<int, mixed>> */
            public array $calls = [];

            /** The id of the identity the real linker would answer with (the survivor of a merge); null answers 1. */
            public ?int $returns = null;

            public function __construct(public ?\Throwable $throws) {}

            public function confirm(...$args): array
            {
                $this->calls[] = $args;
                if ($this->throws) {
                    throw $this->throws;
                }

                return ['id' => $this->returns ?? 1];
            }
        };
        app()->instance(OrgLinker::class, $fake);

        return $fake;
    }

    // ---- data ----

    /**
     * An organisation with whatever is asked for. Options: crm (default true: a CRM organisation record), signals (how many real signals on its
     * Signals company, default 0), gtm (a GTM account, default false), name. Returns the ids: identity, crm, company, gtm, signals (list).
     *
     * @param  array<string, mixed>  $o
     * @return array{identity: int, crm: ?int, company: ?int, gtm: ?int, signals: list<int>}
     */
    protected function makeOrg(int $tenant, string $name, array $o = []): array
    {
        $crm = ($o['crm'] ?? true) ? (int) DB::table('crm_organizations')->insertGetId([
            'account_no' => 'ORG-' . strtoupper(substr(uniqid(), -8)), 'name' => $name, 'sub_institute_id' => $tenant, 'industry' => 'Education', 'billing_city' => 'Surat', 'created_at' => now(), 'updated_at' => now(),
        ]) : null;
        $gtm = ($o['gtm'] ?? false) ? (int) DB::table('gtm_accounts')->insertGetId(['sub_institute_id' => $tenant, 'name' => $name, 'stage' => 'target', 'icp_fit_score' => 70, 'created_at' => now(), 'updated_at' => now()]) : null;
        $company = ($o['signals'] ?? 0) > 0 ? (int) DB::table('g2g_companies')->insertGetId(['sub_institute_id' => $tenant, 'name' => $name, 'normalized_name' => strtolower($name), 'created_at' => now(), 'updated_at' => now()]) : null;
        $signals = [];
        for ($i = 1; $i <= ($o['signals'] ?? 0); $i++) {
            $signals[] = $this->signal($tenant, $company, ['title' => "Signal {$i} for {$name}"]);
        }
        $identity = (int) DB::table('g2g_org_accounts')->insertGetId([
            'sub_institute_id' => $tenant, 'name' => $name, 'normalized_name' => strtolower($name), 'lifecycle' => 'target', 'crm_organization_id' => $crm, 'gtm_account_id' => $gtm, 'g2g_company_id' => $company,
            'crm_link_basis' => $crm ? 'manual' : null, 'gtm_link_basis' => $gtm ? 'exact_name' : null, 'company_link_basis' => $company ? 'existing_link' : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['identity' => $identity, 'crm' => $crm, 'company' => $company, 'gtm' => $gtm, 'signals' => $signals];
    }

    /** @param array<string, mixed> $o */
    protected function signal(int $tenant, int $company, array $o = []): int
    {
        return (int) DB::table('g2g_company_opportunities')->insertGetId($o + [
            'sub_institute_id' => $tenant, 'company_id' => $company, 'research_run_id' => 1, 'title' => 'Signal ' . uniqid(), 'category' => 'hiring', 'observed_event' => 'The school is hiring a principal.',
            'why_indicates_need' => 'w', 'product_fit' => 'f', 'recommended_action' => 'a', 'priority' => 'Medium', 'qualification' => 'Monitoring', 'confidence' => 'Medium',
            'review_status' => 'New', 'sources' => json_encode([['url' => 'https://example.test/job', 'title' => 'Job ad', 'excerpt' => 'We are hiring a principal for the new campus.']]),
            'source_url' => 'https://example.test/job', 'fingerprint' => uniqid(), 'first_discovered_at' => '2026-10-01 10:00:00', 'last_verified_at' => '2026-10-01 10:00:00',
            'is_sample' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> everything an agent write could touch, to prove a refusal wrote nothing */
    protected function writes(): array
    {
        return [
            'comments' => DB::table('crm_organization_comments')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'tasks' => DB::table('task_management_calendar_events')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'identities' => DB::table('g2g_org_accounts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'linker' => count($this->linker->calls),
        ];
    }
}
