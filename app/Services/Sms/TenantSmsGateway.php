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

            return ['sent', null];
        } catch (\Throwable $e) {
            return ['failed', mb_substr($e->getMessage(), 0, 500)];
        }
    }
}
