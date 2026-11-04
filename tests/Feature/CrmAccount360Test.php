<?php

namespace Tests\Feature;

use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GET /api/crm/account360/{id}, the data behind the Account 360 tab of an organization (defect D2 of docs/agentic-crm/PLAN.md). It used to select columns that
 * do not exist (opportunity_name, close_date on crm_opportunities; campaign_name on crm_campaigns), so it failed with a SQL error on every call, and it sent
 * snake_case rows where the screen reads camelCase. The tables here come from the REAL migration files, so a column that is not in them fails the test.
 */
class CrmAccount360Test extends TestCase
{
    private const T = 1;

    private const T2 = 2;

    private const SIGNALS = '/module/crm/signals';

    private int $org;

    protected function setUp(): void
    {
        parent::setUp();
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
        // The leads migrations probe information_schema, which sqlite has not got: the table is declared with the columns the endpoint reads.
        Schema::create('crm_leads', function ($t) {
            $t->id();
            $t->string('first_name')->nullable();
            $t->string('last_name');
            $t->string('company')->nullable();
            $t->string('email')->nullable();
            $t->string('lead_status')->nullable();
            $t->string('lifecycle_status')->nullable();
            $t->string('lead_source')->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->unsignedBigInteger('converted_organization_id')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        foreach ([
            '2026_11_20_090100_create_crm_organizations_table', '2026_11_20_090200_create_crm_contacts_table', '2026_11_20_090400_create_crm_campaigns_table',
            '2026_11_20_090500_create_crm_campaign_targets_table', '2026_11_24_080300_create_crm_opportunities_table', '2026_12_03_100000_create_crm_signal_inbox_table',
            '2026_12_03_110000_create_crm_entity_relationships_table', '2026_12_03_110100_create_crm_knowledge_sources_table',
            // sent_at (and the other touchpoint timestamps) of a campaign target come from this migration, not from the one that creates the table.
            '2026_12_03_080300_add_touchpoint_events_to_crm_campaign_targets',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
        // consent_status is added to the contacts by 2026_12_03_080100, which probes information_schema: added directly.
        Schema::table('crm_contacts', function ($t) {
            $t->string('consent_status')->nullable();
        });

        Cache::flush();
        DB::table('tblmenumaster_g2g')->insert(['id' => 489, 'menu_name' => 'Market Signals', 'access_link' => self::SIGNALS]);
        $this->org = $this->insert('crm_organizations', ['name' => 'Greenfield Academy', 'account_no' => 'ORG-G1', 'industry' => 'Education', 'website' => 'greenfield.example']);
    }

    // ---- helpers ----

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row, int $tenant = self::T): int
    {
        $row += ['created_at' => now(), 'updated_at' => now()];
        if (! in_array($table, ['crm_campaign_targets'], true)) {
            $row += ['sub_institute_id' => $tenant];
        }
        if ($table === 'crm_contacts') {
            $row += ['contact_no' => 'CON-' . uniqid('', true)];   // NOT NULL and unique on the real table
        }
        $columns = Schema::getColumnListing($table);
        if (! in_array('updated_at', $columns, true)) {
            unset($row['updated_at']);
        }
        if (! in_array('created_at', $columns, true)) {
            unset($row['created_at']);
        }

        return (int) DB::table($table)->insertGetId($row);
    }

    /** @return array<string, string> */
    private function headers(array $actions = ['view'], int $tenant = self::T, bool $signalsRow = true): array
    {
        $profile = DB::table('tbluserprofilemaster')->insertGetId(['name' => 'P' . uniqid(), 'role_key' => 'employee', 'sub_institute_id' => $tenant]);
        if ($signalsRow) {
            DB::table('tblgroupwise_rights_g2g')->insert(['sub_institute_id' => $tenant, 'menu_id' => 489, 'profile_id' => $profile]
                + array_combine(array_map(fn ($a) => "can_{$a}", $actions), array_fill(0, count($actions), 1)));
        }
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => $tenant, 'user_profile_id' => $profile, 'status' => 1]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    /** One of everything for the organization, plus rows that must NOT show: another organisation's, deleted ones, another organization's. */
    private function populate(): void
    {
        $contact = ['organization_id' => $this->org, 'first_name' => 'Meera', 'last_name' => 'Iyer', 'title' => 'Principal', 'email' => 'meera@greenfield.example', 'phone' => '98100', 'consent_status' => 'opted_in'];
        $this->insert('crm_contacts', $contact);
        $this->insert('crm_contacts', ['last_name' => 'Deleted', 'deleted_at' => now()] + $contact);
        $this->insert('crm_contacts', ['last_name' => 'OtherTenant'] + $contact, self::T2);

        $this->insert('crm_leads', ['first_name' => 'Ravi', 'last_name' => 'Shah', 'company' => 'Greenfield', 'lead_status' => 'Qualified', 'converted_organization_id' => $this->org]);

        $this->insert('crm_opportunities', ['organization_id' => $this->org, 'name' => 'Scholar licence', 'opportunity_no' => 'OPP-1', 'sales_stage' => 'Proposal/Price Quote', 'amount' => 250000, 'currency' => 'INR', 'closing_date' => '2026-12-31']);
        $this->insert('crm_opportunities', ['organization_id' => $this->org, 'name' => 'Deleted deal', 'opportunity_no' => 'OPP-2', 'deleted_at' => now()]);

        $campaign = $this->insert('crm_campaigns', ['name' => 'Autumn outreach', 'campaign_no' => 'CAM-1']);
        $other = $this->insert('crm_campaigns', ['name' => 'Their campaign', 'campaign_no' => 'CAM-2'], self::T2);
        DB::table('crm_campaign_targets')->insert(['campaign_id' => $campaign, 'target_type' => 'organization', 'target_id' => $this->org, 'response_status' => 'replied', 'created_at' => now(), 'sent_at' => '2026-10-01 10:00:00']);
        DB::table('crm_campaign_targets')->insert(['campaign_id' => $other, 'target_type' => 'organization', 'target_id' => $this->org, 'response_status' => 'sent', 'created_at' => now()]);
        $gone = $this->insert('crm_campaigns', ['name' => 'Deleted campaign', 'campaign_no' => 'CAM-3', 'deleted_at' => now()]);
        DB::table('crm_campaign_targets')->insert(['campaign_id' => $gone, 'target_type' => 'organization', 'target_id' => $this->org, 'response_status' => 'sent', 'created_at' => now()]);

        $this->insert('crm_signal_inbox', ['signal_key' => 'k1', 'title' => 'Greenfield opens a campus', 'classification' => 'buying_signal', 'relevance_score' => 0.9, 'status' => 'new', 'source_name' => 'The Hindu',
            'source_url' => 'https://news.example/greenfield', 'linked_account_id' => $this->org, 'published_at' => '2026-10-02 08:00:00', 'ingested_at' => now()]);

        $this->insert('crm_entity_relationships', ['from_type' => 'organization', 'from_id' => $this->org, 'to_type' => 'organization', 'to_id' => 999, 'relationship_type' => 'subsidiary_of', 'confidence' => 0.8, 'verified' => 1]);
        $this->insert('crm_knowledge_sources', ['entity_type' => 'organization', 'entity_id' => $this->org, 'source_type' => 'news', 'title' => 'Annual report', 'url' => 'https://greenfield.example/report', 'confidence' => 0.7, 'verified' => 0, 'collected_at' => now()]);
    }

    // ---- the repair ----

    public function test_it_answers_instead_of_failing_with_a_sql_error_and_every_section_has_its_real_data(): void
    {
        $this->populate();

        $res = $this->getJson("/api/crm/account360/{$this->org}", $this->headers())->assertOk();
        $d = $res->json('data');

        $this->assertSame(['organization', 'contacts', 'leads', 'opportunities', 'signals', 'campaigns', 'relationships', 'knowledgeSources'], array_keys($d));
        $this->assertSame('Greenfield Academy', $d['organization']['name']);

        $this->assertCount(1, $d['contacts'], 'not the deleted one, not another organisation\'s');
        $this->assertSame(['Meera', 'Iyer', 'Principal', 'opted_in'], [$d['contacts'][0]['firstName'], $d['contacts'][0]['lastName'], $d['contacts'][0]['title'], $d['contacts'][0]['consentStatus']]);

        $this->assertCount(1, $d['leads']);
        $this->assertSame(['Ravi', 'Qualified'], [$d['leads'][0]['firstName'], $d['leads'][0]['leadStatus']]);

        $this->assertCount(1, $d['opportunities'], 'the deleted deal is not shown');
        $this->assertSame(['Scholar licence', 'Proposal/Price Quote', 'INR', '2026-12-31'],
            [$d['opportunities'][0]['name'], $d['opportunities'][0]['salesStage'], $d['opportunities'][0]['currency'], $d['opportunities'][0]['closingDate']]);
        $this->assertEquals(250000, $d['opportunities'][0]['amount'], 'a number, whether JSON writes it with a decimal point or not');
        $this->assertIsNumeric($d['opportunities'][0]['amount']);

        $this->assertCount(1, $d['signals']);
        $this->assertSame(['Greenfield opens a campus', 'buying_signal', 'The Hindu', 'https://news.example/greenfield'],
            [$d['signals'][0]['title'], $d['signals'][0]['classification'], $d['signals'][0]['sourceName'], $d['signals'][0]['sourceUrl']]);

        $this->assertSame([['campaignName' => 'Autumn outreach', 'responseStatus' => 'replied']],
            array_map(fn ($c) => ['campaignName' => $c['campaignName'], 'responseStatus' => $c['responseStatus']], $d['campaigns']), 'the campaign\'s real name; neither another organisation\'s campaign nor a deleted one is shown');

        $this->assertSame(['subsidiary_of', true], [$d['relationships'][0]['relationshipType'], $d['relationships'][0]['verified']]);
        $this->assertSame(['Annual report', 'news'], [$d['knowledgeSources'][0]['title'], $d['knowledgeSources'][0]['sourceType']]);
    }

    public function test_an_organization_with_nothing_attached_answers_with_empty_lists(): void
    {
        $d = $this->getJson("/api/crm/account360/{$this->org}", $this->headers())->assertOk()->json('data');

        foreach (['contacts', 'leads', 'opportunities', 'signals', 'campaigns', 'relationships', 'knowledgeSources'] as $section) {
            $this->assertSame([], $d[$section], $section);
        }
    }

    // ---- who may ask ----

    public function test_another_organisations_or_a_deleted_or_a_missing_organization_is_a_404(): void
    {
        $theirs = $this->insert('crm_organizations', ['name' => 'Their School', 'account_no' => 'ORG-T1'], self::T2);
        $deleted = $this->insert('crm_organizations', ['name' => 'Deleted School', 'account_no' => 'ORG-D1', 'deleted_at' => now()]);
        $h = $this->headers();

        foreach ([$theirs, $deleted, 999999] as $id) {
            $this->getJson("/api/crm/account360/{$id}", $h)->assertStatus(404);
        }
    }

    public function test_the_market_signals_view_right_is_still_what_the_route_asks_for(): void
    {
        $this->getJson("/api/crm/account360/{$this->org}", $this->headers([], self::T, false))->assertStatus(403);
        $this->getJson("/api/crm/account360/{$this->org}")->assertStatus(401);
    }
}
