<?php

namespace App\Domain\Signals\Connectors;

use App\Domain\Signals\Ingestion\DataConnector;
use Illuminate\Support\Facades\Http;

final class YouTubeDataApiConnector implements SocialDataConnectorAdapter
{
    public function __construct(private readonly SocialConnectorTokenManager $tokens)
    {
    }

    public function key(): string
    {
        return 'youtube';
    }

    public function label(): string
    {
        return 'YouTube';
    }

    public function fetch(DataConnector $connector): array
    {
        $token = $this->tokens->accessToken($connector);
        $channelId = (string) $connector->external_account_id;
        if ($channelId === '') {
            throw new SocialConnectorApiException(403, 'permission_required');
        }

        $channel = Http::withToken($token)->get('https://www.googleapis.com/youtube/v3/channels', [
            'part' => 'contentDetails',
            'id' => $channelId,
        ]);
        $this->tokens->assertResponse($channel);
        $uploadsId = $channel->json('items.0.contentDetails.relatedPlaylists.uploads');
        if (! is_string($uploadsId) || $uploadsId === '') {
            throw new SocialConnectorApiException(403, 'permission_required');
        }

        $items = [];
        $maxItems = max(1, (int) config('signals_connectors.max_items', 25));
        $pageSize = min(50, $maxItems);
        $pageToken = null;
        $seenPageTokens = [];
        do {
            $query = [
                'part' => 'snippet,contentDetails',
                'playlistId' => $uploadsId,
                'maxResults' => min($pageSize, $maxItems - count($items)),
            ];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $playlist = Http::withToken($token)->get('https://www.googleapis.com/youtube/v3/playlistItems', $query);
            $this->tokens->assertResponse($playlist);
            foreach ((array) $playlist->json('items', []) as $entry) {
                if (count($items) >= $maxItems) {
                    break;
                }
                $videoId = $entry['contentDetails']['videoId'] ?? null;
                $snippet = (array) ($entry['snippet'] ?? []);
                if (! is_string($videoId) || $videoId === '') {
                    continue;
                }
                $items[] = new SocialConnectorContent(
                    $videoId,
                    'https://www.youtube.com/watch?v=' . rawurlencode($videoId),
                    (string) ($snippet['title'] ?? 'YouTube video'),
                    trim((string) ($snippet['title'] ?? '') . "\n" . (string) ($snippet['description'] ?? '')),
                    is_string($entry['contentDetails']['videoPublishedAt'] ?? null) ? $entry['contentDetails']['videoPublishedAt'] : null,
                );
            }
            $nextPageToken = $playlist->json('nextPageToken');
            $pageToken = is_string($nextPageToken) && $nextPageToken !== '' && ! isset($seenPageTokens[$nextPageToken])
                ? $nextPageToken
                : null;
            if ($pageToken !== null) {
                $seenPageTokens[$pageToken] = true;
            }
        } while ($pageToken !== null && count($items) < $maxItems);

        return $items;
    }
}
