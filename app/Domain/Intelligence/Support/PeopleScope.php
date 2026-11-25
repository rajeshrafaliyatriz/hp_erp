<?php

namespace App\Domain\Intelligence\Support;

use App\Domain\Intelligence\Access\IntelligenceCaller;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * WHICH PEOPLE a caller's insights may be about, derived from the caller's role on the server (never from a request parameter):
 *
 *   self         -> only the caller
 *   team         -> active people whose reporting_manager_id is the caller (direct reports only; D-7 default, no hierarchy roll-up)
 *   department   -> active people in the caller's own department_id (a caller with no department sees NOBODY: fail closed)
 *   organization -> every active person of the caller's tenant
 *
 * All of them are active employees of the caller's tenant (ActiveEmployees). `userIds()` returns a sub-select usable as `whereIn('x.user_id', ...)`, so a
 * detector never loads the list into PHP and never forgets to scope a table to the people it may talk about.
 */
final class PeopleScope
{
    /** A builder selecting `tbluser.id` of the people in scope. */
    public static function userIds(ConnectionInterface $db, IntelligenceCaller $caller): Builder
    {
        $query = ActiveEmployees::query($db, $caller->tenantId)->select('tbluser.id');

        switch ($caller->scope()) {
            case 'self':
                $query->where('tbluser.id', $caller->userId);
                break;
            case 'team':
                $query->where('tbluser.reporting_manager_id', $caller->userId);
                break;
            case 'department':
                if ($caller->departmentId === null) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->where('tbluser.department_id', $caller->departmentId);
                }
                break;
            default: // organization: the tenant filter above is the whole scope
                break;
        }

        return $query;
    }

    /** How many people are in scope (the denominator for coverage and for the small-group rule). */
    public static function count(ConnectionInterface $db, IntelligenceCaller $caller): int
    {
        return (int) self::userIds($db, $caller)->count();
    }
}
