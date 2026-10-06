<?php

namespace App\Support\Poster;

/**
 * The facts strip: what the posting actually records.
 *
 * ── WHY THIS REPLACED "WHY JOIN US" ─────────────────────────────────────────
 *
 * The reference posters carry a "Why join Scholar Clone?" panel. That copy is
 * company-level marketing and it exists nowhere in this system - parsing for it
 * came back empty on all six live postings, because those sections are written
 * as prose rather than lists.
 *
 * The options were to invent it or to replace it. Inventing employment claims
 * under the company's logo, on an artifact with no review step, is not
 * something this code should do. These facts are recorded on every one of the
 * six live postings, so the strip is always true.
 *
 * ── THE RULE FOR MISSING VALUES ─────────────────────────────────────────────
 *
 * A null fact is OMITTED. Never "Not specified", never "Competitive", never a
 * dash. A poster has no room for the absence of information, and "Competitive
 * salary" in particular is a claim nobody made.
 */
class PosterFacts
{
    /**
     * @param  array<string,mixed>  $posting  a row from the public careers payload
     * @return array<int,array{label:string,value:string}>
     */
    public static function build(array $posting, int $limit): array
    {
        $facts = [];

        $add = static function (?string $label, ?string $value) use (&$facts): void {
            if ($label !== null && $value !== null && trim($value) !== '') {
                $facts[] = ['label' => $label, 'value' => $value];
            }
        };

        /*
         * ── THE ORDER IS THE PRIORITY, BECAUSE THE TAIL GETS CUT ────────────
         *
         * $limit varies by format - a square poster shows four chips, a
         * portrait six - so whatever sits last is simply not printed.
         *
         * The first version listed these in schema order and put salary sixth,
         * which meant the square poster rendered Location, Type, Work mode and
         * Experience and dropped the pay entirely. Salary is the single thing a
         * candidate looks for first; publishing a hiring poster without it
         * while showing "Work mode: Hybrid" is the wrong four facts.
         *
         * Ordered by what someone scanning a feed actually wants: where, how
         * much, what kind of contract, how long they have to decide.
         */
        $add('Location', self::text($posting['location'] ?? null, 28));
        $add('Salary', self::salary($posting['salary_min'] ?? null, $posting['salary_max'] ?? null));
        $add('Type', self::text($posting['employment_type'] ?? null, 20));
        $add('Apply by', self::deadline($posting['deadline'] ?? null));

        /*
         * Work mode is omitted when unset rather than defaulting to On-site.
         * The public payload documents this explicitly - "Null means not
         * stated, never On-site" - and a poster that says On-site when nobody
         * decided is a claim a candidate will hold you to.
         */
        $add('Work mode', self::text($posting['work_mode'] ?? null, 20));
        $add('Experience', self::text($posting['experience'] ?? null, 24));
        $add('Openings', self::openings($posting['positions'] ?? null));

        /*
         * One chip reads as a rendering fault rather than as information, so
         * the strip is all-or-nothing below two.
         */
        if (count($facts) < 2) {
            return [];
        }

        return array_slice($facts, 0, $limit);
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return PosterText::tighten($value, $max) ?? PosterText::flatten($value);
    }

    /** "1 opening" tells a reader nothing they did not assume. */
    private static function openings(mixed $positions): ?string
    {
        $count = is_numeric($positions) ? (int) $positions : 0;

        if ($count <= 1) {
            return null;
        }

        return $count > 9 ? '9+ openings' : $count . ' openings';
    }

    /**
     * Indian digit grouping, because these are INR salaries shown in India.
     *
     * The rupee sign is used rather than "INR": it is present in DejaVu Sans
     * Regular and Bold and in Geist, verified by reading the cmap of each,
     * so neither renderer can drop it silently.
     */
    private static function salary(mixed $min, mixed $max): ?string
    {
        $low = is_numeric($min) && $min > 0 ? (float) $min : null;
        $high = is_numeric($max) && $max > 0 ? (float) $max : null;

        if ($low === null && $high === null) {
            return null;
        }

        // Above a lakh, the compact form is what job ads in India actually use.
        $compact = static fn (float $v) => $v >= 100000
            ? rtrim(rtrim(number_format($v / 100000, 1, '.', ''), '0'), '.') . 'L'
            : number_format($v / 1000, 0) . 'k';

        if ($low !== null && $high !== null) {
            return $low === $high
                ? "\u{20B9}" . $compact($low)
                : "\u{20B9}" . $compact($low) . " \u{2013} \u{20B9}" . $compact($high);
        }

        return $low !== null
            ? "\u{20B9}" . $compact($low) . '+'
            : "up to \u{20B9}" . $compact($high);
    }

    /**
     * A closing date, or the urgency when it is near.
     *
     * "Closes in 4 days" earns its space in a way that a date does not; past
     * deadlines are unreachable here because such a posting is not public.
     */
    private static function deadline(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }

        $days = (int) (new \DateTimeImmutable('today'))->diff($date)->format('%r%a');

        if ($days < 0) {
            return null;
        }

        if ($days === 0) {
            return 'Closes today';
        }

        if ($days <= 7) {
            return $days === 1 ? 'Closes tomorrow' : "Closes in {$days} days";
        }

        return $date->format('j M Y');
    }
}
