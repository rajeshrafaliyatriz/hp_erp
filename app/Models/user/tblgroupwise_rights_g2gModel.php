<?php

namespace App\Models\user;

use App\Models\tblmenumaster_g2gModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * CRA-014. `sub_institute_id` here is a TEXT comma-list, the same shape
 * `tblmenumaster_g2gModel` uses - NOT the plain `bigint` the sibling
 * `tblgroupwise_rights` (no `_g2g`) table uses for the same kind of value. The
 * two are NOT interchangeable: comparing this column to that one directly (a
 * plain `=` join, or reading one table's value as if it were the other's)
 * will silently under- or over-match. Always query each through its own
 * model/scope, never across the pair - see tblgroupwise_rightsModel's own
 * doc comment for the other half of this.
 */
class tblgroupwise_rights_g2gModel extends Model
{
    protected $table = 'tblgroupwise_rights_g2g';

    protected $fillable = [
        'id',
        'menu_id',
        'profile_id',
        'can_view',
        'can_add',
        'can_edit',
        'can_delete',
        'dashboard_right',
        'is_mobile',
        'created_at',
        'sub_institute_id',
    ];

    public $timestamps = false;

    public function menuData()
    {
        return $this->belongsTo(tblmenumaster_g2gModel::class, 'menu_id', 'id');
    }

    /**
     * The one correct way to filter this table by tenant - FIND_IN_SET against
     * the comma-list, not `=`.
     *
     * NOT VERIFIED against live data whether an empty/NULL sub_institute_id row
     * means "applies to every tenant" here the same way it does on
     * tblmenumaster_g2gModel::scopeVisibleToTenant() - that meaning was
     * confirmed there by measuring real rows; nobody has queried this table
     * through a scope before, so this mirrors that model's shape for
     * consistency but should be checked against real rows before a new caller
     * relies on the NULL/empty branch specifically.
     */
    public function scopeVisibleToTenant(Builder $query, int|string|null $subInstituteId): Builder
    {
        return $query->where(function (Builder $q) use ($subInstituteId) {
            $q->whereNull('sub_institute_id')
                ->orWhere('sub_institute_id', '');

            if ($subInstituteId !== null && $subInstituteId !== '') {
                $q->orWhereRaw('FIND_IN_SET(?, sub_institute_id)', [$subInstituteId]);
            }
        });
    }
}
