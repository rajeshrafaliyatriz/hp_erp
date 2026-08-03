<?php

namespace App\Models\user;

use App\Models\tblmenumaster_g2gModel;
use Illuminate\Database\Eloquent\Model;

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
}
