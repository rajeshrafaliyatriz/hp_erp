<?php

namespace Tests\Feature;

use App\Domain\Gtm\OutreachMailer;
use App\Support\MailGate;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOutreachWorld;
use Tests\TestCase;

class OutreachMailerTenantMailboxTest extends TestCase
{
    use BuildsOutreachWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOutreachWorld();
    }

    public function test_readiness_resolves_tenant_mailbox_and_enables_sending(): void
    {
        config(['gtm.outreach.sending_enabled' => false]);

        $mailer = app(OutreachMailer::class);
        $before = $mailer->readiness(self::T);
        $this->assertFalse($before['gtm_sending_enabled']);

        // Insert tenant SMTP details
        DB::table('smtp_details')->insert([
            'sub_institute_id' => self::T,
            'module_key' => 'gtm_outreach',
            'gmail' => 'tenant-gtm@example.com',
            'password' => Crypt::encryptString('secret123'),
            'server_address' => 'smtp.tenant-mail.com',
            'port' => '587',
            'from_name' => 'Tenant GTM Outreach',
        ]);

        $after = $mailer->readiness(self::T);
        $this->assertTrue($after['gtm_sending_enabled']);
        $this->assertSame('smtp', $after['mailer']);
        $this->assertSame('smtp.tenant-mail.com', $after['host']);
        $this->assertSame('tenant-gtm@example.com', $after['from']);
        $this->assertSame('Tenant GTM Outreach', $after['from_name']);
        $this->assertNotContains('GTM outreach sending is switched off (GTM_OUTREACH_SENDING_ENABLED).', $after['blockers']);
    }

    public function test_readiness_falls_back_to_tenant_default_mailbox(): void
    {
        config(['gtm.outreach.sending_enabled' => false]);

        DB::table('smtp_details')->insert([
            'sub_institute_id' => self::T,
            'module_key' => 'default',
            'gmail' => 'tenant-default@example.com',
            'password' => Crypt::encryptString('secret123'),
            'server_address' => 'smtp.default-mail.com',
            'port' => '465',
            'from_name' => 'Tenant Default',
        ]);

        $mailer = app(OutreachMailer::class);
        $readiness = $mailer->readiness(self::T);

        $this->assertTrue($readiness['gtm_sending_enabled']);
        $this->assertSame('smtp.default-mail.com', $readiness['host']);
        $this->assertSame('tenant-default@example.com', $readiness['from']);
        $this->assertSame('Tenant Default', $readiness['from_name']);
    }

    public function test_send_bypasses_global_disabled_when_tenant_smtp_configured(): void
    {
        config([
            'gtm.outreach.sending_enabled' => false,
            'mail.default' => 'log',
            'gtm.outreach.allow_local_unsubscribe' => true,
        ]);

        DB::table('school_setup')->updateOrInsert(['id' => self::T], ['outbound_email_enabled' => 1]);

        DB::table('smtp_details')->insert([
            'sub_institute_id' => self::T,
            'module_key' => 'gtm_outreach',
            'gmail' => 'tenant-gtm@example.com',
            'password' => Crypt::encryptString('secret123'),
            'server_address' => 'smtp.tenant-mail.com',
            'port' => '587',
            'from_name' => 'Tenant GTM Outreach',
        ]);

        $contactId = $this->makeGtmContact(self::T);
        $message = $this->makeGtmMessage(self::T, $contactId);
        $mailer = app(OutreachMailer::class);

        // When sending, it should not be refused by 'sending_disabled' or 'mailer_not_delivering'.
        // It will proceed to the transport (and fail safely with a connection exception because host is mock)
        $outcome = $mailer->send($message, 1);
        $this->assertNotSame('sending_disabled', $outcome['code']);
        $this->assertNotSame('mailer_not_delivering', $outcome['code']);
    }
}
