<?php

namespace App\Services\Lms;

use Illuminate\Support\Facades\DB;

/**
 * THE ONE PLACE THAT SAYS "THIS PERSON IS ON THIS COURSE".
 *
 * ── THE BUG THIS EXISTS TO FIX ──────────────────────────────────────────────
 *
 * Assigning a course wrote `lms_assignments`. The learner's own course list
 * reads `lms_course_enroll`. Nothing joined the two, so a course an admin
 * assigned never appeared in My Learning at all.
 *
 * That is not a partial failure. Measured on live 2026-09-02: 58 assignment
 * rows, and 58 of them had no matching enrolment. Every course anybody had
 * ever assigned was invisible to the person it was assigned to.
 *
 * So every path that hands somebody a course now goes through here, and this
 * is the only code that writes an enrolment row.
 *
 * ── WHY IT IS NOT A `firstOrCreate` ─────────────────────────────────────────
 *
 * `lms_course_enroll` has NO unique key on (user_id, course_id) and 1,454 rows
 * were written by a `store()` with no dedupe, so duplicates already exist —
 * 3 pairs on live. A naive create would add more; a `firstOrCreate` would pick
 * an arbitrary one of the duplicates. This mirrors what `courses()` itself
 * does: take the LATEST non-deleted row, because that is the one the learner
 * is actually looking at.
 */
class EnrolmentWriter
{
    /**
     * Which connection to write on.
     *
     * NOT cosmetic. `DB::table()` silently uses the DEFAULT connection, so a
     * backfill invoked with --database=live read and wrote dev instead - it
     * reported 57 rows created and live was untouched. Anything that can run
     * against more than one database has to be told which one, explicitly.
     */
    private ?string $connection = null;

    public function on(?string $connection): self
    {
        $clone = clone $this;
        $clone->connection = $connection;

        return $clone;
    }

    private function db(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection($this->connection);
    }

    /**
     * Make sure $userId has a live enrolment on $courseId, and return its id.
     *
     * Returns null — and writes nothing — when the user or the course does not
     * belong to $tenant. A missing row is recoverable; a row invented in the
     * wrong organisation is not.
     *
     * @param string $status 'enrolled' for an approved assignment,
     *                       'pending' for a request awaiting review.
     */
    public function ensureEnrolment(
        int $userId,
        int $courseId,
        int $tenant,
        string $status = 'enrolled',
        ?string $startDate = null,
        ?string $endDate = null,
    ): ?int {
        // The course must exist, in this tenant. `sub_std_map.sub_institute_id`
        // is NOT NULL and is the authority on who owns a course.
        $courseOwned = $this->db()->table('sub_std_map')
            ->where('id', $courseId)
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            ->exists();

        if (! $courseOwned) {
            return null;
        }

        $userOwned = $this->db()->table('tbluser')
            ->where('id', $userId)
            ->where('sub_institute_id', $tenant)
            ->exists();

        if (! $userOwned) {
            return null;
        }

        $existing = $this->db()->table('lms_course_enroll')
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->first();

        if ($existing) {
            /*
             * NEVER DOWNGRADE.
             *
             * Someone who has finished a course and is then assigned it again
             * has still finished it — resetting them to 'enrolled' would erase
             * that. The one promotion allowed is pending -> enrolled, which is
             * what approving a request means.
             */
            if ($existing->status === 'pending' && $status === 'enrolled') {
                $this->db()->table('lms_course_enroll')
                    ->where('id', $existing->id)
                    ->update(['status' => 'enrolled', 'updated_at' => now()]);
            }

            return (int) $existing->id;
        }

        return (int) $this->db()->table('lms_course_enroll')->insertGetId([
            'user_id' => $userId,
            'course_id' => $courseId,
            'status' => $status,
            /*
             * `start_date` is `date NOT NULL` with no database default, so it
             * cannot be left to the caller. Today is the honest answer: the
             * enrolment starts when it is created. `end_date` IS nullable and
             * stays null - an open-ended enrolment is the normal case, and
             * inventing a deadline nobody set would be worse than having none.
             */
            'start_date' => $startDate ?? now()->toDateString(),
            'end_date' => $endDate,
            // Always written. The column is nullable and rows that predate it
            // being populated are exactly why the learner's course list cannot
            // safely filter on it.
            'sub_institute_id' => $tenant,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Soft-delete the live enrolment, if there is one. Used when an assignment
     * request is rejected — the person should not keep access they were
     * refused.
     */
    public function revokeEnrolment(int $userId, int $courseId, int $tenant): void
    {
        $this->db()->table('lms_course_enroll')
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('sub_institute_id', $tenant)
            ->whereNull('deleted_at')
            // A finished course is a fact about the past; refusing a later
            // request does not un-finish it.
            ->where('status', '<>', 'completed')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }
}
