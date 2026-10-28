<?php

namespace App\Services\TaskManagement;

use App\Support\SubjectAuthority;
use Illuminate\Support\Facades\DB;

/**
 * Whose calendar entries a given viewer may see.
 *
 * Phase 1: self + SubjectAuthority::TASK_PRIVILEGED (sees everyone) +
 * subordinates (same tbluser.employee_id chain MyTasksController::
 * subordinateIds already queries).
 *
 * Phase 4 adds two things, deliberately kept simple rather than replicating
 * CRM's two-layer row-access + "Busy" presentation masking (a presentation
 * nicety with no clear use case here, and real added complexity):
 *
 *   1. task_management_calendar_shares — a VOLUNTARY, per-person grant
 *      (`viewer_user_id = 0` = shared with everyone). The one real CRM
 *      capability this tiered model genuinely cannot express on its own.
 *   2. `visibility = 'PRIVATE'` on an entry — hidden from EVERYONE except
 *      its owner/assignee and TASK_PRIVILEGED, even someone it was shared
 *      with. A private entry is simply absent from a non-owner's feed; there
 *      is no masked "Busy" placeholder standing in for it.
 */
class CalendarVisibilityService
{
    /**
     * The set of owner_ids a viewer may see (shares included), or null for
     * "no restriction" (the viewer is TASK_PRIVILEGED and may see everyone).
     *
     * @return array<int>|null
     */
    public function visibleOwnerIds(int $viewerId, int $subInstituteId): ?array
    {
        if (SubjectAuthority::userSatisfies($viewerId, SubjectAuthority::TASK_PRIVILEGED)) {
            return null;
        }

        $subordinateIds = DB::table('tbluser')
            ->select('id')
            ->where('employee_id', $viewerId)
            ->where('sub_institute_id', $subInstituteId)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $sharedOwnerIds = DB::table('task_management_calendar_shares')
            ->where('sub_institute_id', $subInstituteId)
            ->where(fn ($query) => $query->where('viewer_user_id', $viewerId)->orWhere('viewer_user_id', CalendarShareService::EVERYONE))
            ->pluck('owner_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_unique([$viewerId, ...$subordinateIds, ...$sharedOwnerIds]);
    }

    /** Whether $viewerId may see a PUBLIC/default entry owned by $ownerId — ignores `visibility`, see canViewEntry(). */
    public function canView(int $viewerId, int $ownerId, int $subInstituteId): bool
    {
        $visible = $this->visibleOwnerIds($viewerId, $subInstituteId);

        return $visible === null || in_array($ownerId, $visible, true);
    }

    /**
     * The real check, `visibility` included: a PRIVATE entry is invisible to
     * everyone but its owner and a TASK_PRIVILEGED viewer, REGARDLESS of any
     * share grant — sharing your calendar shares what's on it, not what you
     * marked private.
     */
    public function canViewEntry(int $viewerId, int $ownerId, ?string $visibility, int $subInstituteId): bool
    {
        if ($viewerId === $ownerId) {
            return true;
        }

        $privileged = SubjectAuthority::userSatisfies($viewerId, SubjectAuthority::TASK_PRIVILEGED);
        if ($visibility === 'PRIVATE') {
            return $privileged;
        }

        return $privileged || $this->canView($viewerId, $ownerId, $subInstituteId);
    }
}
