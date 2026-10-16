<?php

namespace App\Services\Platform\Integrations;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks a WhatsApp Cloud API token and phone number id against Meta (a read of the phone number's
 * profile). It never sends a message, so testing the connection costs nothing and reaches nobody.
 */
class WhatsappIntegrationTester implements IntegrationTester
{
    public function test(array $config): array
    {
        $token = trim((string) ($config['access_token'] ?? ''));
        $phone = trim((string) ($config['phone_number_id'] ?? ''));
        $version = trim((string) ($config['api_version'] ?? '')) ?: 'v25.0';

        if ($token === '' || ! preg_match('/^\d+$/', $phone) || ! preg_match('/^v\d+\.\d+$/', $version)) {
            return ['ok' => false, 'message' => 'An access token and a numeric phone number id are required.'];
        }

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(10)
                ->get("https://graph.facebook.com/{$version}/{$phone}", ['fields' => 'display_phone_number,verified_name']);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach Meta: ' . $e->getMessage()];
        }

        $body = $response->json() ?? [];

        if ($response->successful() && isset($body['id'])) {
            return ['ok' => true, 'message' => 'Connected to WhatsApp number ' . ($body['display_phone_number'] ?? $phone)
                . (isset($body['verified_name']) ? ' (' . $body['verified_name'] . ')' : '') . '. No message was sent.'];
        }

        return ['ok' => false, 'message' => 'Meta refused the credentials: ' . ($body['error']['message'] ?? ('HTTP ' . $response->status()))];
    }
}
