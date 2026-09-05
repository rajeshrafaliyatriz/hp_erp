<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make an MCQ option long enough to be a real sentence.
 *
 *   php artisan migrate --database=mysql --path=database/migrations/2026_09_04_120000_widen_assessment_option_columns.php
 *   php artisan migrate --database=live  --path=database/migrations/2026_09_04_120000_widen_assessment_option_columns.php
 *
 * ── THE BUG THIS FIXES IS OLDER THAN CANDIDATE ASSESSMENT ───────────────────
 *
 * `competency_assessment_question.correct_option` and
 * `competency_assessment_response.selected_option` are both VARCHAR(50), and the
 * value stored in them is the OPTION'S FULL TEXT, not a letter - the generator
 * writes the option verbatim, the taker sends the option verbatim, and
 * AssessmentScoringService::scoreMultipleChoice() compares the two as strings.
 *
 * Fifty characters is shorter than a normal multiple-choice option. Generating a
 * hiring paper for "Senior Physiotherapist" produced this correct answer:
 *
 *   "Modify the plan based on the client's performance, motivation, safety,
 *    and outcome measures collected."                          -- 101 chars
 *
 * On the app host, which runs STRICT_TRANS_TABLES, that is error 1406 and the
 * whole generation fails loudly. That is the GOOD outcome.
 *
 * On LIVE it would have been worse. Live runs without STRICT_TRANS_TABLES, so
 * the insert would have SUCCEEDED with the value silently cut to 50 characters -
 * and then `selected_option` (also 50) would have held a differently-truncated
 * copy of whatever the candidate clicked. Every long MCQ would have marked
 * itself wrong, for everybody, with no error anywhere. The employee flow has
 * always had this; it simply never met an option long enough to show it.
 *
 * ── WHY 255 AND NOT TEXT ────────────────────────────────────────────────────
 *
 * These columns are compared for equality on every marked MCQ. VARCHAR compares
 * in-row; TEXT is stored off-page and would make the common case slower to serve
 * a length nobody needs. 255 comfortably holds a full-sentence option, and the
 * generator is separately capped so the model cannot exceed it.
 *
 * ── SIZE, AGAINST LIVE'S LIMITS ─────────────────────────────────────────────
 *
 *   VARCHAR(255) utf8mb4 = 1020 bytes.
 *
 * That is over the 767-byte prefix cap, which would matter IF either column were
 * indexed. NEITHER IS - verified on both hosts before writing this
 * (SHOW INDEX on both tables lists no key on either column), so no index has to
 * be rebuilt and the cap does not apply. If you ever index one of these, index a
 * prefix.
 *
 * Row size is unaffected in practice: VARCHAR is variable-length, so widening
 * the declaration costs nothing for rows already stored.
 *
 * Guarded on the CURRENT width, so re-running is a no-op and a hand-widened
 * column is left alone.
 */
return new class extends Migration
{
    /** table => column. Both must move together or the comparison breaks. */
    private const COLUMNS = [
        'competency_assessment_question' => 'correct_option',
        'competency_assessment_response' => 'selected_option',
    ];

    private const WIDE = 255;
    private const NARROW = 50;

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (!$this->tableExists($table) || $this->widthOf($table, $column) >= self::WIDE) {
                continue;
            }

            // NULL-ability preserved exactly: both are nullable today, and making
            // one NOT NULL here would reject every non-MCQ response.
            DB::statement(
                'ALTER TABLE `' . $table . '`
                 MODIFY `' . $column . '` VARCHAR(' . self::WIDE . ') NULL DEFAULT NULL'
            );
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (!$this->tableExists($table) || $this->widthOf($table, $column) <= self::NARROW) {
                continue;
            }

            /*
             * REFUSED RATHER THAN TRUNCATING. Narrowing would cut every option
             * longer than 50 characters, and on live - which is not in strict
             * mode - it would do so SILENTLY, marking those questions wrong
             * forever. A rollback must not corrupt data to succeed.
             */
            $tooLong = (int) DB::table($table)
                ->whereRaw('CHAR_LENGTH(`' . $column . '`) > ?', [self::NARROW])
                ->count();

            if ($tooLong > 0) {
                throw new RuntimeException(
                    'Refusing to narrow ' . $table . '.' . $column . ': ' . $tooLong
                    . ' row(s) are longer than ' . self::NARROW . ' characters and would be '
                    . 'silently truncated. Shorten or delete them first.'
                );
            }

            DB::statement(
                'ALTER TABLE `' . $table . '`
                 MODIFY `' . $column . '` VARCHAR(' . self::NARROW . ') NULL DEFAULT NULL'
            );
        }
    }

    /** Declared character length, or 0 when the column is absent. */
    private function widthOf(string $table, string $column): int
    {
        $row = DB::select(
            'SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        );

        return $row ? (int) $row[0]->len : 0;
    }

    private function tableExists(string $table): bool
    {
        // information_schema, not Schema::hasTable() - that throws on live.
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ));
    }
};
