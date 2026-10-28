<?php

namespace Tests\Feature;

use App\Domain\AI\Chat\ReportDeliveryService;
use App\Services\Ai\AiRequestScope;
use App\Services\Sms\TenantSmsGateway;
use App\Services\Whatsapp\TenantWhatsappCloud;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * SMS and WhatsApp delivery, through the real service, database tables and webhook code.
 *
 * Only the two outbound HTTP calls (the SMS gateway, Meta's Graph API) are faked - nothing is sent to anyone. What is
 * pinned: "sent" is claimed only when the provider accepted the message, a refusal is shown with its real cause, a
 * second click does not send twice, one organisation's gateway is never used for another, and Meta's delivery reports
 * change a row only when they carry a valid signature for the organisation that owns the number.
 */
class SmsWhatsappDeliveryTest extends TestCase
{
    private const TENANT = 3;

    private const SECRET = 'meta-app-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:' . base64_encode(random_bytes(32)), 'app.cipher' => 'AES-256-CBC']);

        Schema::create('tbluser', function ($t) {
            $t->integer('id')->primary();
            $t->integer('sub_institute_id');
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('mobile')->nullable();
            $t->integer('status')->default(1);
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('ai_generated_reports', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('title');
            $t->text('html_content')->nullable();
            $t->integer('status')->default(1);
        });
        Schema::create('ai_report_deliveries', function ($t) {
            $t->increments('id');
            $t->integer('report_id');
            $t->integer('sub_institute_id');
            $t->integer('sent_by')->nullable();
            $t->integer('recipient_user_id')->nullable();
            $t->string('recipient_email');
            $t->string('recipient_name')->nullable();
            $t->string('channel')->default('email');
            $t->string('status')->default('queued');
            $t->text('error')->nullable();
            $t->string('provider_message_id')->nullable();
            $t->string('delivery_status')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->text('delivery_error')->nullable();
            $t->string('note')->nullable();
            $t->string('dedupe_key')->nullable()->unique();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
        });
        Schema::create('g2g_integration_credentials', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('provider_key');
            $t->text('config')->nullable();
        });
        Schema::create('sms_api_details', function ($t) {
            $t->increments('id');
            $t->integer('sub_institute_id');
            $t->string('url');
            $t->string('pram')->nullable();
            $t->string('mobile_var');
            $t->string('text_var');
            $t->string('last_var')->nullable();
        });

        DB::table('tbluser')->insert([
            ['id' => 1, 'sub_institute_id' => self::TENANT, 'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin@org.test', 'mobile' => '9000000001', 'status' => 1, 'deleted_at' => null],
            ['id' => 2, 'sub_institute_id' => self::TENANT, 'first_name' => 'Asha', 'last_name' => 'Rao', 'email' => 'asha@org.test', 'mobile' => '9876543210', 'status' => 1, 'deleted_at' => null],
            ['id' => 3, 'sub_institute_id' => self::TENANT, 'first_name' => 'Nomob', 'last_name' => 'Person', 'email' => 'n@org.test', 'mobile' => '', 'status' => 1, 'deleted_at' => null],
            ['id' => 9, 'sub_institute_id' => 99, 'first_name' => 'Other', 'last_name' => 'Org', 'email' => 'o@x.test', 'mobile' => '9111111111', 'status' => 1, 'deleted_at' => null],
        ]);
        DB::table('ai_generated_reports')->insert([['id' => 7, 'sub_institute_id' => self::TENANT, 'title' => 'Team report', 'html_content' => '<p>x</p>', 'status' => 1]]);
    }

    private function scope(): AiRequestScope
    {
        return new AiRequestScope(1, 'admin', self::TENANT, [self::TENANT], 7, null, true, false);
    }

    private function sms(): void
    {
        DB::table('sms_api_details')->insert([
            'sub_institute_id' => self::TENANT, 'url' => 'https://gateway.test/api/send?', 'pram' => 'key=K&sender=S',
            'mobile_var' => '&to=', 'text_var' => '&message=', 'last_var' => '',
        ]);
    }

    private function whatsapp(array $extra = []): void
    {
        DB::table('g2g_integration_credentials')->insert([
            'sub_institute_id' => self::TENANT, 'provider_key' => 'whatsapp',
            'config' => Crypt::encryptString(json_encode(['access_token' => 'TOKEN', 'phone_number_id' => '555000111', 'api_version' => 'v25.0'] + $extra)),
        ]);
    }

    private function send(string $channel, array $ids = [2], ?string $key = 'k1'): array
    {
        return app(ReportDeliveryService::class)->sendToMobile($this->scope(), 7, $ids, $channel, 'hello', $key, 'https://g2g.test/ai/reports/7');
    }

    // ------------------------------------------------------------------ SMS

    public function test_sms_is_sent_through_the_organisations_own_gateway_and_recorded(): void
    {
        $this->sms();
        Http::fake(['gateway.test/*' => Http::response('Message GID=991122', 200)]);

        $out = $this->send('sms');

        $this->assertSame(1, $out['sent']);
        $this->assertSame('sent', $out['results'][0]['status']);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://gateway.test/api/send?key=K&sender=S&to=9876543210&message=') && str_contains(urldecode($r->url()), 'Team report'));

        $row = DB::table('ai_report_deliveries')->first();
        $this->assertSame('sms', $row->channel);
        $this->assertSame('sent', $row->status);
        $this->assertSame('9876543210', $row->recipient_email, 'the number the message went to is kept');
        $this->assertNotNull($row->sent_at);
    }

    public function test_a_gateway_that_answers_200_with_an_error_body_is_a_failure_not_a_success(): void
    {
        $this->sms();
        Http::fake(['gateway.test/*' => Http::response('ERR: Invalid template id', 200)]);

        $out = $this->send('sms');

        $this->assertSame(0, $out['sent']);
        $this->assertSame(1, $out['failed']);
        $this->assertStringContainsString('Invalid template id', $out['results'][0]['error']);
        $row = DB::table('ai_report_deliveries')->first();
        $this->assertSame('failed', $row->status);
        $this->assertNull($row->dedupe_key, 'a failed attempt can be retried');
    }

    public function test_a_gateway_http_error_and_an_unreachable_gateway_are_failures_with_the_cause(): void
    {
        $this->sms();
        Http::fake(['gateway.test/*' => Http::response('boom', 503)]);
        $this->assertStringContainsString('HTTP 503', $this->send('sms', [2], 'a')['results'][0]['error']);

        Http::fake(['gateway.test/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host')]);
        $this->assertStringContainsString('Could not resolve host', $this->send('sms', [2], 'b')['results'][0]['error']);
    }

    public function test_with_no_gateway_for_the_organisation_nothing_is_sent_and_the_reason_is_stated(): void
    {
        // Another organisation's gateway must not be borrowed.
        DB::table('sms_api_details')->insert(['sub_institute_id' => 99, 'url' => 'https://other.test/', 'mobile_var' => '&to=', 'text_var' => '&m=']);
        Http::fake();

        try {
            $this->send('sms');
            $this->fail('A send went out without a gateway for this organisation.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No SMS gateway is configured', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('ai_report_deliveries')->count());
    }

    public function test_a_recipient_without_a_number_or_from_another_organisation_blocks_the_whole_send(): void
    {
        $this->sms();
        Http::fake();

        foreach ([[3], [9], [2, 3]] as $ids) {
            try {
                $this->send('sms', $ids);
                $this->fail('Sent despite an unusable recipient.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Cannot send by SMS', $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_a_double_submit_sends_once(): void
    {
        $this->sms();
        Http::fake(['gateway.test/*' => Http::response('OK', 200)]);

        $first = $this->send('sms', [2], 'same');
        $second = $this->send('sms', [2], 'same');

        $this->assertSame(1, $first['sent']);
        $this->assertSame(1, $second['duplicate']);
        Http::assertSentCount(1);
    }

    // ------------------------------------------------------------------ WhatsApp

    public function test_whatsapp_is_sent_through_the_cloud_api_and_the_message_id_is_kept(): void
    {
        $this->whatsapp();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC123']]], 200)]);

        $out = $this->send('whatsapp');

        $this->assertSame(1, $out['sent']);
        $this->assertSame('wamid.ABC123', $out['results'][0]['message_id']);
        Http::assertSent(function ($r) {
            return $r->url() === 'https://graph.facebook.com/v25.0/555000111/messages'
                && $r->hasHeader('Authorization', 'Bearer TOKEN')
                && $r['to'] === '919876543210'
                && $r['type'] === 'template'
                && $r['template']['name'] === 'parents_';
        });

        $row = DB::table('ai_report_deliveries')->first();
        $this->assertSame('whatsapp', $row->channel);
        $this->assertSame('sent', $row->status, '"sent" means Meta accepted it');
        $this->assertSame('wamid.ABC123', $row->provider_message_id);
        $this->assertNull($row->delivery_status, 'nothing has said it was delivered yet');
    }

    public function test_a_meta_refusal_carries_the_real_cause(): void
    {
        $this->whatsapp();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131030, 'message' => 'Recipient phone number not in allowed list']], 400)]);

        $out = $this->send('whatsapp');

        $this->assertSame(1, $out['failed']);
        $error = $out['results'][0]['error'];
        $this->assertStringContainsString('Recipient phone number not in allowed list', $error);
        $this->assertStringContainsString('Meta code 131030', $error);
        $this->assertStringContainsString('allowed list', $error);
    }

    public function test_an_invalid_token_is_explained(): void
    {
        $this->assertStringContainsString('invalid or has expired', TenantWhatsappCloud::explain(190, 'Invalid OAuth access token'));
        $this->assertStringContainsString('parents_, p2 or p3', TenantWhatsappCloud::explain(132001, 'Template name does not exist'));
        $this->assertSame('plain message', TenantWhatsappCloud::explain(null, 'plain message'));
    }

    public function test_the_template_language_falls_back_only_on_template_not_found(): void
    {
        $this->whatsapp();
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['code' => 132001, 'message' => 'Template does not exist in en_US']], 404)
            ->push(['messages' => [['id' => 'wamid.EN']]], 200)]);

        $out = $this->send('whatsapp');

        $this->assertSame(1, $out['sent']);
        Http::assertSentCount(2);
    }

    public function test_without_a_connected_account_whatsapp_is_prepared_never_sent(): void
    {
        Http::fake();

        $out = $this->send('whatsapp');

        $this->assertSame(0, $out['sent']);
        $this->assertSame(1, $out['prepared']);
        $this->assertSame('prepared', $out['results'][0]['status']);
        $this->assertStringStartsWith('https://wa.me/919876543210?text=', $out['results'][0]['link']);
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('ai_report_deliveries')->count(), 'a link is not a delivery');
    }

    // ------------------------------------------------------------------ Meta's delivery reports

    private function report(array $status, ?string $secret = self::SECRET, string $phone = '555000111'): array
    {
        $body = json_encode(['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => $phone], 'statuses' => [$status]]]]]]]);
        $signature = $secret === null ? null : 'sha256=' . hash_hmac('sha256', $body, $secret);

        $response = $this->call('POST', '/api/whatsapp/webhook', [], [], [], array_filter([
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ]), $body);

        return [$response->getStatusCode(), $response];
    }

    private function sentMessage(): void
    {
        $this->whatsapp(['app_secret' => self::SECRET, 'webhook_verify_token' => 'verify-me']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC123']]], 200)]);
        $this->send('whatsapp');
    }

    public function test_a_signed_delivery_report_marks_the_message_delivered_then_read(): void
    {
        $this->sentMessage();

        [$code] = $this->report(['id' => 'wamid.ABC123', 'status' => 'delivered', 'timestamp' => '1790000000']);
        $this->assertSame(200, $code);
        $row = DB::table('ai_report_deliveries')->first();
        $this->assertSame('delivered', $row->delivery_status);
        $this->assertNotNull($row->delivered_at);

        $this->report(['id' => 'wamid.ABC123', 'status' => 'read']);
        $this->assertSame('read', DB::table('ai_report_deliveries')->value('delivery_status'));

        // A late "delivered" must not downgrade "read".
        $this->report(['id' => 'wamid.ABC123', 'status' => 'delivered']);
        $this->assertSame('read', DB::table('ai_report_deliveries')->value('delivery_status'));
    }

    public function test_a_failed_report_records_why(): void
    {
        $this->sentMessage();

        $this->report(['id' => 'wamid.ABC123', 'status' => 'failed', 'errors' => [['code' => 131026, 'message' => 'Message undeliverable']]]);

        $row = DB::table('ai_report_deliveries')->first();
        $this->assertSame('failed', $row->delivery_status);
        $this->assertStringContainsString('Message undeliverable', $row->delivery_error);
        $this->assertSame('sent', $row->status, 'what the send call reported is left as it was');
    }

    public function test_an_unsigned_or_wrongly_signed_report_changes_nothing(): void
    {
        $this->sentMessage();

        [$none] = $this->report(['id' => 'wamid.ABC123', 'status' => 'delivered'], null);
        [$wrong] = $this->report(['id' => 'wamid.ABC123', 'status' => 'delivered'], 'someone-elses-secret');
        [$stranger] = $this->report(['id' => 'wamid.ABC123', 'status' => 'delivered'], self::SECRET, '999999');

        $this->assertSame([403, 403, 403], [$none, $wrong, $stranger]);
        $this->assertNull(DB::table('ai_report_deliveries')->value('delivery_status'));
    }

    public function test_a_report_for_a_message_of_another_organisation_changes_nothing(): void
    {
        $this->sentMessage();
        // The message id exists, but under a different organisation's row.
        DB::table('ai_report_deliveries')->update(['sub_institute_id' => 99]);

        $this->report(['id' => 'wamid.ABC123', 'status' => 'delivered']);

        $this->assertNull(DB::table('ai_report_deliveries')->value('delivery_status'));
    }

    public function test_the_subscription_handshake_answers_only_a_saved_verify_token(): void
    {
        $this->whatsapp(['webhook_verify_token' => 'verify-me', 'app_secret' => self::SECRET]);

        $ok = $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345');
        $bad = $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=guess&hub.challenge=12345');

        $this->assertSame('12345', $ok->getContent());
        $this->assertSame(403, $bad->getStatusCode());
    }

    // ------------------------------------------------------------------ gateway reply reading

    public function test_gateway_replies_are_read_conservatively(): void
    {
        foreach (['Message GID=991122', 'OK', '{"status":"success"}', '<html>fine</html>', ''] as $accepted) {
            $this->assertNull(TenantSmsGateway::refusal($accepted), $accepted);
        }
        foreach (['ERR: bad key', '{"status":"error","message":"no credit"}', 'Invalid mobile number', 'Authentication failed', '{"success":false}'] as $refused) {
            $this->assertNotNull(TenantSmsGateway::refusal($refused), $refused);
        }
    }
}
