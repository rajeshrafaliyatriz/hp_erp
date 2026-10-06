<?php

namespace App\Services\Payroll;

use Illuminate\Support\Facades\DB;

/**
 * Whether a payroll month may still be written to. F-129.
 *
 * One question, one place. `monthlyPayrollStore` asks it before writing, the
 * screen asks it to decide what to show, and both get the same answer - which is
 * the whole point, because a lock enforced only in the browser is the defect
 * F-91 already found in this module's payroll once.
 */
class PayrollMonthLock
{
    /** Is this month closed to further writes? */
    public function isLocked(int $tenant, string $month, int $year): bool
    {
        return $this->state($tenant, $month, $year)['locked'];
    }

    /**
     * The month's full state, for a screen that needs to explain itself.
     *
     * @return array{locked:bool, locked_at:?string, locked_by:?string, reopened_at:?string, reopen_reason:?string}
     */
    public function state(int $tenant, string $month, int $year): array
    {
        $row = DB::table('payroll_month_locks')
            ->where('sub_institute_id', $tenant)
            ->where('month', $month)
            ->where('year', $year)
            ->first();

        if (!$row) {
            return [
                'locked'        => false,
                'locked_at'     => null,
                'locked_by'     => null,
                'reopened_at'   => null,
                'reopen_reason' => null,
            ];
        }

        // Reopening after a lock is what makes the month writable again, so the
        // comparison is between the two timestamps rather than a boolean that
        // would have to be kept in step with them.
        $locked = $row->locked_at !== null
            && ($row->reopened_at === null || $row->reopened_at < $row->locked_at);

        return [
            'locked'        => $locked,
            'locked_at'     => $row->locked_at,
            'locked_by'     => $this->name($row->locked_by),
            'reopened_at'   => $row->reopened_at,
            'reopen_reason' => $row->reopen_reason,
        ];
    }

    /** Declare the month finished. */
    public function lock(int $tenant, string $month, int $year, int $actorId): void
    {
        DB::table('payroll_month_locks')->updateOrInsert(
            ['sub_institute_id' => $tenant, 'month' => $month, 'year' => $year],
            [
                'locked_at'  => now(),
                'locked_by'  => $actorId,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * Reopen it, with a reason.
     *
     * The reason is required by the caller, not defaulted here. A lock that can
     * be lifted silently is not a lock, and "why were March's figures changed
     * after we paid them?" should be answerable from the data.
     */
    public function reopen(int $tenant, string $month, int $year, int $actorId, string $reason): void
    {
        DB::table('payroll_month_locks')
            ->where('sub_institute_id', $tenant)
            ->where('month', $month)
            ->where('year', $year)
            ->update([
                'reopened_at'   => now(),
                'reopened_by'   => $actorId,
                'reopen_reason' => $reason,
                'updated_at'    => now(),
            ]);
    }

    private function name(?int $userId): ?string
    {
        if (!$userId) {
            return null;
        }

        return DB::table('tbluser')
            ->selectRaw("TRIM(CONCAT_WS(' ', first_name, last_name)) AS n")
            ->where('id', $userId)
            ->value('n');
    }
}
