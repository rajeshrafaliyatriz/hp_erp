<?php

namespace App\Models\talent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A role opened to internal candidates only.
 *
 * Deliberately not a flag on talent_job_postings: that table backs the public
 * job portal, the ATS, the requisitions report and the acquisition dashboard,
 * and adding internal-only rows to it would change every one of those counts.
 */
class InternalJob extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'talent_internal_jobs';
    protected $guarded = ['id'];

    public const STATUSES = ['open', 'in-review', 'closed'];

    protected $casts = [
        'sub_institute_id'  => 'integer',
        'department_id'     => 'integer',
        'hiring_manager_id' => 'integer',
        'positions'         => 'integer',
        'posted_on'         => 'date',
        'closing_date'      => 'date',
        'created_by'        => 'integer',
        'updated_by'        => 'integer',
    ];

    public function applications()
    {
        return $this->hasMany(MobilityRequest::class, 'internal_job_id');
    }
}
