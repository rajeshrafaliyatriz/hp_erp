<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Connectors\FacebookPageConnector;
use App\Domain\Signals\Connectors\InstagramOfficialConnector;
use App\Domain\Signals\Connectors\LinkedInOfficialConnector;
use App\Domain\Signals\Connectors\SocialConnectorApiException;
use App\Domain\Signals\Connectors\YouTubeDataApiConnector;
use App\Domain\Signals\Ingestion\DataConnector;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SocialDataConnectorAdapterTest extends TestCase
{
    public function test_youtube_reads_only_the_authorized_channel_uploads_and_uses_video_publication_time(): void
    {
        config(['signals_connectors.max_items' => 2]);
        $playlistPage = 0;
        Http::fake([
            'www.googleapis.com/youtube/v3/channels*' => Http::response([
                'items' => [['contentDetails' => ['relatedPlaylists' => ['uploads' => 'uploads-1']]]],
            ]),
            'www.googleapis.com/youtube/v3/playlistItems*' => function () use (&$playlistPage) {
                $playlistPage++;
                if ($playlistPage === 1) {
                    return Http::response([
                        'items' => [[
                            'snippet' => ['title' => 'Hiring update', 'description' => 'The company announced a new plant and hiring plans.'],
                            'contentDetails' => ['videoId' => 'video-1', 'videoPublishedAt' => '2026-10-10T12:30:00Z'],
                        ]],
                        'nextPageToken' => 'youtube-page-2',
                    ]);
                }

                return Http::response([
                    'items' => [[
                        'snippet' => ['title' => 'Expansion update', 'description' => 'A second announcement about a new plant.'],
                        'contentDetails' => ['videoId' => 'video-2', 'videoPublishedAt' => '2026-10-11T12:30:00Z'],
                    ]],
                ]);
            },
        ]);

        $items = app(YouTubeDataApiConnector::class)->fetch($this->connector('youtube', 'UC-authorized'));

        $this->assertCount(2, $items);
        $this->assertSame('video-1', $items[0]->externalId);
        $this->assertSame('https://www.youtube.com/watch?v=video-1', $items[0]->url);
        $this->assertSame('2026-10-10T12:30:00Z', $items[0]->publishedAt);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/channels')
            && $request->hasHeader('Authorization', 'Bearer test-access-token')
            && $request['id'] === 'UC-authorized');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/playlistItems')
            && ($request->data()['pageToken'] ?? null) === 'youtube-page-2');
    }

    public function test_linkedin_reads_organization_posts_with_required_version_headers(): void
    {
        config(['signals_connectors.linkedin.version' => '202610']);
        config(['signals_connectors.max_items' => 2]);
        Http::fake(function ($request) {
            return (int) $request['start'] === 0
                ? Http::response(['elements' => [[
                    'id' => 'urn:li:share:post-1',
                    'commentary' => 'The organization announced a new manufacturing facility.',
                    'createdAt' => 1000,
                    'publishedAt' => 2000,
                ]]])
                : Http::response(['elements' => [[
                    'id' => 'urn:li:share:post-2',
                    'commentary' => 'The organization announced a second manufacturing facility.',
                ]]]);
        });

        $items = app(LinkedInOfficialConnector::class)->fetch($this->connector('linkedin', '12345'));

        $this->assertCount(2, $items);
        $this->assertSame('urn:li:share:post-1', $items[0]->externalId);
        $this->assertSame('The organization announced a new manufacturing facility.', $items[0]->text);
        $this->assertSame(now()->setTimestamp(2)->toIso8601String(), $items[0]->publishedAt);
        $this->assertNull($items[1]->publishedAt);
        Http::assertSent(fn ($request) => $request->hasHeader('Linkedin-Version', '202610')
            && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0')
            && $request['author'] === 'urn:li:organization:12345'
            && ! isset($request->data()['q']));
        Http::assertSent(fn ($request) => $request['start'] === 1);
    }

    public function test_facebook_reads_only_page_posts(): void
    {
        Http::fake(function ($request) {
            return ($request->data()['after'] ?? null) === null
                ? Http::response([
                    'data' => [
                        [
                            'id' => 'visitor-post',
                            'from' => ['id' => 'visitor-1'],
                            'is_published' => true,
                            'message' => 'A visitor post that must not be ingested.',
                            'permalink_url' => 'https://www.facebook.com/page/posts/visitor',
                        ],
                        [
                            'id' => 'page-42_unpublished',
                            'from' => ['id' => 'page-42'],
                            'is_published' => false,
                            'message' => 'An unpublished Page post that must not be ingested.',
                            'permalink_url' => 'https://www.facebook.com/page/posts/unpublished',
                        ],
                        [
                            'id' => 'page-42_post-1',
                            'from' => ['id' => 'page-42'],
                            'is_published' => true,
                            'message' => 'The Page announced a new regional office and hiring.',
                            'created_time' => '2026-10-20T08:30:00+0000',
                            'permalink_url' => 'https://www.facebook.com/page/posts/post-1',
                        ],
                    ],
                    'paging' => ['cursors' => ['after' => 'facebook-cursor-2']],
                ])
                : Http::response(['data' => [[
                    'id' => 'page-42_post-2',
                    'from' => ['id' => 'page-42'],
                    'is_published' => true,
                    'message' => 'The Page announced another regional office and hiring.',
                    'created_time' => '2026-10-21T08:30:00+0000',
                    'permalink_url' => 'https://www.facebook.com/page/posts/post-2',
                ]]]);
        });

        $items = app(FacebookPageConnector::class)->fetch($this->connector('facebook_page', 'page-42'));

        $this->assertCount(2, $items);
        $this->assertSame('page-42_post-1', $items[0]->externalId);
        $this->assertSame('2026-10-20T08:30:00+0000', $items[0]->publishedAt);
        Http::assertSent(fn ($request) => str_contains($request['fields'], 'is_published'));
        Http::assertSent(fn ($request) => ($request->data()['after'] ?? null) === 'facebook-cursor-2');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/page-42/feed')
            && $request->hasHeader('Authorization', 'Bearer test-access-token'));
    }

    public function test_instagram_reads_media_for_the_authorized_professional_account(): void
    {
        Http::fake(function ($request) {
            return ($request->data()['after'] ?? null) === null
                ? Http::response([
                    'data' => [[
                        'id' => 'media-1',
                        'caption' => 'The company posted a product launch and expansion update.',
                        'permalink' => 'https://www.instagram.com/p/media-1/',
                        'timestamp' => '2026-10-22T10:00:00+0000',
                    ]],
                    'paging' => ['cursors' => ['after' => 'instagram-cursor-2']],
                ])
                : Http::response(['data' => [[
                    'id' => 'media-2',
                    'caption' => 'The company posted a second product launch and expansion update.',
                    'permalink' => 'https://www.instagram.com/p/media-2/',
                    'timestamp' => '2026-10-23T10:00:00+0000',
                ]]]);
        });

        $items = app(InstagramOfficialConnector::class)->fetch($this->connector('instagram_business', 'ig-77'));

        $this->assertCount(2, $items);
        $this->assertSame('media-1', $items[0]->externalId);
        $this->assertSame('https://www.instagram.com/p/media-1/', $items[0]->url);
        Http::assertSent(fn ($request) => ($request->data()['after'] ?? null) === 'instagram-cursor-2');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/ig-77/media')
            && $request->hasHeader('Authorization', 'Bearer test-access-token'));
    }

    public function test_platform_errors_keep_truthful_permission_and_rate_limit_states(): void
    {
        $this->freshHttp();
        Http::fake([
            'api.linkedin.com/rest/posts*' => Http::response(['message' => 'Forbidden'], 403),
        ]);
        try {
            app(LinkedInOfficialConnector::class)->fetch($this->connector('linkedin', '123'));
            $this->fail('A denied API request must fail the sync.');
        } catch (SocialConnectorApiException $exception) {
            $this->assertSame('permission_required', $exception->connectionStatus);
        }

        $this->freshHttp();
        Http::fake([
            'graph.facebook.com/v26.0/page-1/feed*' => Http::response([], 429, ['Retry-After' => '120']),
        ]);
        try {
            app(FacebookPageConnector::class)->fetch($this->connector('facebook_page', 'page-1'));
            $this->fail('A rate-limited API request must be retried.');
        } catch (SocialConnectorApiException $exception) {
            $this->assertSame('rate_limited', $exception->connectionStatus);
            $this->assertSame(120, $exception->retryAfterSeconds);
        }

        $this->freshHttp();
        Http::fake([
            'graph.facebook.com/v26.0/page-1/feed*' => Http::response(['error' => ['code' => 190]], 400),
        ]);
        try {
            app(FacebookPageConnector::class)->fetch($this->connector('facebook_page', 'page-1'));
            $this->fail('An expired Meta token must not be reported as a generic API error.');
        } catch (SocialConnectorApiException $exception) {
            $this->assertSame('token_expired', $exception->connectionStatus);
        }
    }

    private function connector(string $type, string $accountId): DataConnector
    {
        $connector = new DataConnector();
        $connector->forceFill([
            'type' => $type,
            'external_account_id' => $accountId,
            'credentials' => ['access_token' => 'test-access-token'],
        ]);

        return $connector;
    }

    private function freshHttp(): void
    {
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstance();
    }
}
