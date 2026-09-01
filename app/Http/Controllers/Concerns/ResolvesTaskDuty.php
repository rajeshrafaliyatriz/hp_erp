<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Which job role duty is an assigned task an instance of?
 *
 * ── WHY THIS IS SHARED ──────────────────────────────────────────────────────
 *
 * There are three places a `task` row is created — the assign form
 * (`front_desk\taskController`), the CSV import (`front_desk\BulkTaskController`)
 * and the API (`LegacyTaskController`) — and a task that does not know its duty
 * cannot reach the procedure written for it. The rule has to be identical in
 * all three, so it lives once, here.
 *
 * ── EXACTLY ONE MATCH RESOLVES; AMBIGUOUS HOLDS NULL ────────────────────────
 *
 * The assign screen offers task titles pulled from the job role task library,
 * so a new task usually IS a duty — but it arrives as a string, and 6,018 task
 * texts repeat across roles. A title can genuinely belong to several duties.
 *
 * Picking one would attach the wrong procedure to somebody's work, and it would
 * look entirely normal on screen. No link is a state the task drawer knows how
 * to say out loud; a wrong link is not.
 *
 * Measured on the existing 2,253 rows: exactly-one-match resolved 1,078 (48%).
 * The rest matched two or more duties and were deliberately left unresolved.
 */
trait ResolvesTaskDuty
{
    /**
     * Never throws. A task must still be creatable when this cannot answer.
     *
     * ── THE TWO TIE-BREAKERS, ADDED 2026-08-31 ──────────────────────────────
     *
     * Title matching alone leaves roughly half of all tasks unresolved, because
     * 6,018 task texts repeat across roles. Both extra arguments are OPTIONAL and
     * only consulted when the title is genuinely ambiguous, so no existing caller
     * changes behaviour by upgrading:
     *
     *   $jobRoleName  the role the CALLER named. The CSV import has a `jobrole`
     *                 column, so on that path the answer is usually stated
     *                 outright rather than inferred.
     *   $assigneeId   the person the task is FOR. Their own job role picks the
     *                 duty when several share a title.
     *
     * This is the same rule the one-off repair pass applied to existing rows
     * (migration 2026_08_31_160000), which resolved 8 of 11 ambiguous tasks on
     * live. New tasks now get the treatment repaired ones already had, instead of
     * the backlog being fixed while the inflow kept producing more.
     *
     * A stated role beats an inferred one: if the file says which role this duty
     * belongs to, that is a human's answer and outranks a guess from who happens
     * to have been assigned it.
     */
    protected function resolveTaskDuty(
        int $tenantId,
        ?string $title,
        ?int $assigneeId = null,
        ?string $jobRoleName = null
    ): ?int {
        $title = trim((string) $title);

        if ($title === '' || $tenantId <= 0) {
            return null;
        }

        try {
            /*
             * `jobrole_id` is now selected because the tie-breakers need it, and
             * the cap moved from 2 to 50. The old limit(2) answered only "is
             * there exactly one?", which is all the title-only rule needed. 50 is
             * far above any real match set — the worst live title belongs to 4
             * roles — and bounds a pathological one rather than pretending it
             * cannot happen.
             */
            $matches = DB::table('s_user_jobrole_task')
                ->where('sub_institute_id', $tenantId)
                ->whereNull('deleted_at')
                ->whereRaw('TRIM(LOWER(task)) = ?', [mb_strtolower($title)])
                ->limit(50)
                ->get(['id', 'jobrole_id']);

            if ($matches->count() === 1) {
                return (int) $matches->first()->id;
            }

            if ($matches->count() > 1) {
                // 1. THE ROLE THE CALLER NAMED.
                $namedRoleId = $this->jobRoleIdByName($tenantId, $jobRoleName);

                if ($namedRoleId !== null) {
                    $hit = $matches->firstWhere('jobrole_id', $namedRoleId);

                    if ($hit) {
                        return (int) $hit->id;
                    }
                }

                // 2. THE ROLE THE ASSIGNEE ACTUALLY HOLDS.
                foreach ($this->assigneeRoleIds($tenantId, $assigneeId) as $roleId) {
                    $hit = $matches->firstWhere('jobrole_id', $roleId);

                    if ($hit) {
                        return (int) $hit->id;
                    }
                }
            }

            /*
             * SAY WHICH KIND OF NOTHING THIS IS.
             *
             * All three outcomes returned a bare null, so afterwards nobody could
             * tell "this is ad-hoc work with no duty" from "several roles share
             * this title and I refused to guess" — and the second is a fixable
             * problem sitting in a column that looks identical to the first.
             *
             * Measured on live tenant 6: 595 tasks have no candidate at all and
             * 11 are ambiguous. Only the 11 are worth anybody's attention, and
             * until now there was no way to find them except by re-deriving the
             * match by hand.
             *
             * Ambiguity is logged, absence is not — 595 log lines saying "this
             * manually typed task is a manually typed task" would bury the 11.
             */
            if ($matches->count() > 1) {
                Log::info('Task duty ambiguous, left unlinked', [
                    'tenant' => $tenantId,
                    'title'  => $title,
                    'note'   => 'Several job roles share this task title. The assign form should '
                              . 'send jobrole_task_id so this does not need guessing.',
                ]);
            }

            return null;
        } catch (\Throwable $e) {
            // A DATABASE FAILURE IS NOT "NO DUTY". It used to return the same
            // null as both cases above, so an outage silently produced a batch
            // of permanently unlinked tasks that looked exactly like ordinary
            // ad-hoc work. The task is still created — that is deliberate, the
            // work is real — but the reason is now on the record.
            Log::warning('Task duty resolution failed', [
                'tenant' => $tenantId,
                'title'  => $title,
                'error'  => $e->getMessage(),
            ]);

            return null;
        }
    }
    /**
     * A job role id from the name a caller supplied. NULL when it does not
     * resolve to exactly one role — two roles sharing a name is precisely the
     * ambiguity this is meant to remove, so guessing there would defeat it.
     */
    private function jobRoleIdByName(int $tenantId, ?string $name): ?int
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $ids = DB::table('s_user_jobrole')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereRaw('TRIM(LOWER(jobrole)) = ?', [mb_strtolower($name)])
            ->limit(2)
            ->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /**
     * Every job role id an employee might hold, best first.
     *
     * BOTH COLUMNS ARE READ, and that is not belt-and-braces. `jobtitle_id` is 0
     * for most employees because the employee form writes the role to
     * `allocated_standards` instead; on live, 23 of 98 employees have the first
     * and 95 of 98 have the second. Reading either alone loses most people.
     *
     * `allocated_standards` is TEXT and historically held a comma-separated list,
     * so a bare integer and a list both have to work.
     *
     * @return int[]
     */
    private function assigneeRoleIds(int $tenantId, ?int $assigneeId): array
    {
        if (!$assigneeId || $assigneeId <= 0) {
            return [];
        }

        $user = DB::table('tbluser')
            ->where('id', $assigneeId)
            ->where('sub_institute_id', $tenantId)
            ->first(['jobtitle_id', 'allocated_standards']);

        if (!$user) {
            return [];
        }

        $ids = [];

        if ((int) ($user->jobtitle_id ?? 0) > 0) {
            $ids[] = (int) $user->jobtitle_id;
        }

        foreach (explode(',', (string) ($user->allocated_standards ?? '')) as $part) {
            $part = trim($part);

            if ($part !== '' && ctype_digit($part) && (int) $part > 0) {
                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }
}
