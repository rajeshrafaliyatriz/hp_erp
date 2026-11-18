<?php

namespace Tests\Support;

use App\Domain\Crm\Sales\OpportunityGtmSyncBridge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The database the pipeline tests (slice 11) run on: the leads world (users and their rights, organisations, contacts, leads, the Signals tables and the
 * Product Master, from the real migration files) with the opportunity tables REPLACED by the real ones (the leads world builds a cut-down table), plus the GTM
 * deals tables, the stage picklist and the slice's own migrations, all from the real files on the in-memory sqlite database.
 *
 * A test class that uses this trait also uses BuildsFactsWorld and BuildsLeadsWorld (call buildPipelineWorld() in setUp()).
 */
trait BuildsPipelineWorld
{
    protected const OPPS_LINK = '/module/crm/sales/opportunities';

    /** The real files, in an order that satisfies their foreign keys. @var list<string> */
    private array $pipelineMigrations = [
        '2026_11_20_090400_create_crm_campaigns_table',
        '2026_11_24_080100_create_crm_tax_rates_table',
        '2026_11_24_080200_create_crm_products_table',
        '2026_11_24_080300_create_crm_opportunities_table',
        '2026_11_24_080400_create_crm_opportunity_contacts_table',
        '2026_11_24_080500_create_crm_opportunity_products_table',
        '2026_11_24_080600_create_crm_opportunity_stage_history_table',
        '2026_11_30_080000_add_close_reason_to_crm_opportunities_table',
        '2026_10_10_400000_create_gtm_deals_tables',
        '2026_11_24_081100_add_crm_organization_id_to_gtm_accounts_table',
        '2026_11_20_090600_create_crm_picklist_values_table',
        '2026_11_24_081000_add_color_to_crm_picklist_values_table',
    ];

    /** The stages the real seed migration puts in the picklist (that file asks information_schema, which sqlite does not have). @var list<string> */
    protected array $salesStages = [
        'Prospecting', 'Qualification', 'Needs Analysis', 'Value Proposition', 'Identify Decision Makers', 'Perception Analysis', 'Proposal/Quotation', 'Negotiation/Review',
        'Closed Won', 'Closed Lost',
    ];

    protected function buildPipelineWorld(bool $withSlice11Migrations = true): void
    {
        $this->buildLeadsWorld();
        $this->buildSignalsWorld();

        // The leads world's own, cut-down opportunity tables give way to the real ones.
        Schema::dropIfExists('crm_opportunity_stage_history');
        Schema::dropIfExists('crm_opportunity_contacts');
        Schema::dropIfExists('crm_opportunities');
        foreach ($this->pipelineMigrations as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
        foreach ($this->salesStages as $i => $stage) {
            DB::table('crm_picklist_values')->insert(['module' => 'opportunities', 'field_key' => 'sales_stage', 'value' => $stage, 'label' => $stage, 'sort_order' => $i + 1, 'is_default' => false, 'status' => true]);
        }
        if ($withSlice11Migrations) {
            (require base_path('database/migrations/2026_12_11_130000_add_product_and_loss_reason_to_crm_opportunities.php'))->up();
            (require base_path('database/migrations/2026_12_11_130100_create_g2g_forecast_snapshots_table.php'))->up();
        }

        DB::table('tblmenumaster_g2g')->insert(['id' => 470, 'menu_name' => 'Opportunities', 'access_link' => self::OPPS_LINK]);
    }

    /** A user of a tenant with the given actions on the Opportunities screen. @param list<string> $actions @return array{id: int, h: array<string, string>} */
    protected function opportunitiesUser(int $tenant = self::T, array $actions = ['view', 'add', 'edit', 'delete']): array
    {
        return $this->user([self::OPPS_LINK => $actions], $tenant);
    }

    /** @param array<string, mixed> $over */
    protected function makeOpportunity(int $tenant = self::T, array $over = []): int
    {
        return (int) DB::table('crm_opportunities')->insertGetId($over + [
            'opportunity_no' => 'OPP-' . strtoupper(uniqid()), 'name' => 'Deal ' . uniqid(), 'sub_institute_id' => $tenant, 'assigned_to' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A GTM account and a deal on it. @param array<string, mixed> $over */
    protected function makeDeal(int $tenant = self::T, array $over = []): int
    {
        $account = $over['account_id'] ?? DB::table('gtm_accounts')->insertGetId(['sub_institute_id' => $tenant, 'name' => 'Account ' . uniqid(), 'stage' => 'target', 'created_at' => now(), 'updated_at' => now()]);
        unset($over['account_id']);

        return (int) DB::table('gtm_deals')->insertGetId($over + ['sub_institute_id' => $tenant, 'account_id' => $account, 'name' => 'GTM deal ' . uniqid(), 'stage' => 'discovery',
            'amount' => '5000.00', 'currency' => 'INR', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A Product Master row. @param array<string, mixed> $over */
    protected function makeProduct(int $tenant, string $key, string $name = '', array $over = []): int
    {
        return (int) DB::table('g2g_product_master')->insertGetId($over + ['sub_institute_id' => $tenant, 'kind' => 'product', 'product_key' => $key, 'signal_key' => $key,
            'portfolio_name' => $name ?: $key, 'name' => $name ?: $key, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Makes the sync fail the way a host that is behind does: the GTM deals table is gone. */
    protected function breakGtmDeals(): void
    {
        Schema::drop('gtm_deals');
    }

    /** Binds a bridge whose two sync methods throw, and resets its call counter. */
    protected function bindThrowingBridge(): void
    {
        ThrowingGtmBridge::$calls = [];
        $this->app->bind(OpportunityGtmSyncBridge::class, ThrowingGtmBridge::class);
    }
}
