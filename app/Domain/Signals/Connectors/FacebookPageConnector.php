<?php

namespace App\Domain\Signals\Connectors;

use App\Domain\Signals\Ingestion\DataConnector;
use Illuminate\Support\Facades\Http;

final class FacebookPageConnector implements SocialDataConnectorAdapter
{
    public function __construct(private readonly SocialConnectorTokenManager $tokens)
    {
    }

    public function key(): string
    {
        return 'facebook_page';
    }

    public function label(): string
    {
        return 'Facebook Page';
    }

    public function fetch(DataConnector $connector, ?callable $heartbeat = null): array
    {
        $pageId = trim((string) $connector->external_account_id);
        if ($pageId === '') {
            throw new SocialConnectorApiException(403, 'permission_required');
        }

        $token = $this->tokens->accessToken($connector);
        $monitor = ConnectorMonitoring::for($connector);
        $maxItems = $monitor->maxItems;
        $postUrls = [];
        $pageSize = min(100, $maxItems);
        $after = null;
        $seenCursors = [];
        $items = [];
        // Posts by other users and unpublished posts are skipped below, so a busy Page feed could otherwise be paged without limit.
        $maxPages = max(1, (int) config('signals_connectors.max_fetch_pages', 10) ?: 10);
        $pages = 0;
        while (count($items) < $maxItems && $pages < $maxPages) {
            $pages++;
            $query = [
                'fields' => 'id,message,created_time,permalink_url,from{id},is_published',
                'limit' => min($pageSize, $maxItems - count($items)),
            ];
            if ($after !== null) {
                $query['after'] = $after;
            }
            $response = Http::withToken($token)->get($this->graphUrl($pageId . '/feed'), $query);
            $heartbeat && $heartbeat();
            $this->tokens->assertResponse($response);
            $posts = (array) $response->json('data', []);
            foreach ($posts as $post) {
                if (count($items) >= $maxItems) {
                    break;
                }
                $id = $post['id'] ?? null;
                $text = $post['message'] ?? null;
                $url = $post['permalink_url'] ?? null;
                if (($post['from']['id'] ?? null) !== $pageId
                    || ($post['is_published'] ?? false) !== true
                    || ! is_string($id)
                    || ! is_string($url)
                    || trim($url) === '') {
                    continue;
                }
                $text = is_string($text) ? trim($text) : '';
                $postUrls[$id] = $url;
                if (! $monitor->allows('own_posts')) {
                    continue;   // read only so its comments can be fetched below
                }
                $items[] = new SocialConnectorContent(
                    $id,
                    $url,
                    mb_substr($text ?: 'Facebook Page post', 0, 191),
                    $text,
                    is_string($post['created_time'] ?? null) ? $post['created_time'] : null,
                );
            }
            $nextCursor = $response->json('paging.cursors.after');
            if ($posts === [] || ! is_string($nextCursor) || $nextCursor === '' || isset($seenCursors[$nextCursor])) {
                break;
            }
            $after = $nextCursor;
            $seenCursors[$after] = true;
        }

        return array_merge($items, $this->comments($token, $postUrls, $pageId, $monitor, $heartbeat));
    }

    /**
     * Comments other people left on the Page's newest posts (where a school may name itself and its need). Best effort: Meta only returns
     * them with the pages_read_user_content permission, so a refusal is skipped (the posts themselves are still analysed), never fatal.
     *
     * @param  array<string, string>  $postUrls  post id => permalink, newest first
     * @return list<SocialConnectorContent>
     */
    private function comments(string $token, array $postUrls, string $pageId, ConnectorMonitoring $monitor, ?callable $heartbeat): array
    {
        if (! $monitor->allows('comments') || $monitor->commentVideos < 1) {
            return [];
        }
        $out = [];
        foreach (array_slice($postUrls, 0, $monitor->commentVideos, true) as $postId => $postUrl) {
            try {
                $response = Http::withToken($token)->timeout(20)->get($this->graphUrl($postId . '/comments'), [
                    'fields' => 'id,message,created_time,permalink_url,from{id,name}', 'limit' => 25,
                ]);
                $heartbeat && $heartbeat();
                $this->tokens->assertResponse($response);
            } catch (SocialConnectorApiException $e) {
                if (in_array($e->connectionStatus, ['rate_limited', 'permission_required', 'token_expired'], true)) {
                    break;   // the same refusal would answer every remaining post: stop asking
                }

                continue;   // comments not permitted for this post
            } catch (\Throwable) {
                continue;
            }
            foreach ((array) $response->json('data', []) as $comment) {
                $id = $comment['id'] ?? null;
                $body = trim((string) ($comment['message'] ?? ''));
                if (! is_string($id) || mb_strlen($body) < 25 || (string) ($comment['from']['id'] ?? '') === $pageId) {
                    continue;
                }
                $out[] = new SocialConnectorContent(
                    'fb-comment:' . $id,
                    is_string($comment['permalink_url'] ?? null) && $comment['permalink_url'] !== '' ? $comment['permalink_url'] : $postUrl,
                    'Comment on a Facebook post',
                    'Facebook comment by ' . (string) ($comment['from']['name'] ?? 'a visitor') . ":\n" . $body,
                    is_string($comment['created_time'] ?? null) ? $comment['created_time'] : null,
                    'comments',
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
