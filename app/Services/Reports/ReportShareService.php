<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-person report sharing — shaped directly on
 * App\Services\Documents\DocumentShareService (share/unshare/listShares/
 * canEdit), the established pattern in this codebase for "share one record
 * with specific named people".
 *
 * No `syncViewPrincipals()`-style denormalization here: unlike Document
 * Library (which folds sharing into one ordinary list via a materialized
 * `view_principals` column), "Shared With Me" for reports is its own
 * distinct, computed view (`ReportController::index` with `view=shared`) —
 * a live join against `report_shares`, read directly, nothing to keep in
 * sync.
 */
class ReportShareService
{
    public function share(int $reportId, int $viewerUserId, bool $canEdit, int $subInstituteId, int $actorId): void
    {
        DB::table('report_shares')->updateOrInsert(
            ['report_id' => $reportId, 'viewer_user_id' => $viewerUserId],
            ['sub_institute_id' => $subInstituteId, 'can_edit' => $canEdit, 'created_by' => $actorId, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function unshare(int $reportId, int $shareId): bool
    {
        return (bool) DB::table('report_shares')
            ->where('id', $shareId)
            ->where('report_id', $reportId)
            ->delete();
    }

    /** Who this report has been shared with — the owner's "People with access" list. */
    public function listShares(int $reportId): Collection
    {
        return DB::table('report_shares as s')
            ->leftJoin('tbluser as viewer', 'viewer.id', '=', 's.viewer_user_id')
            ->where('s.report_id', $reportId)
            ->orderBy('s.id')
            ->get([
                's.id', 's.viewer_user_id', 's.can_edit',
                DB::raw("TRIM(CONCAT_WS(' ', viewer.first_name, viewer.middle_name, viewer.last_name)) as viewer_name"),
            ]);
    }

    /** Does this user have an active edit-level share on this report? */
    public function canEdit(int $reportId, int $userId): bool
    {
        return DB::table('report_shares')
            ->where('report_id', $reportId)
            ->where('viewer_user_id', $userId)
            ->where('can_edit', true)
            ->exists();
    }

    /** Does this user have ANY share grant on this report (view or edit)? */
    public function isSharedWith(int $reportId, int $userId): bool
    {
        return DB::table('report_shares')
            ->where('report_id', $reportId)
            ->where('viewer_user_id', $userId)
            ->exists();
    }
}
