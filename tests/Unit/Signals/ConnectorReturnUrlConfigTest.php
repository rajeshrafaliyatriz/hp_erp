<?php

namespace Tests\Unit\Signals;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Where the browser lands after provider sign-in (`signals_connectors.frontend_return_url`) and how it relates
 * to the API callback URLs.
 *
 * The real config/signals_connectors.php is evaluated in a clean PHP subprocess with an explicit environment, so
 * the machine's own .env can neither influence nor be read by these tests. Nothing here touches a provider, a
 * database or a network.
 */
class ConnectorReturnUrlConfigTest extends TestCase
{
    private const API = 'https://api.example.com';

    /**
     * @param array<string, string> $env
     * @return array<string, mixed> the evaluated config array
     */
    private function evaluate(array $env): array
    {
        $script = <<<'PHP'
        function env($key, $default = null) { $v = getenv($key); return $v === false ? $default : $v; }
        echo json_encode(require $argv[1]);
        PHP;

        // A child process inherits this process's environment, which on a developer machine includes values loaded
        // from the real .env. Every variable the config reads is therefore explicitly UNSET (false) unless the test
        // supplies it, so results never depend on, or reveal, local settings.
        $unset = array_fill_keys([
            'APP_URL', 'FRONTEND_URL', 'SIGNALS_CONNECTORS_FRONTEND_URL',
            'SIGNALS_CONNECTOR_MAX_ITEMS', 'SIGNALS_CONNECTOR_SCHEDULE_ENABLED',
            'YOUTUBE_OAUTH_CLIENT_ID', 'YOUTUBE_OAUTH_CLIENT_SECRET', 'YOUTUBE_OAUTH_REDIRECT_URI',
            'LINKEDIN_CLIENT_ID', 'LINKEDIN_CLIENT_SECRET', 'LINKEDIN_API_VERSION', 'LINKEDIN_OAUTH_REDIRECT_URI',
            'META_APP_ID', 'META_APP_SECRET', 'META_GRAPH_API_VERSION', 'META_OAUTH_REDIRECT_URI',
        ], false);
        $process = new Process([PHP_BINARY, '-r', $script, '--', base_path('config/signals_connectors.php')], base_path(), $env + $unset);
        $process->mustRun();

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function returnUrl(array $env): string
    {
        return $this->evaluate($env)['frontend_return_url'];
    }

    public function test_origin_without_trailing_slash_gets_the_ingestion_route(): void
    {
        $this->assertSame('https://app.example.com/ingestion', $this->returnUrl(['FRONTEND_URL' => 'https://app.example.com']));
    }

    public function test_origin_with_trailing_slash_does_not_double_the_slash(): void
    {
        $this->assertSame('https://app.example.com/ingestion', $this->returnUrl(['FRONTEND_URL' => 'https://app.example.com/']));
        $this->assertSame('https://app.example.com/ingestion', $this->returnUrl(['FRONTEND_URL' => 'https://app.example.com//']));
    }

    public function test_local_development_origin(): void
    {
        $this->assertSame('http://localhost:3000/ingestion', $this->returnUrl(['FRONTEND_URL' => 'http://localhost:3000']));
        $this->assertSame('http://localhost:3000/ingestion', $this->returnUrl(['FRONTEND_URL' => 'http://localhost:3000/']));
        $this->assertSame('http://127.0.0.1:3000/ingestion', $this->returnUrl(['FRONTEND_URL' => 'http://127.0.0.1:3000']));
    }

    public function test_production_style_https_origin(): void
    {
        $this->assertSame('https://g2g.example.org/ingestion', $this->returnUrl(['FRONTEND_URL' => 'https://g2g.example.org']));
        $this->assertSame('https://g2g.example.org:8443/ingestion', $this->returnUrl(['FRONTEND_URL' => 'https://g2g.example.org:8443']));
    }

    public function test_a_frontend_url_that_already_carries_a_path_is_not_given_a_second_ingestion_segment(): void
    {
        $this->assertSame('https://app.example.com/ingestion', $this->returnUrl(['FRONTEND_URL' => 'https://app.example.com/ingestion']));
        $this->assertStringNotContainsString('/ingestion/ingestion', $this->returnUrl(['FRONTEND_URL' => 'https://app.example.com/ingestion']));
    }

    public function test_the_explicit_connector_setting_wins_and_is_used_exactly_as_given(): void
    {
        $this->assertSame('https://signals.example.com/custom/return', $this->returnUrl([
            'SIGNALS_CONNECTORS_FRONTEND_URL' => 'https://signals.example.com/custom/return',
            'FRONTEND_URL' => 'https://app.example.com',
        ]));
        $this->assertSame('https://signals.example.com', $this->returnUrl([
            'SIGNALS_CONNECTORS_FRONTEND_URL' => 'https://signals.example.com',
        ]), 'an explicit value is trusted verbatim, never rewritten');
    }

    public function test_blank_settings_fall_back_to_the_local_default_which_readiness_flags_outside_local_environments(): void
    {
        $this->assertSame('http://localhost:3000/ingestion', $this->returnUrl([]));
        $this->assertSame('http://localhost:3000/ingestion', $this->returnUrl(['FRONTEND_URL' => '', 'SIGNALS_CONNECTORS_FRONTEND_URL' => '  ']));
    }

    public function test_api_callback_urls_stay_on_the_api_and_are_independent_of_the_frontend_url(): void
    {
        $config = $this->evaluate(['APP_URL' => self::API, 'FRONTEND_URL' => 'https://app.example.com']);

        $this->assertSame(self::API . '/api/signals/ingestion/connectors/oauth/youtube/callback', $config['google']['redirect_uri']);
        $this->assertSame(self::API . '/api/signals/ingestion/connectors/oauth/linkedin/callback', $config['linkedin']['redirect_uri']);
        $this->assertSame(self::API . '/api/signals/ingestion/connectors/oauth/meta/callback', $config['meta']['redirect_uri']);
        $this->assertSame('https://app.example.com/ingestion', $config['frontend_return_url']);
        foreach (['google', 'linkedin', 'meta'] as $provider) {
            $this->assertStringNotContainsString('app.example.com', $config[$provider]['redirect_uri']);
        }
        $this->assertStringNotContainsString('api.example.com', $config['frontend_return_url']);
    }

    public function test_a_provider_redirect_override_does_not_change_the_frontend_return_url(): void
    {
        $config = $this->evaluate([
            'APP_URL' => self::API,
            'FRONTEND_URL' => 'https://app.example.com',
            'YOUTUBE_OAUTH_REDIRECT_URI' => 'https://override.example.com/cb',
        ]);

        $this->assertSame('https://override.example.com/cb', $config['google']['redirect_uri']);
        $this->assertSame('https://app.example.com/ingestion', $config['frontend_return_url']);
    }

    public function test_the_registered_callback_routes_match_the_documented_paths_and_only_those_providers(): void
    {
        $routes = Route::getRoutes();
        foreach (['youtube', 'linkedin', 'meta'] as $provider) {
            $match = $routes->match(Request::create("/api/signals/ingestion/connectors/oauth/{$provider}/callback", 'GET'));
            $this->assertSame('api/signals/ingestion/connectors/oauth/{provider}/callback', $match->uri());
        }

        $this->expectException(NotFoundHttpException::class);
        $routes->match(Request::create('/api/signals/ingestion/connectors/oauth/tiktok/callback', 'GET'));
    }
}
