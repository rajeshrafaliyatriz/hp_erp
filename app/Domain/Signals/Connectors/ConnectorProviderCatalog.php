<?php

namespace App\Domain\Signals\Connectors;

/**
 * Single source of truth for every data connector type: display metadata, how it authenticates,
 * which OAuth application it uses, the scopes it asks for and the deployment config it needs.
 *
 * Application-level credentials (client id / secret) are configured ONCE per deployment in
 * config/signals_connectors.php. Connector instances never carry their own developer credentials.
 */
final class ConnectorProviderCatalog
{
    public const AUTH_PUBLIC_URL = 'public_url';
    public const AUTH_OAUTH = 'oauth';

    /**
     * @return array<string, array{
     *   key: string, label: string, auth: string, oauth_provider: ?string, scopes: list<string>,
     *   config_keys: array<string, string>, account_type: ?string, requirements: list<string>
     * }>
     */
    public static function all(): array
    {
        return [
            'public_web_page' => [
                'key' => 'public_web_page',
                'label' => 'Public website or article',
                'auth' => self::AUTH_PUBLIC_URL,
                'oauth_provider' => null,
                'scopes' => [],
                'config_keys' => [],
                'account_type' => null,
                'requirements' => [],
            ],
            'youtube' => [
                'key' => 'youtube',
                'label' => 'YouTube',
                'auth' => self::AUTH_OAUTH,
                'oauth_provider' => 'youtube',
                'scopes' => ['https://www.googleapis.com/auth/youtube.readonly'],
                'config_keys' => [
                    'signals_connectors.google.client_id' => 'YOUTUBE_OAUTH_CLIENT_ID',
                    'signals_connectors.google.client_secret' => 'YOUTUBE_OAUTH_CLIENT_SECRET',
                ],
                'account_type' => 'channel',
                'requirements' => ['YouTube Data API v3 enabled in a Google Cloud project', 'OAuth scope youtube.readonly'],
            ],
            'linkedin' => [
                'key' => 'linkedin',
                'label' => 'LinkedIn',
                'auth' => self::AUTH_OAUTH,
                'oauth_provider' => 'linkedin',
                'scopes' => ['r_organization_social'],
                'config_keys' => [
                    'signals_connectors.linkedin.client_id' => 'LINKEDIN_CLIENT_ID',
                    'signals_connectors.linkedin.client_secret' => 'LINKEDIN_CLIENT_SECRET',
                ],
                'account_type' => 'organization',
                'requirements' => ['Approved LinkedIn Community Management API access', 'Organization-level r_organization_social permission', 'Organization numeric ID'],
            ],
            'facebook_page' => [
                'key' => 'facebook_page',
                'label' => 'Facebook Page',
                'auth' => self::AUTH_OAUTH,
                'oauth_provider' => 'meta',
                'scopes' => ['pages_show_list', 'pages_read_engagement'],
                'config_keys' => self::metaKeys(),
                'account_type' => 'page',
                'requirements' => ['Meta app with pages_show_list and pages_read_engagement permissions', 'Admin/editor access to the Facebook Page'],
            ],
            'instagram_business' => [
                'key' => 'instagram_business',
                'label' => 'Instagram',
                'auth' => self::AUTH_OAUTH,
                'oauth_provider' => 'meta',
                'scopes' => ['instagram_basic', 'pages_show_list', 'pages_read_engagement'],
                'config_keys' => self::metaKeys(),
                'account_type' => 'instagram_account',
                'requirements' => ['Instagram professional account linked to an accessible Facebook Page', 'instagram_basic, pages_show_list and pages_read_engagement permissions'],
            ],
        ];
    }

    /** @return array{key: string, label: string, auth: string, oauth_provider: ?string, scopes: list<string>, config_keys: array<string, string>, account_type: ?string, requirements: list<string>}|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** OAuth provider (youtube|linkedin|meta) used by a connector type, or null for non-OAuth connectors. */
    public static function oauthProvider(string $type): ?string
    {
        return self::find($type)['oauth_provider'] ?? null;
    }

    /** Scope string for an OAuth provider; connector types sharing a provider (Meta) get the union. */
    public static function scopesForProvider(string $provider): array
    {
        $scopes = [];
        foreach (self::all() as $definition) {
            if ($definition['oauth_provider'] === $provider) {
                $scopes = array_merge($scopes, $definition['scopes']);
            }
        }

        return array_values(array_unique($scopes));
    }

    /** @return array<string, string> */
    private static function metaKeys(): array
    {
        return [
            'signals_connectors.meta.app_id' => 'META_APP_ID',
            'signals_connectors.meta.app_secret' => 'META_APP_SECRET',
        ];
    }
}
