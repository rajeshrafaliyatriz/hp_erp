<?php

namespace App\Domain\Signals\Connectors;

use App\Domain\Signals\Ingestion\DataConnector;
use Illuminate\Support\Facades\Http;

final class SocialConnectorTokenManager
{
    public function accessToken(DataConnector $connector): string
    {
        $credentials = (array) $connector->credentials;
        $token = trim((string) ($credentials['access_token'] ?? ''));
        if ($token === '') {
            throw new SocialConnectorApiException(401, 'permission_required');
        }

        $expiresAt = isset($credentials['expires_at']) ? strtotime((string) $credentials['expires_at']) : null;
        if ($expiresAt !== null && $expiresAt <= time() + 60) {
            if ($connector->type !== 'youtube' || empty($credentials['refresh_token'])) {
                throw new SocialConnectorApiException(401, 'token_expired');
            }

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('signals_connectors.google.client_id'),
                'client_secret' => config('signals_connectors.google.client_secret'),
                'refresh_token' => $credentials['refresh_token'],
                'grant_type' => 'refresh_token',
            ]);
            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new SocialConnectorApiException($response->status(), 'token_expired');
            }

            $token = $response->json('access_token');
            $credentials['access_token'] = $token;
            $credentials['expires_at'] = now()->addSeconds((int) $response->json('expires_in', 3600))->toIso8601String();
            $connector->forceFill(['credentials' => $credentials])->save();
        }

        return $token;
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
