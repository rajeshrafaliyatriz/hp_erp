<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stop hrms_emp_leaves accepting requests that are not requests. F-94, F-123.
 *
 * TWO DEFECTS, ONE TABLE.
 *
 * F-94 - the foreign key on leave_type_id named the WRONG PARENT:
 *
 *     $table->foreign('user_id')      ->references('id')->on('tbluser')...
 *     $table->foreign('leave_type_id')->references('id')->on('tbluser')...
 *                                                          ^^^^^^^ copied
 *
 * so the database constrained a leave's TYPE to be a valid USER id. That is how
 * 15 rows in tenant 3 came to reference leave type 11 - no such leave type has
 * ever existed, but tbluser 11 does, so the constraint was satisfied. They
 * reported as "Unassigned" in every leave report and could be counted against
 * no entitlement.
 *
 * F-123 - from_date, user_id and leave_type_id were all nullable, and two rows
 * had no dates at all. Pending since January and March, invisible to every date
 * filter, counted in every pending total.
 *
 * All 17 were soft-deleted first (reversal in _local-backups), so the data is
 * clean before the constraints go on. Verified immediately before this
 * migration: 0 rows with an unmatched leave type, 0 with a null from_date.
 *
 * ORDER MATTERS. The old foreign key has to be dropped before the column can be
 * made NOT NULL, and the new one added last so it validates against data that
 * is already clean.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ALTER applies to EVERY row, including soft-deleted ones - which is
         * what the first run of this migration got wrong. It checked only live
         * rows, passed, and then failed on
         *
         *   SQLSTATE[01000] 1265 Data truncated for column 'from_date' at row 11
         *
         * because the two dateless rows (F-123) are soft-deleted, not gone.
         * Under STRICT_TRANS_TABLES, MODIFY ... NOT NULL cannot convert their
         * NULL into anything.
         *
         * So: stamp those dead rows with the date they were created, which is
         * the only date anyone knows about them, and record it in the reversal
         * so the NULLs come back if this is rolled back. Nothing live changes -
         * these rows are already invisible to every screen.
         */
        DB::statement(
            'UPDATE hrms_emp_leaves
                SET from_date = COALESCE(from_date, DATE(created_at)),
                    to_date   = COALESCE(to_date, DATE(created_at))
              WHERE deleted_at IS NOT NULL
                AND from_date IS NULL
                AND created_at IS NOT NULL'
        );

        /*
         * Same problem, second form: a FOREIGN KEY also applies to every row,
         * and the 15 soft-deleted F-94 rows still point at leave type 11, which
         * has never existed. They cannot stay as they are and have the corrected
         * constraint go on.
         *
         * They are repointed at their own tenant's first active leave type. They
         * are soft-deleted, so nothing displays them; this is only so the
         * constraint can be applied at all.
         *
         * THIS IS THE ONE THING THE REVERSAL CANNOT PUT BACK EXACTLY. Restoring
         * leave_type_id = 11 would be rejected by the very constraint this adds.
         * The reversal script says so, and gives the statement to drop the
         * constraint first if a byte-for-byte restore is genuinely wanted.
         */
        DB::statement(
            'UPDATE hrms_emp_leaves hel
                JOIN (
                      SELECT sub_institute_id, MIN(id) AS fallback_type_id
                        FROM hrms_leave_types
                       WHERE deleted_at IS NULL
                    GROUP BY sub_institute_id
                ) fallback ON fallback.sub_institute_id = hel.sub_institute_id
           LEFT JOIN hrms_leave_types hlt ON hlt.id = hel.leave_type_id
                 SET hel.leave_type_id = fallback.fallback_type_id
               WHERE hel.deleted_at IS NOT NULL
                 AND hlt.id IS NULL'
        );

        // Now check EVERY row, not just the live ones.
        $unmatched = DB::table('hrms_emp_leaves as hel')
            ->leftJoin('hrms_leave_types as hlt', 'hlt.id', '=', 'hel.leave_type_id')
            ->whereNull('hlt.id')
            ->count();

        $undated = DB::table('hrms_emp_leaves')
            ->where(function ($q) {
                $q->whereNull('from_date')->orWhereNull('user_id')->orWhereNull('leave_type_id');
            })
            ->count();

        if ($unmatched > 0 || $undated > 0) {
            throw new RuntimeException(
                "hrms_emp_leaves is not clean enough to constrain: {$unmatched} row(s) reference a "
                . "leave type that does not exist and {$undated} row(s) have a null from_date, "
                . "user_id or leave_type_id. Clear or soft-delete them first - see F-94 and F-123 "
                . 'in Docs/hrit-audit/AUDIT-HRIT-MANAGEMENT.md. Soft-deleted rows count too - '
                . 'ALTER applies to every row in the table.'
            );
        }

        // 1. Drop the foreign key that named tbluser as the parent of a leave type.
        $this->dropForeignIfExists('hrms_emp_leaves', 'hrms_emp_leaves_leave_type_id_foreign');

        // 2. NOT NULL. A leave request without a date, an employee or a type is
        //    not a leave request, and the table should say so.
        //    Raw SQL: Schema::change() needs Doctrine DBAL, which is not installed.
        DB::statement('ALTER TABLE hrms_emp_leaves MODIFY from_date DATE NOT NULL');
        DB::statement('ALTER TABLE hrms_emp_leaves MODIFY user_id BIGINT(20) UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE hrms_emp_leaves MODIFY leave_type_id BIGINT(20) UNSIGNED NOT NULL');

        // 3. The foreign key it should always have had.
        //    NO ACTION matches every other constraint on this table: leave types
        //    are soft-deleted, never removed, so cascade would be misleading.
        DB::statement(
            'ALTER TABLE hrms_emp_leaves
               ADD CONSTRAINT hrms_emp_leaves_leave_type_id_foreign
               FOREIGN KEY (leave_type_id) REFERENCES hrms_leave_types (id)
               ON DELETE NO ACTION ON UPDATE NO ACTION'
        );
    }

    public function down(): void
    {
        $this->dropForeignIfExists('hrms_emp_leaves', 'hrms_emp_leaves_leave_type_id_foreign');

        DB::statement('ALTER TABLE hrms_emp_leaves MODIFY from_date DATE NULL');
        DB::statement('ALTER TABLE hrms_emp_leaves MODIFY user_id BIGINT(20) UNSIGNED NULL');
        DB::statement('ALTER TABLE hrms_emp_leaves MODIFY leave_type_id BIGINT(20) UNSIGNED NULL');

        // Restores the original, wrong, parent - because that is what rolling
        // back means. It is recorded here so nobody re-derives it as intentional.
        DB::statement(
            'ALTER TABLE hrms_emp_leaves
               ADD CONSTRAINT hrms_emp_leaves_leave_type_id_foreign
               FOREIGN KEY (leave_type_id) REFERENCES tbluser (id)
               ON DELETE NO ACTION ON UPDATE NO ACTION'
        );
    }

    private function dropForeignIfExists(string $table, string $constraint): void
    {
        $exists = DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->exists();

        if ($exists) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
        }
    }
};
