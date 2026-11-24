<?php

namespace App\Domain\Intelligence\Support;

use Carbon\CarbonImmutable;

/**
 * The only source of "now" for detectors. Every date boundary is computed here in PHP (UTC, the application's timezone) and passed to the query as a binding,
 * so no detector depends on the database server's clock, time zone or a driver-specific date function (the live host is MariaDB 10.1; tests run on SQLite).
 */
final class DetectionClock
{
    private function __construct(private readonly CarbonImmutable $now)
    {
    }

    public static function now(): self
    {
        return new self(CarbonImmutable::now('UTC'));
    }

    public static function at(string $utcDateTime): self
    {
        return new self(CarbonImmutable::parse($utcDateTime, 'UTC'));
    }

    public function instant(): CarbonImmutable
    {
        return $this->now;
    }

    /** ISO-8601 UTC, e.g. 2026-11-21T09:30:00Z. */
    public function asOf(): string
    {
        return $this->now->format('Y-m-d\TH:i:s\Z');
    }

    /** 'Y-m-d' today. */
    public function today(): string
    {
        return $this->now->format('Y-m-d');
    }

    /** 'Y-m-d' N days before today. */
    public function daysAgo(int $days): string
    {
        return $this->now->subDays($days)->format('Y-m-d');
    }

    /** 'Y-m-d' N days after today. */
    public function daysAhead(int $days): string
    {
        return $this->now->addDays($days)->format('Y-m-d');
    }

    /** 'Y-m-d H:i:s' N hours before now. */
    public function hoursAgo(int $hours): string
    {
        return $this->now->subHours($hours)->format('Y-m-d H:i:s');
    }

    /** 'Y-m-d H:i:s' N days before now. */
    public function dateTimeDaysAgo(int $days): string
    {
        return $this->now->subDays($days)->format('Y-m-d H:i:s');
    }

    public function dateTimeNow(): string
    {
        return $this->now->format('Y-m-d H:i:s');
    }
}
