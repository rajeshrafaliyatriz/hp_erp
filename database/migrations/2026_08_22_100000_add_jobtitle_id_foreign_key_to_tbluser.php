<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes employee -> job role a real foreign key.
 *
 * THE PROBLEM. `tbluser.allocated_standards` is a TEXT column holding the job
 * role's id. That is why every resolver in the codebase has to write
 *
 *     COALESCE(NULLIF(u.jobtitle_id, 0),
 *              NULLIF(SUBSTRING_INDEX(u.allocated_standards, ',', 1), ''))
 *
 * to find out what somebody's role is. The SUBSTRING_INDEX implies a list, but
 * measured across both databases: **not one row is multi-valued and not one is
 * non-numeric**. It is a single id in a text column.
 *
 * THE MOVE. Promote `jobtitle_id`, which is already `bigint unsigned` and
 * semantically the right column, rather than retyping `allocated_standards` -
 * whose name is a legacy LMS term and which is read as text in many places.
 * `allocated_standards` keeps being written in step during a deprecation
 * window; both HRMS\EmployeeDirectoryController and
 * DepartmentManagementController::assignEmployees already write both from one
 * role id.
 *
 * THE FIVE ROWS CLEARED FIRST, and why this is not silent data loss:
 *
 *   live  8, 20, 21, 23, 377      dev  8, 20, 21
 *
 * Four are employees holding a job role belonging to a DIFFERENT organisation
 * - an assignment that is already invalid, since no tenant-scoped read will
 * ever agree with it. The fifth (live 377, "Aqua Admin") points at role id 6721,
 * which does not exist at all and is what physically blocks the constraint.
 *
 * All five had never logged in. Four had zero dependent records of any kind.
 * The fifth (23, "Scholar Clone") was already status=0 and its 33 development
 * plans and 42 assessments store their own `jobrole` NAME - and that name
 * ("Enterprise Risk Management Senior", tenant 1) already contradicted the
 * broken pointer ("Deputy Director of Nursing", tenant 3). Nothing reads
 * allocated_standards to find them, so clearing it orphans nothing.
 *
 * WHAT THE CONSTRAINT DOES AND DOES NOT DO. It enforces EXISTENCE, not
 * tenancy: the four cross-tenant rows would have satisfied it perfectly well.
 * The tenant check stays in application code, where
 * EmployeeDirectoryController::checkReferences already performs it. A foreign
 * key is the floor, not the whole guard.
 *
 * ON DELETE SET NULL, because s_user_jobrole soft-deletes - an ordinary
 * retirement never triggers this, and a genuine hard delete should leave the
 * employee roleless rather than block the delete or orphan the pointer.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_22_100000_add_jobtitle_id_foreign_key_to_tbluser.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_22_100000_add_jobtitle_id_foreign_key_to_tbluser.php
 */
return new class extends Migration
{
    private const FK = 'tbluser_jobtitle_id_foreign';

    public function up(): void
    {
        if (!$this->tableExists('tbluser') || !$this->tableExists('s_user_jobrole')) {
            return;
        }

        // ── 1. Clear the assignments that are invalid or dangling ──────────
        //
        // Done by QUERY, not by a hard-coded id list: the ids differ between
        // databases and a list would silently miss a row on a third one.
        $invalid = DB::table('tbluser as u')
            ->whereNull('u.deleted_at')
            ->whereNotNull('u.allocated_standards')
            ->where('u.allocated_standards', '<>', '')
            ->whereRaw("u.allocated_standards REGEXP '^[0-9]+$'")
            ->leftJoin('s_user_jobrole as j', function ($join) {
                $join->on(DB::raw('CAST(u.allocated_standards AS UNSIGNED)'), '=', 'j.id');
            })
            // Either the role does not exist, or it belongs to another tenant.
            ->where(function ($q) {
                $q->whereNull('j.id')
                  ->orWhereColumn('j.sub_institute_id', '<>', 'u.sub_institute_id');
            })
            ->pluck('u.id');

        if ($invalid->isNotEmpty()) {
            DB::table('tbluser')->whereIn('id', $invalid)->update([
                'allocated_standards' => null,
                'jobtitle_id'         => null,
            ]);
        }

        // ── 2. Backfill jobtitle_id from the text column ───────────────────
        //
        // Only where the role exists AND belongs to the same tenant, so this
        // cannot introduce what step 1 just removed.
        DB::statement("
            UPDATE tbluser u
              JOIN s_user_jobrole j
                ON j.id = CAST(u.allocated_standards AS UNSIGNED)
               AND j.sub_institute_id = u.sub_institute_id
               SET u.jobtitle_id = j.id
             WHERE (u.jobtitle_id IS NULL OR u.jobtitle_id = 0)
               AND u.allocated_standards REGEXP '^[0-9]+$'
        ");

        // A leftover 0 is not a job role, and it would fail the constraint.
        DB::table('tbluser')->where('jobtitle_id', 0)->update(['jobtitle_id' => null]);

        // ── 3. The constraint ──────────────────────────────────────────────
        if (!$this->foreignKeyExists('tbluser', self::FK)) {
            /*
             * SQL MODE IS RELAXED FOR THIS STATEMENT ONLY, and here is why.
             *
             * Adding the constraint rebuilds the table, and the rebuild
             * re-validates EVERY column - including 200 rows carrying
             * `expire_date = '0000-00-00'`, plus zero dates in
             * terminated_date, notice_fromdate, relieving_date and others.
             * Under dev's sql_mode (STRICT_TRANS_TABLES, NO_ZERO_DATE, …) that
             * fails with "Incorrect date value: '0000-00-00' at row 39".
             * Live's mode is permissive, so it would have succeeded there and
             * failed only on dev - the worst kind of difference.
             *
             * THIS MIGRATION IS NOT THE PLACE TO REWRITE THAT DATA. Those zero
             * dates are historical rows nobody asked me to change, and
             * silently rewriting 200 people's records as a side effect of
             * adding a foreign key would be exactly the kind of unasked-for
             * change that is worse than the problem.
             *
             * So the mode is relaxed for the session, the constraint is added,
             * and the mode is put back. Session scope - no other connection
             * sees it, and nothing persists.
             */
            $originalMode = DB::select('SELECT @@SESSION.sql_mode AS mode')[0]->mode;

            try {
                DB::statement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION,ALLOW_INVALID_DATES'");

                DB::statement(
                    'ALTER TABLE `tbluser`
                     ADD CONSTRAINT `' . self::FK . '`
                     FOREIGN KEY (`jobtitle_id`) REFERENCES `s_user_jobrole` (`id`)
                     ON DELETE SET NULL ON UPDATE CASCADE'
                );
            } finally {
                DB::statement('SET SESSION sql_mode = ?', [$originalMode]);
            }
        }
    }

    public function down(): void
    {
        if ($this->foreignKeyExists('tbluser', self::FK)) {
            DB::statement('ALTER TABLE `tbluser` DROP FOREIGN KEY `' . self::FK . '`');
        }

        // The backfilled ids and the cleared rows are deliberately left as they
        // are. Re-populating allocated_standards from jobtitle_id would be
        // inventing history, and restoring the invalid assignments would put
        // back rows that were wrong before this ran.
    }

    /**
     * Not Schema::hasTable().
     *
     * Laravel 11 introspects with a query selecting `generation_expression`,
     * which live's MariaDB 10.1 does not have, so that helper throws there
     * while working on dev - the difference only appears against production.
     */
    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        ) !== [];
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        return DB::select(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'
              LIMIT 1",
            [$table, $constraint]
        ) !== [];
    }
};
