<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Signals\Connectors\ConnectorCredentialResolver;
use App\Domain\Signals\Connectors\ConnectorProviderCatalog;
use App\Domain\Signals\Connectors\ConnectorProviderReadiness;
use App\Domain\Signals\Connectors\ConnectorProviderSetting;
use App\Domain\Signals\Ingestion\DataConnector;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Connector Settings: an administrator enters the provider application credentials (YouTube, LinkedIn, Meta) in the app instead of editing the
 * server environment. Routes are admin-only (profile:admin) and scoped to the caller's own organisation.
 *
 * What a response may contain: whether a provider is configured and valid, where its credentials come from, the (non-secret) client id of a credential
 * saved here, when it changed, and which settings are missing or malformed BY NAME. It never contains a client secret, a client id that comes from
 * the server environment, or a provider's raw answer. Nothing here writes the environment file.
 */
class ConnectorSettingsController extends Controller
{
    use ResolvesApiIdentity;

    /** What the UI shows in place of a saved secret. The real value is never sent. */
    public const SECRET_MASK = '••••••••••••';

    /** provider => the connector types that sign in with it, and the type whose readiness describes it */
    private const PROVIDERS = [
        'youtube' => ['label' => 'YouTube', 'types' => ['youtube'], 'readiness' => 'youtube'],
        'linkedin' => ['label' => 'LinkedIn', 'types' => ['linkedin'], 'readiness' => 'linkedin'],
        'meta' => ['label' => 'Facebook and Instagram', 'types' => ['facebook_page', 'instagram_business'], 'readiness' => 'facebook_page'],
    ];

    /** Structural shape of each credential: only a malformed value can be rejected here, a wrong one only the provider can tell. */
    private const SHAPES = [
        'youtube' => ['id' => '/^\d+-[A-Za-z0-9_]+\.apps\.googleusercontent\.com$/', 'secret' => '/^\S{20,}$/', 'idHint' => 'It ends with .apps.googleusercontent.com.', 'secretHint' => 'It is at least 20 characters with no spaces.'],
        'linkedin' => ['id' => '/^[A-Za-z0-9]{8,32}$/', 'secret' => '/^\S{8,}$/', 'idHint' => 'It is letters and digits only.', 'secretHint' => 'It is at least 8 characters with no spaces.'],
        'meta' => ['id' => '/^\d{8,20}$/', 'secret' => '/^[A-Fa-f0-9]{32}$/', 'idHint' => 'The App ID is digits only.', 'secretHint' => 'The App Secret is 32 letters and digits (0-9, a-f).'],
    ];

    public function __construct(
        private readonly ConnectorCredentialResolver $credentials,
        private readonly ConnectorProviderReadiness $readiness,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = (int) $identity['sub_institute_id'];

        return response()->json(['status' => 1, 'data' => [
            'storage_ready' => $this->storageReady(),
            'providers' => array_map(fn (string $provider) => $this->present($provider, $tenantId), array_keys(self::PROVIDERS)),
        ]]);
    }

    public function update(Request $request, string $provider): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = (int) $identity['sub_institute_id'];
        if (! isset(self::PROVIDERS[$provider])) {
            return response()->json(['status' => 0, 'message' => 'Unknown provider.'], 404);
        }
        if (! $this->storageReady()) {
            return response()->json(['status' => 0, 'code' => 'storage_not_ready', 'message' => 'Connector settings storage is not set up on the server yet. An administrator must run the pending database migration first.'], 503);
        }

        $v = Validator::make($request->all(), ['client_id' => 'nullable|string|max:512', 'client_secret' => 'nullable|string|max:1024', 'login_config_id' => 'nullable|string|max:64']);
        if ($v->fails()) {
            return response()->json(['status' => 0, 'message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }
        $newId = trim((string) $request->input('client_id', ''));
        $newSecret = trim((string) $request->input('client_secret', ''));
        // Meta only: the Facebook Login for Business Configuration ID (optional, not secret).
        $newConfigId = $provider === 'meta' ? trim((string) $request->input('login_config_id', '')) : '';
        if ($newConfigId !== '' && ! \App\Support\ColumnExists::has('g2g_connector_provider_settings', 'login_config_id')) {
            return response()->json(['status' => 0, 'code' => 'storage_not_ready', 'message' => 'The Configuration ID cannot be saved yet: an administrator must run the pending database migration (php artisan migrate) once.'], 503);
        }
        // The mask is what the form shows for a saved secret; sending it back must never replace the real one.
        if ($newSecret === self::SECRET_MASK) {
            $newSecret = '';
        }

        $existing = $this->credentials->saved($provider, $tenantId);
        $changed = array_values(array_filter([$newId !== '' ? 'client_id' : null, $newSecret !== '' ? 'client_secret' : null, $newConfigId !== '' ? 'login_config_id' : null]));
        if ($changed === []) {
            return response()->json(['status' => 0, 'code' => 'nothing_to_change', 'message' => 'Nothing was changed. Enter a new value, or leave the saved ones as they are.'], 422);
        }
        // A first save needs both. An edit keeps whichever half was left empty.
        if ($existing === null && ($newId === '' || $newSecret === '')) {
            return response()->json(['status' => 0, 'code' => 'incomplete', 'message' => 'Enter both the client ID and the client secret to save the configuration.', 'errors' => array_filter([
                'client_id' => $newId === '' ? ['Enter the client ID.'] : null, 'client_secret' => $newSecret === '' ? ['Enter the client secret.'] : null,
            ])], 422);
        }

        $label = $this->idLabel($provider);
        $problems = [];
        if ($newId !== '' && preg_match(self::SHAPES[$provider]['id'], $newId) !== 1) {
            $problems['client_id'] = ["That does not look like a valid {$label}. " . self::SHAPES[$provider]['idHint']];
        }
        if ($newConfigId !== '' && preg_match('/^\d{8,30}$/', $newConfigId) !== 1) {
            $problems['login_config_id'] = ['That does not look like a Configuration ID. It is a long number from Meta: Facebook Login for Business, then Configurations.'];
        }
        if ($newSecret !== '' && preg_match(self::SHAPES[$provider]['secret'], $newSecret) !== 1) {
            $problems['client_secret'] = ['That does not look like a valid client secret. ' . self::SHAPES[$provider]['secretHint']];
        }
        if ($problems !== []) {
            return response()->json(['status' => 0, 'code' => 'configuration_invalid', 'message' => 'The credentials could not be saved because they do not look valid. Nothing was changed.', 'errors' => $problems], 422);
        }

        $idChanged = $existing !== null && $newId !== '' && $newId !== (string) $existing->client_id;
        DB::transaction(function () use ($tenantId, $provider, $existing, $newId, $newSecret, $newConfigId, $changed, $identity) {
            $values = [
                'client_id' => $newId !== '' ? $newId : (string) $existing?->client_id,
                'client_secret' => $newSecret !== '' ? $newSecret : (string) $existing?->client_secret,
                'updated_by' => $identity['user_id'] ?? null,
            ];
            if ($newConfigId !== '') {
                $values['login_config_id'] = $newConfigId;
            }
            ConnectorProviderSetting::updateOrCreate(['sub_institute_id' => $tenantId, 'provider' => $provider], $values);
            $this->audit($tenantId, $provider, $existing === null ? 'created' : 'updated', $changed, $identity['user_id'] ?? null);
        });
        $this->credentials->forget();
        Log::info('signals.connector_settings_saved', ['tenant' => $tenantId, 'provider' => $provider, 'fields' => $changed, 'by' => $identity['user_id'] ?? null]);

        $warnings = [];
        if ($idChanged && $this->connectedCount($provider, $tenantId) > 0) {
            $warnings[] = 'The client ID changed. Accounts connected through the previous application may need to be reconnected.';
        }

        return response()->json(['status' => 1, 'message' => 'Configuration saved.', 'data' => $this->present($provider, $tenantId), 'warnings' => $warnings]);
    }

    public function destroy(Request $request, string $provider): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = (int) $identity['sub_institute_id'];
        if (! isset(self::PROVIDERS[$provider])) {
            return response()->json(['status' => 0, 'message' => 'Unknown provider.'], 404);
        }
        $existing = $this->storageReady() ? $this->credentials->saved($provider, $tenantId) : null;
        if ($existing === null) {
            return response()->json(['status' => 0, 'code' => 'nothing_saved', 'message' => 'No credentials are saved here for this provider. Credentials set on the server are not changed from this screen.'], 404);
        }

        DB::transaction(function () use ($tenantId, $provider, $identity) {
            ConnectorProviderSetting::where('sub_institute_id', $tenantId)->where('provider', $provider)->delete();
            $this->audit($tenantId, $provider, 'removed', ['client_id', 'client_secret'], $identity['user_id'] ?? null);
        });
        $this->credentials->forget();
        Log::info('signals.connector_settings_removed', ['tenant' => $tenantId, 'provider' => $provider, 'by' => $identity['user_id'] ?? null]);

        $data = $this->present($provider, $tenantId);
        $warnings = [];
        if ($this->connectedCount($provider, $tenantId) > 0) {
            $warnings[] = $data['configured']
                ? 'The saved credentials were removed. The server credentials are used now; accounts connected through the removed application may need to be reconnected.'
                : 'The saved credentials were removed and no server credentials exist. Connected accounts keep their data but cannot refresh their authorization until credentials are added again.';
        }

        return response()->json(['status' => 1, 'message' => 'Saved credentials removed.', 'data' => $data, 'warnings' => $warnings]);
    }

    /**
     * Tests the configuration. Two separate answers, never merged:
     *  - local: are the credentials present, and do they look valid;
     *  - provider_check: whether the provider recognises the application (a harmless request that cannot create a connection and is never given a
     *    real authorization code). "accepted" means the provider knows this client; it does not mean the app is approved or that any account works.
     * OAuth success is never reported here: only a completed sign-in makes a connection.
     */
    public function test(Request $request, string $provider): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }
        $tenantId = (int) $identity['sub_institute_id'];
        if (! isset(self::PROVIDERS[$provider])) {
            return response()->json(['status' => 0, 'message' => 'Unknown provider.'], 404);
        }

        $local = $this->readiness->check(self::PROVIDERS[$provider]['readiness'], $tenantId);
        $connected = $this->connectedCount($provider, $tenantId);
        $providerCheck = ['result' => 'not_checked', 'message' => 'The provider was not contacted because the configuration is not complete and valid.'];
        $status = $this->status($local, $connected);

        if ($local['ready']) {
            $providerCheck = $this->probe($provider, $tenantId, (string) $local['redirect_uri']);
            if ($providerCheck['result'] === 'rejected') {
                $status = 'configuration_invalid';
            }
        }
        if ($status === 'configuration_valid' || $status === 'authorization_required') {
            $status = $connected > 0 ? 'connected' : 'authorization_required';
        }

        return response()->json(['status' => 1, 'data' => [
            'provider' => $provider,
            'status' => $status,
            'local' => ['configured' => $local['missing_config'] === [], 'valid' => $local['ready'], 'missing_config' => $local['missing_config'], 'invalid_config' => $local['invalid_config'], 'message' => $local['message']],
            'provider_check' => $providerCheck,
            'connected_accounts' => $connected,
            'next_step' => match ($status) {
                'not_configured' => 'Enter the client ID and secret, then save.',
                'configuration_invalid' => $providerCheck['result'] === 'rejected' ? 'The provider did not recognise these credentials. Check them in the provider console.' : 'Correct the values marked above and save again.',
                'authorization_required' => 'Credentials look valid. Connect an account to complete authorization.',
                default => 'An account is connected. Run a sync to fetch data.',
            },
        ]]);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function present(string $provider, int $tenantId): array
    {
        $meta = self::PROVIDERS[$provider];
        $readiness = $this->readiness->check($meta['readiness'], $tenantId);
        $resolved = $this->credentials->resolve($provider, $tenantId);
        $saved = $resolved['source'] === ConnectorCredentialResolver::SOURCE_DATABASE;
        $definition = ConnectorProviderCatalog::find($meta['readiness']) ?? [];
        $connected = $this->connectedCount($provider, $tenantId);
        $hasSecret = $resolved['client_secret'] !== null;

        return [
            'provider' => $provider,
            'label' => $meta['label'],
            'connector_types' => $meta['types'],
            'configured' => $readiness['missing_config'] === [],
            'valid' => $readiness['ready'],
            'status' => $this->status($readiness, $connected),
            'source' => $resolved['source'],
            // Only a credential saved here is shown back (its id, which is not secret). A server-environment credential is never echoed.
            'client_id' => $saved ? $resolved['client_id'] : null,
            'secret_saved' => $hasSecret,
            'secret_mask' => $hasSecret ? self::SECRET_MASK : null,
            // Meta only. Not a secret, so it is shown back as saved; null when none is set (or set only in the server environment).
            'login_config_id' => $provider === 'meta' ? ($this->credentials->saved($provider, $tenantId)?->login_config_id ?: null) : null,
            'login_config_required' => $provider === 'meta',
            'updated_at' => $saved ? $resolved['updated_at'] : null,
            'missing_config' => $readiness['missing_config'],
            'invalid_config' => $readiness['invalid_config'],
            'message' => $readiness['message'],
            'redirect_uri' => $readiness['redirect_uri'],
            'requirements' => $definition['requirements'] ?? [],
            'scopes' => $definition['scopes'] ?? [],
            'approval' => $definition['approval'] ?? null,
            'connected_accounts' => $connected,
            'can_remove' => $saved,
        ];
    }

    /** @param array<string, mixed> $readiness */
    private function status(array $readiness, int $connected): string
    {
        if ($readiness['missing_config'] !== [] && ($readiness['invalid_config'] ?? []) === []) {
            return 'not_configured';
        }
        if (! $readiness['ready']) {
            return 'configuration_invalid';
        }

        return $connected > 0 ? 'connected' : 'authorization_required';
    }

    private function connectedCount(string $provider, int $tenantId): int
    {
        if (! Schema::hasTable('g2g_data_connectors')) {
            return 0;
        }

        return DataConnector::where('sub_institute_id', $tenantId)->whereIn('type', self::PROVIDERS[$provider]['types'])
            ->where('connection_status', 'connected')->count();
    }

    /** @return array{result: string, message: string} */
    private function probe(string $provider, int $tenantId, string $redirectUri): array
    {
        $id = (string) $this->credentials->clientId($provider, $tenantId);
        $secret = (string) $this->credentials->clientSecret($provider, $tenantId);
        $unreachable = ['result' => 'unreachable', 'message' => 'The provider could not be reached just now, so the credentials were not checked. Try again.'];

        try {
            if ($provider === 'youtube') {
                // A deliberately invalid code: Google answers invalid_client for an unknown application and invalid_grant for a known one.
                $r = Http::asForm()->timeout(8)->post('https://oauth2.googleapis.com/token', [
                    'client_id' => $id, 'client_secret' => $secret, 'code' => 'g2g-configuration-test', 'grant_type' => 'authorization_code', 'redirect_uri' => $redirectUri,
                ]);
                $error = (string) $r->json('error');

                return match (true) {
                    $r->serverError() => $unreachable,
                    $error === 'invalid_grant' => ['result' => 'accepted', 'message' => 'Google recognised this application. This does not prove the consent screen is approved or that any account can authorise.'],
                    in_array($error, ['invalid_client', 'unauthorized_client'], true) => ['result' => 'rejected', 'message' => 'Google did not recognise this client ID and secret. Check them in the Google Cloud console.'],
                    default => ['result' => 'unverified', 'message' => 'Google gave no clear answer. Complete a sign-in to confirm.'],
                };
            }
            if ($provider === 'linkedin') {
                $r = Http::asForm()->timeout(8)->post('https://www.linkedin.com/oauth/v2/accessToken', [
                    'grant_type' => 'authorization_code', 'code' => 'g2g-configuration-test', 'client_id' => $id, 'client_secret' => $secret, 'redirect_uri' => $redirectUri,
                ]);
                $error = (string) $r->json('error');

                return match (true) {
                    $r->serverError() => $unreachable,
                    in_array($error, ['invalid_client', 'invalid_client_id', 'invalid_client_secret', 'unauthorized_client'], true) => ['result' => 'rejected', 'message' => 'LinkedIn did not recognise this client ID and secret. Check them in the LinkedIn developer portal.'],
                    $error === 'invalid_grant' => ['result' => 'accepted', 'message' => 'LinkedIn recognised this application. This does not prove Community Management API access has been approved.'],
                    default => ['result' => 'unverified', 'message' => 'LinkedIn cannot confirm the credentials without a real sign-in. Connect an account to confirm. Community Management API access still needs LinkedIn approval.'],
                };
            }
            // Meta: an app access token request needs only the app id and secret and creates nothing. The token is discarded unread.
            $r = Http::asForm()->timeout(8)->post('https://graph.facebook.com/' . config('signals_connectors.meta.graph_version') . '/oauth/access_token', [
                'client_id' => $id, 'client_secret' => $secret, 'grant_type' => 'client_credentials',
            ]);

            return match (true) {
                $r->serverError() => $unreachable,
                $r->successful() && is_string($r->json('access_token')) => ['result' => 'accepted', 'message' => 'Meta recognised this app ID and secret. This does not prove the permissions are approved or that any account can authorise.'],
                $r->clientError() => ['result' => 'rejected', 'message' => 'Meta did not accept this App ID and secret. Check them in the Meta developer dashboard.'],
                default => ['result' => 'unverified', 'message' => 'Meta gave no clear answer. Complete a sign-in to confirm.'],
            };
        } catch (\Throwable) {
            return $unreachable;   // the exception text is never returned: it could carry a URL with parameters
        }
    }

    private function idLabel(string $provider): string
    {
        return match ($provider) {
            'youtube' => 'Google OAuth Client ID',
            'linkedin' => 'LinkedIn Client ID',
            default => 'Meta App ID',
        };
    }

    /** @param list<string> $fields */
    private function audit(int $tenantId, string $provider, string $action, array $fields, mixed $actorId): void
    {
        DB::table('g2g_connector_setting_audits')->insert([
            'sub_institute_id' => $tenantId, 'provider' => $provider, 'action' => $action,
            'fields' => implode(',', $fields), 'actor_id' => $actorId, 'created_at' => now(),
        ]);
    }

    private function storageReady(): bool
    {
        try {
            return Schema::hasTable('g2g_connector_provider_settings') && Schema::hasTable('g2g_connector_setting_audits');
        } catch (\Throwable) {
            return false;
        }
    }
}
