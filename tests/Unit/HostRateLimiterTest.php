<?php

namespace Tests\Unit;

use App\Domain\Signals\Support\HostRateLimiter;
use PHPUnit\Framework\TestCase;

class HostRateLimiterTest extends TestCase
{
    private int $now = 10_000;

    /** @var array<int, int> */
    private array $sleeps = [];

    private function limiter(int $interval = 1000, int $max = 256): HostRateLimiter
    {
        $this->sleeps = [];

        return new HostRateLimiter($interval, fn () => $this->now, function (int $ms): void {
            $this->sleeps[] = $ms;
            $this->now += $ms;
        }, $max);
    }

    public function test_first_request_to_a_host_does_not_wait(): void
    {
        $this->assertSame(0, $this->limiter()->wait('a.example'));
        $this->assertSame([], $this->sleeps);
    }

    public function test_repeated_host_waits_the_remaining_interval_only(): void
    {
        $l = $this->limiter(1000);
        $l->wait('a.example');
        $this->now += 400;
        $this->assertSame(600, $l->wait('A.example'));   // host is case-insensitive
        $this->assertSame([600], $this->sleeps);
        $this->now += 1500;
        $this->assertSame(0, $l->wait('a.example'));
    }

    public function test_hosts_are_independent(): void
    {
        $l = $this->limiter(1000);
        $l->wait('a.example');
        $this->assertSame(0, $l->wait('b.example'));
        $this->assertSame(1000, $l->wait('a.example'));
    }

    public function test_zero_interval_never_sleeps_and_reset_forgets_hosts(): void
    {
        $l = $this->limiter(0);
        $l->wait('a.example');
        $this->assertSame(0, $l->wait('a.example'));

        $l = $this->limiter(1000);
        $l->wait('a.example');
        $l->reset();
        $this->assertSame(0, $l->wait('a.example'));
        $this->assertSame([], $this->sleeps);
    }

    public function test_memory_is_bounded(): void
    {
        $l = $this->limiter(1000, 2);
        $l->wait('a.example');
        $l->wait('b.example');
        $l->wait('c.example');   // evicts a
        $this->assertSame(0, $l->wait('a.example'));
        $this->assertSame([], $this->sleeps);
    }
}
