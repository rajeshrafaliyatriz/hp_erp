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

    public function fetch(DataConnector $connector): array
    {
        $accountId = trim((string) $connector->external_account_id);
        if ($accountId === '') {
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
                'fields' => 'id,caption,permalink,timestamp,media_type',
                'limit' => min($pageSize, $maxItems - count($items)),
            ];
            if ($after !== null) {
                $query['after'] = $after;
            }
            $response = Http::withToken($token)->get($this->graphUrl($accountId . '/media'), $query);
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

        return $items;
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/' . config('signals_connectors.meta.graph_version') . '/' . $path;
    }
}
