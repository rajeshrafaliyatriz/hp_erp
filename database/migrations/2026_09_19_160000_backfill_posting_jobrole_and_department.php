<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair the two columns job postings were saved without.
 *
 * Same class of fault as the job-role industry backfill: a read path filters or
 * joins on a column that the write path never set correctly, so nothing errors
 * and the data is simply not there when it is needed.
 *
 * ── jobrole_id ──────────────────────────────────────────────────────────────
 *
 * The assessment invite resolves a posting's exam template through it. NULL on
 * 11 of 13 tenant-6 postings, so those candidates could never be assessed - the
 * invite answers "No assessment blueprint matches this role" no matter how many
 * templates exist.
 *
 * ── department_id ───────────────────────────────────────────────────────────
 *
 * Worse than missing: WRONG. The posting form falls back to the job-role ROW id
 * when a role carries no department_id, so 12 of 13 tenant-6 postings point at
 * ids 131-144, which are another tenant's "Manufacturing" roles rather than any
 * department of this organisation. The careers page and the openings table both
 * join on it, and both render blank.
 *
 * ── HOW EACH IS RESOLVED ────────────────────────────────────────────────────
 *
 * From the posting TITLE, which is a job role name picked from that same
 * dropdown:
 *
 *   title -> s_jobrole.jobrole                     => jobrole_id   (catalogue)
 *   title -> s_user_jobrole (this tenant).department
 *         -> hrms_departments.id                   => department_id
 *
 * Measured before writing: all 13 tenant-6 postings resolve on both columns.
 *
 * A posting that resolves to nothing is left exactly as it is. Guessing would
 * file a vacancy under a department it does not belong to, which is harder to
 * notice than a blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleFixed = 0;
        $deptFixed = 0;

        DB::table('talent_job_postings')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunk(200, function ($postings) use (&$roleFixed, &$deptFixed) {
                foreach ($postings as $posting) {
                    $title = trim((string) $posting->title);
                    if ($title === '') {
                        continue;
                    }

                    $changes = [];

                    if (empty($posting->jobrole_id)) {
                        $jobroleId = DB::table('s_jobrole')->where('jobrole', $title)->value('id');
                        if ($jobroleId) {
                            $changes['jobrole_id'] = (int) $jobroleId;
                        }
                    }

                    // Only when it does not already point at a real department
                    // of this organisation - a correct one is left alone.
                    $valid = $posting->department_id && DB::table('hrms_departments')
                        ->where('id', $posting->department_id)
                        ->where('sub_institute_id', $posting->sub_institute_id)
                        ->whereNull('deleted_at')
                        ->exists();

                    if (!$valid) {
                        $department = DB::table('s_user_jobrole')
                            ->where('sub_institute_id', $posting->sub_institute_id)
                            ->whereNull('deleted_at')
                            ->where('jobrole', $title)
                            ->value('department');

                        if ($department) {
                            $departmentId = DB::table('hrms_departments')
                                ->where('sub_institute_id', $posting->sub_institute_id)
                                ->where('department', $department)
                                ->whereNull('deleted_at')
                                ->value('id');

                            if ($departmentId) {
                                $changes['department_id'] = (int) $departmentId;
                            }
                        }
                    }

                    if ($changes === []) {
                        continue;
                    }

                    DB::table('talent_job_postings')->where('id', $posting->id)->update($changes);
                    if (isset($changes['jobrole_id'])) {
                        $roleFixed++;
                    }
                    if (isset($changes['department_id'])) {
                        $deptFixed++;
                    }
                }
            });

        echo "  jobrole_id set on {$roleFixed} posting(s); department_id repaired on {$deptFixed}\n";
    }

    /**
     * Deliberately irreversible.
     *
     * The previous department_id values were job-role ids that pointed at
     * another tenant's rows, and the previous jobrole_ids were NULL. Restoring
     * either would put back the fault, and nothing records which rows this
     * migration touched.
     */
    public function down(): void
    {
        // no-op, on purpose
    }
};
