<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * X-12 / X-11 — idempotency for two reactors that write things people act on.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE CONSTRAINT I WANTED IS UNAVAILABLE, AND THE REASON IS IN THE DATA.
 *
 *   The natural key for a learning assignment is (user, course, plan).
 *   `lms_assignments` ALREADY CONTAINS 4 ROWS THAT VIOLATE IT - and 11 duplicate
 *   (user, course) pairs. All 49 rows carry source='competency', so there is no
 *   NULL to hide behind either.
 *
 *   Enforcing it would mean deleting or editing existing rows. THE NO-DELETION
 *   RULE FORBIDS THAT, and it is the right rule: those rows are somebody's
 *   record of what was assigned.
 *
 * SO THE CONSTRAINT IS SCOPED TO WHAT THIS SYSTEM CREATES.
 *
 *   `origin_event_id` is NULL on all 49 existing rows and NOT NULL on everything
 *   a reactor writes. MySQL treats NULLs as DISTINCT in a unique index, so the
 *   pre-existing duplicates sit outside the constraint by construction and the
 *   index builds without touching one of them.
 *
 *   (The same NULL-distinctness that made NULL the WRONG choice for
 *   `g2g_terminology`'s global sentinel is exactly the property wanted here.
 *   It is a sharp edge in both directions.)
 *
 * WHAT THIS GUARANTEES AND WHAT IT DOES NOT:
 *   GUARANTEED - one assignment per (person, course, originating event). A retried
 *                or redelivered event cannot assign the same course twice.
 *   NOT GUARANTEED - that a person is never assigned the same course by two
 *                DIFFERENT events. That is guarded in LearningAssigner by an
 *                explicit query, which is a weaker promise than an index, and it
 *                is stated as weaker rather than described as if it were one.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lms_assignments') && !Schema::hasColumn('lms_assignments', 'origin_event_id')) {
            Schema::table('lms_assignments', function (Blueprint $table) {
                $table->unsignedBigInteger('origin_event_id')->nullable()->after('source')
                    ->comment('g2g_event.id that caused this assignment; NULL = created before X-12');
            });
        }

        $this->addUniqueIfAbsent(
            'lms_assignments',
            'lms_assignments_origin_unique',
            ['user_id', 'course_id', 'origin_event_id']
        );

        // X-11: one certificate per enrolment. The table is EMPTY (0 rows), so
        // this is the strong constraint, added while it is still free to add.
        $this->addUniqueIfAbsent(
            'lms_certificates',
            'lms_certificates_enrollment_unique',
            ['enrollment_id']
        );
    }

    /**
     * ASSERT BEFORE CREATING (R15/R16). A unique index that fails to build leaves
     * the migration half-applied and the reactor without its guarantee, so the
     * conflict count is measured and reported rather than discovered by an
     * exception.
     */
    private function addUniqueIfAbsent(string $table, string $name, array $columns): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        $exists = collect(Schema::getIndexes($table))
            ->contains(fn ($i) => ($i['name'] ?? '') === $name);

        if ($exists) {
            return;
        }

        $conflicts = DB::table($table)
            ->select($columns)
            ->selectRaw('count(*) as c')
            ->groupBy($columns)
            ->havingRaw('count(*) > 1')
            ->get()
            // Rows where any indexed column is NULL cannot collide in MySQL.
            ->filter(function ($row) use ($columns) {
                foreach ($columns as $c) {
                    if ($row->$c === null) return false;
                }
                return true;
            });

        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException(
                "Refusing to add $name: $table already holds {$conflicts->count()} conflicting group(s) on ("
                . implode(', ', $columns) . '). Resolving them would mean editing existing rows.'
            );
        }

        Schema::table($table, function (Blueprint $t) use ($name, $columns) {
            $t->unique($columns, $name);
        });
    }

    /**
     * DELIBERATELY EMPTY - same argument as the X-06 tables. Dropping the index
     * would silently restore the ability to double-assign, and dropping the column
     * would discard the record of WHY each assignment exists.
     */
    public function down(): void
    {
        // no-op by design; see the docblock.
    }
};
