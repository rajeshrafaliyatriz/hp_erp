<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Meta's WhatsApp Cloud API tells us what happened to a message AFTER it accepted it (delivered, read, failed) by
 * calling a webhook. This is that webhook's logic: it turns those reports into `ai_report_deliveries.delivery_status`.
 *
 * NOTHING IS TRUSTED UNTIL IT IS PROVEN TO COME FROM META
 *
 *   - Subscription (GET): Meta sends the verify token the organisation typed into the Meta app; it is answered only
 *     if it equals the `webhook_verify_token` saved in some organisation's own WhatsApp credentials.
 *   - Reports (POST): the body must carry a valid `X-Hub-Signature-256` HMAC made with the `app_secret` saved for the
 *     organisation that owns the `phone_number_id` in the payload. No app secret saved, or a bad signature, and the
 *     report is ignored - an unauthenticated caller can never mark a message delivered.
 *   - A status updates only a delivery row of THAT organisation whose `provider_message_id` equals the message id.
 *
 * A message that never gets a report keeps `delivery_status` NULL: "not reported", never "delivered".
 */
class WhatsappWebhook
{
    /** @return string|null The challenge to echo, or null when the token is not recognised. */
    public function verify(?string $mode, ?string $token, ?string $challenge): ?string
    {
        if ($mode !== 'subscribe' || $token === null || $token === '' || $challenge === null) {
            return null;
        }

        foreach ($this->accounts() as $account) {
            $expected = (string) ($account['config']['webhook_verify_token'] ?? '');

            if ($expected !== '' && hash_equals($expected, $token)) {
                return $challenge;
            }
        }

        return null;
    }

    /**
     * @return array{accepted: bool, updated: int, reason: ?string}
     */
    public function handle(string $rawBody, ?string $signature): array
    {
        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return ['accepted' => false, 'updated' => 0, 'reason' => 'The body is not JSON.'];
        }

        $accounts = $this->accounts();
        $updated = 0;
        $rejected = null;

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $phoneId = (string) ($value['metadata']['phone_number_id'] ?? '');
                $account = $accounts[$phoneId] ?? null;

                if ($account === null) {
                    $rejected ??= 'No organisation owns the phone number id in this report.';
                    continue;
                }

                $secret = (string) ($account['config']['app_secret'] ?? '');

                if ($secret === '' || ! $this->signatureValid($rawBody, $signature, $secret)) {
                    $rejected ??= 'The signature is missing or does not match the saved app secret.';
                    continue;
                }

                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $updated += $this->apply((int) $account['tenant'], (array) $status);
                }
            }
        }

        return ['accepted' => $rejected === null, 'updated' => $updated, 'reason' => $rejected];
    }

    /** One Meta status object -> the delivery row that carries that message id. */
    private function apply(int $tenant, array $status): int
    {
        $id = (string) ($status['id'] ?? '');
        $state = strtolower((string) ($status['status'] ?? ''));

        // `sent` only repeats what the send call already told us; only what happened afterwards is recorded.
        if ($id === '' || ! in_array($state, ['delivered', 'read', 'failed'], true)) {
            return 0;
        }

        $error = null;

        if ($state === 'failed') {
            $first = (array) (($status['errors'][0] ?? []));
            $error = TenantWhatsappCloud::explain($first['code'] ?? null, (string) ($first['message'] ?? $first['title'] ?? 'Meta reported the message as failed.'));
        }

        $timestamp = isset($status['timestamp']) && ctype_digit((string) $status['timestamp'])
            ? date('Y-m-d H:i:s', (int) $status['timestamp'])
            : now()->toDateTimeString();

        return DB::table('ai_report_deliveries')
            ->where('sub_institute_id', $tenant)
            ->where('provider_message_id', $id)
            // A later "read" may follow "delivered", but a late "delivered" must not downgrade "read".
            ->when($state === 'delivered', fn ($q) => $q->where(fn ($w) => $w->whereNull('delivery_status')->orWhere('delivery_status', 'delivered')))
            ->update([
                'delivery_status' => $state,
                'delivered_at' => $state === 'failed' ? null : $timestamp,
                'delivery_error' => $error,
                'updated_at' => now(),
            ]);
    }

    private function signatureValid(string $rawBody, ?string $header, string $secret): bool
    {
        if ($header === null || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), substr($header, 7));
    }

    /**
     * Every organisation's WhatsApp credentials, keyed by phone number id.
     *
     * @return array<string, array{tenant: int, config: array<string, mixed>}>
     */
    private function accounts(): array
    {
        $out = [];

        foreach (DB::table('g2g_integration_credentials')->where('provider_key', 'whatsapp')->get(['sub_institute_id', 'config']) as $row) {
            try {
                $config = json_decode(Crypt::decryptString((string) $row->config), true) ?: [];
            } catch (\Throwable) {
                continue;
            }

            $phone = trim((string) ($config['phone_number_id'] ?? ''));

            if ($phone !== '') {
                $out[$phone] = ['tenant' => (int) $row->sub_institute_id, 'config' => $config];
            }
        }

        return $out;
    }
}
