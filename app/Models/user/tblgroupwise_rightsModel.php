<?php

namespace App\Models\user;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Models\tblmenumasterModel;

/**
 * CRA-014. `sub_institute_id` here is a plain `bigint` - scalar equality.
 *
 * The sibling table `tblgroupwise_rights_g2g` (see tblgroupwise_rights_g2gModel)
 * stores the SAME kind of value as a TEXT comma-list instead (same shape the
 * menu catalogue uses - see tblmenumaster_g2gModel's own doc comment). The two
 * are NOT interchangeable: comparing this column to that one directly (a plain
 * `=` join, or reading one table's value as if it were the other's) will
 * silently under- or over-match, because `6` and `'6,12'` need entirely
 * different query logic. Always query each through its own model/scope below,
 * never across the pair.
 */
class tblgroupwise_rightsModel extends Model
{
    protected $table = 'tblgroupwise_rights';

    protected $fillable = [
        'id',
        'menu_id',
        'profile_id',
        'can_view',
        'can_add',
        'can_edit',
        'can_delete',
        'is_mobile',
        'created_at',
        'sub_institute_id'
    ];

    public $timestamps = false;

    public function menuData(){
        return $this->belongsTo(tblmenumasterModel::class, 'menu_id','id');
    }

    /** The one correct way to filter this table by tenant - scalar equality, not FIND_IN_SET. */
    public function scopeVisibleToTenant(Builder $query, int|string|null $subInstituteId): Builder
    {
        return $query->where('sub_institute_id', $subInstituteId);
    }
}
