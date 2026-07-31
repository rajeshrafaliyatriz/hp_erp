<?php

namespace App\Models\talent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named successor on a succession plan, with their 9-box coordinates.
 */
class SuccessionCandidate extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'talent_succession_candidates';
    protected $guarded = ['id'];

    public const READINESS_LEVELS = ['ready-now', 'ready-1-2-years', 'ready-3-plus-years'];

    protected $casts = [
        'plan_id'           => 'integer',
        'sub_institute_id'  => 'integer',
        'employee_id'       => 'integer',
        'potential_score'   => 'integer',
        'performance_score' => 'integer',
        'rank_order'        => 'integer',
        'created_by'        => 'integer',
        'updated_by'        => 'integer',
    ];

    public function plan()
    {
        return $this->belongsTo(SuccessionPlan::class, 'plan_id');
    }
}
