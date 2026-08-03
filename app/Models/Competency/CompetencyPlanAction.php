<?php

namespace App\Models\Competency;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompetencyPlanAction extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 's_competency_plan_actions';
    protected $guarded = ['id'];

    protected $casts = [
        'sub_institute_id' => 'integer',
        'plan_id' => 'integer',
        'competency_id' => 'integer',
        'owner_id' => 'integer',
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'sequence' => 'integer',
    ];

    public function plan()
    {
        return $this->belongsTo(CompetencyDevelopmentPlan::class, 'plan_id');
    }
}
