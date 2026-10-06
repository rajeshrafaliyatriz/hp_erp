<?php

namespace App\Models\Mobility;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MobilityTalentPool extends Model
{
    use SoftDeletes;

    protected $table = 's_mobility_talent_pools';

    protected $guarded = [];

    public function members()
    {
        return $this->hasMany(MobilityTalentPoolMember::class, 'pool_id');
    }
}
