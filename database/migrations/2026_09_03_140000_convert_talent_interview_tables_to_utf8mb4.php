<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * talent_evaluation_form + talent_interview_panel: latin1 -> utf8mb4.
 *
 *   php artisan migrate --database=mysql --path=database/migrations/2026_09_03_140000_convert_talent_interview_tables_to_utf8mb4.php
 *   php artisan migrate --database=live  --path=database/migrations/2026_09_03_140000_convert_talent_interview_tables_to_utf8mb4.php
 *
 * ── WHY ─────────────────────────────────────────────────────────────────────
 *
 * These are the only two talent_* tables still on latin1. Every other one of
 * the 24 is utf8mb4, on both hosts. The consequence is not cosmetic - it was
 * measured by writing into the column:
 *
 *   'સરસ ઉમેદવાર'  stored as  3F 3F 3F 3F 3F 3F 3F 20 ...   ("???????  ??")
 *   'Great candidate 👍' stored as  ... 20 3F                ("Great candidate ?")
 *
 * The characters are not mangled, they are DESTROYED at write time and replaced
 * with question marks. An interviewer writing feedback in Gujarati - in an ERP
 * sold in Gujarat - loses it, and so does anyone who types an emoji or a curly
 * quote. Six of the twelve columns are the free-text interview feedback fields.
 *
 * ── WHY `CONVERT TO CHARACTER SET` IS THE RIGHT INSTRUMENT HERE ─────────────
 *
 * It is the wrong instrument when a latin1 column already holds bytes that are
 * really UTF-8 (the "double-encoded" case), because it re-encodes them a second
 * time and the damage is silent and permanent. So that was checked rather than
 * assumed, on both hosts, over all ten character columns:
 *
 *   rows containing any byte >= 0x80 ......... 1
 *   of those, already valid UTF-8 ............ 0
 *
 * The single row is talent_evaluation_form.notes id=31, holding one 0x97 byte.
 * MySQL's `latin1` is cp1252, not ISO-8859-1 - verified by asking each server
 * directly, `SELECT HEX(CONVERT(_latin1 0x97 USING utf8mb4))`, which returns
 * E28094 on both. So that byte becomes U+2014 EM DASH, which is what whoever
 * pasted it meant. The conversion is lossless.
 *
 * ── WHY IT IS SAFE ON LIVE ──────────────────────────────────────────────────
 *
 * `CONVERT TO CHARACTER SET` rebuilds the table, so the two things that matter
 * are index widths and size. Both were measured on both hosts:
 *
 *   only one index on a character column: talent_interview_panel.panel_name
 *   varchar(50) -> 200 bytes under utf8mb4, against live's 767-byte prefix cap
 *   under ROW_FORMAT=Compact. No other index is on a string.
 *
 *   rows: talent_evaluation_form 124, talent_interview_panel 33.
 *
 * Both hosts reported identical columns, identical indexes and identical row
 * counts, so the same statement is correct for each.
 *
 * The two `status` columns are ENUMs. The house rule bans ENUM for NEW columns;
 * these already exist and their values are ASCII, so the conversion carries
 * them across untouched. Widening them is a separate decision and not made here.
 */
return new class extends Migration
{
    private const TABLES = ['talent_evaluation_form', 'talent_interview_panel'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (!$this->tableExists($table) || $this->isUtf8mb4($table)) {
                continue;
            }

            DB::statement(
                'ALTER TABLE `' . $table . '` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
        }
    }

    /**
     * Reversible, and worth keeping so - but reversing it is lossy by nature:
     * anything genuinely non-latin1 written in the meantime becomes '?'. That is
     * a property of latin1, not of this migration.
     */
    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (!$this->tableExists($table) || !$this->isUtf8mb4($table)) {
                continue;
            }

            DB::statement(
                'ALTER TABLE `' . $table . '` CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci'
            );
        }
    }

    /** Schema::hasTable() throws on the live host; information_schema does not. */
    private function tableExists(string $table): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ));
    }

    /** True once no character column on the table is anything but utf8mb4. */
    private function isUtf8mb4(string $table): bool
    {
        return empty(DB::select(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND CHARACTER_SET_NAME IS NOT NULL
               AND CHARACTER_SET_NAME <> 'utf8mb4' LIMIT 1",
            [$table]
        ));
    }
};
