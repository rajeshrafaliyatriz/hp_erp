<?php

namespace App\Domain\Signals\Opportunities;

/**
 * How far a signal's age can be trusted, decided from its own date columns and nothing else.
 *
 *   event_date_stated  the source states the date the event happens or happened (event_date)
 *   source_date_only   only the date the source was published is known (source_published_at)
 *   undated            neither is known: the signal's age cannot be told
 *
 * One rule, used by the feed (the date badge), by the `signals:backfill-date-status` command and by whatever stores a new signal, so they cannot
 * disagree. Pure: no database, no clock. A value that is not a real date (an empty string, '0000-00-00') counts as absent, never as a date.
 */
final class DateStatus
{
    public const EVENT_DATE_STATED = 'event_date_stated';

    public const SOURCE_DATE_ONLY = 'source_date_only';

    public const UNDATED = 'undated';

    public const ALL = [self::EVENT_DATE_STATED, self::SOURCE_DATE_ONLY, self::UNDATED];

    /**
     * @return array{status: string, date: string|null}  date is Y-m-d, or null when undated
     */
    public static function classify(mixed $eventDate, mixed $sourcePublishedAt): array
    {
        $event = self::day($eventDate);
        if ($event !== null) {
            return ['status' => self::EVENT_DATE_STATED, 'date' => $event];
        }

        $published = self::day($sourcePublishedAt);
        if ($published !== null) {
            return ['status' => self::SOURCE_DATE_ONLY, 'date' => $published];
        }

        return ['status' => self::UNDATED, 'date' => null];
    }

    public static function status(mixed $eventDate, mixed $sourcePublishedAt): string
    {
        return self::classify($eventDate, $sourcePublishedAt)['status'];
    }

    private static function day(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        // Only a real calendar date counts. Carbon::parse would turn "next week" into a date from today's clock; the stored columns hold
        // YYYY-MM-DD (optionally followed by a time), so anything else is absent. '0000-00-00' fails checkdate().
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]|$)/', trim((string) $value), $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
}
