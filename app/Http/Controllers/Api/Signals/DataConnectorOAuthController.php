<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Signals\Connectors\SocialConnectorApiException;
use App\Domain\Signals\Ingestion\DataConnector;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DataConnectorOAuthController extends Controller
{
    use ResolvesApiIdentity;

    public function start(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('g2g_data_connectors')) {
            return response()->json([
                'status' => 0,
                'message' => 'Data connector database tables are not migrated yet.',
            ], 503);
        }

        $connector = DataConnector::where('sub_institute_id', $identity['sub_institute_id'])->find($id);
        if (! $connector) {
            return response()->json(['status' => 0, 'message' => 'Connector not found.'], 404);
        }
        $provider = match ($connector->type) {
            'youtube' => 'youtube',
            'linkedin' => 'linkedin',
            'facebook_page', 'instagram_business' => 'meta',
            default => null,
        };
        if ($provider === null) {
            return response()->json(['status' => 0, 'message' => 'This connector does not use OAuth.'], 422);
        }

        $clientId = match ($provider) {
            'youtube' => config('signals_connectors.google.client_id'),
            'linkedin' => config('signals_connectors.linkedin.client_id'),
            default => config('signals_connectors.meta.app_id'),
        };
        $clientSecret = match ($provider) {
            'youtube' => config('signals_connectors.google.client_secret'),
            'linkedin' => config('signals_connectors.linkedin.client_secret'),
            default => config('signals_connectors.meta.app_secret'),
        };
        if (! is_string($clientId) || trim($clientId) === '' || ! is_string($clientSecret) || trim($clientSecret) === '') {
            return response()->json([
                'status' => 0,
                'message' => 'The platform OAuth application is not configured on the server.',
                'connection_status' => 'permission_required',
            ], 503);
        }

        $state = Str::random(48);
        Cache::put('signals:connector-oauth:' . hash('sha256', $state), [
            'tenant_id' => (int) $connector->sub_institute_id,
            'connector_id' => (int) $connector->id,
            'user_id' => (int) $identity['user_id'],
            'provider' => $provider,
        ], now()->addMinutes(10));

        $query = [
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri($provider),
            'response_type' => 'code',
            'state' => $state,
        ];
        $url = match ($provider) {
            'youtube' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($query + [
                'scope' => 'https://www.googleapis.com/auth/youtube.readonly',
                'access_type' => 'offline',
                'prompt' => 'consent',
                'include_granted_scopes' => 'true',
            ]),
            'linkedin' => 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query($query + [
                'scope' => 'r_organization_social',
            ]),
            default => 'https://www.facebook.com/' . config('signals_connectors.meta.graph_version') . '/dialog/oauth?' . http_build_query($query + [
                'scope' => 'pages_show_list,pages_read_engagement,instagram_basic',
            ]),
        };

        return response()->json(['status' => 1, 'data' => ['authorization_url' => $url]]);
    }

    public function callback(Request $request, string $provider)
    {
        $state = trim((string) $request->query('state'));
        $context = $state === '' ? null : Cache::pull('signals:connector-oauth:' . hash('sha256', $state));
        if (! is_array($context) || ($context['provider'] ?? null) !== $provider) {
            return $this->returnToFrontend('error', null);
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('g2g_data_connectors')) {
            return $this->returnToFrontend('error', (int) ($context['connector_id'] ?? 0));
        }

        $connector = DataConnector::where('sub_institute_id', $context['tenant_id'])
            ->whereKey($context['connector_id'])
            ->first();
        if (! $connector || $request->filled('error') || ! $request->filled('code')) {
            return $this->returnToFrontend('error', (int) $context['connector_id']);
        }

        try {
            switch ($provider) {
                case 'youtube':
                    $this->completeYouTube($connector, (string) $request->query('code'));
                    break;
                case 'linkedin':
                    $this->completeLinkedIn($connector, (string) $request->query('code'));
                    break;
                case 'meta':
                    $this->completeMeta($connector, (string) $request->query('code'));
                    break;
                default:
                    throw new \InvalidArgumentException('Unsupported OAuth provider.');
            }
        } catch (SocialConnectorApiException $e) {
            $connector->forceFill([
                'connection_status' => $e->connectionStatus,
                'last_error' => $e->getMessage(),
            ])->save();
            Log::warning('signals.connector_oauth_failed', ['tenant' => $connector->sub_institute_id, 'connector' => $connector->id, 'status' => $e->httpStatus]);

            return $this->returnToFrontend('error', (int) $connector->id);
        } catch (\Throwable $e) {
            $connector->forceFill([
                'connection_status' => 'error',
                'last_error' => 'The platform authorization could not be completed. Please retry.',
            ])->save();
            Log::warning('signals.connector_oauth_failed', ['tenant' => $connector->sub_institute_id, 'connector' => $connector->id, 'exception' => get_class($e)]);

            return $this->returnToFrontend('error', (int) $connector->id);
        }

        return $this->returnToFrontend('success', (int) $connector->id);
    }

    public function selectAccount(Request $request, int $id): JsonResponse
    {
        $identity = $this->resolveApiIdentity($request);
        if (! is_array($identity)) {
            return $identity;
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('g2g_data_connectors')) {
            return response()->json([
                'status' => 0,
                'message' => 'Data connector database tables are not migrated yet.',
            ], 503);
        }

        $connector = DataConnector::where('sub_institute_id', $identity['sub_institute_id'])->find($id);
        if (! $connector || ! in_array($connector->type, ['facebook_page', 'instagram_business'], true)) {
            return response()->json(['status' => 0, 'message' => 'Connector not found.'], 404);
        }

        $accountId = trim((string) $request->input('account_id'));
        $credentials = (array) $connector->credentials;
        $accounts = (array) ($credentials['available_accounts'] ?? []);
        $selected = collect($accounts)->first(function ($account) use ($accountId, $connector) {
            $candidate = $connector->type === 'facebook_page' ? ($account['page_id'] ?? null) : ($account['instagram_id'] ?? null);

            return is_string($candidate) && hash_equals($candidate, $accountId);
        });
        if (! is_array($selected)) {
            return response()->json(['status' => 422, 'message' => 'Choose an account granted to this application.'], 422);
        }

        $selectedId = $connector->type === 'facebook_page' ? (string) $selected['page_id'] : (string) $selected['instagram_id'];
        $selectedName = $connector->type === 'facebook_page' ? (string) $selected['page_name'] : (string) ($selected['instagram_name'] ?: $selected['page_name']);
        $credentials['access_token'] = $selected['page_access_token'];
        $credentials['expires_at'] = $selected['expires_at'] ?? null;
        unset($credentials['available_accounts']);

        $connector->forceFill([
            'external_account_id' => $selectedId,
            'account_name' => $selectedName,
            'url' => $connector->type === 'facebook_page'
                ? 'https://www.facebook.com/' . rawurlencode($selectedId)
                : 'https://www.instagram.com/' . rawurlencode(ltrim((string) ($selected['instagram_username'] ?? ''), '@')) . '/',
            'credentials' => $credentials,
            'connection_status' => 'connected',
            'connected_at' => now(),
            'disconnected_at' => null,
            'sync_enabled' => true,
            'next_sync_at' => $connector->schedule_enabled
                ? now((string) ($connector->schedule_timezone ?: 'UTC'))->addMinutes((int) ($connector->schedule_interval_minutes ?: 60))
                : null,
            'last_error' => null,
        ])->save();

        return response()->json(['status' => 1, 'data' => ['id' => (int) $connector->id, 'connection_status' => $connector->connection_status, 'account_name' => $connector->account_name]]);
    }

    private function completeYouTube(DataConnector $connector, string $code): void
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => config('signals_connectors.google.client_id'),
            'client_secret' => config('signals_connectors.google.client_secret'),
            'redirect_uri' => $this->redirectUri('youtube'),
            'grant_type' => 'authorization_code',
        ]);
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new SocialConnectorApiException($response->status(), 'permission_required');
        }
        $credentials = $this->tokenCredentials($response);
        $channels = Http::withToken($credentials['access_token'])->get('https://www.googleapis.com/youtube/v3/channels', [
            'part' => 'snippet',
            'mine' => 'true',
        ]);
        if (! $channels->successful() || ! is_string($channels->json('items.0.id'))) {
            throw new SocialConnectorApiException($channels->status(), $channels->status() === 403 ? 'permission_required' : 'error');
        }
        $channelId = $channels->json('items.0.id');
        $channelName = (string) $channels->json('items.0.snippet.title', 'YouTube channel');
        $connector->forceFill([
            'external_account_id' => $channelId,
            'account_name' => $channelName,
            'url' => 'https://www.youtube.com/channel/' . rawurlencode($channelId),
            'credentials' => $credentials,
            'connection_status' => 'connected',
            'connected_at' => now(),
            'disconnected_at' => null,
            'sync_enabled' => true,
            'next_sync_at' => $connector->schedule_enabled
                ? now((string) ($connector->schedule_timezone ?: 'UTC'))->addMinutes((int) ($connector->schedule_interval_minutes ?: 60))
                : null,
            'last_error' => null,
        ])->save();
    }

    private function completeLinkedIn(DataConnector $connector, string $code): void
    {
        $response = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('signals_connectors.linkedin.client_id'),
            'client_secret' => config('signals_connectors.linkedin.client_secret'),
            'redirect_uri' => $this->redirectUri('linkedin'),
        ]);
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new SocialConnectorApiException($response->status(), 'permission_required');
        }

        $organizationId = trim((string) $connector->external_account_id);
        $hasOrganization = $organizationId !== '' && ctype_digit($organizationId);
        $credentials = $this->tokenCredentials($response);
        $status = $hasOrganization ? 'connected' : 'permission_required';
        $error = $hasOrganization ? null : 'Enter the numeric LinkedIn organization ID for the account you administer.';
        if ($hasOrganization) {
            $probe = Http::withToken((string) $credentials['access_token'])
                ->withHeaders([
                    'Linkedin-Version' => (string) config('signals_connectors.linkedin.version'),
                    'X-Restli-Protocol-Version' => '2.0.0',
                ])
                ->get('https://api.linkedin.com/rest/posts', [
                    'author' => 'urn:li:organization:' . $organizationId,
                    'count' => 1,
                ]);
            if (! $probe->successful()) {
                $providerCode = (string) ($probe->json('error.code') ?? '');
                $status = match (true) {
                    $probe->status() === 401 => 'token_expired',
                    $probe->status() === 403 || in_array($providerCode, ['10', '200'], true) => 'permission_required',
                    $probe->status() === 429 => 'rate_limited',
                    default => 'error',
                };
                $error = (new SocialConnectorApiException($probe->status(), $status))->getMessage();
            }
        }
        $connector->forceFill([
            'credentials' => $credentials,
            'url' => $hasOrganization ? 'https://www.linkedin.com/company/' . rawurlencode($organizationId) : $connector->url,
            'account_name' => $hasOrganization ? ($connector->account_name ?: 'LinkedIn organization ' . $organizationId) : $connector->account_name,
            'connection_status' => $status,
            'connected_at' => now(),
            'disconnected_at' => null,
            'sync_enabled' => true,
            'next_sync_at' => $connector->schedule_enabled && $status === 'connected'
                ? now((string) ($connector->schedule_timezone ?: 'UTC'))->addMinutes((int) ($connector->schedule_interval_minutes ?: 60))
                : null,
            'last_error' => $error,
        ])->save();
    }

    private function completeMeta(DataConnector $connector, string $code): void
    {
        $tokenResponse = Http::get('https://graph.facebook.com/' . config('signals_connectors.meta.graph_version') . '/oauth/access_token', [
            'client_id' => config('signals_connectors.meta.app_id'),
            'client_secret' => config('signals_connectors.meta.app_secret'),
            'redirect_uri' => $this->redirectUri('meta'),
            'code' => $code,
        ]);
        $shortLivedToken = $tokenResponse->json('access_token');
        if (! $tokenResponse->successful() || ! is_string($shortLivedToken)) {
            throw new SocialConnectorApiException($tokenResponse->status(), 'permission_required');
        }

        $longLived = Http::get('https://graph.facebook.com/' . config('signals_connectors.meta.graph_version') . '/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('signals_connectors.meta.app_id'),
            'client_secret' => config('signals_connectors.meta.app_secret'),
            'fb_exchange_token' => $shortLivedToken,
        ]);
        if (! $longLived->successful() || ! is_string($longLived->json('access_token'))) {
            throw new SocialConnectorApiException($longLived->status(), 'permission_required');
        }

        $userToken = $longLived->json('access_token');
        $available = [];
        $after = null;
        $seenCursors = [];
        do {
            $query = [
                'fields' => 'id,name,access_token,instagram_business_account{id,username}',
                'limit' => 100,
            ];
            if ($after !== null) {
                $query['after'] = $after;
            }
            $pages = Http::withToken($userToken)->get('https://graph.facebook.com/' . config('signals_connectors.meta.graph_version') . '/me/accounts', $query);
            if (! $pages->successful()) {
                throw new SocialConnectorApiException($pages->status(), $pages->status() === 403 ? 'permission_required' : 'error');
            }
            foreach ((array) $pages->json('data', []) as $page) {
                if (! is_string($page['id'] ?? null) || ! is_string($page['access_token'] ?? null)) {
                    continue;
                }
                $instagram = (array) ($page['instagram_business_account'] ?? []);
                $available[] = [
                    'page_id' => $page['id'],
                    'page_name' => (string) ($page['name'] ?? 'Facebook Page'),
                    'page_access_token' => $page['access_token'],
                    'instagram_id' => is_string($instagram['id'] ?? null) ? $instagram['id'] : null,
                    'instagram_name' => is_string($instagram['username'] ?? null) ? $instagram['username'] : null,
                    'instagram_username' => is_string($instagram['username'] ?? null) ? $instagram['username'] : null,
                ];
            }
            $nextCursor = $pages->json('paging.cursors.after');
            $after = is_string($nextCursor) && $nextCursor !== '' && ! isset($seenCursors[$nextCursor]) ? $nextCursor : null;
            if ($after !== null) {
                $seenCursors[$after] = true;
            }
        } while ($after !== null);
        $matching = $connector->type === 'instagram_business'
            ? collect($available)->contains(fn (array $page) => is_string($page['instagram_id']))
            : $available !== [];
        if (! $matching) {
            throw new SocialConnectorApiException(403, 'permission_required');
        }

        $connector->forceFill([
            'credentials' => ['available_accounts' => $available],
            'connection_status' => 'permission_required',
            'connected_at' => now(),
            'disconnected_at' => null,
            'last_error' => 'Choose a Page or linked Instagram professional account to finish setup.',
        ])->save();
    }

    private function tokenCredentials(\Illuminate\Http\Client\Response $response): array
    {
        $credentials = ['access_token' => $response->json('access_token')];
        if (is_string($response->json('refresh_token'))) {
            $credentials['refresh_token'] = $response->json('refresh_token');
        }
        if (is_numeric($response->json('expires_in'))) {
            $credentials['expires_at'] = now()->addSeconds((int) $response->json('expires_in'))->toIso8601String();
        }

        return $credentials;
    }

    private function redirectUri(string $provider): string
    {
        return (string) config(match ($provider) {
            'youtube' => 'signals_connectors.google.redirect_uri',
            'linkedin' => 'signals_connectors.linkedin.redirect_uri',
            default => 'signals_connectors.meta.redirect_uri',
        });
    }

    private function returnToFrontend(string $result, ?int $connectorId)
    {
        $url = (string) config('signals_connectors.frontend_return_url');
        $separator = str_contains($url, '?') ? '&' : '?';

        return redirect()->to($url . $separator . http_build_query(array_filter([
            'connector_oauth' => $result,
            'connector_id' => $connectorId,
        ], fn ($value) => $value !== null)));
    }
}
