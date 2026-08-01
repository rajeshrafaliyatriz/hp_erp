<?php

namespace App\Models\talent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One department's sign-off on a departing employee.
 *
 * The dashboard's "Clearances Pending" action item counts these individually,
 * not the cases they belong to - one case commonly has five or six.
 */
class OffboardingClearance extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'talent_offboarding_clearances';
    protected $guarded = ['id'];

    public const TYPES = ['it', 'finance', 'hr', 'admin', 'manager', 'library'];

    public const STATUSES = ['pending', 'in-progress', 'cleared', 'not-applicable'];

    /** Still awaiting a sign-off. */
    public const OPEN_STATUSES = ['pending', 'in-progress'];

    protected $casts = [
        'case_id'          => 'integer',
        'sub_institute_id' => 'integer',
        'owner_id'         => 'integer',
        'cleared_by'       => 'integer',
        'cleared_at'       => 'datetime',
        'sort_order'       => 'integer',
        'created_by'       => 'integer',
        'updated_by'       => 'integer',
    ];

    public function offboardingCase()
    {
        return $this->belongsTo(OffboardingCase::class, 'case_id');
    }
}
