<?php

namespace App\Services\Org\Concerns;

/**
 * Moving references from one row to another, and counting them first.
 *
 * Extracted from DepartmentMergeService when JobRoleMergeService was written.
 * These three helpers are the part of a merge that MUST behave identically
 * whichever kind of thing is being merged: if a department merge and a job role
 * merge disagree about what "repoint" means, one of them is wrong and nobody
 * can tell which. A second copy would be a second answer.
 *
 * The behaviour below is unchanged from the original - this is a move, not a
 * rewrite.
 */
trait RepointsReferences
{
    /**
     * Repoint one column. Returns rows affected.
     *
     * A failure here is NOT swallowed. Inside a transaction, catching a
     * duplicate-key error and carrying on is how a merge reports success while
     * leaving rows pointing at a row that has just been retired. A missing
     * table or column is skipped; a real failure propagates and rolls the
     * whole merge back.
     *
     * Callers that repoint into a column carrying a UNIQUE key must resolve
     * the collisions BEFORE calling this - see JobRoleMergeService, where
     * `uq_jcm` and `uq_cjm` are settled first.
     *
     * @param int|list<int> $from
     */
    protected function repoint($db, string $table, string $column, $from, ?int $to): int
    {
        $fromIds = is_array($from) ? $from : [$from];

        if (!$this->hasColumn($db, $table, $column)) {
            return 0;
        }

        return $db->table($table)->whereIn($column, $fromIds)->update([$column => $to]);
    }

    protected function countIn($db, string $table, string $column, array $ids): int
    {
        if (!$this->hasColumn($db, $table, $column)) {
            return 0;
        }

        return $db->table($table)->whereIn($column, $ids)->count();
    }

    /**
     * Schema check that works on MariaDB 10.1.
     *
     * Laravel's Schema::hasColumn() selects generation_expression from
     * information_schema, a column 10.1 does not have - and live runs 10.1, so
     * that call throws there while working fine on dev.
     *
     * This is also the guard for tables that exist in a migration but not on
     * either database: `role_progressions` has a migration and no table, so a
     * merge that assumed it was there would fail on every run.
     *
     * @var array<string,bool>
     */
    private array $columnCache = [];

    protected function hasColumn($db, string $table, string $column): bool
    {
        $key = $table . '.' . $column;

        if (!array_key_exists($key, $this->columnCache)) {
            $found = $db->select(
                'SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
                  LIMIT 1',
                [$table, $column]
            );

            $this->columnCache[$key] = $found !== [];
        }

        return $this->columnCache[$key];
    }
}
