<?php

namespace App\Http\Controllers\HRMS\Concerns;

use App\Support\RoleKey;

/**
 * Who is who in Expense Management, in one place.
 *
 * Shared by the claim, advance, dashboard and report controllers so they cannot
 * disagree about what HR or an approver may do. Each takes the role_key of the
 * caller (RoleKey::forUserId) - never a name or a profile id.
 */
trait ExpenseRoles
{
    private function isHr(?string $role): bool
    {
        return RoleKey::satisfies($role, ['admin', 'hr']);
    }

    /** HR/admin, plus the two read-only oversight roles. */
    private function canReadAll(?string $role): bool
    {
        return RoleKey::satisfies($role, ['admin', 'hr', 'executive', 'auditor']);
    }

    /** The manager the item was routed to, or HR/admin. $row needs `approver_id`. */
    private function mayDecide($row, int $userId, ?string $role): bool
    {
        return $this->isHr($role) || (int) $row->approver_id === $userId;
    }
}
