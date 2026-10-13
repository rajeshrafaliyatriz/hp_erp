<?php

namespace Tests\Unit\Gtm;

use App\Domain\Gtm\Outreach\InboundSignature;
use App\Domain\Gtm\Outreach\SendFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OutreachSafetyTest extends TestCase
{
    // ---------------- send failure classification ----------------

    #[DataProvider('failures')]
    public function test_failures_are_classified_by_what_retrying_could_achieve(string $message, string $kind, ?string $code): void
    {
        $r = SendFailure::classify($message);
        $this->assertSame($kind, $r['kind'], $message);
        $this->assertSame($code, $r['code']);
    }

    public static function failures(): array
    {
        return [
            'mailbox does not exist' => ['Expected response code "250" but got code "550", with message "550 5.1.1 The email account that you tried to reach does not exist."', 'permanent', '550'],
            'greylisted' => ['Expected response code "250" but got code "451", with message "451 4.7.1 Greylisted, try again later"', 'transient', '451'],
            'mailbox busy' => ['Expected response code "250" but got code "450", with message "450 4.2.0 Mailbox busy"', 'transient', '450'],
            'bad credentials' => ['Expected response code "235" but got code "535", with message "535 5.7.8 Username and Password not accepted."', 'auth', '535'],
            'cannot connect' => ['Connection could not be established with host "smtp.gmail.com:587": stream_socket_client(): Unable to connect', 'transient', null],
            'timeout' => ['Connection to "smtp.example.com:25" timed out.', 'transient', null],
            'daily limit' => ['Expected response code "250" but got code "550", with message "550 5.4.5 Daily user sending limit exceeded."', 'rate_limited', '550'],
            'too many' => ['421 4.7.0 Too many messages, slow down', 'rate_limited', '421'],
            'unknown text is NOT retried' => ['something strange happened', 'permanent', null],
        ];
    }

    public function test_a_daily_cap_waits_hours_but_a_burst_limit_waits_an_hour(): void
    {
        $this->assertSame(360, SendFailure::classify('550 Daily user sending limit exceeded')['retry_after_minutes']);
        $this->assertSame(60, SendFailure::classify('421 Too many connections, rate limit')['retry_after_minutes']);
    }

    public function test_only_transient_and_rate_limited_failures_ever_get_a_retry_time(): void
    {
        $this->assertNull(SendFailure::classify('Expected response code "250" but got code "550"')['retry_after_minutes']);
        $this->assertNull(SendFailure::classify('Expected response code "235" but got code "535"')['retry_after_minutes']);
    }

    public function test_backoff_is_bounded_so_a_message_cannot_retry_forever(): void
    {
        $this->assertSame(5, SendFailure::backoff(1));
        $this->assertSame(30, SendFailure::backoff(2));
        $this->assertSame(120, SendFailure::backoff(3));
        $this->assertNull(SendFailure::backoff(4), 'the fourth failure is final');
        $this->assertNull(SendFailure::backoff(10));
    }

    // ---------------- inbound webhook signature ----------------

    private const SECRET = 'a-shared-secret-for-tests';

    public function test_a_correctly_signed_body_is_accepted(): void
    {
        $body = '{"type":"reply"}';
        $this->assertSame(['ok' => true, 'reason' => null], InboundSignature::verify($body, InboundSignature::sign($body, self::SECRET), self::SECRET));
    }

    public function test_nothing_is_accepted_without_a_configured_secret(): void
    {
        $body = '{}';
        $this->assertSame('not_configured', InboundSignature::verify($body, InboundSignature::sign($body, ''), '')['reason']);
    }

    public function test_a_tampered_body_is_rejected(): void
    {
        $sig = InboundSignature::sign('{"type":"reply"}', self::SECRET);
        $this->assertSame('bad_signature', InboundSignature::verify('{"type":"bounce"}', $sig, self::SECRET)['reason']);
    }

    public function test_a_wrong_secret_is_rejected(): void
    {
        $body = '{}';
        $this->assertSame('bad_signature', InboundSignature::verify($body, InboundSignature::sign($body, 'another-secret'), self::SECRET)['reason']);
    }

    public function test_a_replayed_request_outside_the_window_is_rejected(): void
    {
        $body = '{}';
        $old = InboundSignature::sign($body, self::SECRET, 1_000_000);
        $this->assertSame('stale_timestamp', InboundSignature::verify($body, $old, self::SECRET, 1_000_000 + 301)['reason']);
        $this->assertTrue(InboundSignature::verify($body, $old, self::SECRET, 1_000_000 + 299)['ok'], 'inside the window it is fine');
        $future = InboundSignature::sign($body, self::SECRET, 2_000_000);
        $this->assertSame('stale_timestamp', InboundSignature::verify($body, $future, self::SECRET, 1_000_000)['reason'], 'a timestamp far in the future is also refused');
    }

    public function test_missing_and_malformed_headers_are_rejected(): void
    {
        $this->assertSame('missing_signature', InboundSignature::verify('{}', null, self::SECRET)['reason']);
        $this->assertSame('missing_signature', InboundSignature::verify('{}', '   ', self::SECRET)['reason']);
        $this->assertSame('malformed_signature', InboundSignature::verify('{}', 'garbage', self::SECRET)['reason']);
        $this->assertSame('malformed_signature', InboundSignature::verify('{}', 't=abc,v1=deadbeef', self::SECRET)['reason']);
        $this->assertSame('malformed_signature', InboundSignature::verify('{}', 't=123', self::SECRET, 123)['reason']);
    }

    public function test_a_rotated_secret_works_when_the_old_and_new_signatures_are_both_sent(): void
    {
        $body = '{"x":1}';
        $t = 1_700_000_000;
        $old = hash_hmac('sha256', $t.'.'.$body, 'old-secret');
        $new = hash_hmac('sha256', $t.'.'.$body, self::SECRET);
        $this->assertTrue(InboundSignature::verify($body, "t={$t},v1={$old},v1={$new}", self::SECRET, $t)['ok']);
    }
}
