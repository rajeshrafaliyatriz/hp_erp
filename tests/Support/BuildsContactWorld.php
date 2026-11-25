<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The database the S18 contact tests run on (in-memory sqlite): the facts world (users with real rights, organisations, contacts, leads, the event store, consent, facts) plus the REAL migration of
 * every table a contact id can be stored in (quotes, orders, invoices, tickets, opportunities and their people, vendors, comments, e-mails, documents, the calendar, campaigns, SMS, approvals,
 * knowledge, relationships, custom fields, the audit log) and the S18 migration itself (the star and the role). Call buildContactWorld() in setUp().
 */
trait BuildsContactWorld
{
    use BuildsFactsWorld;

    protected const SMS_LINK = '/module/crm/sales/sms-notifier';

    protected const SMS_SUPPORT = '/module/crm/support/sms-notifier';

    protected const QUOTES_LINK = '/module/crm/sales/quotes';

    /** @var list<string> */
    private array $contactWorldMigrations = [
        '2026_08_10_150000_create_g2g_audit_log',
        '2026_11_20_090400_create_crm_campaigns_table', '2026_11_20_090500_create_crm_campaign_targets_table',
        '2026_11_24_080100_create_crm_tax_rates_table', '2026_11_24_080200_create_crm_products_table',
        '2026_11_24_080300_create_crm_opportunities_table', '2026_11_24_080400_create_crm_opportunity_contacts_table',
        '2026_11_24_080700_create_crm_quotes_table', '2026_11_24_080900_create_crm_sms_log_table',
        '2026_11_24_082200_create_crm_vendors_table', '2026_11_24_082300_create_crm_vendor_contacts_table',
        '2026_11_24_082400_create_crm_sales_orders_table', '2026_11_24_082600_create_crm_purchase_orders_table', '2026_11_24_082800_create_crm_invoices_table',
        '2026_11_24_083200_create_crm_service_contracts_table', '2026_11_24_083300_create_crm_assets_table', '2026_11_24_083400_create_crm_tickets_table',
        '2026_11_24_084000_create_crm_entity_comments_tables',
        'document_library:by hand', '2026_11_24_084100_create_document_library_links_table',
        '2026_11_24_084200_create_crm_email_templates_table', '2026_11_24_084300_create_crm_emails_table', '2026_11_30_120000_add_campaign_id_to_crm_emails_table', '2026_11_24_084400_create_crm_email_links_table',
        '2026_10_10_090000_create_task_management_calendar_events_table',
        '2026_12_03_080400_create_crm_outreach_approvals_table', '2026_12_03_110000_create_crm_entity_relationships_table', '2026_12_03_110100_create_crm_knowledge_sources_table',
        '2026_09_26_110000_create_g2g_custom_field_values_table',
        '2026_12_26_100000_add_primary_and_role_to_crm_contacts',
    ];

    protected function buildContactWorld(): void
    {
        $this->buildFactsWorld();
        // The history, e-mail and comment lists name the person with MariaDB's CONCAT, which sqlite has not got.
        DB::connection()->getPdo()->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts), -1);

        foreach ($this->contactWorldMigrations as $migration) {
            if ($migration === 'document_library:by hand') {
                // The real migration adds a FULLTEXT index, which sqlite has not got; these are the columns the documents list reads.
                Schema::create('document_library', function ($t) {
                    $t->id();
                    $t->unsignedBigInteger('sub_institute_id')->nullable();
                    $t->string('title', 191)->nullable();
                    $t->string('original_file_name')->nullable();
                    $t->string('mime_type')->nullable();
                    $t->unsignedBigInteger('size')->nullable();
                    $t->timestamp('created_at')->nullable();
                    $t->timestamp('deleted_at')->nullable();
                });

                continue;
            }
            (require base_path("database/migrations/{$migration}.php"))->up();
        }

        DB::table('tblmenumaster_g2g')->insert([
            ['id' => 460, 'menu_name' => 'SMS Notifier', 'access_link' => self::SMS_LINK],
            ['id' => 461, 'menu_name' => 'SMS Notifier (support)', 'access_link' => self::SMS_SUPPORT],
            ['id' => 462, 'menu_name' => 'Quotes', 'access_link' => self::QUOTES_LINK],
        ]);
    }

    /** A user of a tenant holding every right on Contacts (Marketing) and Organizations, view on Quotes: the person the API tests are made by. @return array{id: int, h: array<string, string>} */
    protected function crmUser(int $tenant = self::T, string $first = 'Asha', string $last = 'Rao'): array
    {
        return $this->user([
            self::CONTACTS_LINK => ['view', 'add', 'edit', 'delete'], self::ORGS_LINK => ['view', 'add', 'edit', 'delete'], self::QUOTES_LINK => ['view'],
            self::SMS_LINK => ['view', 'edit'],
        ], $tenant, $first, $last);
    }

    /** @param array<string, mixed> $over */
    protected function makeQuote(int $tenant, array $over = []): int
    {
        return (int) DB::table('crm_quotes')->insertGetId($over + ['quote_no' => 'Q-' . uniqid(), 'subject' => 'Quote', 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now()]);
    }
}
