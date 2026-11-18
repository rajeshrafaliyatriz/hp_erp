<?php

namespace Tests\Support;

use App\Domain\ProductMaster\ProductMaster;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The database the campaign tests (slice 9) run on, built on top of the leads world (BuildsLeadsWorld, which builds on BuildsFactsWorld: the users and their
 * rights, the contacts, the organisations, the leads table with its real columns, the capture settings, the targets table) so no fixture is written twice.
 * What it adds is what a campaign needs beyond that: the campaigns table, the targets' touchpoint timestamps, the GTM campaigns, the Product Master, the Campaigns
 * menu row, and the three migrations of this slice, all from their real files.
 *
 * A test class that uses this trait also uses BuildsFactsWorld and BuildsLeadsWorld. Call buildCampaignsWorld() in setUp(); buildCampaignsWorld(false) builds the
 * OLD schema (the tables as they were before this slice, no new column) for the tests that prove the existing routes still answer on a host that has not been
 * migrated yet.
 */
trait BuildsCampaignsWorld
{
    protected const CAMPAIGNS_LINK = '/module/crm/marketing/campaigns';

    /** The migrations that existed before this slice, in the order they need. @var list<string> */
    private array $campaignsBaseMigrations = [
        '2026_11_20_090400_create_crm_campaigns_table',
        '2026_12_03_080300_add_touchpoint_events_to_crm_campaign_targets',
        '2026_10_11_110000_gtm_marketing',
        '2026_12_07_090000_create_g2g_product_master_table',
    ];

    /** The three migrations of this slice. @var list<string> */
    private array $campaignsSliceMigrations = [
        '2026_12_11_140000_add_product_master_id_to_crm_campaigns',
        '2026_12_11_140100_add_product_and_crm_campaign_to_gtm_campaigns',
        '2026_12_11_140200_add_campaign_id_to_crm_leads',
    ];

    protected function buildCampaignsWorld(bool $withSliceColumns = true): void
    {
        $this->buildLeadsWorld();

        foreach ($this->campaignsBaseMigrations as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
        // The audit columns the dev table has (a later migration adds them; it is written for MariaDB only), and the delete bookkeeping the controller writes.
        foreach (['updated_by', 'deleted_by'] as $column) {
            if (! Schema::hasColumn('crm_campaigns', $column)) {
                Schema::table('crm_campaigns', fn ($t) => $t->unsignedBigInteger($column)->nullable());
            }
        }
        DB::table('tblmenumaster_g2g')->insert(['id' => 453, 'menu_name' => 'Campaigns', 'access_link' => self::CAMPAIGNS_LINK]);

        if ($withSliceColumns) {
            foreach ($this->campaignsSliceMigrations as $migration) {
                (require base_path("database/migrations/{$migration}.php"))->up();
            }
        }
        Cache::flush();
    }

    /** A user of a tenant with the given actions on the Campaigns screen (and the Leads screen). @param list<string> $actions @return array{id: int, h: array<string, string>} */
    protected function campaignsUser(int $tenant = self::T, array $actions = ['view', 'add', 'edit', 'delete']): array
    {
        return $this->user([self::CAMPAIGNS_LINK => $actions, self::LEADS_LINK => $actions], $tenant);
    }

    /** A CRM campaign of a tenant. @param array<string, mixed> $over */
    protected function makeCampaign(int $tenant = self::T, array $over = []): int
    {
        return (int) DB::table('crm_campaigns')->insertGetId($over + [
            'campaign_no' => 'CAM-' . strtoupper(uniqid()), 'name' => 'Campaign ' . uniqid(), 'campaign_status' => 'Active', 'assigned_to' => 1,
            'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A target of a campaign; $over carries the touchpoint timestamps. @param array<string, mixed> $over */
    protected function makeTarget(int $campaignId, string $type = 'lead', ?int $targetId = null, array $over = []): int
    {
        return (int) DB::table('crm_campaign_targets')->insertGetId($over + [
            'campaign_id' => $campaignId, 'target_type' => $type, 'target_id' => $targetId ?? random_int(1000, 9_999_999), 'response_status' => 'none', 'created_at' => now(),
        ]);
    }

    /** A GTM campaign of a tenant. @param array<string, mixed> $over */
    protected function makeGtmCampaign(int $tenant = self::T, array $over = []): int
    {
        return (int) DB::table('gtm_campaigns')->insertGetId($over + [
            'sub_institute_id' => $tenant, 'name' => 'GTM ' . uniqid(), 'kind' => 'abm', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A Product Master row. 0 as the tenant is a shared row (visible to every organisation). @param array<string, mixed> $over */
    protected function makeProductRow(int $tenant, string $name, array $over = []): int
    {
        return (int) ProductMaster::query()->create($over + [
            'sub_institute_id' => $tenant, 'kind' => 'product', 'product_key' => strtolower(str_replace(' ', '_', $name)) . '_' . uniqid(), 'name' => $name, 'status' => 'active',
        ])->id;
    }

    /** @return array<string, string> the request headers of an administrator of a tenant (the GTM routes are gated by profile) */
    protected function gtmAdmin(int $tenant = self::T): array
    {
        return $this->signalsAdmin($tenant)['h'];
    }
}
