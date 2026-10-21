<?php

namespace Tests\Feature;

use App\Domain\Reports\ReportMobileSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Reports section's SMS / WhatsApp / both send, through the real sender, services and tables.
 * Only the two outbound HTTP calls (SMS gateway, Meta Graph API) are faked - nothing is sent to anyone.
 */
class ReportMobileSenderTest extends TestCase
{
    private const TENANT = 3;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:' . base64_encode(random_bytes(32)), 'app.cipher' => 'AES-256-CBC']);
        Cache::flush();

        Schema::create('tbluser', function ($t) {
            $t->integer('id')->primary();
            $t->integer('sub_institute_id');
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('mobile')->nullable();
            $t->integer('status')->default(1);
            $t->timestamp('deleted_at')->nullable();
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
            ['id' => 1, 'sub_institute_id' => self::TENANT, 'first_name' => 'Admin', 'last_name' => 'User', 'mobile' => '9000000001', 'status' => 1],
            ['id' => 2, 'sub_institute_id' => self::TENANT, 'first_name' => 'Asha', 'last_name' => 'Rao', 'mobile' => '9876543210', 'status' => 1],
            ['id' => 3, 'sub_institute_id' => self::TENANT, 'first_name' => 'Nomob', 'last_name' => 'Person', 'mobile' => '', 'status' => 1],
            ['id' => 9, 'sub_institute_id' => 99, 'first_name' => 'Other', 'last_name' => 'Org', 'mobile' => '9111111111', 'status' => 1],
        ]);
    }

    private function report(): object
    {
        return (object) ['id' => 7, 'sub_institute_id' => self::TENANT, 'title' => 'Fees by class'];
    }

    private function sms(): void
    {
        DB::table('sms_api_details')->insert([
            'sub_institute_id' => self::TENANT, 'url' => 'https://gateway.test/api/send?', 'pram' => 'key=K',
            'mobile_var' => '&to=', 'text_var' => '&message=', 'last_var' => '',
        ]);
    }

    private function whatsapp(): void
    {
        DB::table('g2g_integration_credentials')->insert([
            'sub_institute_id' => self::TENANT, 'provider_key' => 'whatsapp',
            'config' => Crypt::encryptString(json_encode(['access_token' => 'TOKEN', 'phone_number_id' => '555000111'])),
        ]);
    }

    private function send(string $channel, array $ids = [2], ?string $key = 'k1'): array
    {
        return app(ReportMobileSender::class)->send($this->report(), 1, $ids, $channel, 'please read', $key, 'https://g2g.test/module/crm/reports');
    }

    public function test_sms_goes_through_the_organisations_gateway_to_the_number_on_the_persons_record(): void
    {
        $this->sms();
        Http::fake(['gateway.test/*' => Http::response('Message GID=1', 200)]);

        $out = $this->send('sms');

        $this->assertSame(1, $out['sent']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'to=9876543210') && str_contains(urldecode($r->url()), 'Fees by class'));
    }

    public function test_both_attempts_each_channel_on_its_own(): void
    {
        $this->sms();
        $this->whatsapp();
        Http::fake([
            'gateway.test/*' => Http::response('ERR: low balance', 200),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200),
        ]);

        $out = $this->send('both');

        $byChannel = collect($out['results'])->keyBy('channel');
        $this->assertSame('failed', $byChannel['sms']['status'], 'a refused SMS is reported as failed');
        $this->assertStringContainsString('low balance', $byChannel['sms']['error']);
        $this->assertSame('sent', $byChannel['whatsapp']['status'], 'WhatsApp still went out');
        $this->assertSame('wamid.X', $byChannel['whatsapp']['message_id']);
        $this->assertSame([1, 1], [$out['sent'], $out['failed']]);
        Http::assertSent(fn ($r) => str_contains($r->url(), '555000111/messages') && $r['to'] === '919876543210');
    }

    public function test_without_a_whatsapp_account_nothing_is_sent_and_a_link_is_prepared_not_claimed_as_sent(): void
    {
        $this->sms();
        Http::fake(['gateway.test/*' => Http::response('OK', 200)]);

        $out = $this->send('both');

        $this->assertSame(1, $out['sent']);
        $this->assertSame(1, $out['prepared']);
        $wa = collect($out['results'])->firstWhere('channel', 'whatsapp');
        $this->assertSame('prepared', $wa['status']);
        $this->assertStringStartsWith('https://wa.me/919876543210?text=', $wa['link']);
        Http::assertSentCount(1);
    }

    public function test_with_no_sms_gateway_the_sms_leg_fails_with_the_reason_and_whatsapp_is_unaffected(): void
    {
        $this->whatsapp();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.Y']]], 200)]);

        $out = $this->send('both');

        $sms = collect($out['results'])->firstWhere('channel', 'sms');
        $this->assertSame('failed', $sms['status']);
        $this->assertStringContainsString('No SMS gateway', $sms['error']);
        $this->assertSame(1, $out['sent']);
    }

    public function test_a_second_click_does_not_send_twice_but_a_failure_can_be_retried(): void
    {
        $this->sms();
        Http::fake(['gateway.test/*' => Http::sequence()->push('OK', 200)->push('ERR: bad', 200)->push('OK', 200)]);

        $this->assertSame(1, $this->send('sms')['sent']);
        $again = $this->send('sms');
        $this->assertSame(1, $again['duplicate']);
        $this->assertSame(0, $again['sent']);
        Http::assertSentCount(1);

        $this->assertSame(1, $this->send('sms', [2], 'other-key')['failed']);
        $this->assertSame(1, $this->send('sms', [2], 'other-key')['sent'], 'a failed attempt releases its lock');
    }

    public function test_a_person_without_a_number_or_from_another_organisation_blocks_the_whole_send(): void
    {
        $this->sms();
        Http::fake();

        foreach ([[3], [9], [2, 3]] as $ids) {
            try {
                $this->send('sms', $ids);
                $this->fail('Sent despite an unusable recipient.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Cannot send', $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_the_picker_lists_only_this_organisation_and_never_returns_a_number(): void
    {
        $people = app(ReportMobileSender::class)->recipients(self::TENANT, null);

        $this->assertSame([1, 2, 3], array_column($people, 'id'));
        $this->assertNotContains(9, array_column($people, 'id'));
        $this->assertStringNotContainsString('9876543210', json_encode($people));
        $this->assertFalse(collect($people)->firstWhere('id', 3)['has_mobile']);
        $this->assertSame('••••3210', collect($people)->firstWhere('id', 2)['mobile_hint']);
    }
}
