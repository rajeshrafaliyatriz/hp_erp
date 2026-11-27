<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Signals\AiSignals\AiDelivery;
use App\Domain\Signals\AiSignals\AiDeliveryReceiver;
use App\Domain\Signals\AiSignals\AiDeliveryToken;
use App\Domain\Signals\AiSignals\AiSignalProviders;
use App\Domain\Signals\AiSignals\AiSyncStarter;
use App\Domain\Signals\Ingestion\DataConnector;
use App\Domain\Signals\Ingestion\DataConnectorSync;
use App\Domain\Signals\Products\ProductCatalog;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The four AI signal sources (Claude, ChatGPT / GPT, DeepSeek, Gemini) inside Signals > Ingestion Engine > Connectors.
 *
 * Each is an ordinary row of g2g_data_connectors (type `ai_<key>`), scoped to the caller's organisation. These endpoints set one up, show its
 * delivery address and status, rotate its secret, switch it on and off, and run Sync / Sync All: a sync IMPORTS what the scheduled task has delivered.
 * Nothing here fetches from a platform, scrapes, or calls a model. The secret is shown once, when created or rotated, and is never stored in a form
 * that can be read back.
 */
class AiSignalConnectorController extends Controller
{
    use ResolvesApiIdentity;

    public function __construct(private readonly AiSyncStarter $starter)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = (int) $identity['sub_institute_id'];

        return response()->json(['status' => 1] + $this->overview($tenantId));
    }

    /** Sets a source up (creating its connector and secret the first time). The secret is in the answer ONCE. */
    public function enable(Request $request, string $provider): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        if (! in_array($provider, AiSignalProviders::keys(), true)) {
            return response()->json(['status' => 0, 'message' => 'Unknown AI source.'], 404);
        }
        if (! $this->ready()) {
            return response()->json(['status' => 0, 'message' => 'AI signal delivery is not set up on this server yet. Run the pending migration first.'], 503);
        }
        $tenantId = (int) $identity['sub_institute_id'];

        $connector = $this->connector($tenantId, $provider);
        $secret = null;
        if (! $connector) {
            $product = trim((string) $request->input('product_key', ''));
            $known = array_keys(ProductCatalog::definitionsFor($tenantId));
            $connector = DataConnector::create([
                'sub_institute_id' => $tenantId, 'type' => AiSignalProviders::typeFor($provider), 'name' => AiSignalProviders::label($provider) . ' signals',
                'product_key' => in_array($product, $known, true) ? $product : ($known[0] ?? 'ai_feed'),
                'connection_status' => 'connected', 'connected_at' => now(), 'sync_enabled' => true, 'created_by' => $identity['user_id'] ?? null,
            ]);
        }
        $credentials = is_array($connector->credentials) ? $connector->credentials : [];
        if (empty($credentials['delivery_token_hash'])) {
            $secret = $this->issueToken($connector);
        }
        if (! $connector->sync_enabled || $connector->connection_status === 'disconnected') {
            $connector->forceFill(['sync_enabled' => true, 'connection_status' => 'connected', 'disconnected_at' => null, 'last_error' => null])->save();
        }

        return response()->json(['status' => 1, 'secret' => $secret, 'data' => $this->slot($tenantId, $provider)], $secret !== null ? 201 : 200);
    }

    /** A new secret replaces the old one at once; the old one stops working. Shown once. */
    public function rotate(Request $request, string $provider): JsonResponse
    {
        [$identity, $connector, $error] = $this->resolve($request, $provider);
        if ($error) {
            return $error;
        }
        $secret = $this->issueToken($connector);

        return response()->json(['status' => 1, 'secret' => $secret, 'data' => $this->slot((int) $identity['sub_institute_id'], $provider)]);
    }

    public function toggle(Request $request, string $provider): JsonResponse
    {
        [$identity, $connector, $error] = $this->resolve($request, $provider);
        if ($error) {
            return $error;
        }
        $on = filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN);
        $connector->forceFill($on
            ? ['sync_enabled' => true, 'connection_status' => 'connected', 'disconnected_at' => null, 'last_error' => null]
            : ['sync_enabled' => false, 'connection_status' => 'disconnected', 'disconnected_at' => now()])->save();

        return response()->json(['status' => 1, 'data' => $this->slot((int) $identity['sub_institute_id'], $provider)]);
    }

    public function sync(Request $request, string $provider): JsonResponse
    {
        [$identity, $connector, $error] = $this->resolve($request, $provider);
        if ($error) {
            return $error;
        }
        $r = $this->starter->start($connector, $identity['user_id'] ?? null);
        $slot = $this->slot((int) $identity['sub_institute_id'], $provider);
        $message = match ($r['code']) {
            'started' => 'Import started. It shows "queued" until a Signals queue worker picks it up.',
            'already_running' => 'An import is already running for this source.',
            'disabled' => 'Switch this source on before syncing.',
            default => 'The import could not be queued. Please retry.',
        };

        return response()->json(['status' => in_array($r['code'], ['started'], true) ? 1 : 0, 'message' => $message, 'data' => $slot], ['started' => 202, 'already_running' => 409, 'disabled' => 409][$r['code']] ?? 500);
    }

    /** Sync every AI source that is switched on. One that is already running is reported, not an error for the others. */
    public function syncAll(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        if (! $this->ready()) {
            return response()->json(['status' => 0, 'message' => 'AI signal delivery is not set up on this server yet.'], 503);
        }
        $tenantId = (int) $identity['sub_institute_id'];
        $results = [];
        foreach (AiSignalProviders::keys() as $key) {
            $c = $this->connector($tenantId, $key);
            if (! $c || ! $c->sync_enabled || $c->connection_status === 'disconnected') {
                continue;
            }
            $results[$key] = $this->starter->start($c, $identity['user_id'] ?? null)['code'];
        }
        if ($results === []) {
            return response()->json(['status' => 0, 'message' => 'No AI source is switched on yet. Set one up first.'], 409);
        }
        $started = count(array_filter($results, fn ($c) => $c === 'started'));

        return response()->json(['status' => 1, 'message' => $started > 0 ? "Import started for {$started} AI source(s)." : 'Every switched-on AI source already has an import running.', 'results' => $results, 'data' => $this->overview($tenantId)], 202);
    }

    public function deliveries(Request $request, string $provider): JsonResponse
    {
        [$identity, $connector, $error] = $this->resolve($request, $provider);
        if ($error) {
            return $error;
        }
        $rows = AiDelivery::where('sub_institute_id', $connector->sub_institute_id)->where('connector_id', $connector->id)->orderByDesc('id')->limit(30)->get();

        return response()->json(['status' => 1, 'data' => $rows->map(fn (AiDelivery $d) => $this->presentDelivery($d))->values()]);
    }

    /** One delivery with what happened to each of its signals (the reasons for any that were rejected). The delivered text itself is never returned. */
    public function delivery(Request $request, string $provider, int $deliveryId): JsonResponse
    {
        [$identity, $connector, $error] = $this->resolve($request, $provider);
        if ($error) {
            return $error;
        }
        $d = AiDelivery::where('sub_institute_id', $connector->sub_institute_id)->where('connector_id', $connector->id)->whereKey($deliveryId)->first();
        if (! $d) {
            return response()->json(['status' => 0, 'message' => 'Delivery not found.'], 404);
        }

        return response()->json(['status' => 1, 'data' => $this->presentDelivery($d) + ['results' => json_decode((string) $d->results, true) ?: []]]);
    }

    /** A person pastes the output of a task that cannot deliver by itself (or re-sends one): the same storage, the same import. */
    public function submit(Request $request, string $provider, \App\Domain\Signals\AiSignals\AiDeliveryReceiver $receiver): JsonResponse
    {
        [$identity, $connector, $error] = $this->resolve($request, $provider);
        if ($error) {
            return $error;
        }
        $body = $request->input('payload');
        $raw = is_array($body) ? (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $body;
        $result = $receiver->receive($connector, $raw, 'manual');
        $http = ['not_ready' => 503, 'empty' => 422, 'invalid' => 422, 'too_many_records' => 422, 'provider_mismatch' => 422, 'too_large' => 413];
        if (! $result['ok']) {
            return response()->json(['status' => 0, 'code' => $result['code'], 'message' => $result['message']], $http[$result['code']] ?? 422);
        }
        if ($result['duplicate']) {
            return response()->json(['status' => 1, 'message' => 'This exact output was already received. Nothing new to import.', 'data' => $this->slot((int) $identity['sub_institute_id'], $provider)]);
        }
        $sync = $this->starter->start($connector, $identity['user_id'] ?? null);

        return response()->json(['status' => 1, 'message' => $sync['code'] === 'started' ? 'Received. The import has started.' : 'Received. It will be imported by the sync that is already running.', 'data' => $this->slot((int) $identity['sub_institute_id'], $provider)], 202);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function ready(): bool
    {
        try {
            return Schema::hasTable('g2g_data_connectors') && AiDeliveryReceiver::tableReady();
        } catch (\Throwable) {
            return false;
        }
    }

    private function connector(int $tenantId, string $provider): ?DataConnector
    {
        return DataConnector::where('sub_institute_id', $tenantId)->where('type', AiSignalProviders::typeFor($provider))->orderBy('id')->first();
    }

    /** @return array{0: ?array, 1: ?DataConnector, 2: ?JsonResponse} */
    private function resolve(Request $request, string $provider): array
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return [null, null, $identity];
        }
        if (! in_array($provider, AiSignalProviders::keys(), true)) {
            return [$identity, null, response()->json(['status' => 0, 'message' => 'Unknown AI source.'], 404)];
        }
        if (! $this->ready()) {
            return [$identity, null, response()->json(['status' => 0, 'message' => 'AI signal delivery is not set up on this server yet. Run the pending migration first.'], 503)];
        }
        $connector = $this->connector((int) $identity['sub_institute_id'], $provider);
        if (! $connector) {
            return [$identity, null, response()->json(['status' => 0, 'message' => 'Set this AI source up first.'], 404)];
        }

        return [$identity, $connector, null];
    }

    /** Creates a new secret, stores only its hash, and returns the secret itself (the only time it exists in readable form). */
    private function issueToken(DataConnector $connector): string
    {
        $t = AiDeliveryToken::generate((int) $connector->id);
        $credentials = is_array($connector->credentials) ? $connector->credentials : [];
        $credentials['delivery_token_hash'] = $t['hash'];
        $credentials['delivery_token_hint'] = $t['hint'];
        $credentials['delivery_token_at'] = now()->toIso8601String();
        $connector->forceFill(['credentials' => $credentials])->save();

        return $t['token'];
    }

    /** @return array<string, mixed> */
    private function overview(int $tenantId): array
    {
        if (! $this->ready()) {
            return ['tables_migrated' => false, 'notice' => 'AI signal delivery is not set up on this server yet. Run the pending migration first.', 'delivery_url' => $this->deliveryUrl(), 'data' => array_map(fn ($k) => $this->emptySlot($k), AiSignalProviders::keys())];
        }

        return ['tables_migrated' => true, 'delivery_url' => $this->deliveryUrl(), 'data' => array_map(fn ($k) => $this->slot($tenantId, $k), AiSignalProviders::keys())];
    }

    private function deliveryUrl(): string
    {
        return url('/api/signals/ai-delivery');
    }

    private function emptySlot(string $key): array
    {
        return ['provider' => $key, 'label' => AiSignalProviders::label($key), 'connector_id' => null, 'configured' => false, 'enabled' => false, 'state' => 'not_configured', 'state_label' => 'Not set up',
            'delivery_url' => $this->deliveryUrl(), 'token_hint' => null, 'last_received_at' => null, 'last_sync_at' => null, 'last_sync_status' => null, 'last_sync_message' => null,
            'worker_hint' => null, 'totals' => ['deliveries' => 0, 'imported' => 0, 'updated' => 0, 'duplicate' => 0, 'failed' => 0, 'failed_deliveries' => 0, 'waiting' => 0], 'recent' => []];
    }

    /** @return array<string, mixed> */
    private function slot(int $tenantId, string $key): array
    {
        $c = $this->connector($tenantId, $key);
        if (! $c) {
            return $this->emptySlot($key);
        }
        $t = AiDelivery::where('sub_institute_id', $tenantId)->where('connector_id', $c->id)->selectRaw(
            "count(*) as deliveries, max(received_at) as last_received, coalesce(sum(records_accepted),0) as accepted, coalesce(sum(records_updated),0) as updated, "
            . "coalesce(sum(records_duplicate),0) as duplicate, coalesce(sum(records_rejected),0) as rejected, "
            . "coalesce(sum(case when status = 'failed' then 1 else 0 end),0) as failed_deliveries, coalesce(sum(case when status in ('received', 'importing') then 1 else 0 end),0) as waiting"
        )->first();
        $sync = DataConnectorSync::where('sub_institute_id', $tenantId)->where('connector_id', $c->id)->orderByDesc('id')->first();
        $recent = AiDelivery::where('sub_institute_id', $tenantId)->where('connector_id', $c->id)->orderByDesc('id')->limit(5)->get();
        // An import that has waited a minute without a worker taking it is not "importing": say what is missing instead of spinning for ever.
        $waited = $sync && $sync->status === 'queued' && $sync->created_at ? max(0, now()->timestamp - $sync->created_at->timestamp) : 0;
        $workerHint = $waited >= 60
            ? 'This import has been waiting ' . max(1, intdiv($waited, 60)) . ' minute(s) and no Signals queue worker has picked it up. A worker must be running on the server (php artisan queue:work --queue=signals,default). Ask an administrator to check it.'
            : null;
        $enabled = $c->sync_enabled && $c->connection_status !== 'disconnected';
        $running = $sync && in_array($sync->status, ['queued', 'running'], true);
        $credentials = is_array($c->credentials) ? $c->credentials : [];
        $hasToken = ! empty($credentials['delivery_token_hash']);

        [$state, $label] = match (true) {
            ! $enabled => ['off', 'Switched off'],
            ! $hasToken => ['no_secret', 'No secret yet'],
            $running => ['syncing', 'Importing'],
            $sync && $sync->status === 'failed' => ['error', 'Last import failed'],
            (int) $t->deliveries === 0 => ['waiting', 'Waiting for the first delivery'],
            (int) $t->waiting > 0 => ['pending', 'Delivery waiting to import'],
            default => ['ok', 'Receiving'],
        };

        return [
            'provider' => $key, 'label' => AiSignalProviders::label($key), 'connector_id' => (int) $c->id, 'configured' => $hasToken, 'enabled' => $enabled,
            'state' => $state, 'state_label' => $label, 'delivery_url' => $this->deliveryUrl(), 'token_hint' => $credentials['delivery_token_hint'] ?? null,
            'token_created_at' => $credentials['delivery_token_at'] ?? null,
            'last_received_at' => $t->last_received ? \Illuminate\Support\Carbon::parse($t->last_received)->toIso8601String() : null,
            'last_sync_at' => $c->last_sync_at?->toIso8601String(), 'last_successful_sync_at' => $c->last_successful_sync_at?->toIso8601String(),
            'last_sync_status' => $sync?->status, 'last_sync_message' => $sync?->error_message,
            'worker_hint' => $workerHint,
            'totals' => ['deliveries' => (int) $t->deliveries, 'imported' => (int) $t->accepted, 'updated' => (int) $t->updated, 'duplicate' => (int) $t->duplicate,
                'failed' => (int) $t->rejected, 'failed_deliveries' => (int) $t->failed_deliveries, 'waiting' => (int) $t->waiting],
            'recent' => $recent->map(fn (AiDelivery $d) => $this->presentDelivery($d))->values()->all(),
        ];
    }

    /**
     * One delivery as the screen shows it: what it held and what became of each part, with the reason (and how to fix it) for every signal that was rejected.
     * Never the delivered text.
     *
     * @return array<string, mixed>
     */
    private function presentDelivery(AiDelivery $d): array
    {
        $items = json_decode((string) $d->results, true);
        $items = is_array($items) ? $items : [];
        $rejected = array_values(array_filter($items, fn ($x) => ($x['outcome'] ?? null) === 'rejected'));
        $lookAlikes = array_values(array_filter($items, fn ($x) => ! empty($x['possible_duplicate'])));
        $open = in_array($d->status, ['received', 'importing'], true);

        return [
            'id' => (int) $d->id, 'status' => $d->status, 'via' => $d->via, 'delivery_ref' => $d->delivery_ref,
            'records' => (int) $d->records_total, 'imported' => (int) $d->records_accepted, 'updated' => (int) $d->records_updated,
            'duplicate' => (int) $d->records_duplicate, 'failed' => (int) $d->records_rejected,
            // Not yet imported: every signal of a delivery that is still waiting (or being imported).
            'pending' => $open ? (int) $d->records_total : 0,
            'message' => $d->error_message,
            'rejections' => array_map(fn ($x) => [
                'index' => $x['index'] ?? null, 'label' => $x['label'] ?? null, 'reason_code' => $x['reason_code'] ?? null,
                'reason' => $x['reason'] ?? null, 'hint' => $x['hint'] ?? null,
            ], array_slice($rejected, 0, 20)),
            'rejections_more' => max(0, count($rejected) - 20),
            // Look-alike organisations that were NOT merged: a person decides whether they are the same.
            'look_alikes' => array_map(fn ($x) => ['index' => $x['index'] ?? null, 'label' => $x['label'] ?? null] + (array) $x['possible_duplicate'], array_slice($lookAlikes, 0, 10)),
            'received_at' => $d->received_at?->toIso8601String(), 'imported_at' => $d->imported_at?->toIso8601String(),
        ];
    }
}
