<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reference documents attached to a task — the material an employee needs to do it.
 *
 * ── WHY THIS IS NOT THE EXISTING ATTACHMENT TABLE ───────────────────────────
 *
 * `task_management_attachment_versions` already exists, is empty on both
 * databases, and has routes and permissions. Reusing it was tempting and would
 * be wrong: it is keyed `UNIQUE (task_id, version)` and models VERSIONS OF ONE
 * FILE. Its `mirrorLatest()` writes the newest version onto
 * `task.task_attachment` as the current attachment, and `restore` copies an old
 * version forward.
 *
 * Overload it with a document SET and `version 2` silently changes meaning from
 * "the second draft of this file" to "the second document" — so uploading a
 * safety checklist would stamp it over the task's attachment as though it
 * replaced it, and "restore version 1" would restore a different document.
 * Cheap to build, permanently confusing to read.
 *
 * These are two different ideas and they get two tables:
 *
 *   task.task_attachment (+ versions)  THE file for this task — the deliverable
 *   task_documents                     reference material FOR doing the task
 *
 * ── SHAPE COPIED FROM s_performance_attachments ─────────────────────────────
 *
 * That is the only table in this codebase holding real documents with real
 * metadata (17 rows), and its column set is exactly right — title,
 * document_type, mime_type, uploaded_by_name. It could not be reused directly
 * because `review_id` is NOT NULL, so attaching a task would mean inventing a
 * performance review id.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_27_210000_create_task_documents.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_27_210000_create_task_documents.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->tableExists('task_documents')) {
            return;
        }

        DB::statement("
            CREATE TABLE `task_documents` (
                `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `sub_institute_id` BIGINT UNSIGNED NOT NULL,

                -- No FK: tasks are soft deleted, and a hard constraint would
                -- either block that or cascade a delete through somebody's
                -- completed work history. Same reasoning as the department
                -- content tables.
                `task_id`          BIGINT UNSIGNED NOT NULL,

                -- What this document IS, in the uploader's words. Separate from
                -- file_name, because 'Safety checklist' is useful and
                -- 'scan_0042.pdf' is not.
                `title`            VARCHAR(191) NOT NULL,
                `document_type`    VARCHAR(40) NULL,

                -- The name the user recognises, kept only as a display label and
                -- as what the browser is told to save. Never used as a path.
                `file_name`        VARCHAR(191) NOT NULL,
                -- The generated storage path, on the `public` disk.
                `file_path`        VARCHAR(500) NOT NULL,
                `mime_type`        VARCHAR(100) NULL,
                `file_size`        BIGINT UNSIGNED NULL,

                -- The name is denormalised alongside the id because a document
                -- outlives the account that uploaded it, and 'uploaded by user
                -- 41' is not an answer once that user is gone.
                `uploaded_by`      BIGINT UNSIGNED NULL,
                `uploaded_by_name` VARCHAR(191) NULL,

                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                `deleted_at` TIMESTAMP NULL,

                PRIMARY KEY (`id`),
                KEY `task_documents_lookup` (`sub_institute_id`, `task_id`, `deleted_at`),
                KEY `task_documents_task_index` (`task_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public function down(): void
    {
        if ($this->tableExists('task_documents')) {
            DB::statement('DROP TABLE `task_documents`');
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
};
