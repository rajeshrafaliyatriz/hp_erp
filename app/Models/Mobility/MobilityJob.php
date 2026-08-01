<?php

namespace App\Models\Mobility;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MobilityJob extends Model
{
    use SoftDeletes;

    protected $table = 's_mobility_jobs';

    protected $guarded = [];

    protected $casts = [
        'posted_on' => 'date',
        'deadline' => 'date',
        'vacancies' => 'integer',
    ];

    public function applications()
    {
        return $this->hasMany(MobilityApplication::class, 'job_posting_id');
    }
}
