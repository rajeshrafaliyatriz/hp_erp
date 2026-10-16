<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * An organisation's own WhatsApp Cloud API (Meta Graph) account, read from the credential vault
 * (`g2g_integration_credentials`, provider_key = 'whatsapp').
 *
 * The template choice, parameter splitting and language fallback are a port of LMS K12's
 * `WhatsappController::sendWhatsappCloudApi` (same templates `parents_` / `p2` / `p3`, same
 * `en_US` -> `en` -> `en_GB` fallback on error 132001), so a template approved for that flow works
 * here unchanged - but each organisation must have those templates approved in ITS OWN WhatsApp
 * Business account. Credentials are never shared between organisations or applications.
 *
 * "sent" means Meta ACCEPTED the message (it returned a message id). Delivery to the handset is
 * reported later by Meta's status webhooks; this class does not claim it.
 */
class TenantWhatsappCloud
{
    private const DEFAULT_VERSION = 'v25.0';

    private const LANGUAGES = ['en_US', 'en', 'en_GB'];

    /** @return array{token: string, phone_number_id: string, version: string}|null */
    public function resolve(int $tenantId): ?array
    {
        $row = DB::table('g2g_integration_credentials')
            ->where('sub_institute_id', $tenantId)
            ->where('provider_key', 'whatsapp')
            ->first();

        if (! $row || ! $row->config) {
            return null;
        }

        try {
            $config = json_decode(Crypt::decryptString($row->config), true) ?: [];
        } catch (\Throwable $e) {
            return null;
        }

        $token = trim((string) ($config['access_token'] ?? ''));
        $phone = trim((string) ($config['phone_number_id'] ?? ''));
        if ($token === '' || $phone === '') {
            return null;
        }

        $version = trim((string) ($config['api_version'] ?? ''));

        return [
            'token' => $token,
            'phone_number_id' => $phone,
            'version' => preg_match('/^v\d+\.\d+$/', $version) ? $version : self::DEFAULT_VERSION,
        ];
    }

    /**
     * @param  string  $to  Digits with country code (no +).
     * @return array{status: string, id: ?string, error: ?string, http: ?int}  'sent' = accepted by Meta, or 'failed'
     */
    public function send(array $account, string $to, string $message): array
    {
        $url = "https://graph.facebook.com/{$account['version']}/{$account['phone_number_id']}/messages";
        $last = ['status' => 'failed', 'id' => null, 'error' => 'WhatsApp Cloud API request failed', 'http' => null];

        foreach (self::LANGUAGES as $language) {
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $this->templateName($message),
                    'language' => ['code' => $language],
                    'components' => [['type' => 'body', 'parameters' => $this->parameters($message)]],
                ],
            ];

            try {
                $response = Http::withToken($account['token'])->acceptJson()->timeout(15)->post($url, $payload);
            } catch (\Throwable $e) {
                return ['status' => 'failed', 'id' => null, 'error' => mb_substr($e->getMessage(), 0, 300), 'http' => null];
            }

            $body = $response->json() ?? [];

            if ($response->successful() && ! empty($body['messages'][0]['id'])) {
                return ['status' => 'sent', 'id' => (string) $body['messages'][0]['id'], 'error' => null, 'http' => $response->status()];
            }

            $last = [
                'status' => 'failed',
                'id' => null,
                'error' => mb_substr((string) ($body['error']['message'] ?? ('HTTP ' . $response->status())), 0, 300),
                'http' => $response->status(),
            ];

            // Only "template not found in this language" moves on to the next language (as in LMS).
            if (($body['error']['code'] ?? null) !== 132001) {
                break;
            }
        }

        return $last;
    }

    private function normalise(string $message): string
    {
        $message = preg_replace('/<br\s*\/?\s*>/i', "\n", $message) ?? $message;
        $message = html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = str_replace(["\r\n", "\r"], "\n", $message);

        return str_replace("\t", ' ', $message);
    }

    private function clean(string $text): string
    {
        return trim(preg_replace('/\s{2,}/', ' ', $this->normalise($text)) ?? '');
    }

    /** @return array<int, string> */
    private function parts(string $message): array
    {
        $lines = preg_split('/\n+/', $this->normalise($message), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(array_map(fn ($line) => $this->clean($line), $lines), fn ($line) => $line !== ''));
    }

    private function templateName(string $message): string
    {
        $count = min(count($this->parts($message)), 3);

        return $count === 2 ? 'p2' : ($count >= 3 ? 'p3' : 'parents_');
    }

    /** @return array<int, array{type: string, text: string}> */
    private function parameters(string $message): array
    {
        $parts = $this->parts($message);
        if ($parts === []) {
            return [];
        }

        $count = min(count($parts), 3);
        if (count($parts) > $count) {
            $parts[$count - 1] = trim($parts[$count - 1] . ' ' . implode(' ', array_slice($parts, $count)));
            $parts = array_slice($parts, 0, $count);
        }

        return array_map(fn ($part) => ['type' => 'text', 'text' => $part], $parts);
    }
}
