<?php

namespace App\Domain\Signals\Connectors;

use App\Domain\Signals\Ingestion\DataConnector;
use Illuminate\Support\Facades\Http;

final class YouTubeDataApiConnector implements SocialDataConnectorAdapter
{
    private const API = 'https://www.googleapis.com/youtube/v3/';

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

    /**
     * Reads, within one total item cap:
     *  1. every video, Short and live replay on the connected channel AND on every other channel the signed-in account owns,
     *  2. the viewer comments on the newest of those videos (where buyers ask questions and name their school),
     *  3. public YouTube videos from other channels matching the configured buyer-intent searches.
     * Only step 1 is required: a failure in 2 or 3 (comments disabled, search quota) is skipped, never fails the sync.
     */
    public function fetch(DataConnector $connector, ?callable $heartbeat = null): array
    {
        $token = $this->tokens->accessToken($connector);
        $channelId = (string) $connector->external_account_id;
        if ($channelId === '') {
            throw new SocialConnectorApiException(403, 'permission_required');
        }

        $maxItems = max(1, (int) config('signals_connectors.max_items', 60));
        $uploads = $this->uploadPlaylists($token, $channelId, $heartbeat);
        if ($uploads === []) {
            throw new SocialConnectorApiException(403, 'permission_required');
        }

        // 1. own videos / Shorts
        $videos = [];
        foreach ($uploads as $playlistId) {
            foreach ($this->playlistVideos($token, $playlistId, $maxItems, $heartbeat) as $id => $entry) {
                $videos[$id] ??= $entry;
            }
            if (count($videos) >= $maxItems) {
                break;
            }
        }
        $details = $this->videoDetails($token, array_keys($videos), $heartbeat);
        $items = [];
        foreach ($videos as $id => $entry) {
            $d = $details[$id] ?? [];
            $isShort = isset($d['seconds']) && $d['seconds'] > 0 && $d['seconds'] <= 60;
            $title = (string) (($d['title'] ?? '') ?: $entry['title'] ?: 'YouTube video');
            $description = (string) (($d['description'] ?? '') ?: $entry['description']);
            $text = trim(($isShort ? "[YouTube Short]\n" : '') . $title . "\n" . $description
                . (! empty($d['tags']) ? "\nTags: " . implode(', ', array_slice((array) $d['tags'], 0, 15)) : ''));
            $items[] = new SocialConnectorContent(
                $id,
                ($isShort ? 'https://www.youtube.com/shorts/' : 'https://www.youtube.com/watch?v=') . rawurlencode($id),
                $title,
                $text,
                $entry['published'],
            );
        }

        // 2. comments on the newest videos
        $commentVideos = max(0, (int) config('signals_connectors.youtube_comment_videos', 8));
        foreach (array_slice(array_keys($videos), 0, $commentVideos) as $id) {
            try {
                $response = Http::withToken($token)->timeout(15)->get(self::API . 'commentThreads', [
                    'part' => 'snippet', 'videoId' => $id, 'maxResults' => 25, 'order' => 'relevance', 'textFormat' => 'plainText',
                ]);
                $heartbeat && $heartbeat();
                $this->tokens->assertResponse($response);
            } catch (SocialConnectorApiException $e) {
                if ($e->connectionStatus === 'rate_limited') {
                    break;
                }

                continue; // comments are off for this video
            } catch (\Throwable) {
                continue;
            }
            $title = (string) ($details[$id]['title'] ?? $videos[$id]['title'] ?? 'video');
            foreach ((array) $response->json('items', []) as $thread) {
                $c = (array) ($thread['snippet']['topLevelComment']['snippet'] ?? []);
                $commentId = (string) ($thread['snippet']['topLevelComment']['id'] ?? '');
                $body = trim((string) ($c['textDisplay'] ?? ''));
                if ($commentId === '' || mb_strlen($body) < 25) {
                    continue;
                }
                $items[] = new SocialConnectorContent(
                    'yt-comment:' . $commentId,
                    'https://www.youtube.com/watch?v=' . rawurlencode($id) . '&lc=' . rawurlencode($commentId),
                    'Comment on: ' . $title,
                    'Viewer comment on the video "' . $title . '" by ' . (string) ($c['authorDisplayName'] ?? 'a viewer') . ":\n" . $body,
                    is_string($c['publishedAt'] ?? null) ? $c['publishedAt'] : null,
                );
            }
        }

        // 3. public videos from other channels that match buyer-intent searches
        $queries = array_slice(array_filter(array_map('trim', (array) config('signals_connectors.youtube_search_queries', []))), 0, 4);
        $seen = array_fill_keys(array_keys($videos), true);
        foreach ($queries as $query) {
            try {
                $response = Http::withToken($token)->timeout(15)->get(self::API . 'search', [
                    'part' => 'snippet', 'type' => 'video', 'q' => $query, 'maxResults' => 10, 'order' => 'date',
                    'publishedAfter' => now()->subDays(120)->utc()->format('Y-m-d\TH:i:s\Z'),
                ]);
                $heartbeat && $heartbeat();
                $this->tokens->assertResponse($response);
            } catch (\Throwable) {
                break; // quota or permission: the channel's own content is already collected
            }
            foreach ((array) $response->json('items', []) as $hit) {
                $id = (string) ($hit['id']['videoId'] ?? '');
                $sn = (array) ($hit['snippet'] ?? []);
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $title = (string) ($sn['title'] ?? '');
                $items[] = new SocialConnectorContent(
                    'yt-search:' . $id,
                    'https://www.youtube.com/watch?v=' . rawurlencode($id),
                    $title !== '' ? $title : 'YouTube video',
                    trim($title . "\nChannel: " . (string) ($sn['channelTitle'] ?? '') . "\n" . (string) ($sn['description'] ?? '')),
                    is_string($sn['publishedAt'] ?? null) ? $sn['publishedAt'] : null,
                );
            }
        }

        return $items;
    }

    /** Uploads playlist of the connected channel plus every channel this account owns (brand channels included). @return array<int, string> */
    private function uploadPlaylists(string $token, string $channelId, ?callable $heartbeat = null): array
    {
        $playlists = [];
        $primary = Http::withToken($token)->timeout(15)->get(self::API . 'channels', ['part' => 'contentDetails', 'id' => $channelId]);
        $heartbeat && $heartbeat();
        $this->tokens->assertResponse($primary);
        $id = $primary->json('items.0.contentDetails.relatedPlaylists.uploads');
        if (is_string($id) && $id !== '') {
            $playlists[] = $id;
        }
        try {
            $mine = Http::withToken($token)->timeout(15)->get(self::API . 'channels', ['part' => 'contentDetails', 'mine' => 'true', 'maxResults' => 50]);
            $heartbeat && $heartbeat();
            if ($mine->successful()) {
                foreach ((array) $mine->json('items', []) as $channel) {
                    $uploads = $channel['contentDetails']['relatedPlaylists']['uploads'] ?? null;
                    if (is_string($uploads) && $uploads !== '') {
                        $playlists[] = $uploads;
                    }
                }
            }
        } catch (\Throwable) {
            // the connected channel alone is enough
        }

        return array_values(array_unique($playlists));
    }

    /** @return array<string, array{title: string, description: string, published: ?string}> keyed by video id */
    private function playlistVideos(string $token, string $playlistId, int $limit, ?callable $heartbeat = null): array
    {
        $out = [];
        $pageToken = null;
        $maxPages = max(1, (int) config('signals_connectors.max_fetch_pages', 10) ?: 10);
        for ($page = 0; $page < $maxPages && count($out) < $limit; $page++) {
            $query = ['part' => 'snippet,contentDetails', 'playlistId' => $playlistId, 'maxResults' => min(50, $limit - count($out))];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $response = Http::withToken($token)->timeout(15)->get(self::API . 'playlistItems', $query);
            $heartbeat && $heartbeat();
            $this->tokens->assertResponse($response);
            foreach ((array) $response->json('items', []) as $entry) {
                $videoId = $entry['contentDetails']['videoId'] ?? null;
                if (! is_string($videoId) || $videoId === '' || count($out) >= $limit) {
                    continue;
                }
                $snippet = (array) ($entry['snippet'] ?? []);
                $out[$videoId] = [
                    'title' => (string) ($snippet['title'] ?? ''),
                    'description' => (string) ($snippet['description'] ?? ''),
                    'published' => is_string($entry['contentDetails']['videoPublishedAt'] ?? null) ? $entry['contentDetails']['videoPublishedAt'] : null,
                ];
            }
            $next = $response->json('nextPageToken');
            if (! is_string($next) || $next === '' || $next === $pageToken) {
                break;
            }
            $pageToken = $next;
        }

        return $out;
    }

    /** Full description, tags and length (to tell a Short) for up to 50 videos per call. Best effort. @return array<string, array<string, mixed>> */
    private function videoDetails(string $token, array $ids, ?callable $heartbeat = null): array
    {
        $out = [];
        foreach (array_chunk($ids, 50) as $chunk) {
            try {
                $response = Http::withToken($token)->timeout(15)->get(self::API . 'videos', ['part' => 'snippet,contentDetails', 'id' => implode(',', $chunk)]);
                $heartbeat && $heartbeat();
                if (! $response->successful()) {
                    continue;
                }
                foreach ((array) $response->json('items', []) as $video) {
                    $seconds = 0;
                    try {
                        $interval = new \DateInterval((string) ($video['contentDetails']['duration'] ?? ''));
                        $seconds = $interval->d * 86400 + $interval->h * 3600 + $interval->i * 60 + $interval->s;
                    } catch (\Throwable) {
                    }
                    $out[(string) ($video['id'] ?? '')] = [
                        'title' => (string) ($video['snippet']['title'] ?? ''),
                        'description' => (string) ($video['snippet']['description'] ?? ''),
                        'tags' => (array) ($video['snippet']['tags'] ?? []),
                        'seconds' => $seconds,
                    ];
                }
            } catch (\Throwable) {
            }
        }

        return $out;
    }
}
