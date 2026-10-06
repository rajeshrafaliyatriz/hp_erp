<?php

namespace App\Models\Mobility;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MobilityApplication extends Model
{
    use SoftDeletes;

    protected $table = 's_mobility_applications';

    protected $guarded = [];

    protected $casts = [
        'applied_on' => 'date',
    ];

    public function job()
    {
        return $this->belongsTo(MobilityJob::class, 'job_posting_id');
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\user\tbluserModel::class, 'user_id');
    }
}
