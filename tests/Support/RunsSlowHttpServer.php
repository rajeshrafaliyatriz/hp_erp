<?php

namespace Tests\Support;

use App\Domain\Signals\Support\SafeUrlFetcher;

/**
 * Runs tests/Fixtures/slow_http_server.php (a deliberately slow, redirecting, oversized, hanging HTTP server on 127.0.0.1)
 * as a child process, and gives tests a SafeUrlFetcher that reaches it through the REAL cURL transport.
 *
 * Production validation refuses loopback addresses and non-standard ports, so the test fetcher overrides validate() for ONE
 * made-up host name (pinned-host.invalid, which no DNS can resolve) and returns the server's port and 127.0.0.1 as the pin.
 * Every other host still goes through the real validate(). Because the name cannot resolve, a request that reaches the
 * server at all proves cURL used the CURLOPT_RESOLVE pin. Nothing outside this machine is ever contacted.
 */
trait RunsSlowHttpServer
{
    /** @var resource|null */
    private $slowServerProcess = null;

    /** @var array<int, resource> */
    private array $slowServerPipes = [];

    private int $slowServerPort = 0;

    protected function startSlowServer(int $robotsDelayMs = 0, int $lifetimeSeconds = 60): int
    {
        $script = base_path('tests/Fixtures/slow_http_server.php');
        $process = proc_open(
            [PHP_BINARY, $script, (string) $robotsDelayMs, (string) $lifetimeSeconds],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process, 'the test server could not be started');
        $this->slowServerProcess = $process;
        $this->slowServerPipes = $pipes;

        $line = (string) fgets($pipes[1]);   // "PORT <n>", printed as soon as the server is listening
        $this->assertMatchesRegularExpression('/^PORT (\d+)/', $line, 'the test server did not report a port');
        $this->slowServerPort = (int) substr(trim($line), 5);

        return $this->slowServerPort;
    }

    protected function stopSlowServer(): void
    {
        if (is_resource($this->slowServerProcess)) {
            @proc_terminate($this->slowServerProcess);
            foreach ($this->slowServerPipes as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            @proc_close($this->slowServerProcess);
        }
        $this->slowServerProcess = null;
        $this->slowServerPipes = [];
    }

    protected function slowUrl(string $path): string
    {
        return 'http://pinned-host.invalid:' . $this->slowServerPort . $path;
    }

    protected function pinnedFetcher(): SafeUrlFetcher
    {
        return new class($this->slowServerPort, 'pinned-host.invalid') extends SafeUrlFetcher {
            public function __construct(private readonly int $port, private readonly string $pinnedHost)
            {
                parent::__construct(fn () => ['93.184.216.34']);
            }

            public function validate(string $url): array
            {
                if ((parse_url($url, PHP_URL_HOST) ?? '') === $this->pinnedHost) {
                    return [$this->pinnedHost, $this->port, '127.0.0.1'];
                }

                return parent::validate($url);
            }
        };
    }
}
