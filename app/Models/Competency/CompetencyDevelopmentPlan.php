<?php

namespace App\Models\Competency;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompetencyDevelopmentPlan extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 's_competency_development_plans';
    protected $guarded = ['id'];

    protected $casts = [
        'sub_institute_id' => 'integer',
        'user_id' => 'integer',
        'competency_id' => 'integer',
        'framework_id' => 'integer',
        'department_id' => 'integer',
        'progress' => 'integer',
        'start_date' => 'date',
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'approver_id' => 'integer',
        'mentor_id' => 'integer',
    ];
}
