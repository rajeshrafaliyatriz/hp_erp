<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair the task→duty links that text matching could never resolve.
 *
 * ── WHAT WENT WRONG ─────────────────────────────────────────────────────────
 *
 * 2026_08_27_200000 backfilled `task.jobrole_task_id` by matching the task
 * title against `s_user_jobrole_task.task`, and — correctly — only where
 * exactly ONE row matched. Its own comment then said:
 *
 *   "Going forward the assign form sets this directly, so the text match is a
 *    one-time reconciliation and not the mechanism."
 *
 * The assign form never did. So text matching stayed the mechanism, and the
 * column stopped growing the moment the backfill finished.
 *
 * The gap is structural, not incidental. Job roles draw from a shared task
 * catalogue, so the same sentence belongs to several roles at once. Measured on
 * live tenant 6: "Adhere to project standards in the collection of security
 * assessment metrics" exists on FOUR roles — Back End Developer, Front End
 * Developer, Full Stack Developer and Associate Software Engineer. No rule that
 * reads only the title can pick between them, and it is right not to try.
 *
 * ── THE RULE THIS USES INSTEAD ──────────────────────────────────────────────
 *
 * When several duties share a title, prefer the one belonging to THE JOB ROLE
 * THE ASSIGNED EMPLOYEE ACTUALLY HOLDS. If a Full Stack Developer was given
 * that task, the Full Stack Developer duty is the one meant.
 *
 * Measured before writing, against live tenant 6: 11 tasks are ambiguous, and
 * this resolves 8 of them. The other 3 are assigned to somebody whose job role
 * is not among the candidates at all — those stay NULL, because a link invented
 * there would be a fabricated procedure attached to somebody's work.
 *
 * ONE-TIME AND NARROW. It only touches rows where jobrole_task_id IS NULL, so
 * re-running changes nothing and it can never overwrite a real link. Going
 * forward the assign form supplies the id, which is the fix this reconciles to.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_31_160000_repair_ambiguous_task_duty_links.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_31_160000_repair_ambiguous_task_duty_links.php
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['task', 's_user_jobrole_task', 'tbluser', 's_user_jobrole'] as $table) {
            if (! $this->tableExists($table)) {
                return;
            }
        }

        $unlinked = DB::table('task')
            ->whereNull('deleted_at')
            ->whereNull('jobrole_task_id')
            ->whereNotNull('task_title')
            ->where('task_title', '!=', '')
            ->get(['id', 'task_title', 'task_allocated_to', 'sub_institute_id']);

        $repaired = 0;

        foreach ($unlinked as $task) {
            $tenantId = (int) $task->sub_institute_id;
            $assignee = (int) $task->task_allocated_to;

            if ($tenantId <= 0 || $assignee <= 0) {
                continue;
            }

            $candidates = DB::table('s_user_jobrole_task')
                ->where('sub_institute_id', $tenantId)
                ->whereNull('deleted_at')
                ->whereRaw('TRIM(LOWER(task)) = ?', [trim(mb_strtolower((string) $task->task_title))])
                ->get(['id', 'jobrole_id']);

            // One candidate is the original backfill's job and it already ran;
            // zero means this is ad-hoc work with no duty. Only the ambiguous
            // rows are this migration's business.
            if ($candidates->count() < 2) {
                continue;
            }

            $roleId = $this->jobRoleOf($tenantId, $assignee);

            if ($roleId === null) {
                continue;
            }

            $match = $candidates->firstWhere('jobrole_id', $roleId);

            if (! $match) {
                // The assignee holds none of the candidate roles. Leave it NULL —
                // there is no honest way to choose, and a wrong duty would show
                // somebody the wrong procedure.
                continue;
            }

            DB::table('task')
                ->where('id', $task->id)
                ->whereNull('jobrole_task_id')
                ->update(['jobrole_task_id' => (int) $match->id]);

            $repaired++;
        }

        \Log::info('Repaired ambiguous task duty links', ['tasks' => $repaired]);
    }

    /**
     * Reversal clears ONLY what this migration set.
     *
     * A row is identified by the same rule that chose it: still ambiguous by
     * title, and pointing at the duty belonging to the assignee's own job role.
     * Anything else was linked by the earlier backfill or by the assign form and
     * is not ours to remove.
     */
    public function down(): void
    {
        foreach (['task', 's_user_jobrole_task', 'tbluser'] as $table) {
            if (! $this->tableExists($table)) {
                return;
            }
        }

        $linked = DB::table('task')
            ->whereNull('deleted_at')
            ->whereNotNull('jobrole_task_id')
            ->get(['id', 'task_title', 'task_allocated_to', 'sub_institute_id', 'jobrole_task_id']);

        foreach ($linked as $task) {
            $tenantId = (int) $task->sub_institute_id;
            $assignee = (int) $task->task_allocated_to;

            if ($tenantId <= 0 || $assignee <= 0) {
                continue;
            }

            $candidates = DB::table('s_user_jobrole_task')
                ->where('sub_institute_id', $tenantId)
                ->whereNull('deleted_at')
                ->whereRaw('TRIM(LOWER(task)) = ?', [trim(mb_strtolower((string) $task->task_title))])
                ->get(['id', 'jobrole_id']);

            if ($candidates->count() < 2) {
                continue;
            }

            $roleId = $this->jobRoleOf($tenantId, $assignee);
            $match = $roleId === null ? null : $candidates->firstWhere('jobrole_id', $roleId);

            if ($match && (int) $match->id === (int) $task->jobrole_task_id) {
                DB::table('task')->where('id', $task->id)->update(['jobrole_task_id' => null]);
            }
        }
    }

    /**
     * The employee's job role, resolved through BOTH columns.
     *
     * jobtitle_id is 0 for most employees because the employee form writes the
     * role to allocated_standards instead; reading either alone loses most
     * people. Mirrors ResolvesEmployeeJobRole, which cannot be used here because
     * a migration is not a controller.
     */
    private function jobRoleOf(int $tenantId, int $userId): ?int
    {
        $user = DB::table('tbluser')
            ->where('id', $userId)
            ->where('sub_institute_id', $tenantId)
            ->first(['jobtitle_id', 'allocated_standards']);

        if (! $user) {
            return null;
        }

        $candidates = [];

        if ((int) ($user->jobtitle_id ?? 0) > 0) {
            $candidates[] = (int) $user->jobtitle_id;
        }

        // allocated_standards is TEXT and historically held a list, so a bare
        // (int) cast is not safe.
        foreach (array_map('trim', explode(',', (string) ($user->allocated_standards ?? ''))) as $part) {
            if (is_numeric($part) && (int) $part > 0) {
                $candidates[] = (int) $part;
                break;
            }
        }

        return $candidates[0] ?? null;
    }

    /** Schema::hasTable() throws on live (MariaDB 10.1.48); read the catalogue directly. */
    private function tableExists(string $table): bool
    {
        $rows = DB::select(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        return (int) ($rows[0]->c ?? 0) > 0;
    }
};
