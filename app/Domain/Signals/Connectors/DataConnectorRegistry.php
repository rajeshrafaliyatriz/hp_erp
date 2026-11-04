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
    ) {
    }

    /** @return list<array{key: string, label: string, auth: string, requirements: list<string>}> */
    public function available(): array
    {
        $connectors = [];
        foreach ([$this->website, $this->youtube, $this->linkedin, $this->facebook, $this->instagram] as $adapter) {
            $social = $adapter instanceof SocialDataConnectorAdapter;
            $connectors[] = [
                'key' => $adapter->key(),
                'label' => $adapter->label(),
                'auth' => $social ? 'oauth' : 'public_url',
                'requirements' => match ($adapter->key()) {
                    'youtube' => ['YouTube Data API v3 enabled in a Google Cloud project', 'OAuth scope youtube.readonly'],
                    'linkedin' => ['Approved LinkedIn Community Management API access', 'Organization-level r_organization_social permission', 'Organization numeric ID'],
                    'facebook_page' => ['Meta app with pages_show_list and pages_read_engagement permissions', 'Admin/editor access to the Facebook Page'],
                    'instagram_business' => ['Instagram professional account linked to an accessible Facebook Page', 'instagram_basic, pages_show_list and pages_read_engagement permissions'],
                    default => [],
                },
            ];
        }

        return $connectors;
    }

    public function get(string $key): DataConnectorAdapter|SocialDataConnectorAdapter
    {
        foreach ([$this->website, $this->youtube, $this->linkedin, $this->facebook, $this->instagram] as $adapter) {
            if ($adapter->key() === $key) {
                return $adapter;
            }
        }

        throw new InvalidArgumentException("Unsupported data connector: {$key}");
    }
}
