<?php

namespace App\Domain\AI\Support;

use Illuminate\Support\Facades\Schema;

/**
 * "Only the rows that are still in use", across tables that disagree about how to
 * say so.
 *
 * WHY THIS EXISTS
 *
 * G2G has at least three conventions for `status`, and they are not interchangeable:
 *
 *   competency          varchar  'active' | 'draft' | 'published'
 *   s_jobrole           enum     'Active' | 'Inactive'          (capitalised)
 *   hrms_departments    int      1 | 0
 *   agentic_agents      varchar  'deployed' | 'draft' | 'paused'
 *   ai_templates        varchar  'published' | 'draft' | 'archived'
 *
 * `where('status', 1)` is correct for exactly one of those and silently matches
 * NOTHING on the others — which is the worst possible failure for a filter, because
 * an empty result is indistinguishable from "this organisation has no records". It
 * cost two bugs before this class existed: the template preview fell back to its
 * illustration for an organisation holding 199 competencies, and the job-role picker
 * in AI Policies rendered empty against 3,347 rows.
 *
 * THE RULE IS INVERTED, AND THAT IS THE POINT
 *
 * This does not ask "is the row active". It asks "is the row explicitly NOT active",
 * and keeps everything else. Requiring a known-active value means every new
 * convention empties the query; excluding known-inactive values means every new
 * convention is included until somebody decides otherwise. Over-inclusion shows a
 * row that should have been hidden — visible, reported, fixed in a line. Under-
 * inclusion hides data and looks like an empty database.
 *
 * It is deliberately NOT for the `ai_*` tables this feature owns. Those have one
 * convention each, chosen in their own migration, and their call sites name the value
 * they mean — `status = 1` for a credential, `'published'` for a template. A helper
 * guessing on top of a schema we control would hide a real mistake.
 */
final class ActiveRowFilter
{
    /**
     * Values that mean "retired", lowercased and trimmed before comparison.
     *
     * Kept small and literal. A pattern match would catch `inactive` and also
     * `deactivation_pending`, which is not the same statement.
     */
    private const RETIRED = [
        '0', 'inactive', 'archived', 'deleted', 'retired', 'disabled', 'false', 'no',
    ];

    /**
     * Exclude explicitly-retired rows from a query, if the table says anything at all.
     *
     * A table with no such column is not filtered — it has no opinion, and inventing
     * one would drop every row.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    public static function apply($query, string $table, string $column = 'status'): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count(self::RETIRED), '?'));

        // NULL is kept: a row that has never been given a status has not been
        // retired. Bindings rather than interpolation for the values; the column is
        // quoted from a `Schema::hasColumn` result, so it cannot be caller-supplied.
        $query->whereRaw(
            sprintf(
                '(`%s` IS NULL OR LOWER(TRIM(`%s`)) NOT IN (%s))',
                $column,
                $column,
                $placeholders
            ),
            self::RETIRED
        );
    }
}
