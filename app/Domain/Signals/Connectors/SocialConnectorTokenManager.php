<?php

namespace App\Domain\Signals\Connectors;

use App\Domain\Signals\Ingestion\DataConnector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class SocialConnectorTokenManager
{
    /** Refresh this many seconds before the recorded expiry so a sync never starts with a token about to die. */
    private const REFRESH_MARGIN_SECONDS = 300;

    public function accessToken(DataConnector $connector): string
    {
        $credentials = (array) $connector->credentials;
        $token = trim((string) ($credentials['access_token'] ?? ''));
        if ($token === '') {
            throw new SocialConnectorApiException(401, 'permission_required', null, 'No access token is stored for this connector. Connect the account again.');
        }

        $expiresAt = isset($credentials['expires_at']) ? strtotime((string) $credentials['expires_at']) : false;
        if ($expiresAt !== false && $expiresAt <= time() + self::REFRESH_MARGIN_SECONDS) {
            return $this->refresh($connector);
        }

        return $token;
    }

    /**
     * Provider-specific refresh. Google issues long-lived refresh tokens; LinkedIn issues them only to
     * partner-approved apps; Meta has no refresh grant (long-lived tokens are re-authorised by the user).
     * A failed refresh is surfaced as token_expired, which stops scheduled syncs until the user reconnects,
     * so a revoked token is never retried in a loop.
     */
    private function refresh(DataConnector $connector): string
    {
        $endpoint = match ($connector->type) {
            'youtube' => ['https://oauth2.googleapis.com/token', 'google'],
            'linkedin' => ['https://www.linkedin.com/oauth/v2/accessToken', 'linkedin'],
            default => null,
        };
        $reconnect = 'The access token has expired. Reconnect this account to authorise it again.';

        if ($endpoint === null) {
            throw new SocialConnectorApiException(401, 'token_expired', null, $reconnect);
        }

        return Cache::lock("signals:connector-refresh:{$connector->sub_institute_id}:{$connector->id}", 30)->block(10, function () use ($connector, $endpoint, $reconnect) {
            // Another worker may have refreshed while this one waited for the lock.
            $connector->refresh();
            $credentials = (array) $connector->credentials;
            $currentExpiry = isset($credentials['expires_at']) ? strtotime((string) $credentials['expires_at']) : false;
            if (($credentials['access_token'] ?? '') !== '' && ($currentExpiry === false || $currentExpiry > time() + self::REFRESH_MARGIN_SECONDS)) {
                return (string) $credentials['access_token'];
            }

            $refreshToken = (string) ($credentials['refresh_token'] ?? '');
            if ($refreshToken === '') {
                throw new SocialConnectorApiException(401, 'token_expired', null, $reconnect);
            }

            [$url, $configKey] = $endpoint;
            $response = Http::asForm()->post($url, [
                'client_id' => config("signals_connectors.{$configKey}.client_id"),
                'client_secret' => config("signals_connectors.{$configKey}.client_secret"),
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);

            if ($response->status() === 429 || $response->serverError()) {
                // Transient: let the job retry; the stored credentials stay valid.
                throw new SocialConnectorApiException($response->status(), $response->status() === 429 ? 'rate_limited' : 'error');
            }
            $newToken = $response->json('access_token');
            if (! $response->successful() || ! is_string($newToken) || $newToken === '') {
                $revoked = in_array((string) ($response->json('error') ?? ''), ['invalid_grant', 'invalid_token', 'unauthorized_client'], true);
                throw new SocialConnectorApiException(
                    401,
                    'token_expired',
                    null,
                    $revoked
                        ? 'The platform no longer accepts the saved authorization (it was revoked or expired). Reconnect this account.'
                        : $reconnect,
                );
            }

            $credentials['access_token'] = $newToken;
            $credentials['expires_at'] = now()->addSeconds((int) $response->json('expires_in', 3600))->toIso8601String();
            if (is_string($response->json('refresh_token')) && $response->json('refresh_token') !== '') {
                $credentials['refresh_token'] = $response->json('refresh_token');
            }
            $connector->forceFill(['credentials' => $credentials])->save();

            return $newToken;
        });
    }

    public function assertResponse(\Illuminate\Http\Client\Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $providerCode = $response->json('error.code') ?? $response->json('error.errors.0.reason');
        $providerCode = (string) $providerCode;
        $rateLimited = $status === 429
            || in_array($providerCode, ['quotaExceeded', 'userRateLimitExceeded', 'dailyLimitExceeded', '4', '17', '32', '613'], true);
        $category = match (true) {
            $rateLimited => 'rate_limited',
            in_array($providerCode, ['190', '102'], true) || $status === 401 => 'token_expired',
            in_array($providerCode, ['10', '200'], true) || $status === 403 => 'permission_required',
            default => 'error',
        };
        $retryAfter = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT);

        throw new SocialConnectorApiException($status, $category, $retryAfter === false ? null : max(1, (int) $retryAfter));
    }
}
