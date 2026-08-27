<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\DB;

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

            return $matches->count() === 1 ? (int) $matches->first() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
