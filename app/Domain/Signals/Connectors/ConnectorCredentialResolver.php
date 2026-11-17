<?php

namespace App\Domain\Signals\Connectors;

use Illuminate\Support\Facades\Schema;

/**
 * The one place that answers "which application credentials does this organisation use for this provider?".
 *
 * PRECEDENCE (used by readiness, OAuth start, the code exchange, token refresh and revocation alike):
 *   1. the credentials an administrator saved for THIS organisation in the app (encrypted in the database), when both the id and the secret are present;
 *   2. otherwise the server environment (config/signals_connectors.php, fed by YOUTUBE_OAUTH_*, LINKEDIN_*, META_*), as before this existed;
 *   3. otherwise none.
 * A partial database row never mixes with the environment: a half-saved override is not used, and the environment stays in charge.
 *
 * Nothing is cached across requests: only a per-request memo, cleared by forget() whenever settings change, so a saved change is used by the very next
 * call and a secret is never written to a shared cache. Rotating a secret does not break stored tokens (they belong to the provider application, not to
 * the secret); changing the client id does, because tokens are bound to the application that issued them.
 */
class ConnectorCredentialResolver
{
    public const SOURCE_DATABASE = 'database';
    public const SOURCE_ENVIRONMENT = 'environment';
    public const SOURCE_NONE = 'none';

    /** provider => [config prefix, id key, secret key] */
    private const ENV = [
        'youtube' => ['google', 'client_id', 'client_secret'],
        'linkedin' => ['linkedin', 'client_id', 'client_secret'],
        'meta' => ['meta', 'app_id', 'app_secret'],
    ];

    /** @var array<string, array{client_id: ?string, client_secret: ?string, source: string, updated_at: ?string}> */
    private array $memo = [];
    private ?bool $tableExists = null;

    /** @return list<string> */
    public static function providers(): array
    {
        return array_keys(self::ENV);
    }

    /** The OAuth provider (youtube | linkedin | meta) a connector type signs in with, or null. */
    public static function providerFor(string $connectorType): ?string
    {
        return ConnectorProviderCatalog::oauthProvider($connectorType);
    }

    /** @return array{client_id: ?string, client_secret: ?string, source: string, updated_at: ?string} */
    public function resolve(string $provider, ?int $tenantId): array
    {
        $key = $provider . '|' . (int) $tenantId;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = $this->load($provider, $tenantId);
    }

    public function clientId(string $provider, ?int $tenantId): ?string
    {
        return $this->resolve($provider, $tenantId)['client_id'];
    }

    public function clientSecret(string $provider, ?int $tenantId): ?string
    {
        return $this->resolve($provider, $tenantId)['client_secret'];
    }

    /** The organisation's own saved row, if any (even a partial one). Never returned to a client. */
    public function saved(string $provider, int $tenantId): ?ConnectorProviderSetting
    {
        if (! $this->tableExists()) {
            return null;
        }
        try {
            return ConnectorProviderSetting::where('sub_institute_id', $tenantId)->where('provider', $provider)->first();
        } catch (\Throwable) {
            return null;   // an unreadable value (APP_KEY changed) is treated as no override, never as an error that leaks anything
        }
    }

    /** Call after any change to a saved setting. */
    public function forget(): void
    {
        $this->memo = [];
        $this->tableExists = null;
    }

    /** @return array{client_id: ?string, client_secret: ?string, source: string, updated_at: ?string} */
    private function load(string $provider, ?int $tenantId): array
    {
        if (! isset(self::ENV[$provider])) {
            return ['client_id' => null, 'client_secret' => null, 'source' => self::SOURCE_NONE, 'updated_at' => null];
        }

        if ($tenantId !== null && $tenantId > 0) {
            $row = $this->saved($provider, $tenantId);
            $id = $row ? trim((string) $row->client_id) : '';
            $secret = $row ? trim((string) $row->client_secret) : '';
            if ($row && $id !== '' && $secret !== '') {
                return ['client_id' => $id, 'client_secret' => $secret, 'source' => self::SOURCE_DATABASE, 'updated_at' => $row->updated_at?->toIso8601String()];
            }
        }

        [$prefix, $idKey, $secretKey] = self::ENV[$provider];
        $id = config("signals_connectors.{$prefix}.{$idKey}");
        $secret = config("signals_connectors.{$prefix}.{$secretKey}");
        $id = is_string($id) && trim($id) !== '' ? trim($id) : null;
        $secret = is_string($secret) && trim($secret) !== '' ? trim($secret) : null;

        // Anything present counts as the environment's answer (readiness then names whichever half is missing).
        return ['client_id' => $id, 'client_secret' => $secret, 'source' => $id !== null || $secret !== null ? self::SOURCE_ENVIRONMENT : self::SOURCE_NONE, 'updated_at' => null];
    }

    private function tableExists(): bool
    {
        try {
            return $this->tableExists ??= Schema::hasTable('g2g_connector_provider_settings');
        } catch (\Throwable) {
            return $this->tableExists = false;
        }
    }
}
