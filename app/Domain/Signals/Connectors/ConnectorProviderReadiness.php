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
    public const READY = 'ready';
    public const CONFIGURATION_REQUIRED = 'configuration_required';

    /**
     * @return array{
     *   ready: bool, status: string, message: string|null, missing_config: list<string>,
     *   redirect_uri: string|null, warnings: list<string>
     * }
     */
    public function check(string $type): array
    {
        $definition = ConnectorProviderCatalog::find($type);
        if ($definition === null) {
            return $this->result(false, self::CONFIGURATION_REQUIRED, 'This connector type is not supported.', [], null, []);
        }

        // Website connectors need no provider application.
        if ($definition['oauth_provider'] === null) {
            return $this->result(true, self::READY, null, [], null, []);
        }

        $missing = [];
        foreach ($definition['config_keys'] as $configKey => $envName) {
            $value = config($configKey);
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $envName;
            }
        }

        $redirectUri = $this->redirectUri((string) $definition['oauth_provider']);
        $warnings = $this->deploymentWarnings($redirectUri);

        if ($missing !== []) {
            return $this->result(
                false,
                self::CONFIGURATION_REQUIRED,
                'The ' . $definition['label'] . ' application is not configured on this server. Ask an administrator to complete one-time provider setup.',
                $missing,
                $redirectUri,
                $warnings,
            );
        }

        return $this->result(true, self::READY, null, [], $redirectUri, $warnings);
    }

    /** @return array<string, array{ready: bool, status: string, message: string|null, missing_config: list<string>, redirect_uri: string|null, warnings: list<string>}> */
    public function all(): array
    {
        $out = [];
        foreach (ConnectorProviderCatalog::keys() as $type) {
            $out[$type] = $this->check($type);
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
        ];
    }
}
