<?php

namespace App\Domain\Signals\AiSignals;

use App\Domain\Signals\Ingestion\DataConnector;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

/**
 * Accepts one delivery for one connector and stores it, exactly as received, ready to be imported. It does NOT import and it never calls a model.
 *
 * The organisation is the connector's own. A repeat of a delivery already received (same content, whatever its whitespace or key order) is recognised
 * and stored once. Nothing about the payload can name a different organisation or connector: the only provider claim it may make is checked against the
 * connector's own and refused when it differs.
 */
final class AiDeliveryReceiver
{
    public static function tableReady(): bool
    {
        try {
            return Schema::hasTable('g2g_ai_deliveries');
        } catch (\Throwable) {
            return false;
        }
    }

    public static function enabled(): bool
    {
        return (bool) config('signals_connectors.ai_delivery.enabled', true);
    }

    /**
     * @return array{ok: bool, code: string, message: string, delivery: ?AiDelivery, duplicate: bool, records: int}
     *   code: stored | duplicate | empty | too_large | too_many_records | provider_mismatch | not_ready
     */
    public function receive(DataConnector $connector, string $raw, string $via = 'webhook'): array
    {
        $fail = fn (string $code, string $message) => ['ok' => false, 'code' => $code, 'message' => $message, 'delivery' => null, 'duplicate' => false, 'records' => 0];

        if (! self::tableReady()) {
            return $fail('not_ready', 'AI signal delivery is not set up on this server yet.');
        }
        $provider = AiSignalProviders::keyFromType($connector->type);
        if ($provider === null) {
            return $fail('not_ready', 'This connector is not an AI signal source.');
        }
        if (trim($raw) === '') {
            return $fail('empty', 'The delivery body is empty.');
        }
        $maxBytes = max(1, (int) config('signals_connectors.ai_delivery.max_kb', 2048)) * 1024;
        if (strlen($raw) > $maxBytes) {
            return $fail('too_large', 'The delivery is larger than ' . intdiv($maxBytes, 1024) . ' KB. Send fewer signals per delivery.');
        }

        $parsed = AiSignalNormalizer::parse($raw);
        if ($parsed['ok']) {
            $claimed = AiSignalProviders::normaliseName($parsed['meta']['provider']);
            if ($claimed !== null && $claimed !== $provider) {
                return $fail('provider_mismatch', 'This delivery says it is from ' . AiSignalProviders::label($claimed) . ' but it was sent with the ' . AiSignalProviders::label($provider) . ' connector\'s token.');
            }
            $max = max(1, (int) config('signals_connectors.ai_delivery.max_records', 200));
            if (count($parsed['records']) > $max) {
                return $fail('too_many_records', "A delivery may hold at most {$max} signals. Split it into several deliveries.");
            }
        }

        $key = $parsed['canonical_hash'] ?? hash('sha256', trim($raw));
        $existing = AiDelivery::where('sub_institute_id', $connector->sub_institute_id)->where('connector_id', $connector->id)->where('idempotency_key', $key)->first();
        if ($existing) {
            return ['ok' => true, 'code' => 'duplicate', 'message' => 'This exact delivery was already received.', 'delivery' => $existing, 'duplicate' => true, 'records' => (int) $existing->records_total];
        }

        $attributes = [
            'sub_institute_id' => $connector->sub_institute_id, 'connector_id' => $connector->id, 'provider' => $provider,
            'idempotency_key' => $key, 'delivery_ref' => $parsed['meta']['delivery_ref'], 'via' => $via === 'manual' ? 'manual' : 'webhook',
            'payload' => $raw, 'received_at' => now(), 'records_total' => count($parsed['records']),
        ];
        // A body that is not usable is still recorded (so the failure is visible in the connector) but never retried: sending the same text again changes nothing.
        $attributes += $parsed['ok']
            ? ['status' => 'received']
            : ['status' => 'failed', 'attempts' => AiDelivery::MAX_ATTEMPTS, 'error_message' => (string) $parsed['error']];

        try {
            $delivery = AiDelivery::create($attributes);
        } catch (UniqueConstraintViolationException) {
            $winner = AiDelivery::where('sub_institute_id', $connector->sub_institute_id)->where('connector_id', $connector->id)->where('idempotency_key', $key)->first();

            return ['ok' => true, 'code' => 'duplicate', 'message' => 'This exact delivery was already received.', 'delivery' => $winner, 'duplicate' => true, 'records' => (int) ($winner?->records_total ?? 0)];
        }

        if (! $parsed['ok']) {
            return ['ok' => false, 'code' => 'invalid', 'message' => (string) $parsed['error'], 'delivery' => $delivery, 'duplicate' => false, 'records' => 0];
        }

        return ['ok' => true, 'code' => 'stored', 'message' => 'Delivery received.', 'delivery' => $delivery, 'duplicate' => false, 'records' => count($parsed['records'])];
    }
}
