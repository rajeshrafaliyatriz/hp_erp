<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `new_rating` becomes nullable: NULL now means "returned to unmeasured".
 *
 * ── WHY ──────────────────────────────────────────────────────────────────────
 *
 * competency_rating_history already treats `old_rating IS NULL` as "first
 * measurement", distinct from a value. `new_rating` had no equivalent: deleting
 * a rating (RatingWriter::clearItem(), formerly KasbaRatingController::destroy())
 * returns an item to UNMEASURED, and unmeasured is not a rating of zero any more
 * than it is a rating of five — the whole module's documented position. Before
 * this column could record a clearing at all, so a withdrawn rating left the
 * same kind of silent gap in history that a never-recorded change always did.
 *
 * MariaDB 10.1.48: raw ALTER rather than Schema::table()->tinyInteger()
 * ->nullable(), existence/nullability checked through information_schema because
 * Schema::hasColumn() throws on 10.1 (it asks for `generation_expression`, a
 * column that does not exist before MariaDB 10.2).
 *
 * RUN ON BOTH DATABASES, ONE AT A TIME:
 *   php artisan migrate --path=database/migrations/2026_10_14_100000_allow_null_new_rating_in_competency_rating_history.php
 *   php artisan migrate --database=live --path=database/migrations/2026_10_14_100000_allow_null_new_rating_in_competency_rating_history.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists('competency_rating_history')) {
            // The table this column belongs to does not exist yet on this
            // connection - nothing to alter. Its own migration already
            // creates new_rating as NOT NULL; a fresh install only needs this
            // file if that migration already ran.
            return;
        }

        if ($this->isNullable('competency_rating_history', 'new_rating')) {
            return;
        }

        DB::statement('ALTER TABLE `competency_rating_history` MODIFY `new_rating` TINYINT UNSIGNED NULL DEFAULT NULL');
    }

    public function down(): void
    {
        if (!$this->tableExists('competency_rating_history')) {
            return;
        }

        // Refusing to re-tighten: a down() that re-adds NOT NULL would fail
        // outright the moment a single clearing has been recorded, and
        // silently deciding what to do with those rows is a product decision,
        // not a migration's to make.
        if (DB::selectOne(
            'SELECT COUNT(*) AS c FROM `competency_rating_history` WHERE `new_rating` IS NULL'
        )->c > 0) {
            return;
        }

        DB::statement('ALTER TABLE `competency_rating_history` MODIFY `new_rating` TINYINT UNSIGNED NOT NULL');
    }

    private function tableExists(string $table): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->c > 0;
    }

    private function isNullable(string $table, string $column): bool
    {
        $row = DB::selectOne(
            'SELECT IS_NULLABLE AS n FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );

        return $row !== null && strtoupper((string) $row->n) === 'YES';
    }
};
