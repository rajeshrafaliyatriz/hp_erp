<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links LMS learning assignments to the competency module.
 *
 * The Development & Career Path Workspace's "Learning Assignments" tab assigns
 * a course (sub_std_map) to an employee with a due date and tracks progress.
 * `lms_assignments` already models exactly that - user_id, course_id,
 * assignment_type, due_date, status, progress, assigned_by, approval workflow -
 * so this migration reuses it rather than adding a parallel
 * s_competency_learning_assignments table.
 *
 * IMPORTANT - shared ownership:
 * `lms_assignments` is created by migration 2026_07_28_094337_create_lms_assignments_table,
 * which belongs to the in-flight LMS work on another branch and is not present
 * in this tree. Every operation here is therefore guarded:
 *   - the table is created only if it is genuinely absent (so a fresh install of
 *     this branch alone still works), matching the live schema column for column;
 *   - each new column is added only if missing, so re-running after the LMS
 *     branch merges is a no-op rather than a failure;
 *   - down() drops only the columns added here, never the table.
 *
 * The added `source` column is what keeps the two consumers separated: rows
 * this workspace creates are tagged 'competency' and the competency endpoints
 * filter on it, so LMS-owned assignments never leak into the competency tab and
 * vice versa.
 */
return new class extends Migration
{
    /**
     * The LMS branch's own migration for this table. Its timestamp is earlier
     * than this file's, so when both exist it runs first and this migration
     * only adds columns. The one case that needs handling is the reverse: this
     * branch migrating a fresh database ALONE (creating the table below), then
     * the LMS branch merging - its Schema::create would then hit an existing
     * table. Recording it as already-run avoids that without editing their file.
     */
    private const LMS_OWNER_MIGRATION = '2026_07_28_094337_create_lms_assignments_table';

    public function up(): void
    {
        if (!Schema::hasTable('lms_assignments')) {
            Schema::create('lms_assignments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('course_id');
                $table->string('assignment_type', 191)->default('Mandatory');
                $table->date('due_date')->nullable();
                $table->string('status', 191)->default('Not Started');
                $table->enum('approval_status', ['approved', 'pending', 'rejected'])->default('approved')->index();
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_note', 500)->nullable();
                $table->integer('progress')->default(0);
                $table->string('assigned_by', 191)->nullable();
                $table->timestamp('assigned_on')->nullable();
                $table->unsignedBigInteger('sub_institute_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });

            $this->claimOwnerMigration();
        }

        Schema::table('lms_assignments', function (Blueprint $table) {
            if (!Schema::hasColumn('lms_assignments', 'development_plan_id')) {
                $table->unsignedBigInteger('development_plan_id')->nullable()->index();
            }
            if (!Schema::hasColumn('lms_assignments', 'competency_id')) {
                $table->unsignedBigInteger('competency_id')->nullable()->index();
            }
            if (!Schema::hasColumn('lms_assignments', 'assigned_by_id')) {
                // `assigned_by` is a display name (varchar); this is the tbluser id.
                $table->unsignedBigInteger('assigned_by_id')->nullable();
            }
            if (!Schema::hasColumn('lms_assignments', 'source')) {
                // 'competency' = created by the Development & Career workspace.
                $table->string('source', 30)->nullable()->index();
            }
        });
    }

    /**
     * Mark the LMS branch's create-table migration as already applied, so a
     * later `migrate` after that branch merges skips it instead of failing on
     * the table this migration just created. No-op when it is already recorded
     * (the normal case: their migration ran first and really did create it).
     */
    private function claimOwnerMigration(): void
    {
        if (!Schema::hasTable('migrations')) {
            return;
        }

        $alreadyRecorded = DB::table('migrations')
            ->where('migration', self::LMS_OWNER_MIGRATION)
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        DB::table('migrations')->insert([
            'migration' => self::LMS_OWNER_MIGRATION,
            'batch'     => (int) (DB::table('migrations')->max('batch') ?? 0) ?: 1,
        ]);
    }

    public function down(): void
    {
        // The LMS_OWNER_MIGRATION marker is deliberately left in place: the
        // table itself is not dropped here, so un-recording it would make a
        // later `migrate` try to create a table that still exists.
        if (!Schema::hasTable('lms_assignments')) {
            return;
        }

        Schema::table('lms_assignments', function (Blueprint $table) {
            foreach (['development_plan_id', 'competency_id', 'assigned_by_id', 'source'] as $column) {
                if (Schema::hasColumn('lms_assignments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
