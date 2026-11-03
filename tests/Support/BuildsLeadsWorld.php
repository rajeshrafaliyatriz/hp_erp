<?php

namespace Tests\Support;

use App\Domain\Signals\Opportunities\Company;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Models\auth\tbluserModel;
use App\Support\RoleKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The database the leads tests (slice 6) run on, built on top of the facts world (BuildsFactsWorld: the users and their rights, the contacts, the
 * organisations, the facts ledger, the consent tables, the event store) so no fixture is written twice. What it adds is what a lead needs beyond that:
 * every column the lead presenter and LeadFactory read or write, the UNIQUE index on lead_no the real table has (one number space for every organisation),
 * the scoring rules, the comments table, the opportunities, and the two migrations of this slice, run from their real files.
 *
 * A test class that uses this trait also uses BuildsFactsWorld (it calls buildFactsWorld()); call buildLeadsWorld() in setUp().
 */
trait BuildsLeadsWorld
{
    protected const SIGNALS_LINK = '/module/crm/signals';

    /** The tables and columns of the slice's own migrations are built from the real files, in this order. @var list<string> */
    private array $leadsMigrations = [
        '2026_11_30_090100_create_crm_lead_routing_tables', '2026_11_30_100000_create_crm_lead_capture_settings_table', '2026_12_03_100000_create_crm_signal_inbox_table',
        '2026_11_20_090500_create_crm_campaign_targets_table',
        '2026_12_11_100000_create_g2g_number_sequences_table',
        '2026_12_11_100100_add_product_and_org_account_to_crm_leads',
    ];

    protected function buildLeadsWorld(): void
    {
        $this->buildFactsWorld();

        // The columns the facts world's leads table leaves out: the real names and types of crm_leads, as SHOW CREATE TABLE gives them on dev.
        $columns = [
            'salutation' => fn ($t) => $t->string('salutation', 20)->nullable(),
            'fax' => fn ($t) => $t->string('fax', 30)->nullable(),
            'industry' => fn ($t) => $t->string('industry', 191)->nullable(),
            'lead_source' => fn ($t) => $t->string('lead_source', 191)->nullable(),
            'lead_status' => fn ($t) => $t->string('lead_status', 191)->nullable(),
            'lifecycle_status' => fn ($t) => $t->string('lifecycle_status', 50)->nullable()->default('new'),
            'next_action' => fn ($t) => $t->text('next_action')->nullable(),
            'follow_up_due_date' => fn ($t) => $t->timestamp('follow_up_due_date')->nullable(),
            'number_of_emp' => fn ($t) => $t->string('number_of_emp', 50)->nullable(),
            'rating' => fn ($t) => $t->string('rating', 191)->nullable(),
            'lead_score' => fn ($t) => $t->integer('lead_score')->default(0),
            'lead_score_basis' => fn ($t) => $t->text('lead_score_basis')->nullable(),
            'annual_revenue' => fn ($t) => $t->decimal('annual_revenue', 15, 2)->nullable(),
            'campaign_text' => fn ($t) => $t->string('campaign_text', 191)->nullable(),
            'source_company_opportunity_id' => fn ($t) => $t->unsignedBigInteger('source_company_opportunity_id')->nullable(),
            'street' => fn ($t) => $t->string('street', 191)->nullable(),
            'po_box' => fn ($t) => $t->string('po_box', 191)->nullable(),
            'postal_code' => fn ($t) => $t->string('postal_code', 191)->nullable(),
            'country' => fn ($t) => $t->string('country', 191)->nullable(),
            'state' => fn ($t) => $t->string('state', 191)->nullable(),
            'city' => fn ($t) => $t->string('city', 191)->nullable(),
            'description' => fn ($t) => $t->text('description')->nullable(),
        ];
        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('crm_leads', $name)) {
                Schema::table('crm_leads', $add);
            }
        }
        // The real table has this unique index over EVERY organisation (crm_leads_lead_no_unique): it is why the lead counter is one counter.
        Schema::table('crm_leads', fn ($t) => $t->unique('lead_no', 'crm_leads_lead_no_unique'));

        Schema::create('crm_lead_comments', function ($t) {
            $t->id();
            $t->unsignedBigInteger('lead_id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->text('comment');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('crm_opportunities', function ($t) {
            $t->id();
            $t->string('opportunity_no', 100)->unique();
            $t->string('name', 191);
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->string('lead_source', 100)->nullable();
            $t->string('sales_stage', 100)->nullable();
            $t->text('description')->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('sub_institute_id');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('crm_opportunity_contacts', function ($t) {
            $t->id();
            $t->unsignedBigInteger('opportunity_id');
            $t->unsignedBigInteger('contact_id');
            $t->timestamp('created_at')->nullable();
        });

        foreach ($this->leadsMigrations as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
        // The duplicate finder groups with MariaDB's CONCAT, which sqlite does not have.
        DB::connection()->getPdo()->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts), -1);
        // The signal inbox routes are guarded by the Signals right, found by its access_link like every other right.
        DB::table('tblmenumaster_g2g')->insert(['id' => 460, 'menu_name' => 'Signals', 'access_link' => self::SIGNALS_LINK]);
        Cache::flush();
    }

    /**
     * The Signals side of promotion (a signal, its company, the product master and the organisation identities, the decision history), from the real
     * migration files in the order SignalReviewApiTest builds them. Call after buildLeadsWorld().
     */
    protected function buildSignalsWorld(): void
    {
        foreach ([
            '2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables', '2026_09_30_180000_add_signal_fields_to_ingestion_findings',
            '2026_09_30_200000_add_research_sources_and_signal_kind', '2026_09_30_220000_create_g2g_portfolio_and_partners_tables',
            '2026_09_30_230000_add_claude_feed_and_linking_fields', '2026_10_01_130000_add_stage_to_g2g_research_runs',
            '2026_10_08_100000_extend_g2g_signals_for_market_import', '2026_11_29_090000_add_assessment_to_g2g_company_opportunities',
            '2026_12_01_090000_add_product_key_to_signals_runs_and_sources', '2026_10_14_100000_create_g2g_icp_profiles_tables',
            '2026_10_31_090000_add_research_run_configuration_scope', '2026_12_07_090000_add_scope_to_g2g_company_opportunities',
            '2026_12_07_090000_create_g2g_product_master_table',
            '2026_12_08_090000_create_g2g_signal_reviews_table', '2026_12_08_090100_add_review_and_product_columns_to_g2g_company_opportunities',
            '2026_12_08_090200_add_cost_and_product_columns_to_g2g_research_runs', '2026_12_09_090000_create_g2g_org_accounts_tables',
            '2026_08_10_150000_create_g2g_audit_log',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
        Schema::create('tenant_setting', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('setting_key');
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });
        RoleKey::flushCache();
        Cache::flush();
    }

    /** A Signals administrator of a tenant (the tier that may promote a signal). @return array{id: int, h: array<string, string>} */
    protected function signalsAdmin(int $tenant = self::T): array
    {
        $profile = DB::table('tbluserprofilemaster')->where('role_key', 'administrator')->where('sub_institute_id', $tenant)->value('id')
            ?? DB::table('tbluserprofilemaster')->insertGetId(['name' => 'Administrator', 'role_key' => 'administrator', 'sub_institute_id' => $tenant]);
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'a' . uniqid(), 'first_name' => 'Asha', 'last_name' => 'Rao', 'sub_institute_id' => $tenant, 'user_profile_id' => $profile, 'status' => 1]);

        return ['id' => $id, 'h' => ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken]];
    }

    /** A signal of a tenant, with a company. @param array<string, mixed> $over */
    protected function makeSignal(int $tenant = self::T, array $over = [], array $company = []): CompanyOpportunity
    {
        $run = ResearchRun::firstOrCreate(['sub_institute_id' => $tenant, 'report_date' => '2026-10-05', 'trigger' => 'scheduled', 'status' => 'success']);
        $company = Company::create($company + ['sub_institute_id' => $tenant, 'name' => 'Co ' . uniqid(), 'normalized_name' => uniqid()]);

        return CompanyOpportunity::create($over + [
            'sub_institute_id' => $tenant, 'company_id' => $company->id, 'research_run_id' => $run->id, 'title' => 'T ' . uniqid(),
            'category' => 'hiring', 'observed_event' => 'e', 'why_indicates_need' => 'w', 'product_fit' => 'f', 'recommended_action' => 'a',
            'priority' => 'Medium', 'qualification' => 'Monitoring', 'confidence' => 'Medium', 'review_status' => 'New',
            'sources' => [['url' => 'https://x.example.com', 'title' => 'x']], 'fingerprint' => uniqid(), 'first_discovered_at' => now(), 'last_verified_at' => now(),
        ]);
    }

    /** A user of a tenant with the given actions on the Leads screen (and the Signals screen). @param list<string> $actions @return array{id: int, h: array<string, string>} */
    protected function leadsUser(int $tenant = self::T, array $actions = ['view', 'add', 'edit', 'delete'], bool $signals = true): array
    {
        return $this->user([self::LEADS_LINK => $actions] + ($signals ? [self::SIGNALS_LINK => $actions] : []), $tenant);
    }

    /** @param array<string, mixed> $over a lead with a person's name (not the placeholder a signal leaves). */
    protected function makePersonLead(int $tenant = self::T, array $over = []): int
    {
        return $this->makeLead($tenant, $over + ['first_name' => 'Rahul', 'last_name' => 'Verma', 'company' => 'Greenfield School']);
    }
}
