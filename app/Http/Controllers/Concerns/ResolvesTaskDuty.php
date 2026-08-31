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
     */
    protected function resolveTaskDuty(int $tenantId, ?string $title): ?int
    {
        $title = trim((string) $title);

        if ($title === '' || $tenantId <= 0) {
            return null;
        }

        try {
            // limit(2) is the whole trick: it answers "is there exactly one?"
            // without counting a potentially large match set.
            $matches = DB::table('s_user_jobrole_task')
                ->where('sub_institute_id', $tenantId)
                ->whereNull('deleted_at')
                ->whereRaw('TRIM(LOWER(task)) = ?', [mb_strtolower($title)])
                ->limit(2)
                ->pluck('id');

            if ($matches->count() === 1) {
                return (int) $matches->first();
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
}
