<?php

namespace App\Domain\Signals\Connectors;

use App\Domain\Signals\Ingestion\DataConnector;
use Illuminate\Support\Facades\Http;

final class LinkedInOfficialConnector implements SocialDataConnectorAdapter
{
    public function __construct(private readonly SocialConnectorTokenManager $tokens)
    {
    }

    public function key(): string
    {
        return 'linkedin';
    }

    public function label(): string
    {
        return 'LinkedIn organization posts';
    }

    public function fetch(DataConnector $connector): array
    {
        $organizationId = trim((string) $connector->external_account_id);
        if ($organizationId === '' || ! ctype_digit($organizationId)) {
            throw new SocialConnectorApiException(403, 'permission_required');
        }

        $author = 'urn:li:organization:' . $organizationId;
        $token = $this->tokens->accessToken($connector);
        $maxItems = max(1, (int) config('signals_connectors.max_items', 25));
        $pageSize = min(100, $maxItems);
        $start = 0;
        $items = [];
        while (count($items) < $maxItems) {
            $response = Http::withToken($token)
                ->withHeaders([
                    'Linkedin-Version' => (string) config('signals_connectors.linkedin.version'),
                    'X-Restli-Protocol-Version' => '2.0.0',
                ])
                ->get('https://api.linkedin.com/rest/posts', [
                    'author' => $author,
                    'start' => $start,
                    'count' => min($pageSize, $maxItems - count($items)),
                ]);
            $this->tokens->assertResponse($response);

            $posts = (array) $response->json('elements', []);
            if ($posts === []) {
                break;
            }
            foreach ($posts as $post) {
                if (count($items) >= $maxItems) {
                    break;
                }
                $id = $post['id'] ?? null;
                $commentary = $post['commentary'] ?? null;
                if (! is_string($id)) {
                    continue;
                }
                $commentary = is_string($commentary) ? trim($commentary) : '';
                $publishedAt = $post['publishedAt'] ?? null;
                $items[] = new SocialConnectorContent(
                    $id,
                    'https://www.linkedin.com/feed/update/' . rawurlencode($id),
                    mb_substr($commentary ?: 'LinkedIn post', 0, 191),
                    $commentary,
                    is_numeric($publishedAt) ? now()->setTimestamp((int) floor(((int) $publishedAt) / 1000))->toIso8601String() : null,
                );
            }
            $start += count($posts);
        }

        return $items;
    }
}
