<?php

namespace App\Models\Mobility;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MobilityPromotion extends Model
{
    use SoftDeletes;

    protected $table = 's_mobility_promotions';

    protected $guarded = [];

    protected $casts = [
        'effective_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\user\tbluserModel::class, 'user_id');
    }
}
