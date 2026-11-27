<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Signals\Connectors\ConnectorMonitoring;
use App\Domain\Signals\Connectors\DataConnectorRegistry;
use App\Domain\Signals\Connectors\SocialConnectorTokenManager;
use App\Domain\Signals\Ingestion\DataConnector;
use App\Domain\Signals\Ingestion\DataConnectorSync;
use App\Domain\Signals\Ingestion\DataConnectorSyncReaper;
use App\Domain\Signals\Ingestion\IngestionAnalysis;
use App\Domain\Signals\Ingestion\IngestionSource;
use App\Domain\Signals\Ingestion\SyncDecision;
use App\Domain\Signals\Products\ProductCatalog;
use App\Domain\Signals\Products\ProductNotConfigured;
use App\Domain\Signals\Support\RegionInvalid;
use App\Domain\Signals\Support\RegionScope;
use App\Domain\Signals\Support\SafeUrlFetcher;
use App\Domain\Signals\Support\UnsafeUrlException;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use App\Jobs\SyncDataConnectorJob;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class DataConnectorController extends Controller
{
    use ResolvesApiIdentity;

    public function __construct(
        private readonly DataConnectorRegistry $connectors,
        private readonly SafeUrlFetcher $fetcher,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->tablesExist()) {
            return response()->json([
                'status' => 1,
                'tables_migrated' => false,
                'global_schedule_enabled' => (bool) config('signals_connectors.schedule_enabled'),
                'available' => $this->connectors->available($tenantId ?? null),
                'data' => [],
                'notice' => 'Data connector database tables are not migrated yet. Run php artisan migrate to activate this feature.',
            ]);
        }

        try {
            $tenantId = $identity['sub_institute_id'];
            // A sync whose worker died is closed with a reason, never shown as "running" forever (same pattern as research runs).
            DataConnectorSyncReaper::sweepSafely($tenantId);   // housekeeping: a failed sweep is logged and must not break the list
            // AI signal sources (Claude, GPT, DeepSeek, Gemini) are listed by their own endpoint: this list, and its OAuth-style cards, are unchanged.
            $connectors = DataConnector::where('sub_institute_id', $tenantId)->whereNotIn('type', \App\Domain\Signals\AiSignals\AiSignalProviders::types())->orderBy('id')->get();
            $history = DataConnectorSync::where('sub_institute_id', $tenantId)
                ->whereIn('connector_id', $connectors->pluck('id'))
                ->orderByDesc('id')->limit(100)->get()->groupBy('connector_id');

            // Self-healing: a sync still waiting for a worker after a few seconds means the launch from Sync Now did not take. The
            // launcher is guarded (one spawn per few seconds) and a worker that is already running just ignores a duplicate.
            $waiting = $history->flatten(1)->contains(fn (DataConnectorSync $s) => $s->status === 'queued' && $s->updated_at && $s->updated_at->lte(now()->subSeconds(12)));
            if ($waiting && config('queue.default') !== 'sync') {
                \App\Support\QueueWorkerLauncher::start((string) config('signals.queue', 'signals'));
            }

            return response()->json([
                'status' => 1,
                'tables_migrated' => true,
                'global_schedule_enabled' => (bool) config('signals_connectors.schedule_enabled'),
                'available' => $this->connectors->available($tenantId),
                // Whether this caller may open Connector Settings (administrators only). The settings themselves are served by admin-only routes.
                'can_manage_settings' => \App\Support\RoleKey::satisfies(\App\Support\RoleKey::forUserId((int) ($identity['user_id'] ?? 0)), ['admin']),
                'data' => $connectors->map(fn (DataConnector $connector) => $this->presentConnector($connector, $history->get($connector->id, collect())))->values(),
            ]);
        } catch (QueryException $e) {
            Log::error('signals.connectors.index_query_failed', ['error' => $e->getMessage()]);
            if ($this->isMissingTableException($e)) {
                return response()->json([
                    'status' => 1,
                    'tables_migrated' => false,
                    'global_schedule_enabled' => (bool) config('signals_connectors.schedule_enabled'),
                    'available' => $this->connectors->available($tenantId ?? null),
                    'data' => [],
                    'notice' => 'Data connector database tables are not migrated yet. Run php artisan migrate to activate this feature.',
                ]);
            }

            return response()->json([
                'status' => 0,
                'message' => 'Unable to load data connectors due to a database error.',
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->tablesExist()) {
            return response()->json([
                'status' => 0,
                'message' => 'Data connector database tables are not migrated yet. Run php artisan migrate before configuring connectors.',
            ], 503);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|string|in:' . implode(',', \App\Domain\Signals\Connectors\ConnectorProviderCatalog::keys()),
            'name' => 'required|string|max:191',
            'url' => 'nullable|string|max:2000',
            'external_account_id' => 'nullable|string|max:191|required_if:type,linkedin|regex:/^[0-9]+$/',
            'product_key' => ['required', 'string', 'in:' . implode(',', array_keys(ProductCatalog::definitionsFor($identity['sub_institute_id'])))],
            'offer_id' => 'nullable|string|max:191',
            'customer_model' => 'nullable|string|in:b2b,b2c,b2g',
            'regions' => 'nullable|array|max:5',
            'regions.*.country' => 'required|string|max:80',
            'regions.*.state' => 'nullable|string|max:80',
            'regions.*.city' => 'nullable|string|max:80',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $product = $this->readyProduct($request->string('product_key')->toString(), $identity['sub_institute_id']);
        if ($product instanceof JsonResponse) {
            return $product;
        }
        $scope = $this->scopeConfiguration($request, $identity['sub_institute_id'], $product);
        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        $type = (string) $request->input('type');
        $url = trim((string) $request->input('url'));
        if ($type === 'public_web_page') {
            if ($url === '') {
                return response()->json(['status' => 0, 'message' => 'A page URL is required.'], 422);
            }
            try {
                $this->fetcher->validate($url);
            } catch (UnsafeUrlException $e) {
                return response()->json(['status' => 0, 'message' => $e->getMessage()], 422);
            }
        }
        // One connector per page / organisation per product: a second would import everything twice and show two cards for one thing.
        $holder = $type === 'public_web_page'
            ? \App\Domain\Signals\Connectors\ConnectorDuplicates::pageHolder((int) $identity['sub_institute_id'], $product, $url)
            : ($type === 'linkedin' ? \App\Domain\Signals\Connectors\ConnectorDuplicates::accountHolder((int) $identity['sub_institute_id'], 'linkedin', $product, (string) $request->input('external_account_id')) : null);
        if ($holder) {
            return response()->json(['status' => 0, 'code' => 'duplicate_connector', 'message' => \App\Domain\Signals\Connectors\ConnectorDuplicates::message($holder, $type === 'public_web_page' ? 'page' : 'LinkedIn organisation'), 'existing_connector_id' => (int) $holder->id], 409);
        }

        try {
            $connector = DataConnector::create([
                'sub_institute_id' => $identity['sub_institute_id'],
                'type' => $type,
                'name' => trim((string) $request->input('name')),
                'url' => $url !== '' ? $url : null,
                'external_account_id' => $type === 'linkedin' ? trim((string) $request->input('external_account_id')) ?: null : null,
                'product_key' => $product,
                ...$scope,
                'connection_status' => 'not_configured',
                'created_by' => $identity['user_id'],
            ]);

            return response()->json(['status' => 1, 'data' => $this->presentConnector($connector, collect())], 201);
        } catch (QueryException $e) {
            Log::error('signals.connectors.store_failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 0,
                'message' => $this->isMissingTableException($e)
                    ? 'Data connector database tables are not migrated yet. Run php artisan migrate.'
                    : 'A database error occurred while creating the connector.',
            ], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->tablesExist()) {
            return response()->json([
                'status' => 0,
                'message' => 'Data connector database tables are not migrated yet. Run php artisan migrate before configuring connectors.',
            ], 503);
        }

        try {
            $connector = DataConnector::where('sub_institute_id', $identity['sub_institute_id'])->find($id);
            if (! $connector) {
                return response()->json(['status' => 0, 'message' => 'Connector not found.'], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:191',
                'url' => 'nullable|string|max:2000',
                'external_account_id' => 'nullable|string|max:191',
                'product_key' => ['required', 'string', 'in:' . implode(',', array_keys(ProductCatalog::definitionsFor($identity['sub_institute_id'])))],
                'offer_id' => 'sometimes|nullable|string|max:191',
                'customer_model' => 'sometimes|nullable|string|in:b2b,b2c,b2g',
                'regions' => 'sometimes|nullable|array|max:5',
                'regions.*.country' => 'required|string|max:80',
                'regions.*.state' => 'nullable|string|max:80',
                'regions.*.city' => 'nullable|string|max:80',
            ]);
            if ($validator->fails()) {
                return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
            }

            $product = $this->readyProduct($request->string('product_key')->toString(), $identity['sub_institute_id']);
            if ($product instanceof JsonResponse) {
                return $product;
            }
            $scope = $this->scopeConfiguration($request, $identity['sub_institute_id'], $product, $connector);
            if ($scope instanceof JsonResponse) {
                return $scope;
            }

            $url = trim((string) $request->input('url'));
            if ($connector->type === 'public_web_page') {
                if ($url === '') {
                    return response()->json(['status' => 0, 'message' => 'A page URL is required.'], 422);
                }
                try {
                    $this->fetcher->validate($url);
                } catch (UnsafeUrlException $e) {
                    return response()->json(['status' => 0, 'message' => $e->getMessage()], 422);
                }
            }

            $oldAccountId = (string) $connector->external_account_id;
            $newAccountId = $connector->type === 'linkedin' ? trim((string) $request->input('external_account_id', $oldAccountId)) : $oldAccountId;
            if ($connector->type === 'linkedin' && $newAccountId !== '' && ! ctype_digit($newAccountId)) {
                return response()->json(['status' => 0, 'message' => 'LinkedIn organization IDs must be numeric.'], 422);
            }
            $accountChanged = $oldAccountId !== $newAccountId;
            $linkedinAuthorized = $connector->type === 'linkedin'
                && $newAccountId !== ''
                && ctype_digit($newAccountId)
                && ! empty(((array) $connector->credentials)['access_token'] ?? null);
            $connector->forceFill([
                'name' => trim((string) $request->input('name')),
                'url' => $connector->type === 'linkedin' && $newAccountId !== ''
                    ? 'https://www.linkedin.com/company/' . rawurlencode($newAccountId)
                    : ($url !== '' ? $url : $connector->url),
                'external_account_id' => $newAccountId !== '' ? $newAccountId : null,
                'product_key' => $product,
                ...$scope,
                'connection_status' => $accountChanged && $connector->type === 'linkedin'
                    ? ($linkedinAuthorized ? 'connected' : 'permission_required')
                    : $connector->connection_status,
                'last_error' => $accountChanged && $connector->type === 'linkedin'
                    ? ($linkedinAuthorized ? null : 'Authorize the LinkedIn organization through OAuth.')
                    : $connector->last_error,
            ])->save();

            return response()->json(['status' => 1, 'data' => $this->presentConnector($connector, collect())]);
        } catch (QueryException $e) {
            Log::error('signals.connectors.update_failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 0,
                'message' => 'A database error occurred while updating the connector.',
            ], 500);
        }
    }

    public function connect(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->tablesExist()) {
            return response()->json([
                'status' => 0,
                'message' => 'Data connector database tables are not migrated yet. Run php artisan migrate before configuring connectors.',
            ], 503);
        }

        try {
            $connector = DataConnector::where('sub_institute_id', $identity['sub_institute_id'])->find($id);
            if (! $connector) {
                return response()->json(['status' => 0, 'message' => 'Connector not found.'], 404);
            }
            if ($connector->type !== 'public_web_page') {
                return response()->json(['status' => 0, 'message' => 'Use the platform OAuth flow to connect this account.'], 422);
            }

            $connector->forceFill(['connection_status' => 'not_configured', 'disconnected_at' => null, 'last_error' => null, 'sync_enabled' => true])->save();

            return response()->json(['status' => 1, 'data' => $this->presentConnector($connector, collect())]);
        } catch (QueryException $e) {
            Log::error('signals.connectors.connect_failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 0,
                'message' => 'A database error occurred while connecting.',
            ], 500);
        }
    }

    public function disconnect(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->tablesExist()) {
            return response()->json([
                'status' => 0,
                'message' => 'Data connector database tables are not migrated yet. Run php artisan migrate before configuring connectors.',
            ], 503);
        }

        try {
            $connector = DataConnector::where('sub_institute_id', $identity['sub_institute_id'])->find($id);
            if (! $connector) {
                return response()->json(['status' => 0, 'message' => 'Connector not found.'], 404);
            }

            // Ask the provider to revoke first (the token is needed for that), then remove the local copy whatever the answer was:
            // a provider that cannot be reached must never leave a usable token stored here.
            $revocation = app(SocialConnectorTokenManager::class)->revoke($connector);
            $connector->forceFill([
                'connection_status' => 'disconnected', 'disconnected_at' => now(), 'last_error' => null,
                'credentials' => null, 'sync_enabled' => false, 'schedule_enabled' => false, 'next_sync_at' => null,
            ])->save();
            $message = match ($revocation) {
                'revoked' => 'Disconnected. The platform confirmed the authorization was revoked.',
                'failed' => 'Disconnected here and the saved authorization was deleted, but the platform could not be asked to revoke it. Remove this app in your account settings on the platform to be sure.',
                'not_supported' => 'Disconnected here and the saved authorization was deleted. This platform is not asked to revoke it: remove the app in your account settings on the platform.',
                default => 'Disconnected.',
            };

            return response()->json(['status' => 1, 'message' => $message, 'revocation' => $revocation, 'data' => $this->presentConnector($connector, collect())]);
        } catch (QueryException $e) {
            Log::error('signals.connectors.disconnect_failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 0,
                'message' => 'A database error occurred while disconnecting the connector.',
            ], 500);
        }
    }

    public function schedule(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->tablesExist()) {
            return response()->json([
                'status' => 0,
                'message' => 'Data connector database tables are not migrated yet. Run php artisan migrate before configuring connectors.',
            ], 503);
        }

        try {
            $tenantId = $identity['sub_institute_id'];
            $connector = DataConnector::where('sub_institute_id', $tenantId)->find($id);
            if (! $connector) {
                return response()->json(['status' => 0, 'message' => 'Connector not found.'], 404);
            }
            $validator = Validator::make($request->all(), [
                'sync_enabled' => 'required|boolean',
                'schedule_enabled' => 'required|boolean',
                'schedule_interval_minutes' => 'required|integer|min:15|max:10080',
                'schedule_timezone' => 'required|string|timezone',
            ]);
            if ($validator->fails()) {
                return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
            }

            try {
                new DateTimeZone((string) $request->input('schedule_timezone'));
            } catch (\Exception) {
                return response()->json(['status' => 0, 'message' => 'The timezone is invalid.'], 422);
            }

            $enabled = $request->boolean('schedule_enabled');
            $connector->forceFill([
                'sync_enabled' => $request->boolean('sync_enabled'),
                'schedule_enabled' => $enabled,
                'schedule_interval_minutes' => (int) $request->input('schedule_interval_minutes'),
                'schedule_timezone' => (string) $request->input('schedule_timezone'),
                'next_sync_at' => $request->boolean('sync_enabled') && $enabled && in_array($connector->connection_status, ['connected', 'rate_limited'], true)
                    ? now((string) $request->input('schedule_timezone'))->addMinutes((int) $request->input('schedule_interval_minutes'))
                    : null,
            ])->save();

            return response()->json(['status' => 1, 'data' => $this->presentConnector($connector, collect())]);
        } catch (QueryException $e) {
            Log::error('signals.connectors.schedule_failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 0,
                'message' => 'A database error occurred while updating the connector schedule.',
            ], 500);
        }
    }

    public function sync(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->tablesExist()) {
            return response()->json([
                'status' => 0,
                'message' => 'Data connector database tables are not migrated yet. Run php artisan migrate before configuring connectors.',
            ], 503);
        }

        try {
            $tenantId = $identity['sub_institute_id'];
            $connector = DataConnector::where('sub_institute_id', $tenantId)->find($id);
            if (! $connector) {
                return response()->json(['status' => 0, 'message' => 'Connector not found.'], 404);
            }
            if ($connector->connection_status === 'disconnected' || ! $connector->sync_enabled) {
                return response()->json(['status' => 0, 'message' => 'Enable and connect this connector before syncing.'], 409);
            }
            if ($connector->type !== 'public_web_page' && ! in_array($connector->connection_status, ['connected', 'rate_limited'], true)) {
                return response()->json(['status' => 0, 'message' => $connector->last_error ?: 'Complete the platform authorization before syncing.'], 409);
            }
            $product = $this->readyProduct((string) $connector->product_key, $tenantId);
            if ($product instanceof JsonResponse) {
                return $product;
            }

            // Free a connector blocked by a lost sync before deciding whether one is "already running".
            // If the sweep fails it is logged and Sync Now is still attempted: a sync that is genuinely still pending gets the
            // normal 409 "already running" answer below, and nothing is claimed to have been recovered.
            DataConnectorSyncReaper::sweepSafely($tenantId);
            $connector->refresh();

            $sync = DB::transaction(function () use ($connector, $tenantId, $identity) {
                $lockedConnector = DataConnector::where('sub_institute_id', $tenantId)->whereKey($connector->id)->lockForUpdate()->first();
                if (! $lockedConnector || ! $lockedConnector->sync_enabled) {
                    return null;
                }
                $pending = DataConnectorSync::where('sub_institute_id', $tenantId)
                    ->where('connector_id', $connector->id)
                    ->whereIn('status', ['queued', 'running'])
                    ->exists();
                if ($pending) {
                    return null;
                }

                $sync = DataConnectorSync::create([
                    'sub_institute_id' => $tenantId,
                    'connector_id' => $connector->id,
                    'status' => 'queued',
                    'created_by' => $identity['user_id'],
                    'started_at' => now(),
                ]);
                $lockedConnector->forceFill(['last_sync_at' => now()])->save();

                return $sync;
            });
            if (! $sync) {
                return response()->json(['status' => 0, 'message' => 'A sync is already running for this connector.'], 409);
            }

            try {
                SyncDataConnectorJob::dispatch($tenantId, (int) $connector->id, (int) $sync->id, $identity['user_id']);
            } catch (\Throwable $exception) {
                Log::warning('signals.connector_sync_dispatch_failed', [
                    'tenant' => $tenantId,
                    'connector' => $connector->id,
                    'exception' => get_class($exception),
                ]);

                return $this->failSync($connector, $sync, 'The connector sync could not be queued. Please retry.');
            }

            // Like Generate Signals and URL analysis: a short-lived worker (stops when the queue is empty) drains the queue so the
            // sync does not wait for a standing worker. Where the host forbids starting a process, or the queue is sync, nothing
            // changes: the response below still reports the sync as queued, never as done.
            if ($sync->status === 'queued' && config('queue.default') !== 'sync') {
                \App\Support\QueueWorkerLauncher::start((string) config('signals.queue', 'signals'));
            }

            $sync->refresh();
            if ($sync->status === 'failed') {
                return response()->json(['status' => 0, 'message' => $sync->error_message, 'data' => $this->presentSync($sync)], 422);
            }
            if ($sync->status === 'skipped') {
                return response()->json(['status' => 1, 'message' => $sync->error_message, 'data' => $this->presentSync($sync)], 200);
            }

            return response()->json(['status' => 1, 'message' => $sync->status === 'queued'
                ? 'Connector sync queued. It stays "queued" until a Signals queue worker picks it up, then shows "running" and its final result in the sync history.'
                : 'Connector sync is ' . $sync->status . ' in the existing ingestion pipeline.', 'data' => $this->presentSync($sync)], 202);
        } catch (QueryException $e) {
            Log::error('signals.connectors.sync_failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 0,
                'message' => 'A database error occurred while initiating the connector sync.',
            ], 500);
        }
    }

    public function configureAccount(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->tablesExist()) {
            return response()->json([
                'status' => 0,
                'message' => 'Data connector database tables are not migrated yet.',
            ], 503);
        }

        try {
            $connector = DataConnector::where('sub_institute_id', $identity['sub_institute_id'])->where('type', 'linkedin')->find($id);
            if (! $connector) {
                return response()->json(['status' => 0, 'message' => 'Connector not found.'], 404);
            }

            $validator = Validator::make($request->all(), ['external_account_id' => 'required|string|max:191|regex:/^[0-9]+$/']);
            if ($validator->fails()) {
                return response()->json(['status' => 0, 'message' => 'Enter the numeric LinkedIn organization ID.', 'errors' => $validator->errors()], 422);
            }
            $accountId = trim((string) $request->input('external_account_id'));
            if ($holder = \App\Domain\Signals\Connectors\ConnectorDuplicates::accountHolder((int) $identity['sub_institute_id'], 'linkedin', (string) $connector->product_key, $accountId, (int) $connector->id)) {
                return response()->json(['status' => 0, 'code' => 'duplicate_connector', 'message' => \App\Domain\Signals\Connectors\ConnectorDuplicates::message($holder, 'LinkedIn organisation'), 'existing_connector_id' => (int) $holder->id], 409);
            }
            $credentials = (array) $connector->credentials;
            $connected = ! empty($credentials['access_token']);
            $connector->forceFill([
                'external_account_id' => $accountId,
                'url' => 'https://www.linkedin.com/company/' . rawurlencode($accountId),
                'connection_status' => $connected ? 'connected' : 'permission_required',
                'next_sync_at' => $connected && $connector->schedule_enabled
                    ? now((string) ($connector->schedule_timezone ?: 'UTC'))->addMinutes((int) ($connector->schedule_interval_minutes ?: 60))
                    : null,
                'last_error' => $connected ? null : 'Authorize the LinkedIn organization through OAuth.',
            ])->save();

            return response()->json(['status' => 1, 'data' => $this->presentConnector($connector, collect())]);
        } catch (QueryException $e) {
            Log::error('signals.connectors.configure_account_failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 0,
                'message' => 'A database error occurred while configuring the account.',
            ], 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        if (! $this->tablesExist()) {
            return response()->json([
                'status' => 0,
                'message' => 'Data connector database tables are not migrated yet.',
            ], 503);
        }

        try {
            $tenantId = $identity['sub_institute_id'];
            $connector = DataConnector::where('sub_institute_id', $tenantId)->find($id);
            if (! $connector) {
                return response()->json(['status' => 0, 'message' => 'Connector not found.'], 404);
            }

            DB::transaction(function () use ($connector, $tenantId) {
                if (Schema::hasTable('g2g_ingestion_sources')) {
                    DB::table('g2g_ingestion_sources')
                        ->where('sub_institute_id', $tenantId)
                        ->where('connector_id', $connector->id)
                        ->update(['connector_id' => null, 'connector_sync_id' => null]);
                }

                DataConnectorSync::where('sub_institute_id', $tenantId)
                    ->where('connector_id', $connector->id)
                    ->delete();

                $connector->delete();
            });

            return response()->json([
                'status' => 1,
                'message' => 'Connector deleted successfully.',
            ]);
        } catch (QueryException $e) {
            Log::error('signals.connectors.destroy_failed', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 0,
                'message' => 'A database error occurred while deleting the connector.',
            ], 500);
        }
    }

    private function tablesExist(): bool
    {
        try {
            return Schema::hasTable('g2g_data_connectors') && Schema::hasTable('g2g_data_connector_syncs');
        } catch (\Throwable) {
            return false;
        }
    }

    private function isMissingTableException(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, "doesn't exist")
            || str_contains($message, 'no such table')
            || str_contains($message, 'base table or view not found')
            || (string) $e->getCode() === '42S02'
            || $e->getCode() === 1146;
    }

    private function readyProduct(string $key, int $tenantId): string|JsonResponse
    {
        $normalised = ProductCatalog::normalise($key, $tenantId);
        if ($normalised === null) {
            return response()->json(['status' => 0, 'message' => 'Unknown product.'], 422);
        }

        return $normalised;
    }

    /** @return array{offer_id: ?string, customer_model: string, region_scope: ?array}|JsonResponse */
    private function scopeConfiguration(Request $request, int $tenantId, string $product, ?DataConnector $connector = null): array|JsonResponse
    {
        $offerId = trim((string) $request->input('offer_id', $connector?->offer_id ?? '')) ?: null;
        $customerModel = $request->input('customer_model', $connector?->customer_model ?: 'b2b');
        $customerModel = is_string($customerModel) && in_array($customerModel, ['b2b', 'b2c', 'b2g'], true) ? $customerModel : 'b2b';
        $regions = $request->exists('regions') ? $request->input('regions') : $connector?->region_scope;

        try {
            $regionScope = RegionScope::fromInput($regions)?->selections();
        } catch (RegionInvalid $e) {
            return response()->json(['status' => 0, 'message' => $e->getMessage()], 422);
        }

        if ($offerId !== null) {
            try {
                (new ProductCatalog())->resolveSub($tenantId, $product, $offerId, $customerModel);
            } catch (ProductNotConfigured $e) {
                return response()->json(['status' => 0, 'message' => $e->getMessage()], 422);
            }
        }

        return [
            'offer_id' => $offerId,
            'customer_model' => $customerModel,
            'region_scope' => $regionScope,
        ];
    }

    private function failSync(DataConnector $connector, DataConnectorSync $sync, string $message, bool $connectionError = false): JsonResponse
    {
        $sync->forceFill([
            'status' => 'failed', 'records_failed' => max(1, (int) $sync->records_fetched),
            'error_message' => mb_substr($message, 0, 500), 'completed_at' => now(),
        ])->save();
        $connector->forceFill([
            'last_sync_at' => now(),
            'connection_status' => $connectionError ? 'error' : $connector->connection_status,
            'last_error' => $message,
        ])->save();

        return response()->json(['status' => 0, 'message' => $message, 'data' => $this->presentSync($sync)], 422);
    }

    private function presentConnector(DataConnector $connector, $history): array
    {
        return [
            'id' => (int) $connector->id, 'type' => $connector->type, 'name' => $connector->name,
            'url' => $connector->url, 'product_key' => $connector->product_key,
            'offer_id' => $connector->offer_id, 'customer_model' => $connector->customer_model ?: 'b2b',
            'regions' => $connector->region_scope,
            'account_name' => $connector->account_name,
            'external_account_id' => $connector->external_account_id,
            'sync_enabled' => (bool) $connector->sync_enabled,
            'schedule_enabled' => (bool) $connector->schedule_enabled,
            'schedule_interval_minutes' => (int) ($connector->schedule_interval_minutes ?: 60),
            'schedule_timezone' => (string) ($connector->schedule_timezone ?: 'UTC'),
            'next_sync_at' => $connector->next_sync_at?->toIso8601String(),
            'available_accounts' => $this->safeAvailableAccounts($connector),
            'monitoring' => ConnectorMonitoring::for($connector)->toArray(),
            'monitoring_customised' => is_array($connector->monitoring_settings ?? null) && $connector->monitoring_settings !== [],
            'connection_status' => $connector->connection_status,
            'last_sync_at' => $connector->last_sync_at?->toIso8601String(),
            'last_successful_sync_at' => $connector->last_successful_sync_at?->toIso8601String(),
            'last_error' => $connector->last_error,
            'history' => $history->take(10)->map(fn (DataConnectorSync $sync) => $this->presentSync($sync))->values(),
        ];
    }

    /** 0-100 for the progress bar: waiting for a worker, reading the platform, then analysing posts in batches. */
    private function syncProgress(DataConnectorSync $sync): int
    {
        if (! in_array($sync->status, ['queued', 'running'], true)) {
            return 100;
        }
        if ($sync->status === 'queued') {
            return 3;
        }
        $fetched = (int) $sync->records_fetched;
        if ($fetched <= 0) {
            return 12;
        }
        $handled = (int) $sync->records_processed + (int) $sync->records_skipped + (int) $sync->records_failed;

        return (int) min(97, 25 + round(72 * min(1, $handled / $fetched)));
    }

    /**
     * The one stage a sync is in, told apart so the interface never shows a finished or lost sync as "running":
     * waiting_in_queue | fetching | analysing | completed | completed_with_errors | failed | interrupted.
     * `interrupted` is only a sync the recovery sweep closed because its worker stopped writing; the reason is in error_message.
     *
     * @return array{0: string, 1: string}
     */
    private function syncStage(DataConnectorSync $sync): array
    {
        $interrupted = in_array($sync->status, ['failed', 'partial'], true) && $sync->error_message === DataConnectorSyncReaper::INTERRUPTED_MESSAGE;

        return match (true) {
            $sync->status === 'queued' => ['waiting_in_queue', 'Waiting in queue'],
            $sync->status === 'running' && (int) $sync->records_fetched <= 0 => ['fetching', 'Fetching from the platform'],
            $sync->status === 'running' => ['analysing', 'Analysing posts'],
            $interrupted => ['interrupted', 'Interrupted'],
            $sync->status === 'partial' => ['completed_with_errors', 'Completed with errors'],
            $sync->status === 'failed' => ['failed', 'Failed'],
            default => ['completed', 'Completed'],
        };
    }

    private function presentSync(DataConnectorSync $sync): array
    {
        [$stage, $stageLabel] = $this->syncStage($sync);

        return [
            'progress' => $this->syncProgress($sync),
            'stage' => $stage, 'stage_label' => $stageLabel,
            'records_pending' => in_array($sync->status, ['queued', 'running'], true)
                ? max(0, (int) $sync->records_fetched - (int) $sync->records_processed - (int) $sync->records_skipped - (int) $sync->records_failed) : null,
            'id' => (int) $sync->id, 'source_id' => $sync->source_id ? (int) $sync->source_id : null,
            'status' => $sync->status, 'records_fetched' => (int) $sync->records_fetched,
            'records_processed' => (int) $sync->records_processed, 'records_skipped' => (int) $sync->records_skipped,
            'records_created' => (int) ($sync->records_created ?? 0), 'records_duplicate' => (int) ($sync->records_duplicate ?? 0),
            'records_failed' => (int) $sync->records_failed, 'error_message' => $sync->error_message,
            'started_at' => $sync->started_at?->toIso8601String(), 'completed_at' => $sync->completed_at?->toIso8601String(),
        ];
    }

    /** The organisation's own monitoring rules for one connector: keywords, exclusions, accounts, limits, content types, on/off. */
    public function monitoring(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        if (! $this->tablesExist()) {
            return response()->json(['status' => 0, 'message' => 'Data connector database tables are not migrated yet.'], 503);
        }
        if (! \App\Support\ColumnExists::has('g2g_data_connectors', 'monitoring_settings')) {
            return response()->json(['status' => 0, 'message' => 'Monitoring settings are not available until the latest database update is applied. Ask an administrator to run php artisan migrate.'], 503);
        }
        $connector = DataConnector::where('sub_institute_id', $identity['sub_institute_id'])->find($id);
        if (! $connector) {
            return response()->json(['status' => 0, 'message' => 'Connector not found.'], 404);
        }
        $validator = Validator::make($request->all(), [
            'enabled' => 'sometimes|boolean',
            'search_keywords' => 'sometimes|array|max:8', 'search_keywords.*' => 'string|max:80',
            'exclude_keywords' => 'sometimes|array|max:30', 'exclude_keywords.*' => 'string|max:60',
            'target_accounts' => 'sometimes|array|max:10', 'target_accounts.*' => 'string|max:31',
            'max_items' => 'sometimes|integer|min:1|max:200', 'search_limit' => 'sometimes|integer|min:1|max:25',
            'comment_videos' => 'sometimes|integer|min:0|max:25',
            'content_types' => 'sometimes|array', 'content_types.*' => 'in:' . implode(',', ConnectorMonitoring::CONTENT_TYPES),
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $saved = array_merge(is_array($connector->monitoring_settings) ? $connector->monitoring_settings : [], ConnectorMonitoring::sanitize($request->all()));
        $connector->forceFill(['monitoring_settings' => $saved]);
        // "Enabled" is this connector's monitoring switch: the same sync_enabled flag that Sync Now and the schedule already honour.
        if (array_key_exists('enabled', $saved)) {
            $connector->forceFill(['sync_enabled' => (bool) $saved['enabled']]);
        }
        $connector->save();

        return response()->json(['status' => 1, 'data' => $this->presentConnector($connector, collect())]);
    }

    /**
     * What one sync did, post by post: the numbers, the queue, and for every post its decision (qualified, or the structured reason it was
     * not), the exact quote when it qualified and the error when it failed. Read-only; scoped to the caller's organisation.
     */
    public function report(Request $request, int $id, int $syncId): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        if (! $this->tablesExist()) {
            return response()->json(['status' => 0, 'message' => 'Data connector database tables are not migrated yet.'], 503);
        }
        $tenantId = (int) $identity['sub_institute_id'];
        $connector = DataConnector::where('sub_institute_id', $tenantId)->find($id);
        $sync = $connector ? DataConnectorSync::where('sub_institute_id', $tenantId)->where('connector_id', $id)->find($syncId) : null;
        if (! $connector || ! $sync) {
            return response()->json(['status' => 0, 'message' => 'Sync not found.'], 404);
        }

        $decisionsRecorded = SyncDecision::columnsReady();
        $stored = IngestionSource::where('sub_institute_id', $tenantId)->where('connector_id', $id)->where('connector_sync_id', $syncId)
            ->orderBy('id')->limit(500)->get();
        $analysedHere = $decisionsRecorded
            ? IngestionAnalysis::where('sub_institute_id', $tenantId)->where('connector_sync_id', $syncId)->orderBy('id')->get()->groupBy('source_id')
            : collect();
        $extraIds = $analysedHere->keys()->diff($stored->pluck('id'));
        $extra = $extraIds->isEmpty() ? collect() : IngestionSource::where('sub_institute_id', $tenantId)->where('connector_id', $id)->whereIn('id', $extraIds)->get();
        $items = $stored->concat($extra)->sortBy('id')->values();
        // Posts analysed before per-post reasons were kept: analysed, but with no recorded reason.
        $legacy = $decisionsRecorded ? collect() : IngestionAnalysis::where('sub_institute_id', $tenantId)->whereIn('source_id', $items->pluck('id'))
            ->whereIn('status', ['success', 'partial'])->get()->groupBy('source_id');

        $rows = [];
        $byReason = [];
        $counts = ['analysed' => 0, 'pending' => 0, 'qualified' => 0, 'rejected' => 0, 'errors' => 0];
        foreach ($items as $source) {
            $attempts = $analysedHere->get($source->id, collect());
            $last = $attempts->last();
            $done = $attempts->first(fn ($a) => in_array($a->status, ['success', 'partial'], true)) ?? $legacy->get($source->id)?->last();
            $row = [
                'source_id' => (int) $source->id, 'connector' => $connector->type, 'origin' => SyncDecision::originOf($source),
                'title' => $source->page_title ?: $source->name, 'url' => $source->url,
                'content' => mb_substr($this->postText($source), 0, 800), 'published_at' => $source->published_at?->toIso8601String(),
                'status' => 'pending', 'decision_code' => null, 'decision_label' => null, 'detail' => null, 'quote' => null, 'organisation' => null, 'error' => null,
            ];
            if ($done !== null) {
                $code = $done->decision_code ?? null;
                $row['status'] = 'analysed';
                $row['decision_code'] = $code;
                $row['decision_label'] = $code ? (SyncDecision::LABELS[$code] ?? $code) : 'Analysed (no reason was recorded for this older sync)';
                $row['detail'] = $done->decision_detail ?? null;
                $row['quote'] = $done->decision_quote ?? null;
                $row['organisation'] = $done->decision_organisation ?? null;
                $counts['analysed']++;
                if ($code === SyncDecision::QUALIFIED) {
                    $counts['qualified']++;
                } elseif ($code !== null) {
                    $counts['rejected']++;
                }
                $byReason[$code ?? 'UNRECORDED'] = ($byReason[$code ?? 'UNRECORDED'] ?? 0) + 1;
            } elseif ($last !== null && $last->status === 'failed') {
                $failCode = $last->decision_code ?? $last->error_code ?? SyncDecision::AI_FAILED;
                $row['status'] = 'failed';
                $row['decision_code'] = $failCode;
                $row['decision_label'] = SyncDecision::LABELS[$failCode] ?? 'Analysis failed';
                $row['error'] = $last->error_message;
                $counts['errors']++;
                $counts['pending']++;
                $byReason[$failCode] = ($byReason[$failCode] ?? 0) + 1;
            } else {
                $counts['pending']++;
            }
            $rows[] = $row;
        }

        $end = $sync->completed_at ?? now();
        $queue = DataConnectorSyncReaper::queueDiagnostics();
        [$stage, $stageLabel] = $this->syncStage($sync);

        return response()->json(['status' => 1, 'data' => [
            'sync' => $this->presentSync($sync),
            'stage' => $stage, 'stage_label' => $stageLabel,
            'reasons_recorded' => $decisionsRecorded,
            'totals' => [
                'fetched' => (int) $sync->records_fetched, 'stored' => $stored->count(), 'analysed' => $counts['analysed'],
                'pending' => $counts['pending'], 'rejected' => $counts['rejected'], 'qualified' => $counts['qualified'],
                'errors' => $counts['errors'], 'duplicates' => (int) ($sync->records_duplicate ?? 0), 'skipped' => (int) $sync->records_skipped,
                'signals_created' => (int) ($sync->records_created ?? 0),
                'duration_seconds' => $sync->started_at ? max(0, (int) $sync->started_at->diffInSeconds($end)) : null,
            ],
            'by_reason' => $byReason,
            'queue' => ['connection' => $queue['connection'], 'driver' => $queue['driver'], 'name' => (string) config('signals.queue', 'signals'),
                'sync_status' => $sync->status, 'retry_after_safe' => $queue['safe']],
            'items' => $rows,
            'items_truncated' => $stored->count() >= 500,
        ]]);
    }

    private function postText(IngestionSource $source): string
    {
        $text = [];
        foreach ((array) json_decode((string) ($source->segments ?? ''), true) as $segment) {
            $text[] = trim((string) ($segment['text'] ?? ''));
        }

        return trim(implode("\n", array_filter($text)));
    }

    private function safeAvailableAccounts(DataConnector $connector): array
    {
        $credentials = (array) $connector->credentials;
        $accounts = (array) ($credentials['available_accounts'] ?? []);

        return array_values(array_map(function (array $account) use ($connector) {
            return $connector->type === 'facebook_page'
                ? ['id' => $account['page_id'] ?? '', 'name' => $account['page_name'] ?? 'Facebook Page']
                : ['id' => $account['instagram_id'] ?? '', 'name' => $account['instagram_name'] ?? $account['page_name'] ?? 'Instagram account'];
        }, array_filter($accounts, 'is_array')));
    }
}
