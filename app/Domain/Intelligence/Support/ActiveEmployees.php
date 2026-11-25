<?php

namespace App\Domain\Intelligence\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * THE active-employee rule, in one place (decision D-6 default: the dashboard's canonical definition, verified in
 * App\Services\Dashboard\WorkforceMetricsService::activeEmployeeQuery):
 *
 *     tbluser.sub_institute_id = :tenant  AND  status = 1  AND  terminated_date IS NULL  AND  deleted_at IS NULL
 *
 * A NULL `status` is NOT active (consistent with `status = 1`). Detectors must not inline their own variant: three different definitions already exist
 * elsewhere in the product and they produce three different headcounts. Columns are table-qualified so the rule survives joins.
 */
final class ActiveEmployees
{
    /** Start a query over active employees of one tenant. */
    public static function query(ConnectionInterface $db, int $tenantId, string $alias = 'tbluser'): Builder
    {
        $table = $alias === 'tbluser' ? 'tbluser' : "tbluser as {$alias}";

        return self::constrain($db->table($table), $tenantId, $alias);
    }

    /** Apply the rule to a builder that already selects from `tbluser` under `$alias`. */
    public static function constrain(Builder $query, int $tenantId, string $alias = 'tbluser'): Builder
    {
        return $query
            ->where("{$alias}.sub_institute_id", $tenantId)
            ->where("{$alias}.status", 1)
            ->whereNull("{$alias}.terminated_date")
            ->whereNull("{$alias}.deleted_at");
    }
}
