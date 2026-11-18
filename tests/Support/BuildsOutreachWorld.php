<?php

namespace Tests\Support;

use App\Domain\Gtm\GtmOutreachMessage;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * The world the outreach gate tests (slice 10) and the characterisation tests of the two mailers run in: the facts world (CRM contacts, organisations, leads,
 * consent preferences, the GTM foundation, outreach messages, attempts and suppressions, all from the real migration files), plus the tables the mailers
 * touch that the facts world does not build (the platform mail gate, the SMTP profiles, CRM emails and their links, opportunities and tickets), the product
 * master, and the gate's own tables and columns from THEIR real migration files.
 *
 * NOTHING HERE CAN DELIVER MAIL. The mailers' `MailSender::mailer()` returns an `Illuminate\Mail\Mailer` (a class, so `Mail::fake()`'s MailFake cannot stand in
 * for it, and MailFake::raw() records nothing anyway). So the tests point the default mailer at a mailer called "capture" whose transport is Laravel's
 * in-memory `array` transport: a real Mailer, a real code path, and a transport that only appends to a PHP array. sendingOn() proves that before it switches
 * anything on, and `smtp_details` stays empty so no tenant profile (a real SMTP host) can ever be resolved.
 */
trait BuildsOutreachWorld
{
    use BuildsFactsWorld;

    protected const CLEAN_BODY = 'Hello, we help schools run admissions without paperwork. Would a short call next week suit you?';

    protected const GATE_MIGRATIONS = [
        '2026_12_11_150000_create_g2g_outreach_gate_checks_table',
        '2026_12_11_150100_create_g2g_product_claims_table',
        '2026_12_11_150200_add_product_master_id_to_gtm_playbooks',
        '2026_12_11_150300_add_product_master_id_to_gtm_outreach_messages',
        '2026_12_11_150400_add_delivery_indexes_to_gtm_outreach_messages',
    ];

    protected function buildOutreachWorld(bool $withGate = true): void
    {
        $this->buildFactsWorld();

        Schema::create('school_setup', function ($t) {
            $t->id();
            $t->boolean('outbound_email_enabled')->default(false);
        });
        Schema::create('smtp_details', function ($t) {
            $t->id();
            $t->string('gmail')->nullable();
            $t->string('password')->nullable();
            $t->string('server_address')->nullable();
            $t->string('port')->nullable();
            $t->string('module_key')->nullable();
            $t->string('from_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        // The real columns of crm_emails on dev (SHOW CREATE TABLE, 4 Nov 2026), minus the foreign key sqlite does not need.
        Schema::create('crm_emails', function ($t) {
            $t->id();
            $t->unsignedBigInteger('legacy_id')->nullable();
            $t->string('subject', 255);
            $t->text('body');
            $t->string('to_address', 191);
            $t->unsignedBigInteger('template_id')->nullable();
            $t->unsignedBigInteger('campaign_id')->nullable();
            $t->string('status', 20)->default('Draft');
            $t->timestamp('sent_at')->nullable();
            $t->text('failure_reason')->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('sub_institute_id');
            $t->timestamps();
            $t->string('message_id', 191)->nullable();
            $t->string('direction', 10)->default('outbound');
            $t->string('from_address', 191)->nullable();
            $t->unsignedBigInteger('in_reply_to_inbound_id')->nullable();
        });
        Schema::create('crm_email_links', function ($t) {
            $t->id();
            $t->unsignedBigInteger('email_id');
            $t->string('linked_type', 40);
            $t->unsignedBigInteger('linked_id');
            $t->unsignedBigInteger('sub_institute_id');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('crm_opportunities', function ($t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('crm_tickets', function ($t) {
            $t->id();
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        (require base_path('database/migrations/2026_12_07_090000_create_g2g_product_master_table.php'))->up();
        if ($withGate) {
            foreach (self::GATE_MIGRATIONS as $migration) {
                if (is_file(base_path("database/migrations/{$migration}.php"))) {
                    (require base_path("database/migrations/{$migration}.php"))->up();
                }
            }
        }

        DB::table('tblmenumaster_g2g')->insert([
            ['id' => 520, 'menu_name' => 'Outreach Approvals', 'access_link' => self::APPROVALS_LINK],
            ['id' => 521, 'menu_name' => 'GTM Outreach', 'access_link' => self::GTM_OUTREACH_LINK],
            ['id' => 522, 'menu_name' => 'Products', 'access_link' => self::PRODUCTS_LINK],
        ]);
    }

    protected const APPROVALS_LINK = '/module/crm/marketing/outreach-approvals';

    protected const GTM_OUTREACH_LINK = '/gtm/outreach';

    protected const PRODUCTS_LINK = '/module/crm/sales/products';

    // ---- the only transport there is ----

    /** @return ArrayTransport the in-memory transport of the "capture" mailer */
    protected function captureTransport(): ArrayTransport
    {
        config(['mail.mailers.capture' => ['transport' => 'array']]);
        $transport = Mail::mailer('capture')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport, 'the capture mailer must be the in-memory transport or no send test may run');

        return $transport;
    }

    /** How many messages the capture transport was handed. */
    protected function captured(): int
    {
        return count($this->captureTransport()->messages());
    }

    /** Switch both mailers' own flags and the platform gate ON for a tenant, with the in-memory transport as the default mailer. Nothing else is switched on. */
    protected function sendingOn(int $tenant = self::T): void
    {
        $this->captureTransport();
        config([
            'mail.default' => 'capture', 'mail.from.address' => 'sender@example.test', 'mail.from.name' => 'Sender',
            'app.url' => 'https://crm.example.org',
            'gtm.outreach.sending_enabled' => true, 'crm.email_sending_enabled' => true,
        ]);
        Mail::purge();
        $this->assertSame('capture', config('mail.default'));
        $this->assertSame([], DB::table('smtp_details')->get()->all(), 'no tenant SMTP profile may exist in a send test');
        if (! DB::table('school_setup')->where('id', $tenant)->exists()) {
            DB::table('school_setup')->insert(['id' => $tenant, 'outbound_email_enabled' => 1]);
        } else {
            DB::table('school_setup')->where('id', $tenant)->update(['outbound_email_enabled' => 1]);
        }
        $this->assertSame(0, $this->captured(), 'the capture transport starts empty');
    }

    // ---- records ----

    protected function makeGtmAccount(int $tenant = self::T): int
    {
        return (int) DB::table('gtm_accounts')->insertGetId(['sub_institute_id' => $tenant, 'name' => 'Verify School ' . uniqid(), 'stage' => 'target', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $over */
    protected function makeGtmContact(int $tenant = self::T, array $over = []): int
    {
        return (int) DB::table('gtm_contacts')->insertGetId($over + [
            'sub_institute_id' => $tenant, 'account_id' => $this->makeGtmAccount($tenant), 'full_name' => 'Verify Recipient', 'email' => 'recipient.' . uniqid() . '@example.test',
            'status' => 'active', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** An APPROVED outreach message with its hash pinned, as the approval flow leaves it. @param array<string, mixed> $over */
    protected function makeGtmMessage(int $tenant, int $contactId, array $over = []): GtmOutreachMessage
    {
        $contact = DB::table('gtm_contacts')->where('id', $contactId)->first();
        $m = new GtmOutreachMessage($over + [
            'sub_institute_id' => $tenant, 'account_id' => $contact->account_id, 'contact_id' => $contactId, 'subject' => 'A short question about admissions',
            'body' => self::CLEAN_BODY, 'status' => 'approved', 'step' => 1,
        ]);
        $m->approved_hash = $m->contentHash();
        $m->save();

        return $m;
    }

    /** A product (tenant 0 = shared) in the product master. @param array<string, mixed> $over */
    protected function makeProduct(int $tenant, string $name, array $over = []): int
    {
        return (int) DB::table('g2g_product_master')->insertGetId($over + [
            'sub_institute_id' => $tenant, 'product_key' => 'p' . uniqid(), 'kind' => 'sub_product', 'name' => $name, 'status' => 'active', 'origin' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A claim of a product, written straight into the table (the API's own tests use the API). */
    protected function makeClaim(int $tenant, int $productId, string $claim, string $status = 'approved'): int
    {
        return (int) DB::table('g2g_product_claims')->insertGetId([
            'sub_institute_id' => $tenant, 'product_master_id' => $productId, 'claim' => $claim, 'claim_hash' => \App\Domain\OutreachGate\ClaimBook::hash($claim), 'status' => $status,
            'approved_at' => $status === 'approved' ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A Queued CRM email linked to a record. @param array<string, mixed> $over @return object the crm_emails row */
    protected function makeCrmEmail(int $tenant, string $linkedType, int $linkedId, array $over = []): object
    {
        $id = (int) DB::table('crm_emails')->insertGetId($over + [
            'subject' => 'A short question about admissions', 'body' => self::CLEAN_BODY, 'to_address' => 'recipient.' . uniqid() . '@example.test',
            'status' => 'Queued', 'sub_institute_id' => $tenant, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('crm_email_links')->insert(['email_id' => $id, 'linked_type' => strtoupper($linkedType), 'linked_id' => $linkedId, 'sub_institute_id' => $tenant, 'created_at' => now()]);

        return DB::table('crm_emails')->find($id);
    }
}
