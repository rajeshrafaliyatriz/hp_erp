<?php

namespace App\Domain\Signals\Sources\Adapters;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Shared guard rails for official-API adapters: never throws, hard request cap, error category only (never a message that could hold a key). */
abstract class AbstractApiAdapter
{
    protected int $requests = 0;
    protected ?string $lastError = null;

    /** @param array<string,mixed> $config */
    public function __construct(protected array $config = [], protected ?Factory $http = null, protected int $maxRequests = 5)
    {
        $this->maxRequests = max(1, (int) ($config['max_requests'] ?? $maxRequests));
    }

    abstract public function isConfigured(): bool;

    /** Category only: not_configured, request_cap, http_403, http_429, timeout, error. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function requestsMade(): int
    {
        return $this->requests;
    }

    protected function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false) && $this->isConfigured();
    }

    protected function cfg(string $k): string
    {
        return trim((string) ($this->config[$k] ?? ''));
    }

    protected function limit(int $limit): int
    {
        return max(1, min($limit, (int) ($this->config['max_results'] ?? 25), 50));
    }

    protected function client(): PendingRequest
    {
        $f = $this->http ?? Http::getFacadeRoot();

        return $f->timeout(12)->connectTimeout(6)->acceptJson();
    }

    /**
     * @param callable(PendingRequest):\Illuminate\Http\Client\Response $call
     * @return array<string,mixed>|null decoded JSON, or null with lastError set
     */
    protected function call(callable $call): ?array
    {
        if ($this->requests >= $this->maxRequests) {
            $this->lastError = 'request_cap';

            return null;
        }
        $this->requests++;
        try {
            $res = $call($this->client());
            if (! $res->successful()) {
                $this->lastError = 'http_' . $res->status();

                return null;
            }
            $json = $res->json();
            if (! is_array($json)) {
                $this->lastError = 'bad_response';

                return null;
            }

            return $json;
        } catch (ConnectionException) {
            $this->lastError = 'timeout';
        } catch (\Throwable) {
            $this->lastError = 'error';
        }

        return null;
    }

    protected static function day(mixed $s): ?string
    {
        if (! is_string($s) || $s === '') {
            return null;
        }
        $t = strtotime($s);

        return $t === false ? null : gmdate('Y-m-d', $t);
    }

    protected static function cut(string $s, int $n): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, $n);
    }
}
