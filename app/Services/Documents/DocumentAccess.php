<?php

namespace App\Services\Documents;

use App\Support\RoleKey;
use App\Support\SubjectAuthority;
use Illuminate\Database\Query\Builder;

/**
 * Who may see a document_library row, applied two ways: as a query scope (for
 * lists/search) and as a single-row check (for download/detail), so a list
 * and a direct fetch can never disagree about what a caller may read.
 *
 * Modeled on the reference implementation's `DocumentMaster::scopeVisibleTo()`
 * (next_lms_erp's IDMS), adapted to this codebase's DB::table() convention and
 * its `SubjectAuthority` tiers rather than a raw `is_admin` column.
 *
 * Three ways in, checked in order of least privilege - cheapest and most
 * common first:
 *   1. it is theirs (owner_id = caller);
 *   2. HR_ELEVATED in the caller's own tenant (role gate already proven
 *      correct by EmployeeDocumentController's readableDocument());
 *   3. the row's own visibility says so - organization-wide, or department
 *      matching the caller's department, or the caller is named in
 *      view_principals.
 */
class DocumentAccess
{
    /** Narrow a document_library query to rows this caller may see. */
    public static function visibleTo(Builder $query, int $userId, int $tenantId, ?int $departmentId): Builder
    {
        $isElevated = SubjectAuthority::userSatisfies($userId, SubjectAuthority::HR_ELEVATED);

        return $query->where('sub_institute_id', $tenantId)
            ->where(function (Builder $q) use ($userId, $departmentId, $isElevated) {
                $q->where('owner_id', $userId);

                if ($isElevated) {
                    // HR/admin reads organisation-wide WITHIN their own tenant -
                    // the caller is already scoped to sub_institute_id above.
                    $q->orWhere('sub_institute_id', '>', 0);

                    return;
                }

                $q->orWhere('visibility', 'organization')
                    ->orWhere('view_principals', 'like', self::principalLike('user:' . $userId));

                if ($departmentId) {
                    $q->orWhere(function (Builder $dq) use ($departmentId) {
                        $dq->where('visibility', 'department')->where('department_id', $departmentId);
                    })->orWhere('view_principals', 'like', self::principalLike('dept:' . $departmentId));
                }
            });
    }

    /** May this caller read this one row? */
    public static function canView(object $row, int $userId, int $tenantId, ?int $departmentId): bool
    {
        if ((int) $row->sub_institute_id !== $tenantId) {
            return false;
        }

        if ((int) $row->owner_id === $userId) {
            return true;
        }

        if (SubjectAuthority::userSatisfies($userId, SubjectAuthority::HR_ELEVATED)) {
            return true;
        }

        if ((string) $row->visibility === 'organization') {
            return true;
        }

        $principals = self::decodePrincipals($row->view_principals ?? null);

        if (in_array('user:' . $userId, $principals, true)) {
            return true;
        }

        if ($departmentId && (string) $row->visibility === 'department'
            && (int) ($row->department_id ?? 0) === $departmentId) {
            return true;
        }

        return $departmentId && in_array('dept:' . $departmentId, $principals, true);
    }

    /** May this caller write (rename/retag/delete) this row? Narrower than canView. */
    public static function canManage(object $row, int $userId, int $tenantId): bool
    {
        if ((int) $row->sub_institute_id !== $tenantId) {
            return false;
        }

        if ((int) $row->owner_id === $userId || (int) ($row->created_by ?? 0) === $userId) {
            return true;
        }

        return in_array(RoleKey::forUserId($userId), SubjectAuthority::RECORD_OWNERS, true);
    }

    /**
     * A LIKE pattern matching this principal as one element of the
     * JSON-array-shaped string in `view_principals` (e.g. `["user:5","dept:2"]`).
     *
     * Not JSON_CONTAINS(): this table is also migrated onto the `live`
     * connection (MariaDB 10.1.48), which has no JSON functions at all - see
     * the document_library migration's note on why these columns are
     * longText rather than the native `json` type. A quoted-string LIKE
     * match is portable to every host this runs on and correct for the
     * simple flat array this column actually holds.
     */
    private static function principalLike(string $principal): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $principal);

        return '%"' . $escaped . '"%';
    }

    /** @return string[] */
    private static function decodePrincipals($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Rebuild view_principals from visibility + department + explicit
     * permissions, so the scope above can filter on this JSON array directly
     * instead of re-deriving the ACL per row at query time.
     *
     * @param  array<string, mixed>  $permissions  per-principal overrides, same shape stored in the `permissions` column
     * @return string[]
     */
    public static function computePrincipals(string $visibility, ?int $departmentId, array $permissions): array
    {
        $principals = [];

        if ($visibility === 'department' && $departmentId) {
            $principals[] = 'dept:' . $departmentId;
        }

        foreach (array_keys($permissions) as $principal) {
            if (is_string($principal) && $principal !== '') {
                $principals[] = $principal;
            }
        }

        return array_values(array_unique($principals));
    }
}
