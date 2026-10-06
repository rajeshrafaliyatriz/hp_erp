<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets an adopted row say where it came from.
 *
 * ── WHY PROVENANCE MATTERS MORE THAN IT SOUNDS ─────────────────────────────
 *
 * A new organisation now starts empty and adopts catalogue content on request.
 * The moment it does, two kinds of row live side by side in the same table:
 * content the customer wrote, and content they took from the shared catalogue.
 * Without a source id NOTHING can tell them apart, and three ordinary questions
 * become unanswerable:
 *
 *   - "the catalogue's wording of this role changed - does yours still match?"
 *   - "adopt these 20 roles" run twice: is row 2 a duplicate or a second role
 *     that happens to share a name?
 *   - "show me what we actually authored" - the question a customer asks when
 *     they want to know what their own framework says.
 *
 * ── WHY NOT BACKFILL THE EXISTING ROWS ─────────────────────────────────────
 *
 * Because after the fact the only key left is the NAME, and names do not
 * identify these rows. The one attempt on record keyed 80,064 tasks and left
 * 5,599 NULL, of which 5,470 were AMBIGUOUS - the same name in more than one
 * place with nothing to choose between them. A guessed provenance is worse than
 * an absent one: it looks like a fact.
 *
 * So these columns start empty and are filled AT ADOPTION TIME, by the import
 * path, where the source id is known rather than inferred. Rows that predate
 * the adopt endpoint keep NULL, which is the honest answer - nobody recorded
 * where they came from, and nobody can now.
 *
 * ── THE FOURTH COLUMN ──────────────────────────────────────────────────────
 *
 * `s_user_jobrole_task.catalogue_task_id` already exists and is formalised by
 * 2026_08_22_120000. These are the other three, deliberately given the same
 * shape - nullable, indexed, no foreign key.
 *
 * NO FOREIGN KEY, ON PURPOSE. The catalogue is curated content that gets
 * pruned; a role being retired from the catalogue must not cascade into a
 * customer's own library or block the prune. The column records history, and
 * history does not stop being true when the source is deleted.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_22_130000_add_catalogue_provenance_columns.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_22_130000_add_catalogue_provenance_columns.php
 */
return new class extends Migration
{
    /** table => [column, index name, what it points at] */
    private const COLUMNS = [
        's_user_jobrole'       => ['catalogue_jobrole_id', 'idx_ujr_catalogue_jobrole', 's_jobrole.id'],
        's_users_skills'       => ['catalogue_skill_id', 'idx_uskills_catalogue_skill', 'master_skills.id'],
        's_user_skill_jobrole' => ['catalogue_skill_jobrole_id', 'idx_usjr_catalogue_link', 's_jobrole_skills.id'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => [$column, $index, $_referent]) {
            if (!$this->tableExists($table)) {
                continue;
            }

            if (!$this->columnExists($table, $column)) {
                DB::statement("ALTER TABLE `{$table}` ADD COLUMN `{$column}` BIGINT UNSIGNED NULL");
            }

            // Added separately from the column: the adopt path looks rows up by
            // this value to make re-adoption a no-op instead of a duplicate, so
            // it is a read key, not just a record.
            if ($this->columnExists($table, $column) && !$this->indexExists($table, $index)) {
                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$index}` (`{$column}`)");
            }
        }
    }

    public function down(): void
    {
        /*
         * NOT DROPPED.
         *
         * Once the adopt endpoint has run, these columns hold the only record of
         * which rows came from the catalogue and which the customer wrote. That
         * cannot be recomputed - see the 5,470 ambiguous names above. Dropping
         * them to reverse an ADD COLUMN would destroy information to undo
         * something that cost nothing.
         */
    }

    /**
     * Not Schema::hasTable() / hasColumn(): Laravel 11 introspects with a query
     * selecting `generation_expression`, which live's MariaDB 10.1 does not
     * have, so those helpers throw there while working on dev.
     */
    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
              LIMIT 1',
            [$table, $column]
        ) !== [];
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
              LIMIT 1',
            [$table, $index]
        ) !== [];
    }
};
