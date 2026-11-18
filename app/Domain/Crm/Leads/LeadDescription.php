<?php

namespace App\Domain\Crm\Leads;

use Illuminate\Support\Facades\DB;

/**
 * How much a lead's description may hold (slice 6, defect D9).
 *
 * crm_leads.description was a TINYTEXT: 255 BYTES. The live database is not strict, so a longer description was silently CUT OFF there, and the strict dev
 * database refused it. The migration 2026_12_11_100100 widens it to TEXT and the validators cap a description at MAX_CHARS characters; this class is what
 * makes the code safe on a host that has not run the migration yet: until it has, a description is cut deliberately (on a character boundary, never in the
 * middle of a UTF-8 character) at what the column really holds, instead of by the database.
 */
final class LeadDescription
{
    /** The longest description the screens and the API accept, in characters. */
    public const MAX_CHARS = 2000;

    private const TINYTEXT_BYTES = 255;

    private const TEXT_BYTES = 65535;

    /** @var array<string, int> connection and database => bytes, read once per process; the sqlite test database never asks */
    private static array $bytes = [];

    /** What the column holds on this host, in bytes. */
    public static function maxBytes(): int
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return self::TEXT_BYTES;
        }

        $key = DB::connection()->getName() . ':' . DB::connection()->getDatabaseName();
        if (! isset(self::$bytes[$key])) {
            $type = DB::table('information_schema.COLUMNS')->whereRaw('TABLE_SCHEMA = DATABASE()')->where('TABLE_NAME', 'crm_leads')->where('COLUMN_NAME', 'description')->value('DATA_TYPE');
            self::$bytes[$key] = strtolower((string) $type) === 'tinytext' ? self::TINYTEXT_BYTES : self::TEXT_BYTES;
        }

        return self::$bytes[$key];
    }

    /** The text, cut to MAX_CHARS characters and then to what the column holds. Null stays null. */
    public static function fit(?string $text, ?int $maxBytes = null): ?string
    {
        if ($text === null) {
            return null;
        }

        return mb_strcut(mb_substr($text, 0, self::MAX_CHARS, 'UTF-8'), 0, $maxBytes ?? self::maxBytes(), 'UTF-8');
    }
}
