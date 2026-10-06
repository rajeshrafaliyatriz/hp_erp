<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class tblmenumaster_g2gModel extends Model
{
    /**
     * The table has always had a `deleted_at` column; the model never honoured
     * it. Exactly one reader - DashboardLinkResolver - filtered on it by hand,
     * so soft-deleting a menu would have removed its dashboard link while
     * leaving it in the sidebar. The trait makes every reader agree.
     *
     * Behaviour-neutral to add: 0 soft-deleted rows on both databases, and
     * nothing calls delete() on this model - it is a catalogue that is read.
     */
    use SoftDeletes;

    protected $table = 'tblmenumaster_g2g';

    /**
     * THE MENU CATALOGUE IS GLOBAL UNLESS A ROW SAYS OTHERWISE.
     *
     * ── THE BUG THIS EXISTS TO FIX ──────────────────────────────────────────
     *
     * `sub_institute_id` here is NOT a tenant column in the sense the rest of
     * the schema uses it. It is a TEXT comma-list, and every one of the 188
     * rows on both databases carried the SAME literal:
     *
     *     '1,2,3,4,5,6,7,8,9,10,11'
     *
     * Four call sites gated on `FIND_IN_SET(<tenant>, sub_institute_id)`, so
     * the catalogue was silently closed to every organisation with an id of 12
     * or above - a hard-coded guest list that nobody was maintaining. Measured
     * on live before the change:
     *
     *     tenant  3 -> 137 menus        tenant 13 -> 0
     *     tenant  6 -> 137 menus        tenant 14 -> 0
     *                                   tenant 15 (the next signup) -> 0
     *
     * A new organisation could not open Department Management, Employee
     * Directory, Capability Library, Competency Library or Competency
     * Framework - and no rights row could have made them appear, because this
     * filter runs BEFORE rights are consulted. The rights table showed nothing
     * wrong, which is why the cause sat here rather than there.
     *
     * ── WHY NOT JUST APPEND THE NEW ID ──────────────────────────────────────
     *
     * Because the list would then grow by one entry per signup, in a TEXT
     * column re-read on every sidebar request, and would be one forgotten
     * INSERT away from the same outage. The literal is removed instead (see
     * 2026_08_22_110000_make_g2g_menu_catalogue_global), leaving the column
     * NULL and this scope reading NULL as "available to everyone".
     *
     * The column is KEPT, not dropped: a genuinely tenant-specific menu is a
     * reasonable future need, and this scope still honours one. What changes is
     * the default - absent means global, not denied.
     *
     * ── THE PARENTHESES ARE LOAD-BEARING ────────────────────────────────────
     *
     * The three OR terms are wrapped in a nested `where(function ...)`. Without
     * that grouping the OR would escape the closure and swallow every other
     * condition on the query - `status = 1` most importantly - turning a
     * filtered read into a full-table one. This is exactly the shape that makes
     * an OR in a shared scope dangerous, so it is grouped here once rather than
     * trusted to four call sites.
     */
    public function scopeVisibleToTenant(Builder $query, int|string|null $subInstituteId): Builder
    {
        return $query->where(function (Builder $q) use ($subInstituteId) {
            $q->whereNull('sub_institute_id')
                ->orWhere('sub_institute_id', '');

            // An empty tenant cannot match a restriction, and must not be
            // handed the restricted rows by accident.
            if ($subInstituteId !== null && $subInstituteId !== '') {
                $q->orWhereRaw('FIND_IN_SET(?, sub_institute_id)', [$subInstituteId]);
            }
        });
    }
}
