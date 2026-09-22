<?php

namespace App\Support\Poster;

/**
 * How much text each poster size can hold.
 *
 * ── WHY THIS IS A HARD TABLE AND NOT A GUESS ────────────────────────────────
 *
 * Neither renderer can measure text. Satori has no measurement API, and dompdf
 * only discovers an overflow by drawing it. With no preview step before the
 * poster is published, an over-long string does not get noticed - it silently
 * clips, overlaps, or pushes the apply link off the canvas.
 *
 * So the caps are enforced in PHP, before either renderer sees a string, and
 * they are character counts rather than hopes. Both renderers use the same
 * typeface (DejaVu Sans), which is what makes one table valid for both.
 */
class PosterFormat
{
    public const PORTRAIT  = 'portrait';
    public const SQUARE    = 'square';
    public const LANDSCAPE = 'landscape';
    public const A4        = 'a4';

    /**
     * bullets  how many items a content panel may show
     * width    characters per bullet
     * chips    how many facts the strip may show
     * title    the largest title size in px (pt for a4); the ramp steps down
     */
    /*
     * ── THE WIDTHS ARE ONE LINE OF TYPE, NOT A GUESS ────────────────────────
     *
     * These were first set generously (86 characters on portrait) and the
     * render overflowed the canvas: at 22px in a ~850px column, 86 characters
     * of DejaVu Sans wrap to two lines, so two five-item panels ran past 1350px
     * and pushed the Apply button off the poster entirely.
     *
     * A poster bullet should be one scannable line anyway. The widths below are
     * what fits a single line in each column, and the counts are what then fits
     * the canvas - both confirmed by rendering, not by arithmetic.
     *
     * A4 keeps larger numbers because it is a print document with a full page
     * and a reader who is standing still.
     */
    private const TABLE = [
        self::PORTRAIT => [
            'label' => 'Portrait', 'w' => 1080, 'h' => 1350,
            'bullets' => 4, 'width' => 64, 'chips' => 6, 'title' => 96, 'headline' => 96,
        ],
        /*
         * Square is 270px shorter than portrait and was carrying almost the
         * same content, so the Apply button rendered off the bottom edge - the
         * one element the whole poster exists for. Four chips and a smaller
         * headline buy back the room.
         */
        self::SQUARE => [
            'label' => 'Square', 'w' => 1080, 'h' => 1080,
            'bullets' => 3, 'width' => 58, 'chips' => 4, 'title' => 72, 'headline' => 76,
        ],
        /*
         * Landscape is mostly headline and apply link. At 630px tall, three
         * short bullets is the honest maximum - five would be unreadable in a
         * timeline, which is the only place this size is ever seen.
         */
        self::LANDSCAPE => [
            'label' => 'Landscape', 'w' => 1200, 'h' => 630,
            'bullets' => 3, 'width' => 46, 'chips' => 4, 'title' => 56, 'headline' => 64,
        ],
        self::A4 => [
            'label' => 'A4 print', 'w' => 794, 'h' => 1123,
            'bullets' => 7, 'width' => 110, 'chips' => 7, 'title' => 34, 'headline' => 40,
        ],
    ];

    /**
     * Multi-role posters carry no parsed content - title and facts only.
     *
     * That is deliberate: every parser failure mode in JobDescriptionParser is
     * simply absent from this path, because the path never calls it.
     *
     * The title scale grows as the role count falls, because the canvas is
     * fixed and two compact cards on a 1350px poster otherwise leave a third of
     * it empty. Fewer roles means each one is allowed to be larger, which uses
     * the space rather than padding it out.
     */
    private const MULTI = [
        2 => ['chips' => 4, 'title' => 0.88],
        3 => ['chips' => 4, 'title' => 0.62],
    ];

    public static function all(): array
    {
        return array_keys(self::TABLE);
    }

    public static function exists(?string $format): bool
    {
        return $format !== null && isset(self::TABLE[$format]);
    }

    public static function spec(string $format, int $roleCount = 1): array
    {
        $spec = self::TABLE[$format] ?? self::TABLE[self::PORTRAIT];
        $spec['format'] = $format;
        $spec['roles'] = $roleCount;

        if ($roleCount > 1) {
            $multi = self::MULTI[min($roleCount, 3)];
            $spec['bullets'] = 0;                       // see the class docblock
            $spec['chips'] = $multi['chips'];
            $spec['title'] = (int) round($spec['title'] * $multi['title']);
        }

        return $spec;
    }

    /**
     * The title size for a given length.
     *
     * A poster title is one line of display type, and this product's titles
     * range from "Data Analyst" to an 87-character
     * "Field Digital Transformation Executive - Project Manager / School
     * Transformation Lead". One size cannot serve both, so the size steps down
     * as the title grows and the renderer clamps to three lines below that.
     */
    public static function titleSize(int $max, int $length): int
    {
        $scale = match (true) {
            $length <= 22 => 1.0,
            $length <= 34 => 0.82,
            $length <= 48 => 0.66,
            $length <= 70 => 0.54,
            default       => 0.46,
        };

        return max(12, (int) round($max * $scale));
    }

    /** Titles beyond this are cut at a word boundary; see titleSize(). */
    public const TITLE_MAX_CHARS = 84;
}
