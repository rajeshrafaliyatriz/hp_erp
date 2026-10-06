<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE ESO RECORD — §5 of ESO_v1_Schema_and_Sprint_Scope.md.
 *
 * ── WHAT THIS ADDS THAT DID NOT EXIST ───────────────────────────────────────
 *
 * `jobrole_task_execution` answers HOW MUCH of a role could be automated, at
 * what risk. It says nothing about HOW a task is actually executed. That is the
 * other half of an "execution model" and it had no table at all: no steps, no
 * controls, no inputs or outputs, no evidence. The nearest thing in the whole
 * codebase was a one-sentence `automation_rationale`.
 *
 * ── ONE TABLE, TWO SCOPES ───────────────────────────────────────────────────
 *
 * §6.1 permits "a single table with a `scope` discriminator", and that is what
 * this is.
 *
 *   Template  generic execution pattern, product IP, sub_institute_id NULL
 *   Instance  one customer's version of it, seeded from a template
 *
 * ── RESOLVING A CONTRADICTION IN THE DOCUMENT ───────────────────────────────
 *
 * §5 field 2 is `task_id (fk)`, which reads as one ESO per task. §1 says
 * 200-400 templates cover 3,572 tasks. Both cannot be true.
 *
 * §2 field 10 settles it: the TASK carries `eso_template_id`. So many tasks
 * point at one template, and only an Instance names a task. `eso_template_id`
 * was omitted when `jobrole_task_execution` was created and is added here.
 *
 * ── LONGTEXT, NOT JSON, AND THAT IS NOT LAZINESS ────────────────────────────
 *
 * Six of the §5 fields are lists. MEASURED: live is MariaDB 10.1.48, which has
 * no native JSON type, and there are ZERO json columns on either database - the
 * house pattern is LONGTEXT holding JSON (g2g_event.payload,
 * agentic_tool_invocations.payload, hpbrain_process_definitions.steps).
 *
 * A `JSON` column here would migrate cleanly on dev and fail on live. That
 * divergence is the single most repeated failure in this project.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_27_170000_create_eso_and_link_template.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_27_170000_create_eso_and_link_template.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->tableExists('eso')) {
            DB::statement("
                CREATE TABLE `eso` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

                    -- ── Identity (§5.1-6) ──────────────────────────────────
                    -- Template | Instance
                    `scope`                VARCHAR(10) NOT NULL DEFAULT 'Template',
                    -- NULL for a Template. The house tenant column name; the
                    -- document calls it tenant_id.
                    `sub_institute_id`     BIGINT UNSIGNED NULL,
                    -- A Template may anchor to the shared catalogue task it was
                    -- written from; an Instance names the tenant's own task.
                    `catalogue_task_id`    BIGINT UNSIGNED NULL,
                    `user_jobrole_task_id` BIGINT UNSIGNED NULL,
                    -- Instance -> the Template it was seeded from.
                    `eso_template_id`      BIGINT UNSIGNED NULL,

                    `title`   VARCHAR(191) NOT NULL,
                    `version` INT NOT NULL DEFAULT 1,
                    -- Draft | Reviewed | Published | Retired
                    `status`  VARCHAR(12) NOT NULL DEFAULT 'Draft',

                    -- NOT IN THE DOCUMENT, but its own §1 requires it: a template
                    -- is an EXECUTION PATTERN, and §6.4 wants 20 covering all six
                    -- modes. A pattern has to declare which mode it embodies.
                    `execution_mode` VARCHAR(20) NULL,

                    -- ── Purpose (§5.7-8) ───────────────────────────────────
                    `objective`        TEXT NULL,
                    `expected_outcome` TEXT NULL,

                    -- ── Allocation (§5.9-12) ───────────────────────────────
                    `human_responsibility` TEXT NULL,
                    `agent_responsibility` TEXT NULL,
                    `human_decision_points` LONGTEXT NULL,  -- JSON list
                    `escalation_triggers`   LONGTEXT NULL,  -- JSON list

                    -- ── Workflow (§5.13) ───────────────────────────────────
                    -- JSON [{seq, description, actor: H|A|S, tool, output}]
                    `steps` LONGTEXT NULL,

                    -- ── I/O (§5.14-15) ─────────────────────────────────────
                    `inputs`  LONGTEXT NULL,  -- JSON [{name, source, format, required}]
                    `outputs` LONGTEXT NULL,  -- JSON [{name, format, destination}]

                    -- ── Governance (§5.16-17) ──────────────────────────────
                    `required_controls`  LONGTEXT NULL,  -- JSON list
                    `prohibited_actions` LONGTEXT NULL,  -- JSON list

                    -- ── Evidence (§5.18) ───────────────────────────────────
                    -- JSON [{evidence_type, competency_id, format}]
                    -- §5 calls this the connection back to the capability engine
                    -- and says explicitly not to drop it to save time.
                    `evidence_emitted` LONGTEXT NULL,

                    -- NOT IN THE DOCUMENT. §2 already establishes that an
                    -- AI-proposed value must never be presented as fact; a
                    -- generated ESO needs to be distinguishable from one a person
                    -- wrote for exactly the same reason.
                    -- human | ai-generated
                    `source` VARCHAR(16) NOT NULL DEFAULT 'human',
                    `model`  VARCHAR(100) NULL,

                    `created_by` BIGINT UNSIGNED NULL,
                    `updated_by` BIGINT UNSIGNED NULL,
                    `created_at` TIMESTAMP NULL,
                    `updated_at` TIMESTAMP NULL,
                    `deleted_at` TIMESTAMP NULL,

                    PRIMARY KEY (`id`),
                    KEY `eso_scope_index` (`scope`, `sub_institute_id`, `deleted_at`),
                    KEY `eso_task_index` (`user_jobrole_task_id`),
                    KEY `eso_catalogue_index` (`catalogue_task_id`),
                    KEY `eso_template_index` (`eso_template_id`),
                    KEY `eso_mode_index` (`execution_mode`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        // §2 field 10. Missed when jobrole_task_execution was created.
        if (!$this->columnExists('jobrole_task_execution', 'eso_template_id')) {
            DB::statement("
                ALTER TABLE `jobrole_task_execution`
                ADD COLUMN `eso_template_id` BIGINT UNSIGNED NULL AFTER `catalogue_task_id`,
                ADD KEY `jte_eso_template_index` (`eso_template_id`)
            ");
        }
    }

    public function down(): void
    {
        if ($this->columnExists('jobrole_task_execution', 'eso_template_id')) {
            DB::statement('ALTER TABLE `jobrole_task_execution` DROP COLUMN `eso_template_id`');
        }

        if ($this->tableExists('eso')) {
            DB::statement('DROP TABLE `eso`');
        }
    }

    /** information_schema directly - live is MariaDB 10.1, where Schema::hasTable() throws. */
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
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        ) !== [];
    }
};
