<?php

namespace App\Models\Mobility;

use Illuminate\Database\Eloquent\Model;

class MobilityTalentPoolMember extends Model
{
    protected $table = 's_mobility_talent_pool_members';

    protected $guarded = [];

    protected $casts = [
        'added_on' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\user\tbluserModel::class, 'user_id');
    }

    public function pool()
    {
        return $this->belongsTo(MobilityTalentPool::class, 'pool_id');
    }
}
