<?php

namespace App\Domain\Signals\Connectors;

use App\Domain\Signals\Ingestion\DataConnector;
use Illuminate\Support\Facades\Http;

final class InstagramOfficialConnector implements SocialDataConnectorAdapter
{
    public function __construct(private readonly SocialConnectorTokenManager $tokens)
    {
    }

    public function key(): string
    {
        return 'instagram_business';
    }

    public function label(): string
    {
        return 'Instagram professional account';
    }

    public function fetch(DataConnector $connector, ?callable $heartbeat = null): array
    {
        $accountId = trim((string) $connector->external_account_id);
        if ($accountId === '') {
            throw new SocialConnectorApiException(403, 'permission_required');
        }

        $token = $this->tokens->accessToken($connector);
        $monitor = ConnectorMonitoring::for($connector);
        $maxItems = $monitor->maxItems;
        $mediaUrls = [];
        $pageSize = min(100, $maxItems);
        $after = null;
        $seenCursors = [];
        $items = [];
        $maxPages = max(1, (int) config('signals_connectors.max_fetch_pages', 10) ?: 10);
        $pages = 0;
        while (count($items) < $maxItems && $pages < $maxPages) {
            $pages++;
            $query = [
                'fields' => 'id,caption,permalink,timestamp,media_type',
                'limit' => min($pageSize, $maxItems - count($items)),
            ];
            if ($after !== null) {
                $query['after'] = $after;
            }
            $response = Http::withToken($token)->get($this->graphUrl($accountId . '/media'), $query);
            $heartbeat && $heartbeat();
            $this->tokens->assertResponse($response);
            $mediaItems = (array) $response->json('data', []);
            foreach ($mediaItems as $media) {
                if (count($items) >= $maxItems) {
                    break;
                }
                $id = $media['id'] ?? null;
                $caption = $media['caption'] ?? null;
                $url = $media['permalink'] ?? null;
                if (! is_string($id) || ! is_string($url) || trim($url) === '') {
                    continue;
                }
                $caption = is_string($caption) ? trim($caption) : '';
                $mediaUrls[$id] = $url;
                if (! $monitor->allows('own_posts')) {
                    continue;   // read only so its comments can be fetched below
                }
                $items[] = new SocialConnectorContent(
                    $id,
                    $url,
                    mb_substr($caption ?: 'Instagram media', 0, 191),
                    $caption,
                    is_string($media['timestamp'] ?? null) ? $media['timestamp'] : null,
                );
            }
            $nextCursor = $response->json('paging.cursors.after');
            if ($mediaItems === [] || ! is_string($nextCursor) || $nextCursor === '' || isset($seenCursors[$nextCursor])) {
                break;
            }
            $after = $nextCursor;
            $seenCursors[$after] = true;
        }

        return array_merge(
            $items,
            $this->comments($token, $mediaUrls, $monitor, $heartbeat),
            $this->businessDiscovery($token, $accountId, $monitor, $heartbeat),
        );
    }

    /**
     * Comments on the account's newest media. Best effort: Meta returns them only with instagram_manage_comments, so a refusal is
     * skipped and the captions are still analysed.
     *
     * @param  array<string, string>  $mediaUrls  media id => permalink, newest first
     * @return list<SocialConnectorContent>
     */
    private function comments(string $token, array $mediaUrls, ConnectorMonitoring $monitor, ?callable $heartbeat): array
    {
        if (! $monitor->allows('comments') || $monitor->commentVideos < 1) {
            return [];
        }
        $out = [];
        foreach (array_slice($mediaUrls, 0, $monitor->commentVideos, true) as $mediaId => $mediaUrl) {
            try {
                $response = Http::withToken($token)->timeout(20)->get($this->graphUrl($mediaId . '/comments'), [
                    'fields' => 'id,text,timestamp,username', 'limit' => 25,
                ]);
                $heartbeat && $heartbeat();
                $this->tokens->assertResponse($response);
            } catch (SocialConnectorApiException $e) {
                if (in_array($e->connectionStatus, ['rate_limited', 'permission_required', 'token_expired'], true)) {
                    break;   // the same refusal would answer every remaining post: stop asking
                }

                continue;
            } catch (\Throwable) {
                continue;
            }
            foreach ((array) $response->json('data', []) as $comment) {
                $id = $comment['id'] ?? null;
                $body = trim((string) ($comment['text'] ?? ''));
                if (! is_string($id) || mb_strlen($body) < 25) {
                    continue;
                }
                $out[] = new SocialConnectorContent(
                    'ig-comment:' . $id,
                    $mediaUrl,
                    'Comment on an Instagram post',
                    'Instagram comment by @' . (string) ($comment['username'] ?? 'a follower') . ":\n" . $body,
                    is_string($comment['timestamp'] ?? null) ? $comment['timestamp'] : null,
                    'comments',
                );
            }
        }

        return $out;
    }

    /**
     * Recent media of other public Instagram Business/Creator accounts the organisation chose to monitor (Business Discovery). Meta
     * answers only for eligible professional accounts: an account that is private, personal or unknown is skipped, never scraped.
     *
     * @return list<SocialConnectorContent>
     */
    private function businessDiscovery(string $token, string $accountId, ConnectorMonitoring $monitor, ?callable $heartbeat): array
    {
        if (! $monitor->allows('discovery') || $monitor->targetAccounts === []) {
            return [];
        }
        $limit = max(1, min(25, $monitor->searchLimit));
        $out = [];
        foreach ($monitor->targetAccounts as $username) {
            try {
                $response = Http::withToken($token)->timeout(20)->get($this->graphUrl($accountId), [
                    'fields' => 'business_discovery.username(' . $username . '){username,name,media.limit(' . $limit . '){id,caption,permalink,timestamp}}',
                ]);
                $heartbeat && $heartbeat();
                $this->tokens->assertResponse($response);
            } catch (SocialConnectorApiException $e) {
                if (in_array($e->connectionStatus, ['rate_limited', 'permission_required', 'token_expired'], true)) {
                    break;   // the same refusal would answer every remaining post: stop asking
                }

                continue;   // not an eligible professional account, or not permitted
            } catch (\Throwable) {
                continue;
            }
            $account = (array) $response->json('business_discovery', []);
            foreach ((array) ($account['media']['data'] ?? []) as $media) {
                $id = $media['id'] ?? null;
                $url = $media['permalink'] ?? null;
                $caption = trim((string) ($media['caption'] ?? ''));
                if (! is_string($id) || ! is_string($url) || mb_strlen($caption) < 40) {
                    continue;
                }
                $out[] = new SocialConnectorContent(
                    'ig-discovery:' . $id,
                    $url,
                    mb_substr('Instagram post by @' . $username, 0, 191),
                    'Instagram post by @' . $username . (isset($account['name']) ? ' (' . (string) $account['name'] . ')' : '') . ":\n" . $caption,
                    is_string($media['timestamp'] ?? null) ? $media['timestamp'] : null,
                    'discovery',
                );
            }
        }

        return $out;
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/' . config('signals_connectors.meta.graph_version') . '/' . $path;
    }
}
