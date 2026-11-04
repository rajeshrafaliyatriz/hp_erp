<?php

namespace Tests\Unit\Signals;

use App\Domain\Signals\Opportunities\DateStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DateStatusTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: mixed, 2: string, 3: string|null}> */
    public static function cases(): array
    {
        return [
            'event date wins over a published date' => ['2026-10-12', '2026-10-03 08:00:00', 'event_date_stated', '2026-10-12'],
            'event date alone' => ['2026-10-12', null, 'event_date_stated', '2026-10-12'],
            'event date given with a time' => ['2026-10-12 00:00:00', null, 'event_date_stated', '2026-10-12'],
            'published date alone' => [null, '2026-10-03 08:15:00', 'source_date_only', '2026-10-03'],
            'published date is a date object' => [null, new \DateTimeImmutable('2026-10-03 23:59:59'), 'source_date_only', '2026-10-03'],
            'neither' => [null, null, 'undated', null],
            'empty strings are absent' => ['', '   ', 'undated', null],
            'zero dates are absent' => ['0000-00-00', '0000-00-00 00:00:00', 'undated', null],
            'zero event date falls back to the published date' => ['0000-00-00', '2026-10-03', 'source_date_only', '2026-10-03'],
            'garbage is absent, not a date' => ['soon', 'n/a', 'undated', null],
            'garbage event date falls back to a good published date' => ['next week', '2026-10-03', 'source_date_only', '2026-10-03'],
            'an impossible calendar date is absent' => ['2026-02-30', '2026-13-01', 'undated', null],
            'a year-first date with a T time' => [null, '2026-10-03T08:15:00+05:30', 'source_date_only', '2026-10-03'],
        ];
    }

    #[DataProvider('cases')]
    public function test_classify(mixed $event, mixed $published, string $status, ?string $date): void
    {
        $this->assertSame(['status' => $status, 'date' => $date], DateStatus::classify($event, $published));
        $this->assertSame($status, DateStatus::status($event, $published));
    }

    public function test_every_status_it_can_return_is_in_the_published_list(): void
    {
        foreach (self::cases() as [$e, $p]) {
            $this->assertContains(DateStatus::status($e, $p), DateStatus::ALL);
        }
    }
}
