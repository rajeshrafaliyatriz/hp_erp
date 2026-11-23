<?php

namespace App\Domain\Signals\Connectors;

/**
 * Reports whether the deployment-level OAuth application for a connector type is configured.
 *
 * Only the NAMES of missing settings are ever returned, never their values. Nothing here
 * calls a provider, so "ready" means "configured", not "approved by the provider".
 */
final class ConnectorProviderReadiness
{
    public function __construct(private readonly ConnectorCredentialResolver $credentials)
    {
    }

    public const READY = 'ready';
    public const CONFIGURATION_REQUIRED = 'configuration_required';
    public const CONFIGURATION_INVALID = 'configuration_invalid';

    /**
     * What each setting must look like. A setting that is present but does not match is reported as invalid (by NAME only; the value is never shown or
     * returned). Deliberately loose: it catches a truncated, quoted or wrong-kind value, not a revoked credential, which only the provider can tell.
     */
    private const SHAPES = [
        'signals_connectors.google.client_id' => '/^\d+-[A-Za-z0-9_]+\.apps\.googleusercontent\.com$/',
        'signals_connectors.google.client_secret' => '/^\S{20,}$/',
        'signals_connectors.linkedin.client_id' => '/^[A-Za-z0-9]{8,32}$/',
        'signals_connectors.linkedin.client_secret' => '/^\S{8,}$/',
        'signals_connectors.meta.app_id' => '/^\d{8,20}$/',
        'signals_connectors.meta.app_secret' => '/^[A-Fa-f0-9]{32}$/',
    ];

    /**
     * @return array{
     *   ready: bool, status: string, message: string|null, missing_config: list<string>,
     *   redirect_uri: string|null, warnings: list<string>, invalid_config: list<string>
     * }
     */
    public function check(string $type, ?int $tenantId = null): array
    {
        $definition = ConnectorProviderCatalog::find($type);
        if ($definition === null) {
            return $this->result(false, self::CONFIGURATION_REQUIRED, 'This connector type is not supported.', [], null, []);
        }

        // Website connectors need no provider application.
        if ($definition['oauth_provider'] === null) {
            return $this->result(true, self::READY, null, [], null, []);
        }

        // Where the credentials come from (this organisation's saved ones, else the server environment) is the resolver's decision.
        $resolved = $this->credentials->resolve((string) $definition['oauth_provider'], $tenantId);
        $configKeys = array_keys($definition['config_keys']);
        $missing = [];
        $invalid = [];
        foreach ([[$configKeys[0], $resolved['client_id']], [$configKeys[1], $resolved['client_secret']]] as [$configKey, $value]) {
            $envName = $definition['config_keys'][$configKey];
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $envName;
            } elseif (isset(self::SHAPES[$configKey]) && preg_match(self::SHAPES[$configKey], $value) !== 1) {
                $invalid[] = $envName;
            }
        }

        $redirectUri = $this->redirectUri((string) $definition['oauth_provider']);
        $warnings = $this->deploymentWarnings($redirectUri);

        if ($missing !== []) {
            $result = $this->result(
                false,
                self::CONFIGURATION_REQUIRED,
                'The ' . $definition['label'] . ' application is not configured yet. An administrator must add its credentials in Connector Settings.',
                $missing,
                $redirectUri,
                $warnings,
            );
            $result['credential_source'] = $resolved['source'];

            return $result;
        }

        if ($invalid !== []) {
            $result = $this->result(
                false,
                self::CONFIGURATION_INVALID,
                'The ' . $definition['label'] . ' application settings are present but do not look valid (' . implode(', ', $invalid) . '). Check they were copied completely from the provider console, without quotes or spaces. Values are never shown.',
                [],
                $redirectUri,
                $warnings,
            );
            $result['invalid_config'] = $invalid;
            $result['credential_source'] = $resolved['source'];

            return $result;
        }

        $result = $this->result(true, self::READY, null, [], $redirectUri, $warnings);
        $result['credential_source'] = $resolved['source'];

        return $result;
    }

    /** @return array<string, array{ready: bool, status: string, message: string|null, missing_config: list<string>, redirect_uri: string|null, warnings: list<string>}> */
    public function all(?int $tenantId = null): array
    {
        $out = [];
        foreach (ConnectorProviderCatalog::keys() as $type) {
            $out[$type] = $this->check($type, $tenantId);
        }

        return $out;
    }

    public function redirectUri(string $oauthProvider): string
    {
        return (string) config(match ($oauthProvider) {
            'youtube' => 'signals_connectors.google.redirect_uri',
            'linkedin' => 'signals_connectors.linkedin.redirect_uri',
            default => 'signals_connectors.meta.redirect_uri',
        });
    }

    /** @return list<string> */
    private function deploymentWarnings(string $redirectUri): array
    {
        if (app()->environment(['local', 'testing'])) {
            return [];
        }

        $warnings = [];
        if ($this->isLocalUrl($redirectUri)) {
            $warnings[] = 'The OAuth redirect URI points at a local address. Set APP_URL (or the provider redirect URI setting) to the public API URL and register the same URI in the provider console.';
        }
        if ($this->isLocalUrl((string) config('signals_connectors.frontend_return_url'))) {
            $warnings[] = 'The post-authorization return URL points at a local address. Set SIGNALS_CONNECTORS_FRONTEND_URL or FRONTEND_URL to the public app URL.';
        }

        return $warnings;
    }

    private function isLocalUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    /** @param list<string> $missing @param list<string> $warnings */
    private function result(bool $ready, string $status, ?string $message, array $missing, ?string $redirectUri, array $warnings): array
    {
        return [
            'ready' => $ready,
            'status' => $status,
            'message' => $message,
            'missing_config' => $missing,
            'redirect_uri' => $redirectUri,
            'warnings' => $warnings,
            'invalid_config' => [],
            'credential_source' => ConnectorCredentialResolver::SOURCE_NONE,
        ];
    }
}
