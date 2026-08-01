<?php

namespace App\Models\talent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A critical role, its incumbent, and the bench behind it.
 *
 * Backs the Mobility & Succession screen's "Critical Roles" card and the 9-box
 * succession matrix.
 */
class SuccessionPlan extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'talent_succession_plans';
    protected $guarded = ['id'];

    public const CRITICALITIES = ['critical', 'high', 'medium', 'low'];

    public const RISK_LEVELS = ['high', 'medium', 'low'];

    /** Whether a ready successor exists for the role. */
    public const COVERAGE_STATUSES = ['covered', 'at-risk', 'gap'];

    protected $casts = [
        'sub_institute_id' => 'integer',
        'department_id'    => 'integer',
        'incumbent_id'     => 'integer',
        'created_by'       => 'integer',
        'updated_by'       => 'integer',
    ];

    public function candidates()
    {
        return $this->hasMany(SuccessionCandidate::class, 'plan_id');
    }
}
