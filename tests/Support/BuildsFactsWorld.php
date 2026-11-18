<?php

namespace Tests\Support;

use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The database and the people the facts and consent tests (slice 4) run on: every table comes from the REAL migration file on the in-memory sqlite database
 * (the ledger's own three included), the users and their rights are real rows, and the menu rows carry the access_links the routes look for. Call
 * buildFactsWorld() in setUp().
 */
trait BuildsFactsWorld
{
    protected const T = 1;

    protected const T2 = 2;

    protected const CONTACTS_LINK = '/module/crm/marketing/contacts';

    protected const CONTACTS_SUPPORT = '/module/crm/support/contacts';

    protected const ORGS_LINK = '/module/crm/marketing/organizations';

    protected const ORGS_SUPPORT = '/module/crm/support/organizations';

    protected const LEADS_LINK = '/module/crm/marketing/leads';

    /** @var list<string> */
    private array $factsMigrations = [
        '2026_08_10_140000_create_g2g_event_store', '2026_11_20_090100_create_crm_organizations_table', '2026_11_20_090200_create_crm_contacts_table',
        'crm_leads:by hand', '2026_12_03_080100_add_consent_fields_to_crm_leads_contacts', '2026_12_03_080200_create_crm_consent_preferences_table',
        '2026_10_10_100000_create_gtm_foundation_tables', '2026_10_10_500000_create_gtm_outreach_messages', '2026_10_11_140000_gtm_outreach_automation',
        '2026_12_11_090000_create_g2g_entity_facts_table', '2026_12_11_090100_create_g2g_entity_fact_evidence_table', '2026_12_11_090200_add_linkedin_url_to_crm_contacts_and_organizations',
    ];

    protected function buildFactsWorld(): void
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

        foreach ($this->factsMigrations as $migration) {
            if ($migration === 'crm_leads:by hand') {
                // The leads table's own migrations are written for MariaDB only (information_schema); the other tests that need it build it by hand as well.
                // The columns are the real ones the facts and the consent gate read or write.
                Schema::create('crm_leads', function ($t) {
                    $t->id();
                    $t->string('lead_no', 30)->nullable();
                    $t->string('first_name', 191)->nullable();
                    $t->string('last_name', 191);
                    $t->string('company', 191)->nullable();
                    $t->string('designation')->nullable();
                    $t->string('email', 191)->nullable();
                    $t->string('secondary_email', 191)->nullable();
                    $t->string('phone', 50)->nullable();
                    $t->string('mobile', 50)->nullable();
                    $t->string('website', 191)->nullable();
                    $t->boolean('email_opt_out')->default(false);
                    $t->boolean('converted')->default(false);
                    $t->unsignedBigInteger('converted_organization_id')->nullable();
                    $t->unsignedBigInteger('converted_contact_id')->nullable();
                    $t->unsignedBigInteger('converted_opportunity_id')->nullable();
                    $t->unsignedBigInteger('assigned_to')->default(0);
                    $t->unsignedBigInteger('sub_institute_id')->nullable();
                    $t->unsignedBigInteger('created_by')->nullable();
                    $t->unsignedBigInteger('updated_by')->nullable();
                    $t->unsignedBigInteger('deleted_by')->nullable();
                    $t->timestamp('created_at')->nullable();
                    $t->timestamp('updated_at')->nullable();
                    $t->timestamp('deleted_at')->nullable();
                });

                continue;
            }
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
        // The audit columns the dev tables have (a later migration adds them to the organisations and contacts tables); the tests only need them to exist.
        foreach (['crm_organizations', 'crm_contacts'] as $table) {
            foreach (['updated_by', 'deleted_by'] as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn ($t) => $t->unsignedBigInteger($column)->nullable());
                }
            }
        }

        Cache::flush();
        DB::table('tblmenumaster_g2g')->insert([
            ['id' => 451, 'menu_name' => 'Contacts', 'access_link' => self::CONTACTS_LINK],
            ['id' => 482, 'menu_name' => 'Contacts (support)', 'access_link' => self::CONTACTS_SUPPORT],
            ['id' => 452, 'menu_name' => 'Organizations', 'access_link' => self::ORGS_LINK],
            ['id' => 483, 'menu_name' => 'Organizations (support)', 'access_link' => self::ORGS_SUPPORT],
            ['id' => 201, 'menu_name' => 'Leads', 'access_link' => self::LEADS_LINK],
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

    /** View and edit on the Contacts, Organizations and Leads screens: everything the facts API asks for. @return array{id: int, h: array<string, string>} */
    protected function editor(int $tenant = self::T, string $first = 'Asha', string $last = 'Rao'): array
    {
        return $this->user([self::CONTACTS_LINK => ['view', 'edit'], self::ORGS_LINK => ['view', 'edit'], self::LEADS_LINK => ['view', 'edit']], $tenant, $first, $last);
    }

    // ---- records ----

    /** @param array<string, mixed> $over */
    protected function makeContact(int $tenant = self::T, array $over = []): int
    {
        return (int) DB::table('crm_contacts')->insertGetId($over + ['contact_no' => 'CON-' . uniqid(), 'first_name' => 'Priya', 'last_name' => 'Sharma', 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $over */
    protected function makeOrganization(int $tenant = self::T, array $over = []): int
    {
        return (int) DB::table('crm_organizations')->insertGetId($over + ['account_no' => 'ORG-' . uniqid(), 'name' => 'School ' . uniqid(), 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $over */
    protected function makeLead(int $tenant = self::T, array $over = []): int
    {
        return (int) DB::table('crm_leads')->insertGetId($over + ['lead_no' => 'L-' . uniqid(), 'first_name' => 'Rahul', 'last_name' => 'Verma', 'company' => 'Greenfield School', 'assigned_to' => 1, 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** One piece of evidence per kind, as a caller would hand it over. @return list<array{kind: string, source_url: string, quote: string}> */
    protected function evidence(string ...$kinds): array
    {
        return array_map(fn ($kind, $i) => ['kind' => $kind, 'source_url' => "https://source.example/{$i}", 'quote' => "saw {$kind}"], $kinds, array_keys($kinds));
    }
}
