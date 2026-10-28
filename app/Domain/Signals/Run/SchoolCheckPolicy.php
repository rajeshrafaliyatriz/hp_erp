<?php

namespace App\Domain\Signals\Run;

/**
 * Which target schools a run may mark as "checked" (g2g_target_accounts.last_checked_at), so they are not searched again for the recheck days.
 * Pure: it reads the run's own record of what was executed and what failed.
 *
 * ONE policy for schools with and without a website. A school is checked when
 *   - at least one of its searches was EXECUTED in this run, and
 *   - NONE of its executed searches failed.
 * So a search that was skipped (time budget, never reached) leaves the school unchecked; a school whose searches all failed is unchecked; and a
 * partial failure (one of two searches failed) is also unchecked, because the failed search was not done and the retry policy is to try the school
 * again on the next run. An empty but successful search counts as a real check. A school that was never attempted is never in the result.
 *
 * Search ids: a school with a website is searched as `account-<id>`; one without as `account-name-<id>-<n>` (see IcpResearchPlanner).
 */
final class SchoolCheckPolicy
{
    /**
     * @param  list<int>  $accountIds  the schools this run planned searches for
     * @param  list<string>  $executed  ids of the searches that were executed (attempted)
     * @param  list<string>  $failed  ids of the executed searches that failed
     * @return list<int>
     */
    public static function completed(array $accountIds, array $executed, array $failed): array
    {
        $done = [];
        foreach (array_unique(array_map('intval', $accountIds)) as $id) {
            $mine = fn (string $searchId) => preg_match('/^account-(?:name-)?' . $id . '(?:-\d+)?$/', $searchId) === 1;
            $ran = array_filter(array_map('strval', $executed), $mine);
            $bad = array_filter(array_map('strval', $failed), $mine);
            if ($ran !== [] && $bad === []) {
                $done[] = $id;
            }
        }

        return $done;
    }
}
