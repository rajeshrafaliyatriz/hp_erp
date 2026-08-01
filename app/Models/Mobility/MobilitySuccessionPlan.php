<?php

namespace App\Models\Mobility;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MobilitySuccessionPlan extends Model
{
    use SoftDeletes;

    protected $table = 's_mobility_succession_plans';

    protected $guarded = [];

    protected $casts = [
        'emergency_successor' => 'boolean',
    ];

    public function successor()
    {
        return $this->belongsTo(\App\Models\user\tbluserModel::class, 'successor_user_id');
    }

    public function criticalJobrole()
    {
        return $this->belongsTo(\App\Models\libraries\userJobroleModel::class, 'critical_jobrole_id');
    }
}
