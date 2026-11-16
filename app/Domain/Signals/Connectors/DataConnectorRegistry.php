<?php

namespace App\Domain\Signals\Connectors;

use InvalidArgumentException;

final class DataConnectorRegistry
{
    public function __construct(
        private readonly PublicWebPageConnector $website,
        private readonly YouTubeDataApiConnector $youtube,
        private readonly LinkedInOfficialConnector $linkedin,
        private readonly FacebookPageConnector $facebook,
        private readonly InstagramOfficialConnector $instagram,
        private readonly ConnectorProviderReadiness $readiness,
    ) {
    }

    /**
     * Provider list for the UI. `key`, `label`, `auth` and `requirements` are the original fields;
     * the rest comes from ConnectorProviderCatalog / ConnectorProviderReadiness (no secret values).
     */
    public function available(): array
    {
        $connectors = [];
        foreach ($this->adapters() as $adapter) {
            $definition = ConnectorProviderCatalog::find($adapter->key());
            $readiness = $this->readiness->check($adapter->key());
            $connectors[] = [
                'key' => $adapter->key(),
                'label' => $adapter->label(),
                'auth' => $definition['auth'] ?? ($adapter instanceof SocialDataConnectorAdapter ? 'oauth' : 'public_url'),
                'requirements' => $definition['requirements'] ?? [],
                'oauth_provider' => $definition['oauth_provider'] ?? null,
                'scopes' => $definition['scopes'] ?? [],
                'account_type' => $definition['account_type'] ?? null,
                'ready' => $readiness['ready'],
                'readiness_status' => $readiness['status'],
                'readiness_message' => $readiness['message'],
                'missing_config' => $readiness['missing_config'],
                'redirect_uri' => $readiness['redirect_uri'],
                'warnings' => $readiness['warnings'],
            ];
        }

        return $connectors;
    }

    public function get(string $key): DataConnectorAdapter|SocialDataConnectorAdapter
    {
        foreach ($this->adapters() as $adapter) {
            if ($adapter->key() === $key) {
                return $adapter;
            }
        }

        throw new InvalidArgumentException("Unsupported data connector: {$key}");
    }

    /** @return list<DataConnectorAdapter|SocialDataConnectorAdapter> */
    private function adapters(): array
    {
        return [$this->website, $this->youtube, $this->linkedin, $this->facebook, $this->instagram];
    }
}
