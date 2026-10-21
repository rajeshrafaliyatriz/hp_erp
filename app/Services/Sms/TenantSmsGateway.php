<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The organisation's own SMS gateway: credentials vault first (`g2g_integration_credentials`,
 * provider_key = 'sms'), then the legacy `sms_api_details` row. Extracted unchanged from
 * CrmSmsController so the CRM SMS notifier and the AI report send share one mechanism.
 *
 * "sent" means the gateway accepted the request, not that a carrier delivered it.
 */
class TenantSmsGateway
{
    /** @return array{url: string, pram: string, mobileVar: string, textVar: string, lastVar: string}|null */
    public function resolve(int $tenantId): ?array
    {
        $vaultRow = DB::table('g2g_integration_credentials')
            ->where('sub_institute_id', $tenantId)
            ->where('provider_key', 'sms')
            ->first();

        if ($vaultRow && $vaultRow->config) {
            try {
                $config = json_decode(Crypt::decryptString($vaultRow->config), true) ?: [];
            } catch (\Throwable $e) {
                $config = [];
            }

            if (! empty($config['url']) && ! empty($config['mobile_var']) && ! empty($config['text_var'])) {
                return [
                    'url' => $config['url'], 'pram' => $config['pram'] ?? '',
                    'mobileVar' => $config['mobile_var'], 'textVar' => $config['text_var'],
                    'lastVar' => $config['last_var'] ?? '',
                ];
            }
        }

        $legacy = DB::table('sms_api_details')->where('sub_institute_id', $tenantId)->first();

        if ($legacy && ! empty($legacy->url)) {
            return [
                'url' => $legacy->url, 'pram' => $legacy->pram ?? '',
                'mobileVar' => $legacy->mobile_var, 'textVar' => $legacy->text_var,
                'lastVar' => $legacy->last_var ?? '',
            ];
        }

        return null;
    }

    /**
     * The gateway's own reason when its reply body says the message was refused, else null.
     *
     * Vendors differ and none is documented here, so this only recognises the unmistakable: a JSON reply whose
     * status/success/type says failure, or plain text that opens with (or is built around) an error word. A reply it
     * does not recognise is treated as accepted - the same as before - and carries no claim of carrier delivery.
     */
    public static function refusal(string $body): ?string
    {
        $text = trim($body);

        if ($text === '') {
            return null;
        }

        $json = json_decode($text, true);

        if (is_array($json)) {
            foreach (['status', 'type', 'result', 'state'] as $key) {
                if (isset($json[$key]) && is_string($json[$key]) && preg_match('/^(error|fail|failed|failure|rejected|invalid)$/i', $json[$key])) {
                    return 'Gateway refused the message: ' . mb_substr((string) ($json['message'] ?? $json['msg'] ?? $json['error'] ?? $json[$key]), 0, 300);
                }
            }

            if (($json['success'] ?? null) === false || ($json['error'] ?? false) === true) {
                return 'Gateway refused the message: ' . mb_substr((string) ($json['message'] ?? $json['msg'] ?? 'unspecified'), 0, 300);
            }

            return null;
        }

        $plain = trim(strip_tags($text));

        if (preg_match('/^(err(or)?\b|fail(ed|ure)?\b|invalid\b|rejected\b|authentication|unauthori[sz]ed|insufficient|low balance|not subscribed|account (is )?(blocked|inactive|expired))/i', $plain)) {
            return 'Gateway refused the message: ' . mb_substr(preg_replace('/\s+/', ' ', $plain) ?? $plain, 0, 300);
        }

        return null;
    }

    /** @return array{0: string, 1: ?string} [status 'sent'|'failed', error reason] */
    public function dispatch(array $gateway, string $toNumber, string $message): array
    {
        $url = $gateway['url'] . $gateway['pram'] . $gateway['mobileVar'] . $toNumber
            . $gateway['textVar'] . urlencode($message) . urlencode($gateway['lastVar']);

        try {
            $response = Http::timeout(10)->withOptions(['verify' => false])->get($url);

            if (! $response->successful()) {
                return ['failed', 'Gateway returned HTTP ' . $response->status() . '.'];
            }

            // Many SMS gateways answer HTTP 200 with the failure in the body (bad key, unregistered template,
            // no credit). "sent" is only claimed when the body does not say otherwise.
            $refusal = self::refusal($response->body());

            return $refusal === null ? ['sent', null] : ['failed', $refusal];
        } catch (\Throwable $e) {
            return ['failed', mb_substr($e->getMessage(), 0, 500)];
        }
    }
}
