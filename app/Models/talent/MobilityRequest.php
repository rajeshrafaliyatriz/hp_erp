<?php

namespace App\Models\talent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An employee's move: an application to an internal job, or a transfer,
 * promotion or deputation raised directly.
 *
 * The dashboard's Mobility card reads total active requests and, separately,
 * the internal-application subset - hence request_type is a closed set.
 */
class MobilityRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'talent_mobility_requests';
    protected $guarded = ['id'];

    public const TYPES = ['internal-application', 'transfer', 'promotion', 'deputation'];

    public const STATUSES = ['pending', 'in-review', 'approved', 'rejected', 'withdrawn'];

    /** Still moving through the workflow - what the Mobility KPI counts. */
    public const ACTIVE_STATUSES = ['pending', 'in-review'];

    protected $casts = [
        'sub_institute_id'   => 'integer',
        'employee_id'        => 'integer',
        'internal_job_id'    => 'integer',
        'job_posting_id'     => 'integer',
        'from_department_id' => 'integer',
        'to_department_id'   => 'integer',
        'reviewed_by'        => 'integer',
        'requested_on'       => 'date',
        'effective_date'     => 'date',
        'reviewed_at'        => 'datetime',
        'created_by'         => 'integer',
        'updated_by'         => 'integer',
    ];

    public function internalJob()
    {
        return $this->belongsTo(InternalJob::class, 'internal_job_id');
    }
}
