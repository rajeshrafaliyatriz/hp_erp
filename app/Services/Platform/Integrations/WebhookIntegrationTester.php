<?php

namespace App\Services\Platform\Integrations;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A real, signed HTTP POST to a tenant-supplied endpoint.
 *
 * The payload is signed with the stored secret — `X-Signature: sha256=<hmac>` over
 * the exact JSON bytes sent — so the same test doubles as proof the endpoint can
 * verify what this platform will send it later, not just that a socket opened.
 * `withBody()` is used rather than letting the client encode the payload, because
 * the signature has to be computed over the SAME bytes that go on the wire — signing
 * one encoding and sending another would make the endpoint's own verification fail
 * even when everything here is correct.
 */
class WebhookIntegrationTester implements IntegrationTester
{
    private const TIMEOUT_SECONDS = 8;

    public function test(array $config): array
    {
        $url = trim((string) ($config['url'] ?? ''));

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return ['ok' => false, 'message' => 'A valid URL is required.'];
        }

        $payload = json_encode([
            'event' => 'platform.integration.test',
            'sent_at' => now()->toIso8601String(),
        ]);

        $headers = ['Content-Type' => 'application/json'];

        $secret = (string) ($config['secret'] ?? '');

        if ($secret !== '') {
            $headers['X-Signature'] = 'sha256=' . hash_hmac('sha256', (string) $payload, $secret);
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(self::TIMEOUT_SECONDS)
                ->withBody((string) $payload, 'application/json')
                ->post($url);

            if ($response->successful()) {
                return ['ok' => true, 'message' => 'Received a ' . $response->status() . ' response.'];
            }

            return [
                'ok' => false,
                'message' => 'The endpoint responded with ' . $response->status() . '.',
            ];
        } catch (Throwable $exception) {
            // A connection failure, DNS failure or timeout — Laravel's HTTP client
            // wraps these in a ConnectionException whose message already names which.
            return ['ok' => false, 'message' => 'Could not reach the endpoint: ' . $exception->getMessage()];
        }
    }
}
