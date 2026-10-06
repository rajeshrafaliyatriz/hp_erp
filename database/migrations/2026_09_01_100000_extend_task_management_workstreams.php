<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give a workstream the identity the lifecycle model needs.
 *
 * ── WHAT A WORKSTREAM WAS ───────────────────────────────────────────────────
 *
 * Thirteen columns, of which the product could write exactly three: name, owner,
 * status. `description` has existed since the table was created and is NULL on
 * 12 of 12 rows across both databases, because no form field anywhere writes it.
 *
 * Live project 7 (G2G, tenant 6) carries four hand-typed workstreams matching the
 * customer's own operating model — WS01 Product & Requirements, WS02 Engineering
 * & AI Delivery, WS03 Project Delivery & Governance, WS04 Quality, Release &
 * Adoption. What the product did with them is the argument for this change:
 * sort_order came out 4,1,2,3 because the form writes an insertion counter rather
 * than a sequence anyone chose, and all four carry identical dates silently
 * copied from the project.
 *
 * ── THE FIVE COLUMNS ────────────────────────────────────────────────────────
 *
 *   purpose        Field 1 of the customer's nine. A dedicated column rather than
 *                  reusing `description`: Purpose is the first thing the model
 *                  names and it must be unambiguous. `description` is migrated
 *                  into it below and then retired from the API surface, so there
 *                  is ONE prose field rather than two with one permanently empty.
 *   code           WS01, WS02.1 — the model's own identifiers. Unique per project.
 *   kind           DELIVERY | GOVERNANCE. See below; this one is load-bearing.
 *   parent_id      Sub-workstreams (WS02.1 AI/ML), one level.
 *   core_question  "What and why are we building?" — the line that makes the
 *                  lifecycle diagram readable at a glance.
 *
 * ── WHY `kind` IS NOT JUST A SORT ORDER ─────────────────────────────────────
 *
 * The source model is explicit that WS03 is NOT a fourth stage:
 *
 *   "WS3 is deliberately horizontal. Rajesh coordinates the entire system
 *    instead of becoming another sequential stage."
 *
 * WS01 -> WS02 -> WS04 is a sequence with a feedback loop back to WS01; WS03 sits
 * ACROSS all three. A model carrying only "position 1,2,3,4" cannot express that,
 * and any diagram drawn from it would render WS03 between WS02 and WS04 — exactly
 * the mistake the model warns against. `kind` is what keeps the picture honest.
 *
 * VARCHAR + a controller const, never ENUM: adding a value later would mean an
 * ALTER TABLE rebuild on live. This is already the module's pattern.
 *
 * ── THE UNIQUE INDEX THAT WOULD HAVE PASSED HERE AND FAILED ON LIVE ─────────
 *
 * The obvious key is unique(project_id, name) — and it is a trap. Measured:
 *
 *   live  10.1.48-MariaDB  innodb_large_prefix=ON  innodb_file_format=Barracuda
 *         task_management_workstreams ROW_FORMAT = Compact
 *
 * `large_prefix=ON` only lifts the index prefix to 3072 bytes for DYNAMIC or
 * COMPRESSED rows. This table is COMPACT, so the limit is 767 bytes. `name` is
 * VARCHAR(191) utf8mb4 = 764 bytes, plus the BIGINT = 772. Dev (10.11, dynamic)
 * accepts it; live fails with error 1071.
 *
 * Uniqueness therefore goes on (project_id, code): 8 + 80 = 88 bytes.
 *
 * `code` is NULLABLE so the unique index can be created BEFORE any backfill —
 * NULLs do not collide in a MySQL unique index, so all 12 existing rows coexist.
 *
 * ── parent_id IS RESTRICT, DELIBERATELY ─────────────────────────────────────
 *
 * cascadeOnDelete would silently delete a sub-workstream and every deliverable,
 * KPI and risk under it. nullOnDelete would silently promote it to top level.
 * Both destroy a plan without saying so. RESTRICT makes the database refuse and
 * the controller returns a readable 422 naming the children.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_09_01_100000_extend_task_management_workstreams.php
 *   php artisan migrate --database=live --path=database/migrations/2026_09_01_100000_extend_task_management_workstreams.php
 */
return new class extends Migration
{
    private const TABLE = 'task_management_workstreams';

    public function up(): void
    {
        if (! $this->tableExists(self::TABLE)) {
            return;
        }

        // Raw DDL rather than Schema::table(): Laravel 11's schema builder reads
        // `generation_expression`, which MariaDB 10.1 does not have, so every
        // introspection call throws on live.
        $add = [];

        if (! $this->columnExists(self::TABLE, 'purpose')) {
            $add[] = 'ADD COLUMN `purpose` TEXT NULL AFTER `name`';
        }
        if (! $this->columnExists(self::TABLE, 'code')) {
            $add[] = 'ADD COLUMN `code` VARCHAR(20) NULL AFTER `id`';
        }
        if (! $this->columnExists(self::TABLE, 'kind')) {
            $add[] = "ADD COLUMN `kind` VARCHAR(20) NOT NULL DEFAULT 'DELIVERY' AFTER `code`";
        }
        if (! $this->columnExists(self::TABLE, 'parent_id')) {
            $add[] = 'ADD COLUMN `parent_id` BIGINT UNSIGNED NULL AFTER `project_id`';
        }
        if (! $this->columnExists(self::TABLE, 'core_question')) {
            $add[] = 'ADD COLUMN `core_question` VARCHAR(191) NULL AFTER `purpose`';
        }

        if ($add !== []) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` ' . implode(', ', $add));
        }

        /*
         * ONE PROSE FIELD, NOT TWO. Anything already authored in `description`
         * becomes the Purpose. Measured as NULL on all 12 rows today, so this
         * moves nothing — it exists so the migration is correct on a database
         * where somebody has since written one, rather than stranding it.
         *
         * `description` is left in place. Dropping a column that other code may
         * still SELECT is a separate, riskier change; it is simply no longer
         * exposed by the API.
         */
        DB::statement('
            UPDATE `' . self::TABLE . '`
               SET `purpose` = `description`
             WHERE `purpose` IS NULL
               AND `description` IS NOT NULL
               AND TRIM(`description`) <> \'\'
        ');

        // Every index and constraint is named explicitly. MySQL caps identifiers
        // at 64 characters and Laravel's generated names on this table run long.
        if (! $this->indexExists(self::TABLE, 'tm_ws_project_code_unique')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '`
                ADD UNIQUE `tm_ws_project_code_unique` (`project_id`, `code`)');
        }

        if (! $this->indexExists(self::TABLE, 'tm_ws_parent_idx')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '`
                ADD INDEX `tm_ws_parent_idx` (`parent_id`)');
        }

        if (! $this->indexExists(self::TABLE, 'tm_ws_project_kind_order_idx')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '`
                ADD INDEX `tm_ws_project_kind_order_idx` (`project_id`, `kind`, `sort_order`)');
        }

        if (! $this->constraintExists('tm_ws_parent_fk')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '`
                ADD CONSTRAINT `tm_ws_parent_fk`
                FOREIGN KEY (`parent_id`) REFERENCES `' . self::TABLE . '` (`id`)
                ON DELETE RESTRICT');
        }
    }

    public function down(): void
    {
        if (! $this->tableExists(self::TABLE)) {
            return;
        }

        if ($this->constraintExists('tm_ws_parent_fk')) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` DROP FOREIGN KEY `tm_ws_parent_fk`');
        }

        foreach (['tm_ws_project_code_unique', 'tm_ws_parent_idx', 'tm_ws_project_kind_order_idx'] as $index) {
            if ($this->indexExists(self::TABLE, $index)) {
                DB::statement('ALTER TABLE `' . self::TABLE . '` DROP INDEX `' . $index . '`');
            }
        }

        /*
         * `purpose` is dropped LAST and its content is copied back into
         * `description` first, so rolling back does not destroy prose somebody
         * wrote. Only where description is empty — a rollback must not overwrite
         * an authored description with a purpose.
         */
        if ($this->columnExists(self::TABLE, 'purpose')) {
            DB::statement('
                UPDATE `' . self::TABLE . '`
                   SET `description` = `purpose`
                 WHERE `purpose` IS NOT NULL
                   AND (`description` IS NULL OR TRIM(`description`) = \'\')
            ');
        }

        $drop = [];
        foreach (['core_question', 'parent_id', 'kind', 'code', 'purpose'] as $column) {
            if ($this->columnExists(self::TABLE, $column)) {
                $drop[] = 'DROP COLUMN `' . $column . '`';
            }
        }

        if ($drop !== []) {
            DB::statement('ALTER TABLE `' . self::TABLE . '` ' . implode(', ', $drop));
        }
    }

    /** Schema::hasTable() throws on live (MariaDB 10.1.48); read the catalogue. */
    private function tableExists(string $table): bool
    {
        return (int) (DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        )->c ?? 0) > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) (DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )->c ?? 0) > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        return (int) (DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        )->c ?? 0) > 0;
    }

    private function constraintExists(string $name): bool
    {
        return (int) (DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
            [$name]
        )->c ?? 0) > 0;
    }
};
