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

    public function fetch(DataConnector $connector): array
    {
        $pageId = trim((string) $connector->external_account_id);
        if ($pageId === '') {
            throw new SocialConnectorApiException(403, 'permission_required');
        }

        $token = $this->tokens->accessToken($connector);
        $maxItems = max(1, (int) config('signals_connectors.max_items', 25));
        $pageSize = min(100, $maxItems);
        $after = null;
        $seenCursors = [];
        $items = [];
        while (count($items) < $maxItems) {
            $query = [
                'fields' => 'id,message,created_time,permalink_url,from{id},is_published',
                'limit' => min($pageSize, $maxItems - count($items)),
            ];
            if ($after !== null) {
                $query['after'] = $after;
            }
            $response = Http::withToken($token)->get($this->graphUrl($pageId . '/feed'), $query);
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

        return $items;
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/' . config('signals_connectors.meta.graph_version') . '/' . $path;
    }
}
