<?php

namespace Tests\Feature;

use App\Domain\Signals\Connectors\ConnectorProviderCatalog;
use App\Domain\Signals\Connectors\SocialConnectorApiException;
use App\Domain\Signals\Connectors\SocialConnectorTokenManager;
use App\Domain\Signals\Ingestion\DataConnector;
use App\Models\auth\tbluserModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Connector onboarding: provider readiness, OAuth start/callback, token lifecycle.
 *
 * ALL provider traffic is mocked with Http::fake(); these tests prove OUR handling of provider
 * responses, not that a live provider accepts the app. Runs on the in-memory sqlite database
 * from phpunit.xml.
 */
class DataConnectorOnboardingTest extends TestCase
{
    private const T1 = 1;
    private const FRONTEND = 'https://app.example.test/ingestion';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);

        Schema::create('personal_access_tokens', function ($t) {
            $t->id();
            $t->morphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluserprofilemaster', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('role_key')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->softDeletes();
        });
        Schema::create('tenant_setting', function ($t) {
            $t->id();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->string('setting_key');
            $t->text('setting_value')->nullable();
            $t->timestamps();
        });
        Schema::create('tbluser', function ($t) {
            $t->id();
            $t->string('user_name')->nullable();
            $t->unsignedBigInteger('sub_institute_id')->nullable();
            $t->unsignedBigInteger('user_profile_id')->nullable();
            $t->integer('status')->default(1);
        });
        (require base_path('database/migrations/2026_09_30_150000_create_g2g_opportunity_and_ingestion_tables.php'))->up();
        (require base_path('database/migrations/2026_11_04_120000_create_g2g_data_connector_tables.php'))->up();
        (require base_path('database/migrations/2026_11_04_140000_extend_g2g_data_connectors_for_social_sync.php'))->up();
        DB::table('tbluserprofilemaster')->insert([['id' => 1, 'name' => 'Administrator', 'role_key' => 'administrator']]);

        Cache::flush();
        config([
            'signals_connectors.frontend_return_url' => self::FRONTEND,
            'signals_connectors.google.client_id' => '1234567890-abcdefgh.apps.googleusercontent.com',
            'signals_connectors.google.client_secret' => 'GOCSPX-abcdefghijklmnopqrst',
            'signals_connectors.google.redirect_uri' => 'https://api.example.test/cb/youtube',
            'signals_connectors.linkedin.client_id' => 'liclient12345',
            'signals_connectors.linkedin.client_secret' => 'WPL_AP1.0123456789',
            'signals_connectors.meta.app_id' => '1234567890123456',
            'signals_connectors.meta.app_secret' => 'abcdef0123456789abcdef0123456789',
            'signals_connectors.meta.redirect_uri' => 'https://api.example.test/cb/meta',
        ]);
    }

    private function h(): array
    {
        $id = DB::table('tbluser')->insertGetId(['user_name' => 'u' . uniqid(), 'sub_institute_id' => self::T1, 'user_profile_id' => 1]);

        return ['Authorization' => 'Bearer ' . tbluserModel::find($id)->createToken('t')->plainTextToken];
    }

    private function connector(string $type, array $o = []): DataConnector
    {
        return DataConnector::create($o + [
            'sub_institute_id' => self::T1, 'type' => $type, 'name' => ucfirst($type) . ' connector',
            'product_key' => 'scholarclone', 'connection_status' => 'not_configured',
        ]);
    }

    /** Starts OAuth for a connector and returns the `state` the provider would echo back. */
    private function startOAuth(DataConnector $connector, array $headers): string
    {
        $url = $this->postJson("/api/signals/ingestion/connectors/{$connector->id}/oauth", [], $headers)
            ->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    private function callbackQuery(string $location): array
    {
        $this->assertStringStartsWith(self::FRONTEND, $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    // ── readiness ────────────────────────────────────────────────────────────

    public function test_catalog_is_the_single_source_for_registry_listing_and_validation(): void
    {
        $listed = collect($this->getJson('/api/signals/ingestion/connectors', $this->h())->assertOk()->json('available'))->pluck('key')->all();

        $this->assertSame(ConnectorProviderCatalog::keys(), $listed);
    }

    public function test_readiness_names_missing_settings_without_ever_exposing_secret_values(): void
    {
        config(['signals_connectors.google.client_id' => null, 'signals_connectors.google.client_secret' => null]);

        $response = $this->getJson('/api/signals/ingestion/connectors', $this->h())->assertOk();
        $byKey = collect($response->json('available'))->keyBy('key');

        $this->assertFalse($byKey['youtube']['ready']);
        $this->assertSame('configuration_required', $byKey['youtube']['readiness_status']);
        $this->assertEqualsCanonicalizing(['YOUTUBE_OAUTH_CLIENT_ID', 'YOUTUBE_OAUTH_CLIENT_SECRET'], $byKey['youtube']['missing_config']);
        $this->assertTrue($byKey['facebook_page']['ready']);
        $this->assertTrue($byKey['public_web_page']['ready'], 'a website needs no provider application');
        $this->assertSame('https://api.example.test/cb/meta', $byKey['instagram_business']['redirect_uri']);
        foreach (['GOCSPX-abcdefghijklmnopqrst', 'abcdef0123456789abcdef0123456789', 'WPL_AP1.0123456789'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_oauth_start_refuses_an_unconfigured_provider_and_issues_no_state(): void
    {
        config(['signals_connectors.google.client_secret' => '']);
        $connector = $this->connector('youtube');
        Http::fake();

        $this->postJson("/api/signals/ingestion/connectors/{$connector->id}/oauth", [], $this->h())
            ->assertStatus(503)
            ->assertJsonPath('code', 'provider_not_configured')
            ->assertJsonPath('missing_config.0', 'YOUTUBE_OAUTH_CLIENT_SECRET');
        Http::assertNothingSent();
        $this->assertSame('not_configured', $connector->fresh()->connection_status);
    }

    public function test_oauth_start_requests_only_the_scopes_the_connector_needs(): void
    {
        $facebook = $this->connector('facebook_page');
        $instagram = $this->connector('instagram_business');
        $youtube = $this->connector('youtube');
        $headers = $this->h();

        $fb = $this->postJson("/api/signals/ingestion/connectors/{$facebook->id}/oauth", [], $headers)->json('data.authorization_url');
        $ig = $this->postJson("/api/signals/ingestion/connectors/{$instagram->id}/oauth", [], $headers)->json('data.authorization_url');
        $yt = $this->postJson("/api/signals/ingestion/connectors/{$youtube->id}/oauth", [], $headers)->json('data.authorization_url');

        $this->assertStringNotContainsString('instagram_basic', urldecode($fb));
        $this->assertStringContainsString('instagram_basic', urldecode($ig));
        $this->assertStringContainsString('youtube.readonly', $yt);
        $this->assertStringContainsString('access_type=offline', $yt);
        $this->assertStringContainsString('redirect_uri=' . urlencode('https://api.example.test/cb/youtube'), $yt);
    }

    // ── callback / state ─────────────────────────────────────────────────────

    public function test_callback_rejects_unknown_expired_and_replayed_state(): void
    {
        $connector = $this->connector('youtube');
        Http::fake();

        $unknown = $this->get('/api/signals/ingestion/connectors/oauth/youtube/callback?state=forged&code=abc');
        $this->assertSame('invalid_state', $this->callbackQuery($unknown->headers->get('Location'))['reason']);

        $state = $this->startOAuth($connector, $this->h());
        $wrongProvider = $this->get("/api/signals/ingestion/connectors/oauth/meta/callback?state={$state}&code=abc");
        $this->assertSame('invalid_state', $this->callbackQuery($wrongProvider->headers->get('Location'))['reason']);

        // The wrong-provider attempt consumed the state (single use), so even the right provider cannot reuse it.
        $replay = $this->get("/api/signals/ingestion/connectors/oauth/youtube/callback?state={$state}&code=abc");
        $this->assertSame('invalid_state', $this->callbackQuery($replay->headers->get('Location'))['reason']);
        Http::assertNothingSent();
        $this->assertSame('not_configured', $connector->fresh()->connection_status);
    }

    public function test_callback_reports_user_cancellation_without_breaking_a_working_connection(): void
    {
        $connected = $this->connector('youtube', ['connection_status' => 'connected', 'credentials' => ['access_token' => 'keep-me']]);
        $fresh = $this->connector('youtube');
        $headers = $this->h();
        Http::fake();

        $state = $this->startOAuth($connected, $headers);
        $query = $this->callbackQuery($this->get("/api/signals/ingestion/connectors/oauth/youtube/callback?state={$state}&error=access_denied")->headers->get('Location'));
        $this->assertSame(['error', 'cancelled'], [$query['connector_oauth'], $query['reason']]);
        $this->assertSame('connected', $connected->fresh()->connection_status);
        $this->assertSame('keep-me', $connected->fresh()->credentials['access_token']);

        $state = $this->startOAuth($fresh, $headers);
        $this->get("/api/signals/ingestion/connectors/oauth/youtube/callback?state={$state}&error=access_denied");
        $this->assertStringContainsString('cancelled', $fresh->fresh()->last_error);
        $this->assertNotSame('connected', $fresh->fresh()->connection_status);
        Http::assertNothingSent();
    }

    public function test_youtube_oauth_exchanges_code_discovers_channel_and_stores_tokens_encrypted(): void
    {
        $connector = $this->connector('youtube');
        $headers = $this->h();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.ACCESS-TOKEN', 'refresh_token' => '1//REFRESH-TOKEN', 'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/youtube.readonly',
            ]),
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['id' => 'UC123', 'snippet' => ['title' => 'Acme Channel']]]]),
        ]);

        $state = $this->startOAuth($connector, $headers);
        $query = $this->callbackQuery($this->get("/api/signals/ingestion/connectors/oauth/youtube/callback?state={$state}&code=auth-code")->headers->get('Location'));

        $this->assertSame('success', $query['connector_oauth']);
        $fresh = $connector->fresh();
        $this->assertSame('connected', $fresh->connection_status);
        $this->assertSame('UC123', $fresh->external_account_id);
        $this->assertSame('Acme Channel', $fresh->account_name);

        $raw = (string) DB::table('g2g_data_connectors')->where('id', $connector->id)->value('credentials');
        $this->assertStringNotContainsString('ya29.ACCESS-TOKEN', $raw);
        $this->assertStringNotContainsString('1//REFRESH-TOKEN', $raw);
        $this->assertSame('1//REFRESH-TOKEN', $fresh->credentials['refresh_token']);

        $listing = $this->getJson('/api/signals/ingestion/connectors', $headers)->assertOk()->getContent();
        $this->assertStringNotContainsString('ya29.ACCESS-TOKEN', $listing);
        $this->assertStringNotContainsString('1//REFRESH-TOKEN', $listing);
    }

    public function test_youtube_oauth_with_a_declined_scope_is_not_marked_connected(): void
    {
        $connector = $this->connector('youtube');
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.X', 'expires_in' => 3600, 'scope' => 'openid email'])]);

        $state = $this->startOAuth($connector, $this->h());
        $query = $this->callbackQuery($this->get("/api/signals/ingestion/connectors/oauth/youtube/callback?state={$state}&code=c")->headers->get('Location'));

        $this->assertSame('error', $query['connector_oauth']);
        $this->assertSame('permission_required', $query['reason']);
        $this->assertSame('permission_required', $connector->fresh()->connection_status);
        $this->assertStringContainsString('youtube.readonly', $connector->fresh()->last_error);
        $this->assertNull($connector->fresh()->credentials['access_token'] ?? null);
    }

    public function test_redirect_uri_mismatch_is_reported_as_an_administrator_action(): void
    {
        $connector = $this->connector('youtube');
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'redirect_uri_mismatch'], 400)]);

        $state = $this->startOAuth($connector, $this->h());
        $this->get("/api/signals/ingestion/connectors/oauth/youtube/callback?state={$state}&code=c");

        $this->assertStringContainsString('redirect URI', $connector->fresh()->last_error);
        $this->assertNotSame('connected', $connector->fresh()->connection_status);
    }

    public function test_facebook_oauth_requires_every_permission_then_lists_pages_for_selection(): void
    {
        $connector = $this->connector('facebook_page');
        $headers = $this->h();
        $permissions = [['permission' => 'pages_show_list', 'status' => 'granted'], ['permission' => 'pages_read_engagement', 'status' => 'declined']];
        Http::fake(function ($request) use (&$permissions) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/oauth/access_token') => Http::response(['access_token' => 'meta-user-token']),
                str_contains($url, '/me/permissions') => Http::response(['data' => $permissions]),
                str_contains($url, '/me/accounts') => Http::response(['data' => [
                    ['id' => '555', 'name' => 'Acme Page', 'access_token' => 'PAGE-TOKEN-555', 'instagram_business_account' => ['id' => '777', 'username' => 'acme_ig']],
                ]]),
                default => Http::response([], 404),
            };
        });

        $state = $this->startOAuth($connector, $headers);
        $query = $this->callbackQuery($this->get("/api/signals/ingestion/connectors/oauth/meta/callback?state={$state}&code=c")->headers->get('Location'));
        $this->assertSame(['error', 'permission_required'], [$query['connector_oauth'], $query['reason']]);
        $this->assertStringContainsString('pages_read_engagement', $connector->fresh()->last_error);

        // Retry after granting everything.
        $permissions[1]['status'] = 'granted';
        $state = $this->startOAuth($connector, $headers);
        $query = $this->callbackQuery($this->get("/api/signals/ingestion/connectors/oauth/meta/callback?state={$state}&code=c")->headers->get('Location'));
        $this->assertSame(['pending', 'account_selection_required'], [$query['connector_oauth'], $query['reason']]);
        $this->assertNotSame('connected', $connector->fresh()->connection_status, 'a page has not been chosen yet');

        $listing = $this->getJson('/api/signals/ingestion/connectors', $headers)->assertOk();
        $this->assertSame([['id' => '555', 'name' => 'Acme Page']], $listing->json('data.0.available_accounts'));
        $this->assertStringNotContainsString('PAGE-TOKEN-555', $listing->getContent());

        $this->postJson("/api/signals/ingestion/connectors/{$connector->id}/accounts", ['account_id' => 'not-granted'], $headers)->assertStatus(422);
        $this->postJson("/api/signals/ingestion/connectors/{$connector->id}/accounts", ['account_id' => '555'], $headers)
            ->assertOk()->assertJsonPath('data.connection_status', 'connected')->assertJsonPath('data.account_name', 'Acme Page');
        $this->assertSame('PAGE-TOKEN-555', $connector->fresh()->credentials['access_token']);
        $this->assertArrayNotHasKey('available_accounts', $connector->fresh()->credentials);
    }

    // ── token lifecycle ──────────────────────────────────────────────────────

    public function test_google_token_is_refreshed_before_expiry_and_the_refresh_token_is_kept(): void
    {
        $connector = $this->connector('youtube', ['connection_status' => 'connected', 'credentials' => [
            'access_token' => 'old', 'refresh_token' => 'refresh-1', 'expires_at' => now()->addSeconds(30)->toIso8601String(),
        ]]);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-token', 'expires_in' => 3600])]);

        $this->assertSame('new-token', app(SocialConnectorTokenManager::class)->accessToken($connector));

        $stored = $connector->fresh()->credentials;
        $this->assertSame('new-token', $stored['access_token']);
        $this->assertSame('refresh-1', $stored['refresh_token'], 'Google does not re-issue the refresh token');
        $this->assertGreaterThan(now()->addMinutes(50)->timestamp, strtotime($stored['expires_at']));
        Http::assertSent(fn ($r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'refresh-1');
    }

    public function test_a_valid_token_is_not_refreshed(): void
    {
        $connector = $this->connector('youtube', ['credentials' => ['access_token' => 'still-good', 'refresh_token' => 'r', 'expires_at' => now()->addHour()->toIso8601String()]]);
        Http::fake();

        $this->assertSame('still-good', app(SocialConnectorTokenManager::class)->accessToken($connector));
        Http::assertNothingSent();
    }

    public function test_linkedin_refresh_uses_its_own_endpoint_and_stores_a_rotated_refresh_token(): void
    {
        $connector = $this->connector('linkedin', ['credentials' => ['access_token' => 'old', 'refresh_token' => 'li-r1', 'expires_at' => now()->subMinute()->toIso8601String()]]);
        Http::fake(['www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'li-new', 'expires_in' => 5184000, 'refresh_token' => 'li-r2'])]);

        $this->assertSame('li-new', app(SocialConnectorTokenManager::class)->accessToken($connector));
        $this->assertSame('li-r2', $connector->fresh()->credentials['refresh_token']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'linkedin.com') && $r['client_id'] === 'liclient12345');
    }

    public function test_a_revoked_refresh_token_requires_reconnecting(): void
    {
        $connector = $this->connector('youtube', ['connection_status' => 'connected', 'credentials' => [
            'access_token' => 'old', 'refresh_token' => 'revoked', 'expires_at' => now()->subMinute()->toIso8601String(),
        ]]);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        try {
            app(SocialConnectorTokenManager::class)->accessToken($connector);
            $this->fail('expected a token_expired failure');
        } catch (SocialConnectorApiException $e) {
            $this->assertSame('token_expired', $e->connectionStatus);
            $this->assertStringContainsString('Reconnect', $e->getMessage());
        }
    }

    public function test_a_provider_outage_during_refresh_is_transient_and_keeps_the_credentials(): void
    {
        $connector = $this->connector('youtube', ['credentials' => ['access_token' => 'old', 'refresh_token' => 'r', 'expires_at' => now()->subMinute()->toIso8601String()]]);
        Http::fake(['oauth2.googleapis.com/token' => Http::response('', 503)]);

        try {
            app(SocialConnectorTokenManager::class)->accessToken($connector);
            $this->fail('expected an API exception');
        } catch (SocialConnectorApiException $e) {
            $this->assertSame(503, $e->httpStatus);
            $this->assertNotSame('token_expired', $e->connectionStatus);
        }
        $this->assertSame('r', $connector->fresh()->credentials['refresh_token']);
    }

    public function test_meta_tokens_have_no_refresh_grant_so_expiry_asks_the_user_to_reconnect(): void
    {
        $connector = $this->connector('facebook_page', ['credentials' => ['access_token' => 'page', 'expires_at' => now()->subMinute()->toIso8601String()]]);
        Http::fake();

        try {
            app(SocialConnectorTokenManager::class)->accessToken($connector);
            $this->fail('expected token_expired');
        } catch (SocialConnectorApiException $e) {
            $this->assertSame('token_expired', $e->connectionStatus);
        }
        Http::assertNothingSent();
    }
}
